<?php

declare(strict_types=1);

namespace Bfocus\Resources;

use Bfocus\Exception\BfocusException;
use Bfocus\Page;

/**
 * Release notes por produto — `$bf->releaseNotes`. Escopos `release_notes:read` / `release_notes:write`.
 *
 * @phpstan-import-type RequestOptions from AbstractResource
 *
 * @phpstan-type ReleaseNote array{
 *     id: string,
 *     product: string,
 *     version: string,
 *     title: string,
 *     description_html: string,
 *     audience: string,
 *     is_published: bool,
 *     require_ack_internal: bool,
 *     require_ack_external: bool,
 *     published_at: string|null,
 *     created_at: string|null,
 *     updated_at: string|null
 * }
 * @phpstan-type ReleaseNoteFields array{
 *     title?: string|null,
 *     description_html?: string|null,
 *     description_markdown?: string|null,
 *     audience?: 'internal'|'external'|'both'|null,
 *     require_ack_internal?: bool|null,
 *     require_ack_external?: bool|null,
 *     publish?: bool
 * }
 * @phpstan-type ReleaseNoteListParams array{published?: bool|null, page?: int, page_size?: int}
 * @phpstan-type ReleaseNoteListAllParams array{published?: bool|null, page_size?: int}
 */
final class ReleaseNotes extends AbstractResource
{
    private const FIELDS = [
        'title',
        'description_html',
        'description_markdown',
        'audience',
        'require_ack_internal',
        'require_ack_external',
        'publish',
    ];
    private const LIST_PARAMS = ['published', 'page', 'page_size'];
    private const LIST_ALL_PARAMS = ['published', 'page_size'];

    /**
     * @param ReleaseNoteListParams $params `published`: `true` só publicadas, `false` só rascunhos.
     * @param RequestOptions $options
     * @return Page<ReleaseNote>
     * @throws BfocusException
     */
    public function list(string $productSlug, array $params = [], array $options = []): Page
    {
        return $this->callPage(self::base($productSlug), self::only($params, self::LIST_PARAMS, 'releaseNotes->list'), $options);
    }

    /**
     * Todas as release notes do produto, página a página (padrão `page_size` = 100).
     *
     * @param ReleaseNoteListAllParams $params
     * @param RequestOptions $options
     * @return \Generator<int, ReleaseNote>
     */
    public function listAll(string $productSlug, array $params = [], array $options = []): \Generator
    {
        $params = self::only($params, self::LIST_ALL_PARAMS, 'releaseNotes->listAll');
        $path = self::base($productSlug);

        return self::iterate(fn (array $query): Page => $this->callPage($path, $query, $options), $params);
    }

    /**
     * @param RequestOptions $options
     * @return ReleaseNote
     * @throws BfocusException
     */
    public function get(string $productSlug, string $version, array $options = []): array
    {
        return $this->call('GET', self::base($productSlug) . '/' . self::segment($version, 'version'), [], null, $options);
    }

    /**
     * Cria ou atualiza a release note da versão (PUT parcial). Use `description_markdown` OU
     * `description_html`. `publish => true` publica depois de salvar — ideal no CI:
     *
     * ```php
     * $bf->releaseNotes->upsert('erp-cloud', '2.3.0', [
     *     'title' => 'Emissão de NFS-e em lote',
     *     'description_markdown' => file_get_contents('CHANGELOG.md'),
     *     'publish' => true,
     * ]);
     * ```
     *
     * @param ReleaseNoteFields $fields
     * @param RequestOptions $options
     * @return ReleaseNote
     * @throws BfocusException
     */
    public function upsert(string $productSlug, string $version, array $fields = [], array $options = []): array
    {
        $body = self::only($fields, self::FIELDS, 'releaseNotes->upsert');

        return $this->call('PUT', self::base($productSlug) . '/' . self::segment($version, 'version'), [], $body, $options);
    }

    /**
     * Publica a release note (já publicada = nada muda).
     *
     * @param RequestOptions $options
     * @return ReleaseNote
     * @throws BfocusException
     */
    public function publish(string $productSlug, string $version, array $options = []): array
    {
        return $this->call('POST', self::base($productSlug) . '/' . self::segment($version, 'version') . '/publish', [], null, $options);
    }

    private static function base(string $productSlug): string
    {
        return '/products/' . self::segment($productSlug, 'product_slug') . '/release-notes';
    }
}
