<?php

declare(strict_types=1);

namespace Bfocus\Resources;

use Bfocus\Exception\BfocusException;
use Bfocus\Page;

/**
 * Interações (histórico e notas) de um cliente — `$bf->customers->interactions`.
 *
 * @phpstan-import-type RequestOptions from AbstractResource
 *
 * @phpstan-type Interaction array{
 *     id: string,
 *     content: string,
 *     is_internal: bool,
 *     author_kind: string,
 *     author_name: string|null,
 *     created_at: string|null
 * }
 * @phpstan-type InteractionListParams array{page?: int, page_size?: int}
 * @phpstan-type InteractionListAllParams array{page_size?: int}
 * @phpstan-type InteractionCreateParams array{is_internal?: bool, author_email?: string|null}
 */
final class CustomerInteractions extends AbstractResource
{
    private const LIST_PARAMS = ['page', 'page_size'];
    private const LIST_ALL_PARAMS = ['page_size'];
    private const CREATE_FIELDS = ['is_internal', 'author_email'];

    /**
     * @param InteractionListParams $params
     * @param RequestOptions $options
     * @return Page<Interaction>
     * @throws BfocusException
     */
    public function list(string $externalId, array $params = [], array $options = []): Page
    {
        return $this->callPage(self::base($externalId), self::only($params, self::LIST_PARAMS, 'customers->interactions->list'), $options);
    }

    /**
     * Todas as interações, página a página (padrão `page_size` = 100).
     *
     * @param InteractionListAllParams $params
     * @param RequestOptions $options
     * @return \Generator<int, Interaction>
     */
    public function listAll(string $externalId, array $params = [], array $options = []): \Generator
    {
        $params = self::only($params, self::LIST_ALL_PARAMS, 'customers->interactions->listAll');
        $path = self::base($externalId);

        return self::iterate(fn (array $query): Page => $this->callPage($path, $query, $options), $params);
    }

    /**
     * Registra uma interação. `$content` aceita HTML ou texto puro (vira parágrafos).
     * `is_internal` (padrão `true` na API) = nota interna; `author_email` = usuário do bFocus que assina.
     *
     * @param InteractionCreateParams $params
     * @param RequestOptions $options
     * @return Interaction
     * @throws BfocusException
     */
    public function create(string $externalId, string $content, array $params = [], array $options = []): array
    {
        $body = ['content' => $content] + self::only($params, self::CREATE_FIELDS, 'customers->interactions->create');

        return $this->call('POST', self::base($externalId), [], $body, $options);
    }

    private static function base(string $externalId): string
    {
        return '/customers/' . self::segment($externalId, 'external_id') . '/interactions';
    }
}
