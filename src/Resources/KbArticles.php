<?php

declare(strict_types=1);

namespace Bfocus\Resources;

use Bfocus\Exception\BfocusException;
use Bfocus\Page;

/**
 * Artigos da base de conhecimento — `$bf->kb->articles`.
 *
 * O artigo é identificado pelo SEU `external_id` (ex.: `notion:emitir-nfse`, `git:docs:guia`
 * — sem barras). `external_id` com `/`, vazio, `.` ou `..` é recusado antes de chamar a API.
 *
 * @phpstan-import-type RequestOptions from AbstractResource
 * @phpstan-import-type Deleted from Customers
 *
 * @phpstan-type KbArticleSummary array{
 *     id: string,
 *     external_id: string|null,
 *     product: string|null,
 *     title: string,
 *     excerpt: string,
 *     status: string,
 *     origin: string,
 *     published_at: string|null,
 *     created_at: string|null,
 *     updated_at: string|null
 * }
 * @phpstan-type KbArticle array{
 *     id: string,
 *     external_id: string|null,
 *     product: string|null,
 *     title: string,
 *     body_html: string,
 *     excerpt: string,
 *     status: string,
 *     origin: string,
 *     published_at: string|null,
 *     created_at: string|null,
 *     updated_at: string|null
 * }
 * @phpstan-type KbArticleFields array{
 *     title?: string|null,
 *     body_html?: string|null,
 *     body_markdown?: string|null,
 *     product?: string|null,
 *     status?: 'draft'|'published'|null
 * }
 * @phpstan-type KbBatchItem array{
 *     external_id: string,
 *     title?: string|null,
 *     body_html?: string|null,
 *     body_markdown?: string|null,
 *     product?: string|null,
 *     status?: 'draft'|'published'|null
 * }
 * @phpstan-type KbBatchResult array{
 *     external_id: string,
 *     ok: bool,
 *     action: 'created'|'updated'|'unchanged'|null,
 *     error: string|null,
 *     article: KbArticleSummary|null
 * }
 * @phpstan-type KbBatchOut array{
 *     results: list<KbBatchResult>,
 *     created: int,
 *     updated: int,
 *     unchanged: int,
 *     failed: int
 * }
 * @phpstan-type KbArticleListParams array{
 *     product?: string|null,
 *     status?: 'draft'|'published'|null,
 *     q?: string|null,
 *     updated_since?: \DateTimeInterface|string|null,
 *     page?: int,
 *     page_size?: int
 * }
 * @phpstan-type KbArticleListAllParams array{
 *     product?: string|null,
 *     status?: 'draft'|'published'|null,
 *     q?: string|null,
 *     updated_since?: \DateTimeInterface|string|null,
 *     page_size?: int
 * }
 */
final class KbArticles extends AbstractResource
{
    /** Limite de artigos por requisição de lote na API; `batchUpsert()` divide sozinho. */
    public const BATCH_SIZE = 100;

    private const FIELDS = ['title', 'body_html', 'body_markdown', 'product', 'status'];
    private const BATCH_FIELDS = ['external_id', 'title', 'body_html', 'body_markdown', 'product', 'status'];
    private const LIST_PARAMS = ['product', 'status', 'q', 'updated_since', 'page', 'page_size'];
    private const LIST_ALL_PARAMS = ['product', 'status', 'q', 'updated_since', 'page_size'];

    /**
     * Uma página de artigos (resumo: sem `body_html`; use `get()` para o corpo).
     *
     * @param KbArticleListParams $params
     * @param RequestOptions $options
     * @return Page<KbArticleSummary>
     * @throws BfocusException
     */
    public function list(array $params = [], array $options = []): Page
    {
        return $this->callPage('/kb/articles', self::only($params, self::LIST_PARAMS, 'kb->articles->list'), $options);
    }

    /**
     * Todos os artigos (resumo), página a página (padrão `page_size` = 100, o máximo da API).
     *
     * @param KbArticleListAllParams $params
     * @param RequestOptions $options
     * @return \Generator<int, KbArticleSummary>
     */
    public function listAll(array $params = [], array $options = []): \Generator
    {
        $params = self::only($params, self::LIST_ALL_PARAMS, 'kb->articles->listAll');

        return self::iterate(fn (array $query): Page => $this->callPage('/kb/articles', $query, $options), $params);
    }

    /**
     * @param RequestOptions $options
     * @return KbArticle
     * @throws BfocusException
     */
    public function get(string $externalId, array $options = []): array
    {
        return $this->call('GET', self::path($externalId), [], null, $options);
    }

