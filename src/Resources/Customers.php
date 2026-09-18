<?php

declare(strict_types=1);

namespace Bfocus\Resources;

use Bfocus\Exception\BfocusException;
use Bfocus\Internal\Transport;
use Bfocus\Page;

/**
 * Clientes (empresas) — `$bf->customers`. Escopos `customers:read` / `customers:write`.
 *
 * O cliente é identificado pelo SEU código (`external_id`, ex.: o código do ERP).
 *
 * @phpstan-import-type RequestOptions from AbstractResource
 *
 * @phpstan-type CustomField array{
 *     key: string,
 *     label?: string|null,
 *     type?: string,
 *     value?: mixed,
 *     visibility?: string
 * }
 * @phpstan-type Customer array{
 *     id: string,
 *     external_id: string,
 *     name: string,
 *     document: string|null,
 *     email: string|null,
 *     phone: string|null,
 *     website: string|null,
 *     notes: string|null,
 *     custom_fields: list<CustomField>,
 *     is_active: bool,
 *     created_at: string|null,
 *     updated_at: string|null
 * }
 * @phpstan-type CustomFieldInput array{
 *     key: string,
 *     label?: string,
 *     type?: 'text'|'textarea'|'email'|'phone'|'url'|'number'|'date'|'datetime'|'bool'|'select'|'file',
 *     value?: mixed,
 *     options?: list<string>|null
 * }
 * @phpstan-type CustomerFields array{
 *     name?: string|null,
 *     document?: string|null,
 *     email?: string|null,
 *     phone?: string|null,
 *     website?: string|null,
 *     notes?: string|null,
 *     custom_fields?: list<CustomFieldInput>|null
 * }
 * @phpstan-type CustomerListParams array{
 *     q?: string|null,
 *     updated_since?: \DateTimeInterface|string|null,
 *     page?: int,
 *     page_size?: int
 * }
 * @phpstan-type CustomerListAllParams array{
 *     q?: string|null,
 *     updated_since?: \DateTimeInterface|string|null,
 *     page_size?: int
 * }
 * @phpstan-type Deleted array{deleted: bool}
 */
final class Customers extends AbstractResource
{
    private const FIELDS = ['name', 'document', 'email', 'phone', 'website', 'notes', 'custom_fields'];
    private const LIST_PARAMS = ['q', 'updated_since', 'page', 'page_size'];
    private const LIST_ALL_PARAMS = ['q', 'updated_since', 'page_size'];

    /** Contatos (pessoas) de um cliente. */
    public readonly CustomerContacts $contacts;

    /** Produtos vinculados a um cliente. */
    public readonly CustomerProducts $products;

    /** Interações (histórico/notas) de um cliente. */
    public readonly CustomerInteractions $interactions;

    /** @internal */
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
        $this->contacts = new CustomerContacts($transport);
        $this->products = new CustomerProducts($transport);
        $this->interactions = new CustomerInteractions($transport);
    }

    /**
     * Cria ou atualiza o cliente (PUT parcial): só as chaves presentes em `$fields` mudam, e
     * `null` explícito LIMPA o campo. `custom_fields`, quando enviado, SUBSTITUI a lista inteira.
     *
     * ```php
     * $bf->customers->upsert('ERP 1042', ['name' => 'Padaria Estrela', 'phone' => null]);
     * ```
     *
     * @param CustomerFields $fields
     * @param RequestOptions $options
     * @return Customer
     * @throws BfocusException
     */
    public function upsert(string $externalId, array $fields = [], array $options = []): array
    {
        $body = self::only($fields, self::FIELDS, 'customers->upsert');

        return $this->call('PUT', '/customers/' . self::segment($externalId, 'external_id'), [], $body, $options);
    }

    /**
     * @param RequestOptions $options
     * @return Customer
     * @throws BfocusException `NotFoundException` com código `CUSTOMER_NOT_FOUND` se não existir.
     */
    public function get(string $externalId, array $options = []): array
    {
        return $this->call('GET', '/customers/' . self::segment($externalId, 'external_id'), [], null, $options);
    }

    /**
     * Uma página de clientes. `updated_since` aceita `\DateTimeInterface` (vai em UTC) ou string ISO 8601.
     *
     * @param CustomerListParams $params
     * @param RequestOptions $options
     * @return Page<Customer>
     * @throws BfocusException
     */
    public function list(array $params = [], array $options = []): Page
    {
        return $this->callPage('/customers', self::only($params, self::LIST_PARAMS, 'customers->list'), $options);
    }

    /**
     * Todos os clientes, página a página (sob demanda). Padrão `page_size` = 100.
     *
     * ```php
     * foreach ($bf->customers->listAll(['updated_since' => $ultimaSync]) as $cliente) { ... }
     * ```
     *
     * @param CustomerListAllParams $params
     * @param RequestOptions $options
     * @return \Generator<int, Customer>
     * @throws BfocusException durante a iteração.
     */
    public function listAll(array $params = [], array $options = []): \Generator
    {
        $params = self::only($params, self::LIST_ALL_PARAMS, 'customers->listAll');

        return self::iterate(fn (array $query): Page => $this->callPage('/customers', $query, $options), $params);
    }

    /**
     * @param RequestOptions $options
     * @return Deleted
     * @throws BfocusException
     */
    public function delete(string $externalId, array $options = []): array
    {
        return $this->call('DELETE', '/customers/' . self::segment($externalId, 'external_id'), [], null, $options);
    }
}
