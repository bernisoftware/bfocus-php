<?php

declare(strict_types=1);

namespace Bfocus\Internal;

use Bfocus\Bfocus;
use Bfocus\Exception\BfocusException;
use Bfocus\Exception\NetworkException;

/**
 * Transporte HTTP (cURL): headers, novas tentativas, desembrulho de erros.
 *
 * @internal Não faz parte da superfície pública — pode mudar sem aviso.
 */
final class Transport
{
    public const API_PREFIX = '/api/v1/integration';

    /** Status que valem nova tentativa (além de erro de rede/timeout). */
    private const RETRYABLE_STATUS = [429, 502, 503, 504];

    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const REQUEST_OPTIONS = ['idempotency_key', 'timeout'];

    private const MAX_RETRY_AFTER = 60.0;

    private ?\CurlHandle $curl = null;

    /** @var \Closure(float): void */
    private \Closure $sleep;

    /**
     * @param (\Closure(float): void)|null $sleep
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly float $timeout,
        private readonly int $maxRetries,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
    }

    /**
     * Executa UMA chamada lógica (com as novas tentativas) e devolve o envelope decodificado
     * (um objeto JSON com a chave `data`).
     *
     * @param string $path Caminho já codificado, relativo a `/api/v1/integration`.
     * @param array<string, mixed> $query Valores `null` são omitidos.
     * @param array<string, mixed>|null $body `null` = sem corpo.
     * @param array{idempotency_key?: string, timeout?: int|float} $options
     * @return array<string, mixed>
     *
     * @throws BfocusException
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, array $options = []): array
    {
        self::checkOptions($options);

        $url = $this->baseUrl . self::API_PREFIX . $path;
        $queryString = self::buildQuery($query);
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }
        $payload = $body === null ? null : self::encodeJson($body);
        $timeout = isset($options['timeout']) ? (float) $options['timeout'] : $this->timeout;

        // Gerados UMA vez por chamada lógica e repetidos em toda nova tentativa: é isso que
        // deixa a API devolver a resposta original (Idempotent-Replayed) em vez de repetir a escrita.
        // O X-Request-Id também é o último recurso do getRequestId() dos erros (a API o ecoa).
        $requestId = str_replace('-', '', self::uuid4());
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'X-Bfocus-Client: ' . Bfocus::CLIENT_ID,
            'User-Agent: ' . Bfocus::CLIENT_ID,
            'X-Request-Id: ' . $requestId,
            'Expect:', // sem "100-continue" em corpos grandes
        ];
        if (in_array($method, self::WRITE_METHODS, true)) {
            $headers[] = 'Idempotency-Key: ' . ($options['idempotency_key'] ?? self::uuid4());
        }
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        } elseif ($method !== 'GET' && $method !== 'DELETE') {
            $headers[] = 'Content-Length: 0';
        }

        for ($attempt = 0; ; $attempt++) {
            try {
                [$status, $responseHeaders, $raw] = $this->send($method, $url, $headers, $payload, $timeout, $requestId);
            } catch (NetworkException $e) {
                if ($attempt < $this->maxRetries) {
                    ($this->sleep)(self::backoff($attempt));
                    continue;
                }
                throw $e;
            }

            if ($status >= 200 && $status < 300) {
                return self::decodeSuccess($status, $responseHeaders, $raw, $requestId);
            }
            if (in_array($status, self::RETRYABLE_STATUS, true) && $attempt < $this->maxRetries) {
                $retryAfter = self::parseRetryAfter($responseHeaders['retry-after'] ?? null);
                ($this->sleep)($retryAfter !== null ? min(self::MAX_RETRY_AFTER, $retryAfter) : self::backoff($attempt));
                continue;
            }

            throw BfocusException::fromResponse($status, $responseHeaders, $raw, $requestId);
        }
    }

    /**
     * @param list<string> $headers
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function send(string $method, string $url, array $headers, ?string $payload, float $timeout, string $requestId): array
    {
        if ($this->curl === null) {
            $handle = curl_init();
            if ($handle === false) {
                throw new NetworkException('NETWORK_ERROR: não foi possível iniciar o cURL.', $requestId);
            }
            $this->curl = $handle;
        } else {
            curl_reset($this->curl); // reaproveita a conexão (keep-alive) sem herdar opções
        }

        $responseHeaders = [];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TIMEOUT_MS => max(1, (int) round($timeout * 1000)),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'HTTP/')) {
                    $responseHeaders = []; // nova resposta (ex.: depois de um 100 Continue)
                } elseif (($pos = strpos($trimmed, ':')) !== false) {
                    $responseHeaders[strtolower(trim(substr($trimmed, 0, $pos)))] = trim(substr($trimmed, $pos + 1));
                }

                return strlen($line);
            },
        ];
        if (defined('CURLOPT_PATH_AS_IS')) {
            $options[CURLOPT_PATH_AS_IS] = true; // o caminho vai exatamente como codificado
        }
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = $payload;
        }
        curl_setopt_array($this->curl, $options);

        $raw = curl_exec($this->curl);
        if (!is_string($raw)) {
            $errno = curl_errno($this->curl);
            $error = curl_error($this->curl);
            throw new NetworkException(sprintf(
                'NETWORK_ERROR: %s %s falhou (cURL %d: %s) [request_id=%s]',
                $method,
                self::stripQuery($url),
                $errno,
                $error !== '' ? $error : 'erro desconhecido',
                $requestId,
            ), $requestId);
        }

        return [(int) curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE), $responseHeaders, $raw];
    }

    /**
     * 2xx precisa ser o envelope `{"code", "data", "message"}`; qualquer outra coisa (HTML de
     * proxy, corpo vazio, JSON sem `data`) vira `INVALID_RESPONSE` — nunca um retorno vazio calado.
     *
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private static function decodeSuccess(int $status, array $headers, string $raw, string $sentRequestId): array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }
        if (is_array($decoded) && array_key_exists('data', $decoded)) {
            return $decoded;
        }

        $requestId = (is_array($decoded) && is_string($decoded['request_id'] ?? null) && $decoded['request_id'] !== '')
            ? $decoded['request_id']
            : (($headers['x-request-id'] ?? '') !== '' ? $headers['x-request-id'] : $sentRequestId);

        throw new BfocusException(
            sprintf('INVALID_RESPONSE (HTTP %d): resposta de sucesso sem envelope JSON válido [request_id=%s]', $status, $requestId),
            'INVALID_RESPONSE',
            $status,
            $requestId,
        );
    }

    /**
     * Query string: `null` omitido; booleanos `true`/`false`; datas em ISO 8601 UTC com `Z`.
     *
     * @param array<string, mixed> $query
     */
    public static function buildQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            if ($value === null) {
                continue;
            }
            $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode(self::queryValue((string) $name, $value));
        }

        return implode('&', $pairs);
    }

    private static function queryValue(string $name, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value instanceof \DateTimeInterface) {
            return self::formatDate($value);
        }
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        throw new \InvalidArgumentException(sprintf(
            'Parâmetro "%s": tipo %s não suportado na query.',
            $name,
            get_debug_type($value),
        ));
    }

    /** ISO 8601 em UTC com `Z` (frações de segundo só quando existem). */
    public static function formatDate(\DateTimeInterface $date): string
    {
        $utc = \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'));

        return $utc->format($utc->format('u') === '000000' ? 'Y-m-d\TH:i:s\Z' : 'Y-m-d\TH:i:s.u\Z');
    }

    /** @param array<string, mixed> $body */
    private static function encodeJson(array $body): string
    {
        try {
            // (object): corpo vazio vira `{}`, nunca `[]`.
            return json_encode(
                (object) $body,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Corpo não serializável em JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /** @param array<array-key, mixed> $options */
    private static function checkOptions(array $options): void
    {
        $unknown = array_diff(array_keys($options), self::REQUEST_OPTIONS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Opção de requisição desconhecida: %s. Aceitas: %s.',
                implode(', ', $unknown),
                implode(', ', self::REQUEST_OPTIONS),
            ));
        }
        if (isset($options['idempotency_key']) && (!is_string($options['idempotency_key']) || $options['idempotency_key'] === '')) {
            throw new \InvalidArgumentException('idempotency_key precisa ser uma string não vazia.');
        }
        if (isset($options['timeout']) && ((!is_int($options['timeout']) && !is_float($options['timeout'])) || $options['timeout'] <= 0)) {
            throw new \InvalidArgumentException('timeout precisa ser um número de segundos maior que zero.');
        }
    }

    /** Espera sem Retry-After: `min(8, 0.5 × 2^tentativa)` s + jitter de até 25%. */
    public static function backoff(int $attempt): float
    {
        $base = min(8.0, 0.5 * (2 ** $attempt));

        return $base * (1 + (random_int(0, 1_000_000) / 1_000_000) * 0.25);
    }

    /** Segundos do header `Retry-After` (número ou data HTTP); `null` se ausente/ilegível. */
    public static function parseRetryAfter(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? null : (float) max(0, $timestamp - time());
    }

    /** UUID v4 aleatório (com hífens). */
    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    private static function stripQuery(string $url): string
    {
        $pos = strpos($url, '?');

        return $pos === false ? $url : substr($url, 0, $pos);
    }
}
