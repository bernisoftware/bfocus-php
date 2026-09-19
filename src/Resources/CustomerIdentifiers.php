<?php

declare(strict_types=1);

namespace Bfocus\Resources;

use Bfocus\Exception\BfocusException;

/**
 * Identificadores extras de um cliente — `$bf->customers->identifiers`.
 *
 * Liga o id de OUTRO sistema seu (ex.: `crm-88`) ao mesmo cadastro, que continua tendo o
 * `external_id` principal. Depois disso, qualquer chamada com o id extra acha o mesmo cliente.
 * Idempotente. Se o id já é de outro cadastro: `ConflictException` com código `IDENTIFIER_IN_USE`.
 *
 * @phpstan-import-type RequestOptions from AbstractResource
 * @phpstan-import-type Customer from Customers
 *
 * @phpstan-type Identifier array{external_id: string, label: string|null, source: string}
 * @phpstan-type CustomerWithIdentifiers array{
 *     id: string,
 *     external_id: string,
 *     name: string,
 *     document: string|null,
 *     email: string|null,
 *     phone: string|null,
 *     website: string|null,
 *     notes: string|null,
 *     custom_fields: list<array<string, mixed>>,
 *     is_active: bool,
 *     created_at: string|null,
 *     updated_at: string|null,
 *     identifiers: list<Identifier>
 * }
 * @phpstan-type IdentifierParams array{label?: string|null}
 */
final class CustomerIdentifiers extends AbstractResource
{
    private const PARAMS = ['label'];

    /**
     * Liga `$extraId` ao cliente `$externalId`. `label` (opcional) é um rótulo livre, ex.: o nome
     * do sistema; sem `label`, a requisição vai sem corpo.
     *
     * ```php
     * $bf->customers->identifiers->add('erp-1042', 'crm-88', ['label' => 'CRM']);
     * ```
     *
     * @param IdentifierParams $params
     * @param RequestOptions $options
     * @return CustomerWithIdentifiers
     * @throws BfocusException `ConflictException` (`IDENTIFIER_IN_USE`), `NotFoundException` (`CUSTOMER_NOT_FOUND`).
     */
    public function add(string $externalId, string $extraId, array $params = [], array $options = []): array
    {
        $body = self::only($params, self::PARAMS, 'customers->identifiers->add');

        return $this->call('PUT', self::path($externalId, $extraId), [], $body === [] ? null : $body, $options);
    }

    /**
     * Desliga o identificador extra do cliente.
     *
     * @param RequestOptions $options
     * @return CustomerWithIdentifiers
     * @throws BfocusException `NotFoundException` (`CUSTOMER_NOT_FOUND`, `IDENTIFIER_NOT_FOUND`).
     */
    public function remove(string $externalId, string $extraId, array $options = []): array
    {
        return $this->call('DELETE', self::path($externalId, $extraId), [], null, $options);
    }

    private static function path(string $externalId, string $extraId): string
    {
        return '/customers/' . self::segment($externalId, 'external_id') . '/identifiers/' . self::segment($extraId, 'extra_id');
    }
}
