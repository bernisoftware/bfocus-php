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
 * acha a pessoa que já chegou por e-mail ou por outro sistema: ela é adotada, nunca duplicada.
 * A mesma pessoa informada com OUTRO cliente NÃO é transferida: ela é ligada também a ele — o
 * cadastro é único e a mesma pessoa circula por vários clientes (`linked` na resposta diz isso).
 *
 * @phpstan-import-type RequestOptions from AbstractResource
 * @phpstan-import-type BatchResult from Customers
 *
 * @phpstan-type Person array{
 *     external_id: string|null,
 *     name: string,
 *     email: string|null,
 *     phone: string|null,
 *     document: string|null,
 *     role: string|null,
 *     access: bool,
 *     is_primary: bool,
 *     customer_external_id: string,
 *     custom_fields: list<array{key: string, label: string|null, type: string, value: mixed, visibility: string}>,
 *     identifiers: list<array{external_id: string, label: string|null, source: string}>
 * }
 * @phpstan-type PersonUpsertResult array{
 *     external_id: string|null,
 *     name: string,
 *     email: string|null,
 *     phone: string|null,
 *     document: string|null,
 *     role: string|null,
 *     access: bool,
 *     is_primary: bool,
 *     customer_external_id: string,
 *     custom_fields: list<array{key: string, label: string|null, type: string, value: mixed, visibility: string}>,
 *     identifiers: list<array{external_id: string, label: string|null, source: string}>,
 *     status: 'created'|'updated'|'unchanged',
 *     linked: bool,
 *     merged_into: string|null
 * }
 * @phpstan-type PersonRevokeResult array{
 *     external_id: string|null,
 *     name: string,
 *     email: string|null,
 *     phone: string|null,
 *     document: string|null,
 *     role: string|null,
 *     access: bool,
 *     is_primary: bool,
 *     customer_external_id: string,
 *     custom_fields: list<array{key: string, label: string|null, type: string, value: mixed, visibility: string}>,
 *     identifiers: list<array{external_id: string, label: string|null, source: string}>,
 *     unlinked: bool
 * }
 * @phpstan-type PersonFields array{
 *     name?: string|null,
 *     email?: string|null,
 *     phone?: string|null,
 *     document?: string|null,
 *     role?: string|null,
 *     access?: bool|null,
 *     is_primary?: bool|null,
 *     extra_emails?: list<string>|null,
 *     extra_phones?: list<string>|null,
 *     custom_fields?: list<array{key: string, label?: string, type?: string, value?: mixed}>|null,
 *     clear?: list<string>|null
 * }
 * @phpstan-type PersonBatchItem array{
 *     customer_external_id: string,
 *     external_id: string,
 *     name?: string|null,
 *     email?: string|null,
 *     phone?: string|null,
 *     document?: string|null,
 *     role?: string|null,
 *     access?: bool|null,
 *     is_primary?: bool|null,
 *     extra_emails?: list<string>|null,
 *     extra_phones?: list<string>|null,
 *     custom_fields?: list<array{key: string, label?: string, type?: string, value?: mixed}>|null,
 *     clear?: list<string>|null
 * }
 */
final class People extends AbstractResource
{
    /** Máximo de itens por chamada de `batch()` (limite da API). A SDK NÃO divide: acima disso, erro. */
    public const BATCH_MAX = Customers::BATCH_MAX;

    private const FIELDS = ['name', 'email', 'phone', 'document', 'role', 'access', 'is_primary', 'extra_emails', 'extra_phones', 'custom_fields', 'clear'];

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
     * `custom_fields` é a exceção: quando enviada, a lista SUBSTITUI a lista inteira de campos
     * personalizados da pessoa — campo que ficar de fora é REMOVIDO. Omitir a chave não mexe em
     * nada. A `visibility` é decidida no bFocus e preservada entre sincronizações.
     *
     * `clear` APAGA contato: `['email']`, `['phone']` ou os dois. Apagar é EXPLÍCITO — `null`,
     * lista vazia e chave ausente continuam significando "não mexe", e a SDK não traduz `null`
     * em `clear`. Campo fora da lista aceita é RECUSADO (422 `PERSON_CLEAR_FIELD_INVALID`), não
     * ignorado. E só se limpa a PRÓPRIA ficha: alcançando a pessoa por um identificador EXTRA, a
     * API recusa (409 `PERSON_CLEAR_NOT_OWN_RECORD`) — apagar contato de ficha alcançada por
     * apelido seria apagar dado de outro sistema.
     *
     * `document` é o CPF da pessoa (com ou sem máscara; a resposta traz só os 11 dígitos). A
     * PESSOA É ÚNICA: o mesmo CPF é sempre o mesmo cadastro, em qualquer produto. Id desconhecido
     * + CPF de uma ficha existente → a resposta vem com `merged_into` = id principal dela (o seu
     * id vira identificador extra). Id de uma ficha + CPF de OUTRA → as duas são mescladas na
     * hora (`merged_into` = a que tinha o CPF). `null`/vazio NÃO apaga (não é campo do `clear`).
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
     * @throws BfocusException ex.: `ConflictException` com `PERSON_EMAIL_STAFF`, `PERSON_EMAIL_TAKEN`,
     *     `PERSON_PHONE_TAKEN` (o `getData()` diz de quem é o contato) ou `PERSON_CONTACT_OTHER_CUSTOMER`
     *     (o contato é de uma pessoa de OUTRO cliente: a API não liga sozinha e repetir não resolve;
     *     o `getData()` diz de quem é, para ligar pelo identificador extra se for a mesma pessoa);
     *     `ValidationException` com `PERSON_CLEAR_FIELD_INVALID` e `ConflictException` com
     *     `PERSON_CLEAR_NOT_OWN_RECORD` (ver `clear`, acima); `ValidationException` com
     *     `PERSON_DOCUMENT_INVALID` (CPF inválido) e `ConflictException` com
     *     `PERSON_DOCUMENT_CONFLICT` (a ficha já tem OUTRO CPF — nunca troca sozinho).
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
     * Retira o acesso da pessoa NESTE cliente (widget/portal). Ela continua no histórico; um
     * `upsert()` com `access => true` devolve o acesso. Devolve a pessoa (com `access = false`) e
     * `unlinked`: o acesso é DO VÍNCULO, então se ela também é de outros clientes continua ativa
     * neles e `unlinked` volta `true`.
     *
     * @param RequestOptions $options
     * @return PersonRevokeResult
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
