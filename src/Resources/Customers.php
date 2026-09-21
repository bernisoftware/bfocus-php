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
 *     kind: 'pj'|'pf'|null,
 *     legal_name: string|null,
 *     state_registration: string|null,
 *     municipal_registration: string|null,
 *     id_document: string|null,
 *     document: string|null,
 *     email: string|null,
 *     phone: string|null,
 *     website: string|null,
 *     notes: string|null,
 *     custom_fields: list<CustomField>,
 *     is_active: bool,
 *     logo_url: string|null,
 *     extra_emails: list<string>,
 *     extra_phones: list<string>,
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
 *     kind?: 'pj'|'pf'|null,
 *     legal_name?: string|null,
 *     state_registration?: string|null,
 *     municipal_registration?: string|null,
 *     id_document?: string|null,
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
 * @phpstan-type CustomerBatchItem array{
 *     external_id: string,
 *     name?: string|null,
 *     document?: string|null,
 *     email?: string|null,
 *     phone?: string|null,
 *     website?: string|null,
 *     notes?: string|null,
 *     custom_fields?: list<CustomFieldInput>|null
 * }
 * @phpstan-type BatchItemResult array{
 *     index: int,
 *     status: 'created'|'updated'|'unchanged'|'error',
 *     external_id: string|null,
 *     merged_into: string|null,
 *     error: string|null,
 *     code: int|null
 * }
 * @phpstan-type BatchSummary array{created: int, updated: int, unchanged: int, error: int}
 * @phpstan-type BatchResult array{results: list<BatchItemResult>, summary: BatchSummary}
 */
final class Customers extends AbstractResource
{
    /** Máximo de itens por chamada de `batch()` (limite da API). A SDK NÃO divide: acima disso, erro. */
    public const BATCH_MAX = 500;

    // O tipo `CustomerFields` e estas listas andam JUNTOS: `only()` recusa o que não estiver aqui antes de
    // a requisição sair. Na 0.2.3 o tipo anunciou os campos de PJ/PF e as listas não — a SDK recusava.
    private const FIELDS = ['name', 'document', 'kind', 'legal_name', 'state_registration', 'municipal_registration', 'id_document',
        'email', 'phone', 'website', 'notes', 'custom_fields'];
    private const BATCH_FIELDS = ['external_id', 'name', 'document', 'kind', 'legal_name', 'state_registration', 'municipal_registration', 'id_document',
        'email', 'phone', 'website', 'notes', 'custom_fields'];
    private const LIST_PARAMS = ['q', 'updated_since', 'page', 'page_size'];
    private const LIST_ALL_PARAMS = ['q', 'updated_since', 'page_size'];

    /** Contatos (pessoas) de um cliente. */
    public readonly CustomerContacts $contacts;

    /** Produtos vinculados a um cliente. */
    public readonly CustomerProducts $products;

    /** Interações (histórico/notas) de um cliente. */
    public readonly CustomerInteractions $interactions;

    /** Identificadores extras (ids de outros sistemas seus) de um cliente. */
    public readonly CustomerIdentifiers $identifiers;

    /** @internal */
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
        $this->contacts = new CustomerContacts($transport);
        $this->products = new CustomerProducts($transport);
        $this->interactions = new CustomerInteractions($transport);
        $this->identifiers = new CustomerIdentifiers($transport);
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
     * Cria/atualiza até 500 clientes numa chamada (`Customers::BATCH_MAX`). Cada item tem os
     * mesmos campos do `upsert()` + `external_id` (obrigatório); só as chaves presentes mudam e
     * `null` explícito limpa.
     *
     * Devolve um resultado por item (`index` = posição NESTE lote, `status`, `external_id`,
     * `merged_into`, `error`, `code`) + `summary`. Um item com erro não desfaz os outros.
     * A SDK NÃO divide: mais de 500 itens → `\InvalidArgumentException` antes de qualquer
     * requisição (divida com `array_chunk($itens, Customers::BATCH_MAX)`). Lista vazia devolve
     * o resultado zerado sem requisição.
     *
     * ```php
     * $r = $bf->customers->batch([
     *     ['external_id' => 'erp-1042', 'name' => 'Padaria Estrela'],
     *     ['external_id' => 'erp-1043', 'name' => 'Mercado Sol', 'phone' => null],
     * ]);
     * echo $r['summary']['error'];
     * ```
     *
     * @param iterable<CustomerBatchItem> $items
     * @param RequestOptions $options
     * @return BatchResult
     * @throws BfocusException
     */
    public function batch(iterable $items, array $options = []): array
    {
        return $this->callBatch('/customers/batch', $items, self::BATCH_MAX, static function (mixed $item, int $position): array {
            $item = self::batchItem($item, $position, 'external_id', 'customers->batch');

            return self::only($item, self::BATCH_FIELDS, 'customers->batch');
        }, 'customers->batch', $options);
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
