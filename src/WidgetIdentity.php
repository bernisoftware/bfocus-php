<?php

declare(strict_types=1);

namespace Bfocus;

/**
 * Identidade assinada do widget de atendimento — roda no SEU backend, sem rede e sem chave de API.
 *
 * ```php
 * $assinatura = \Bfocus\WidgetIdentity::sign($segredoDoWidget, $usuario->id, $empresa->codigoErp);
 * // entregue ao front: bfocus('identify', { userExternalId, customerExternalId, signature: assinatura })
 * ```
 */
final class WidgetIdentity
{
    private function __construct()
    {
    }

    /**
     * HMAC-SHA256 (hex minúsculo) de `"v1:" . $userExternalId . ":" . $customerExternalId`.
     *
     * As strings são usadas como bytes: passe-as em UTF-8 (o padrão do PHP moderno).
     *
     * @param string $secret             Segredo do widget (painel → Widget).
     * @param string $userExternalId     Id do usuário logado no seu sistema.
     * @param string $customerExternalId `external_id` do cliente (empresa) no bFocus.
     */
    public static function sign(string $secret, string $userExternalId, string $customerExternalId): string
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('WidgetIdentity::sign: o segredo não pode ser vazio.');
        }

        return hash_hmac('sha256', 'v1:' . $userExternalId . ':' . $customerExternalId, $secret);
    }
}
