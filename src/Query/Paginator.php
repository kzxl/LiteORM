<?php

declare(strict_types=1);

namespace LiteORM\Query;

/**
 * Lightweight paginator for API results.
 *
 * @template T
 */
readonly class Paginator implements \JsonSerializable
{
    /**
     * @param T[] $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $currentPage,
        public int $perPage,
        public int $lastPage,
    ) {}

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return array{data: T[], meta: array{total: int, current_page: int, per_page: int, last_page: int, has_more: bool}}
     */
    public function toArray(): array
    {
        return [
            'data' => $this->items,
            'meta' => [
                'total' => $this->total,
                'current_page' => $this->currentPage,
                'per_page' => $this->perPage,
                'last_page' => $this->lastPage,
                'has_more' => $this->hasMorePages(),
            ],
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
