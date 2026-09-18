<?php

declare(strict_types=1);

namespace Bfocus\Exception;

/**
 * Falha de conexão ou timeout (já esgotadas as novas tentativas): `status` 0, código `NETWORK_ERROR`.
 * `getRequestId()` é o `X-Request-Id` que a SDK enviou — procure-o nos logs, se a requisição chegou.
 */
class NetworkException extends BfocusException
{
    public function __construct(string $message, ?string $requestId = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 'NETWORK_ERROR', 0, $requestId, [], null, null, $previous);
    }
}
