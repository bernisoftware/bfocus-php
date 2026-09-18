<?php

declare(strict_types=1);

namespace Bfocus\Exception;

use Bfocus\Internal\Transport;

/**
 * Erro devolvido pela API (qualquer status fora de 2xx) ou de rede.
 *
 * **Use `getErrorCode()` na sua lógica** — é o código estável da API (`CUSTOMER_NOT_FOUND`,
 * `VALIDATION_ERROR`, `RATE_LIMITED`…). A mensagem é só para humanos e pode mudar.
 *
 * ```php
 * try {
 *     $bf->customers->get('ERP 1042');
 * } catch (\Bfocus\Exception\NotFoundException $e) {
 *     // $e->getErrorCode() === 'CUSTOMER_NOT_FOUND'
 * } catch (\Bfocus\Exception\BfocusException $e) {
 *     error_log($e->getErrorCode() . ' request_id=' . $e->getRequestId());
 * }
 * ```
 *
 * `getCode()` (o inteiro nativo do PHP) é o status HTTP, igual a `getStatus()`.
 */
class BfocusException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $validation
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $status = 0,
        private readonly ?string $requestId = null,
        private readonly array $validation = [],
        private readonly ?int $retryAfter = null,
        private readonly ?string $requiredScope = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /** Código estável: `body.error`, senão `body.message`, senão `HTTP_<status>`; `NETWORK_ERROR` em falha de rede. */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** Status HTTP (`0` em erro de rede). */
    public function getStatus(): int
    {
        return $this->status;
    }

    /** Id da requisição (corpo `request_id` ou header `X-Request-Id`) — informe ao suporte. */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Campo → motivo, em erros de validação (422). Vazio nos demais.
     *
     * @return array<string, mixed>
     */
    public function getValidation(): array
    {
        return $this->validation;
    }

    /** Segundos para tentar de novo (header `Retry-After`, só em 429). */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /** Escopo que a chave precisaria ter (header `X-Required-Scope`, só em 403 de escopo). */
    public function getRequiredScope(): ?string
    {
        return $this->requiredScope;
    }

    /**
     * @internal Constrói a exceção certa a partir de uma resposta de erro.
     *
     * @param array<string, string> $headers nomes em minúsculas
     * @param string|null $sentRequestId X-Request-Id que a SDK enviou (último recurso do request id)
     */
    public static function fromResponse(int $status, array $headers, string $raw, ?string $sentRequestId = null): self
    {
        $body = [];
        if (trim($raw) !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            } catch (\JsonException) {
                // corpo não-JSON (ex.: HTML de um proxy) → código HTTP_<status>
            }
        }

        $code = self::nonEmptyString($body['error'] ?? null)
            ?? self::nonEmptyString($body['message'] ?? null)
            ?? 'HTTP_' . $status;
        // corpo → header → o X-Request-Id que a SDK enviou (a API o ecoa; bate com o log dela)
        $requestId = self::nonEmptyString($body['request_id'] ?? null)
            ?? self::nonEmptyString($headers['x-request-id'] ?? null)
            ?? self::nonEmptyString($sentRequestId);
        $validation = is_array($body['validation'] ?? null) ? $body['validation'] : [];

        $retryAfter = null;
        if ($status === 429) {
            $seconds = Transport::parseRetryAfter($headers['retry-after'] ?? null);
            $retryAfter = $seconds === null ? null : (int) ceil($seconds);
        }
        $requiredScope = $status === 403 ? self::nonEmptyString($headers['x-required-scope'] ?? null) : null;

        $message = sprintf('%s (HTTP %d)', $code, $status);
        if ($requiredScope !== null) {
            $message .= sprintf(': a chave precisa do escopo "%s"', $requiredScope);
        } elseif ($validation !== []) {
            $details = [];
            foreach ($validation as $field => $reason) {
                $details[] = $field . ': ' . (is_scalar($reason) ? (string) $reason : json_encode($reason));
            }
            $message .= ': ' . implode('; ', $details);
        } elseif ($retryAfter !== null) {
            $message .= sprintf(': tente de novo em %d s', $retryAfter);
        }
        if ($requestId !== null) {
            $message .= sprintf(' [request_id=%s]', $requestId);
        }

        $class = match (true) {
            $status === 401 => AuthenticationException::class,
            $status === 403 => PermissionDeniedException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 422 => ValidationException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 => ServerException::class,
            default => self::class,
        };

        return new $class($message, $code, $status, $requestId, $validation, $retryAfter, $requiredScope);
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
