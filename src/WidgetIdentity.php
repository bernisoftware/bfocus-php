<?php

declare(strict_types=1);

namespace Bfocus;

/**
 * Identidade assinada do widget de atendimento — roda no SEU backend, sem rede e sem chave de API.
 *
 * ```php
 * $assinatura = \Bfocus\WidgetIdentity::sign($segredoDoWidget, $usuario->id, $empresa->codigoErp);
 * // entregue ao front: bfocus('identify', { userExternalId, customerExternalId, signature: assinatura })
 *
 * // v2, com validade (7 dias atrás até 5 minutos à frente): gere a cada renderização da página.
 * $assinatura = \Bfocus\WidgetIdentity::signV2($segredoDoWidget, $usuario->id, $empresa->codigoErp);
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

    /**
     * Identidade v2, com validade: `"v2.<ts>.<hex>"`, em que `ts` são os segundos unix do instante
     * e `hex` é o HMAC-SHA256 (hex minúsculo) de `"v2:<ts>:" . $userExternalId . ":" . $customerExternalId`.
     *
     * A API aceita a assinatura de 7 dias atrás até 5 minutos à frente: gere a cada renderização
     * da página, nunca guarde. Vai no mesmo lugar da v1 (`userHash`/`signature` do widget); a v1
     * continua aceita.
     *
     * @param string                      $secret             Segredo do widget (painel → Widget).
     * @param string                      $userExternalId     Id do usuário logado no seu sistema. Não pode conter `:`.
     * @param string                      $customerExternalId `external_id` do cliente no bFocus (pode conter `:`).
     * @param int|\DateTimeInterface|null $at                 Instante da assinatura: segundos unix (NÃO ms) ou
     *                                                        data; `null` = agora.
     *
     * @throws \InvalidArgumentException segredo vazio, `:` no id do usuário ou instante negativo.
     */
    public static function signV2(
        string $secret,
        string $userExternalId,
        string $customerExternalId,
        int|\DateTimeInterface|null $at = null,
    ): string {
        if ($secret === '') {
            throw new \InvalidArgumentException('WidgetIdentity::signV2: o segredo não pode ser vazio.');
        }
        if (str_contains($userExternalId, ':')) {
            throw new \InvalidArgumentException(sprintf(
                'WidgetIdentity::signV2: o id do usuário não pode conter ":" (é o separador da assinatura): "%s".',
                $userExternalId,
            ));
        }
        $ts = match (true) {
            $at === null => time(),
            $at instanceof \DateTimeInterface => $at->getTimestamp(),
            default => $at,
        };
        if ($ts < 0) {
            throw new \InvalidArgumentException(sprintf('WidgetIdentity::signV2: o instante não pode ser negativo (recebido %d).', $ts));
        }

        return 'v2.' . $ts . '.' . hash_hmac('sha256', 'v2:' . $ts . ':' . $userExternalId . ':' . $customerExternalId, $secret);
    }
}
