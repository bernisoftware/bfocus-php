<?php

declare(strict_types=1);

namespace Bfocus\Resources;

use Bfocus\Exception\BfocusException;
use Bfocus\Internal\Transport;

/**
 * Base de conhecimento — `$bf->kb`. Escopos `kb:read` / `kb:write`.
 *
 * @phpstan-import-type RequestOptions from AbstractResource
 *
 * @phpstan-type KbSearchHit array{id: string, external_id: string|null, title: string, excerpt: string}
 * @phpstan-type KbSearchParams array{product?: string|null, limit?: int}
 */
final class Kb extends AbstractResource
{
    private const SEARCH_PARAMS = ['product', 'limit'];

    /** Artigos (CRUD, lote, publicação). */
    public readonly KbArticles $articles;

    /** @internal */
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
        $this->articles = new KbArticles($transport);
    }

    /**
     * Busca nos artigos PUBLICADOS (a mesma busca que alimenta o agente de IA).
     *
     * @param KbSearchParams $params `product` (slug) filtra; `limit` 1–20 (padrão 5).
     * @param RequestOptions $options
     * @return list<KbSearchHit>
     * @throws BfocusException
     */
    public function search(string $q, array $params = [], array $options = []): array
    {
        $query = ['q' => $q] + self::only($params, self::SEARCH_PARAMS, 'kb->search');

        return $this->call('GET', '/kb/search', $query, null, $options);
    }
}
