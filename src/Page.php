<?php

declare(strict_types=1);

namespace Bfocus;

/**
 * Uma página de uma listagem paginada.
 *
 * ```php
 * $pagina = $bf->customers->list(['page_size' => 50]);
 * foreach ($pagina as $cliente) { ... }      // itera os itens
 * echo count($pagina), ' de ', $pagina->total;
 * ```
 *
 * Para percorrer todas as páginas, use o `listAll()` do recurso.
 *
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
final class Page implements \IteratorAggregate, \Countable
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        /** @var list<T> Itens desta página. */
        public readonly array $items,
        /** Número desta página (começa em 1). */
        public readonly int $page,
        /** Tamanho de página usado pela API. */
        public readonly int $pageSize,
        /** Total de itens em todas as páginas. */
        public readonly int $total,
        /** Total de páginas. */
        public readonly int $pages,
    ) {
    }

    /**
     * @internal Monta a página a partir do envelope `{data, pagination}` da API.
     *
     * @param array<string, mixed> $envelope
     * @return self<mixed>
     */
    public static function fromEnvelope(array $envelope): self
    {
        $items = is_array($envelope['data'] ?? null) ? array_values($envelope['data']) : [];
        $pagination = is_array($envelope['pagination'] ?? null) ? $envelope['pagination'] : [];
        $count = count($items);

        return new self(
            $items,
            (int) ($pagination['page'] ?? 1),
            (int) ($pagination['page_size'] ?? $count),
            (int) ($pagination['total'] ?? $count),
            (int) ($pagination['pages'] ?? 1),
        );
    }

    /** Há uma página depois desta? */
    public function hasNextPage(): bool
    {
        return $this->page < $this->pages;
    }

    /** @return \ArrayIterator<int, T> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /** Quantidade de itens NESTA página (o total geral está em `$total`). */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Forma neutra (a mesma do JSON da API).
     *
     * @return array{items: list<T>, page: int, page_size: int, total: int, pages: int}
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'page' => $this->page,
            'page_size' => $this->pageSize,
            'total' => $this->total,
            'pages' => $this->pages,
        ];
    }
}
