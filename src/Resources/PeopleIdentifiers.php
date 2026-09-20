<?php

declare(strict_types=1);

namespace Bfocus\Resources;

use Bfocus\Exception\BfocusException;

/**
 * Identificadores extras de uma pessoa — `$bf->people->identifiers`.
 *
 * Liga o id da pessoa em OUTRO sistema seu ao mesmo cadastro. Idempotente. Se o id já é de outra
 * pessoa: `ConflictException` com código `IDENTIFIER_IN_USE`.
 *
 * @phpstan-import-type RequestOptions from AbstractResource
 * @phpstan-import-type Identifier from CustomerIdentifiers
 * @phpstan-import-type IdentifierParams from CustomerIdentifiers
 *
 * @phpstan-type PersonIdentifiers array{external_id: string|null, identifiers: list<Identifier>}
 */
final class PeopleIdentifiers extends AbstractResource
{
    private const PARAMS = ['label'];

    /**
     * Todos os identificadores da pessoa: o principal (`external_id` do retorno) e os extras.
     * Aceita no caminho o principal OU qualquer um dos extras. Escopo `customers:read`.
     *
     * É a fonte de verdade para RECONCILIAR: `people->list()` mostra só o identificador principal,
     * então um id que virou extra some de lá sem ter sumido do cadastro — e, sem esta leitura, era
     * preciso ESCREVER (tentar um `add()`) para descobrir o que tinha acontecido.
     *
     * ```php
     * $ids = $bf->people->identifiers->list('crm-p5'); // um id extra também serve
     * echo $ids['external_id'];                        // o principal do cadastro
     * ```
     *
     * @param RequestOptions $options
     * @return PersonIdentifiers
     * @throws BfocusException `NotFoundException` (`PERSON_NOT_FOUND`).
     */
    public function list(string $personExternalId, array $options = []): array
    {
        return $this->call('GET', '/people/' . self::segment($personExternalId, 'person_external_id') . '/identifiers', [], null, $options);
    }

    /**
     * Liga `$extraId` à pessoa `$personExternalId`. Sem `label`, a requisição vai sem corpo.
     *
     * ```php
     * $bf->people->identifiers->add('app-77', 'crm-p5', ['label' => 'CRM']);
     * ```
     *
     * @param IdentifierParams $params
     * @param RequestOptions $options
     * @return PersonIdentifiers
     * @throws BfocusException `ConflictException` (`IDENTIFIER_IN_USE`), `NotFoundException` (`PERSON_NOT_FOUND`).
     */
    public function add(string $personExternalId, string $extraId, array $params = [], array $options = []): array
    {
        $body = self::only($params, self::PARAMS, 'people->identifiers->add');

        return $this->call('PUT', self::path($personExternalId, $extraId), [], $body === [] ? null : $body, $options);
    }

    /**
     * Desliga o identificador extra da pessoa.
     *
     * @param RequestOptions $options
     * @return PersonIdentifiers
     * @throws BfocusException `NotFoundException` (`PERSON_NOT_FOUND`, `IDENTIFIER_NOT_FOUND`).
     */
    public function remove(string $personExternalId, string $extraId, array $options = []): array
    {
        return $this->call('DELETE', self::path($personExternalId, $extraId), [], null, $options);
    }

    private static function path(string $personExternalId, string $extraId): string
    {
        return '/people/' . self::segment($personExternalId, 'person_external_id') . '/identifiers/' . self::segment($extraId, 'extra_id');
    }
}
