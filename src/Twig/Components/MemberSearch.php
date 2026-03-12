<?php

namespace App\Twig\Components;

use App\Repository\MemberRepository;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;

// Diese Live Component steuert Suche, Sortierung und Paginierung
// auf der Vereinsplaner-Seite — alles ohne Page Reload.
#[AsLiveComponent]
class MemberSearch
{
    use DefaultActionTrait;

    private const PER_PAGE = 5;

    // writable: true = dieses Property kann vom Browser geändert werden.
    // Wenn der User ins Suchfeld tippt, wird `query` automatisch aktualisiert.
    #[LiveProp(writable: true)]
    public string $query = '';

    // Sortierfeld: nach welcher Spalte sortiert wird (name, email, team, role).
    #[LiveProp(writable: true)]
    public string $sortBy = 'name';

    // Sortierrichtung: ASC (aufsteigend) oder DESC (absteigend).
    #[LiveProp(writable: true)]
    public string $sortDirection = 'ASC';

    // Aktuelle Seite für die Paginierung.
    #[LiveProp(writable: true)]
    public int $page = 1;

    public function __construct(
        private MemberRepository $memberRepository,
    ) {
    }

    // LiveAction: Wird aufgerufen wenn der User auf eine Spaltenüberschrift klickt.
    // Wenn dieselbe Spalte nochmal geklickt wird, dreht sich die Richtung um.
    // Bei einer neuen Spalte wird auf ASC zurückgesetzt.
    #[LiveAction]
    // #[LiveArg] = erlaubt Symfony, den Wert aus dem Browser-Request zu lesen.
    // Ohne dieses Attribut weiß Symfony nicht, woher $field kommen soll.
    public function sort(#[LiveArg] string $field): void
    {
        if ($this->sortBy === $field) {
            // Gleiche Spalte nochmal geklickt → Richtung umdrehen
            $this->sortDirection = 'ASC' === $this->sortDirection ? 'DESC' : 'ASC';
        } else {
            // Neue Spalte → aufsteigend starten
            $this->sortBy = $field;
            $this->sortDirection = 'ASC';
        }

        // Bei neuer Sortierung zurück auf Seite 1
        $this->page = 1;
    }

    // LiveAction: Wird aufgerufen wenn der User auf eine Seitenzahl klickt.
    #[LiveAction]
    public function goToPage(#[LiveArg] int $page): void
    {
        $this->page = $page;
    }

    // Wird vor jedem Re-Render aufgerufen.
    // Stellt sicher, dass die Seite nicht höher ist als die letzte Seite.
    // z.B. wenn man auf Seite 3 war und dann sucht → nur noch 1 Seite Ergebnisse → zurück auf 1.
    #[PreReRender]
    public function ensureValidPage(): void
    {
        $lastPage = $this->getLastPage();
        if ($this->page > $lastPage) {
            $this->page = $lastPage;
        }
    }

    /**
     * Gibt die gefilterten, sortierten und paginierten Mitglieder zurück.
     *
     * @return \App\Entity\Member[]
     */
    public function getMembers(): array
    {
        return $this->memberRepository->search(
            $this->query,
            $this->sortBy,
            $this->sortDirection,
            $this->page,
            self::PER_PAGE,
        );
    }

    /**
     * Gesamtzahl der Treffer (für Paginierung und Anzeige).
     */
    public function getTotal(): int
    {
        return $this->memberRepository->countSearch($this->query);
    }

    /**
     * Letzte mögliche Seite.
     */
    public function getLastPage(): int
    {
        return max(1, (int) ceil($this->getTotal() / self::PER_PAGE));
    }
}
