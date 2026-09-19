<?php

declare(strict_types=1);

namespace Bfocus\Resources;

use Bfocus\Exception\BfocusException;
use Bfocus\Internal\Transport;

/**
 * Pessoas dos clientes (quem abre chamado, usa o widget, recebe e-mail) — `$bf->people`.
 * Escopos `customers:read` / `customers:write`.
 *
 * A pessoa é identificada pelo SEU id do usuário (`person_external_id`) — o mesmo
 * `userExternalId` assinado no widget; por isso não pode conter `:`. O e-mail (ou o telefone)
 * acha a pessoa que já chegou por e-mail ou por outro sistema: ela é adotada, nunca duplicada;
 * a mesma pessoa informada com outro cliente é transferida para ele.
 *
 * @phpstan-import-type RequestOptions from AbstractResource
 * @phpstan-import-type BatchResult from Customers
 *
 * @phpstan-type Person array{
 *     external_id: string|null,
 *     name: string,
 *     email: string|null,
 *     phone: string|null,
 *     role: string|null,
 *     access: bool,
 *     is_primary: bool,
 *     customer_external_id: string
 * }
 * @phpstan-type PersonUpsertResult array{
 *     external_id: string|null,
 *     name: string,
 *     email: string|null,
 *     phone: string|null,
 *     role: string|null,
 *     access: bool,
 *     is_primary: bool,
 *     customer_external_id: string,
 *     status: 'created'|'updated'|'unchanged'
 * }
 * @phpstan-type PersonFields array{
 *     name?: string|null,
 *     email?: string|null,
 *     phone?: string|null,
 *     role?: string|null,
 *     access?: bool|null,
 *     is_primary?: bool|null,
 *     extra_emails?: list<string>|null,
 *     extra_phones?: list<string>|null
 * }
 * @phpstan-type PersonBatchItem array{
 *     customer_external_id: string,
 *     external_id: string,
 *     name?: string|null,
 *     email?: string|null,
 *     phone?: string|null,
 *     role?: string|null,
 *     access?: bool|null,
 *     is_primary?: bool|null,
 *     extra_emails?: list<string>|null,
 *     extra_phones?: list<string>|null
 * }
 */
final class People extends AbstractResource
{
    /** Máximo de itens por chamada de `batch()` (limite da API). A SDK NÃO divide: acima disso, erro. */
    public const BATCH_MAX = Customers::BATCH_MAX;

    private const FIELDS = ['name', 'email', 'phone', 'role', 'access', 'is_primary', 'extra_emails', 'extra_phones'];

    /** Identificadores extras (ids de outros sistemas seus) de uma pessoa. */
    public readonly PeopleIdentifiers $identifiers;

    /** @internal */
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
        $this->identifiers = new PeopleIdentifiers($transport);
    }

    /**
     * Cria ou atualiza a pessoa do cliente (PUT parcial): só as chaves presentes em `$fields`
     * mudam; `null` explícito vai como `null`. `extra_emails`/`extra_phones` SOMAM aos que já
     * existem. `access => false` retira o acesso; `access => true` devolve. O retorno traz
     * `status` (`created`, `updated` ou `unchanged`).
     *
     * ```php
     * $p = $bf->people->upsert('erp-1042', 'app-77', [
     *     'name' => 'Paula Reis', 'email' => 'paula@padaria.example', 'is_primary' => true,
     * ]);
     * echo $p['status'];
     * ```
     *
     * @param PersonFields $fields
     * @param RequestOptions $options
     * @return PersonUpsertResult
     * @throws BfocusException ex.: `ConflictException` com `PERSON_EMAIL_STAFF` / `PERSON_EMAIL_TAKEN`.
     */
    public function upsert(string $customerExternalId, string $personExternalId, array $fields = [], array $options = []): array
    {
        $person = self::only($fields, self::FIELDS, 'people->upsert');

        return $this->call('PUT', self::path($customerExternalId, $personExternalId), [], [
            'person' => $person === [] ? new \stdClass() : $person,
        ], $options);
    }

    /**
     * Pessoas do cliente (com e sem acesso).
     *
     * @param RequestOptions $options
     * @return list<Person>
     * @throws BfocusException `NotFoundException` com `CUSTOMER_NOT_FOUND`.
     */
    public function list(string $customerExternalId, array $options = []): array
    {
        return $this->call('GET', self::base($customerExternalId), [], null, $options);
    }

    /**
     * Retira o acesso da pessoa (widget/portal). Ela continua no histórico; um `upsert()` com
     * `access => true` devolve o acesso. Devolve a pessoa (com `access = false`).
     *
     * @param RequestOptions $options
     * @return Person
     * @throws BfocusException `NotFoundException` com `PERSON_NOT_FOUND` / `CUSTOMER_NOT_FOUND`.
     */
    public function delete(string $customerExternalId, string $personExternalId, array $options = []): array
    {
        return $this->call('DELETE', self::path($customerExternalId, $personExternalId), [], null, $options);
    }

    /**
     * Cria/atualiza até 500 pessoas numa chamada (`People::BATCH_MAX`), de quaisquer clientes.
     * Cada item: `customer_external_id` + `external_id` (da pessoa) + os campos do `upsert()`.
     *
     * Devolve um resultado por item (`index` = posição NESTE lote, `status`, `external_id`,
     * `merged_into`, `error`, `code`) + `summary`. Um item com erro não desfaz os outros.
     * A SDK NÃO divide: mais de 500 itens → `\InvalidArgumentException` antes de qualquer
     * requisição. Lista vazia devolve o resultado zerado sem requisição.
     *
     * ```php
     * $r = $bf->people->batch([
     *     ['customer_external_id' => 'erp-1042', 'external_id' => 'app-77', 'name' => 'Paula Reis'],
     * ]);
     * ```
     *
     * @param iterable<PersonBatchItem> $items
     * @param RequestOptions $options
     * @return BatchResult
     * @throws BfocusException
     */
    public function batch(iterable $items, array $options = []): array
    {
        return $this->callBatch('/people/batch', $items, self::BATCH_MAX, static function (mixed $item, int $position): array {
            $item = self::batchItem($item, $position, 'customer_external_id', 'people->batch');
            $item = self::batchItem($item, $position, 'external_id', 'people->batch');
            $customer = $item['customer_external_id'];
            unset($item['customer_external_id']);
            $person = self::only($item, ['external_id', ...self::FIELDS], 'people->batch');

            return ['customer_external_id' => $customer, 'person' => $person];
        }, 'people->batch', $options);
    }

    private static function base(string $customerExternalId): string
    {
        return '/customers/' . self::segment($customerExternalId, 'customer_external_id') . '/people';
    }

    private static function path(string $customerExternalId, string $personExternalId): string
    {
        return self::base($customerExternalId) . '/' . self::segment($personExternalId, 'person_external_id');
    }
}
