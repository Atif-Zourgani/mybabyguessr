<?php

namespace App\Admin;

/**
 * Une page de résultats paginés pour les listes d'administration.
 *
 * @template T
 */
final class Page
{
    public const PER_PAGE = 25;

    /**
     * @param list<T> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage = self::PER_PAGE,
    ) {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pages();
    }

    /** Numéro du premier résultat affiché (0 si la page est vide). */
    public function from(): int
    {
        return $this->items === [] ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function to(): int
    {
        return $this->items === [] ? 0 : $this->from() + count($this->items) - 1;
    }
}
