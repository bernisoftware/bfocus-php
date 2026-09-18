<?php

declare(strict_types=1);

namespace Bfocus\Resources;

use Bfocus\Exception\BfocusException;

/**
 * Contatos (pessoas) de um cliente — `$bf->customers->contacts`.
 *
 * @phpstan-import-type RequestOptions from AbstractResource
 * @phpstan-import-type Deleted from Customers
 *
 * @phpstan-type Contact array{
 *     id: string,
 *     external_id: string|null,
 *     name: string,
 *     role: string|null,
 *     email: string|null,
 *     phone: string|null,
 *     notes: string|null,
 *     is_primary: bool,
 *     created_at: string|null,
 *     updated_at: string|null
 * }
 * @phpstan-type ContactFields array{
 *     name?: string|null,
 *     role?: string|null,
 *     email?: string|null,
 *     phone?: string|null,
 *     notes?: string|null,
 *     is_primary?: bool|null
 * }
 */
final class CustomerContacts extends AbstractResource
{
    private const FIELDS = ['name', 'role', 'email', 'phone', 'notes', 'is_primary'];

    /**
     * @param RequestOptions $options
     * @return list<Contact>
     * @throws BfocusException
     */
    public function list(string $externalId, array $options = []): array
    {
        return $this->call('GET', self::base($externalId), [], null, $options);
    }

    /**
     * Cria ou atualiza o contato (PUT parcial; `name` é obrigatório ao criar; `null` limpa).
     *
     * @param ContactFields $fields
     * @param RequestOptions $options
     * @return Contact
     * @throws BfocusException
     */
    public function upsert(string $externalId, string $contactExternalId, array $fields = [], array $options = []): array
    {
        $body = self::only($fields, self::FIELDS, 'customers->contacts->upsert');

        return $this->call('PUT', self::base($externalId) . '/' . self::segment($contactExternalId, 'contact_external_id'), [], $body, $options);
    }

    /**
     * @param RequestOptions $options
     * @return Deleted
     * @throws BfocusException
     */
    public function delete(string $externalId, string $contactExternalId, array $options = []): array
    {
        return $this->call('DELETE', self::base($externalId) . '/' . self::segment($contactExternalId, 'contact_external_id'), [], null, $options);
    }

    private static function base(string $externalId): string
    {
        return '/customers/' . self::segment($externalId, 'external_id') . '/contacts';
    }
}
