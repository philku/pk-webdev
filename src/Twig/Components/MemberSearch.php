<?php

namespace App\Twig\Components;

use App\Repository\MemberRepository;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

// Diese Live Component steuert die Echtzeit-Suche auf der Vereinsplaner-Seite.
// Der User tippt ins Suchfeld → der Wert wird an den Server geschickt →
// die Mitgliederliste wird sofort mit den gefilterten Ergebnissen neu gerendert.
#[AsLiveComponent]
class MemberSearch
{
    use DefaultActionTrait;

    // writable: true = dieses Property kann vom Browser geändert werden.
    // Wenn der User ins Suchfeld tippt, wird `query` automatisch aktualisiert.
    // Das ist das Herzstück: die Verbindung zwischen Input-Feld und Server-State.
    #[LiveProp(writable: true)]
    public string $query = '';

    // Dependency Injection: Symfony gibt uns automatisch das MemberRepository.
    // __construct-Parameter werden von Symfony's Service Container aufgelöst.
    public function __construct(
        private MemberRepository $memberRepository,
    ) {
    }

    /**
     * Wird bei jedem Re-Render aufgerufen (also bei jeder Eingabe).
     * Gibt die gefilterten Mitglieder zurück, die im Template angezeigt werden.
     *
     * @return \App\Entity\Member[]
     */
    public function getMembers(): array
    {
        return $this->memberRepository->search($this->query);
    }
}