    /**
     * Cria ou atualiza o artigo (PUT parcial). Use `body_markdown` OU `body_html`.
     * `product => null` (explícito) torna o artigo global; chave ausente mantém o produto atual.
     * Só artigo `published` alimenta o agente de IA.
     *
     * @param KbArticleFields $fields
     * @param RequestOptions $options
     * @return KbArticle
     * @throws BfocusException
     */
    public function upsert(string $externalId, array $fields = [], array $options = []): array
    {
        $body = self::only($fields, self::FIELDS, 'kb->articles->upsert');

        return $this->call('PUT', self::path($externalId), [], $body, $options);
    }

    /**
     * Cria/atualiza QUALQUER quantidade de artigos: divide em lotes de 100 (limite da API),
     * envia em sequência e devolve UM resultado agregado (`results` na ordem de entrada,
     * contadores somados). Falha de um artigo não derruba o lote — confira `failed` e
     * `results[i]['error']`. Lista vazia devolve o resultado zerado sem requisição.
     *
     * Cada lote é uma chamada própria, com a sua `Idempotency-Key`. Se você passar
     * `idempotency_key`, o 1º lote usa a chave como veio e os seguintes `"<chave>:2"`,
     * `"<chave>:3"`… Se um lote inteiro falhar (erro HTTP), a exceção sobe e os lotes
     * anteriores já foram gravados — rodar de novo é seguro (upsert por `external_id`).
     *
     * @param iterable<KbBatchItem> $articles
     * @param RequestOptions $options
     * @return KbBatchOut
     * @throws BfocusException
     */
    public function batchUpsert(iterable $articles, array $options = []): array
    {
        // Valida TUDO antes da primeira requisição: item inválido não deixa lote pela metade.
        $items = [];
        foreach ($articles as $article) {
            $position = count($items);
            if (!is_array($article)) {
                throw new \InvalidArgumentException(sprintf('kb->articles->batchUpsert: o item #%d precisa ser um array.', $position));
            }
            $externalId = $article['external_id'] ?? null;
            if (!is_string($externalId) || $externalId === '') {
                throw new \InvalidArgumentException(sprintf('kb->articles->batchUpsert: o item #%d precisa de external_id.', $position));
            }
            self::path($externalId); // mesmas regras do caminho: sem "/", ".", ".."
            $items[] = self::only($article, self::BATCH_FIELDS, 'kb->articles->batchUpsert');
        }

        $result = ['results' => [], 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];
        foreach (array_chunk($items, self::BATCH_SIZE) as $number => $chunk) {
            $chunkOptions = $options;
            if (isset($options['idempotency_key']) && $number > 0) {
                // 1º lote: a chave como veio; seguintes: "<chave>:2", "<chave>:3"…
                $chunkOptions['idempotency_key'] = $options['idempotency_key'] . ':' . ($number + 1);
            }
            $data = $this->call('POST', '/kb/articles/batch', [], ['articles' => $chunk], $chunkOptions);

            foreach (is_array($data['results'] ?? null) ? $data['results'] : [] as $row) {
                $result['results'][] = $row;
            }
            foreach (['created', 'updated', 'unchanged', 'failed'] as $counter) {
                $result[$counter] += (int) ($data[$counter] ?? 0);
            }
        }

        return $result;
    }

    /**
     * @param RequestOptions $options
     * @return KbArticle
     * @throws BfocusException
     */
    public function publish(string $externalId, array $options = []): array
    {
        return $this->call('POST', self::path($externalId) . '/publish', [], null, $options);
    }

    /**
     * Volta o artigo para rascunho (sai da busca e do agente de IA).
     *
     * @param RequestOptions $options
     * @return KbArticle
     * @throws BfocusException
     */
    public function unpublish(string $externalId, array $options = []): array
    {
        return $this->call('POST', self::path($externalId) . '/unpublish', [], null, $options);
    }

    /**
     * @param RequestOptions $options
     * @return Deleted
     * @throws BfocusException
     */
    public function delete(string $externalId, array $options = []): array
    {
        return $this->call('DELETE', self::path($externalId), [], null, $options);
    }

    private static function path(string $externalId): string
    {
        if (str_contains($externalId, '/')) {
            throw new \InvalidArgumentException(sprintf(
                'external_id de artigo não pode conter "/" (a API recusa): "%s".',
                $externalId,
            ));
        }

        return '/kb/articles/' . self::segment($externalId, 'external_id');
    }
}
