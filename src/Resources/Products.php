<?php

declare(strict_types=1);

namespace Bfocus\Resources;

use Bfocus\Exception\BfocusException;

/**
 * Catálogo de produtos — `$bf->products`. Escopos `products:read` / `products:write`.
 *
 * @phpstan-import-type RequestOptions from AbstractResource
 *
 * @phpstan-type Product array{
 *     id: string,
 *     slug: string,
 *     name: string,
 *     description: string|null,
 *     color: string|null,
 *     icon: string|null,
 *     is_active: bool,
 *     sort_order: int,
 *     current_version: string,
 *     ai_level: string,
 *     created_at: string|null,
 *     updated_at: string|null
 * }
 * @phpstan-type ProductFields array{
 *     name?: string|null,
 *     description?: string|null,
 *     color?: string|null,
 *     icon?: string|null,
 *     is_active?: bool|null,
 *     sort_order?: int|null
 * }
 * @phpstan-type ProductListParams array{include_inactive?: bool}
 */
final class Products extends AbstractResource
{
    private const FIELDS = ['name', 'description', 'color', 'icon', 'is_active', 'sort_order'];
    private const LIST_PARAMS = ['include_inactive'];

    /**
     * @param ProductListParams $params `include_inactive` inclui os arquivados.
     * @param RequestOptions $options
     * @return list<Product>
     * @throws BfocusException
     */
    public function list(array $params = [], array $options = []): array
    {
        return $this->call('GET', '/products', self::only($params, self::LIST_PARAMS, 'products->list'), null, $options);
    }

    /**
     * @param RequestOptions $options
     * @return Product
     * @throws BfocusException
     */
    public function get(string $slug, array $options = []): array
    {
        return $this->call('GET', '/products/' . self::segment($slug, 'slug'), [], null, $options);
    }

    /**
     * Cria ou atualiza o produto (PUT parcial; `name` é obrigatório ao criar; `null` limpa).
     *
     * @param ProductFields $fields
     * @param RequestOptions $options
     * @return Product
     * @throws BfocusException
     */
    public function upsert(string $slug, array $fields = [], array $options = []): array
    {
        $body = self::only($fields, self::FIELDS, 'products->upsert');

        return $this->call('PUT', '/products/' . self::segment($slug, 'slug'), [], $body, $options);
    }

    /**
     * Arquiva o produto (`is_active` = false). Não apaga nada.
     *
     * @param RequestOptions $options
     * @return Product
     * @throws BfocusException
     */
    public function archive(string $slug, array $options = []): array
    {
        return $this->call('DELETE', '/products/' . self::segment($slug, 'slug'), [], null, $options);
    }
}
