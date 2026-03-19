<?php

namespace App\Twig\Components;

use App\Repository\MemberRepository;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class MemberSearch
{
    use DefaultActionTrait;

    private const PER_PAGE = 5;

    #[LiveProp(writable: true)]
    public string $query = '';

    #[LiveProp(writable: true)]
    public string $sortBy = 'name';

    #[LiveProp(writable: true)]
    public string $sortDirection = 'ASC';

    #[LiveProp(writable: true)]
    public int $page = 1;

    public function __construct(
        private MemberRepository $memberRepository,
    ) {
    }

    // Toggles direction on same column, resets to ASC on new column.
    #[LiveAction]
    public function sort(#[LiveArg] string $field): void
    {
        if ($this->sortBy === $field) {
            $this->sortDirection = 'ASC' === $this->sortDirection ? 'DESC' : 'ASC';
        } else {
            $this->sortBy = $field;
            $this->sortDirection = 'ASC';
        }

        $this->page = 1;
    }

    #[LiveAction]
    public function goToPage(#[LiveArg] int $page): void
    {
        $this->page = $page;
    }

    // Clamp page when search narrows results.
    #[PreReRender]
    public function ensureValidPage(): void
    {
        $lastPage = $this->getLastPage();
        if ($this->page > $lastPage) {
            $this->page = $lastPage;
        }
    }

    /** @return \App\Entity\Member[] */
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

    public function getTotal(): int
    {
        return $this->memberRepository->countSearch($this->query);
    }

    public function getLastPage(): int
    {
        return max(1, (int) ceil($this->getTotal() / self::PER_PAGE));
    }
}
