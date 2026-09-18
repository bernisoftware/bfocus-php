<?php

declare(strict_types=1);

namespace Bfocus\Resources;

use Bfocus\Exception\BfocusException;

/**
 * Produtos vinculados a um cliente — `$bf->customers->products`.
 *
 * @phpstan-import-type RequestOptions from AbstractResource
 * @phpstan-import-type Deleted from Customers
 *
 * @phpstan-type ProductRef array{id: string, slug: string, name: string, is_active: bool}
 */
final class CustomerProducts extends AbstractResource
{
    /**
     * @param RequestOptions $options
     * @return list<ProductRef>
     * @throws BfocusException
     */
    public function list(string $externalId, array $options = []): array
    {
        return $this->call('GET', self::base($externalId), [], null, $options);
    }

    /**
     * Vincula o produto ao cliente (idempotente).
     *
     * @param RequestOptions $options
     * @return ProductRef
     * @throws BfocusException
     */
    public function attach(string $externalId, string $productSlug, array $options = []): array
    {
        return $this->call('PUT', self::base($externalId) . '/' . self::segment($productSlug, 'product_slug'), [], null, $options);
    }

    /**
     * Desvincula o produto do cliente.
     *
     * @param RequestOptions $options
     * @return Deleted
     * @throws BfocusException `NotFoundException` com `PRODUCT_NOT_LINKED` se não estava vinculado.
     */
    public function detach(string $externalId, string $productSlug, array $options = []): array
    {
        return $this->call('DELETE', self::base($externalId) . '/' . self::segment($productSlug, 'product_slug'), [], null, $options);
    }

    private static function base(string $externalId): string
    {
        return '/customers/' . self::segment($externalId, 'external_id') . '/products';
    }
}
