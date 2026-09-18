<?php

declare(strict_types=1);

namespace Bfocus\Resources;

use Bfocus\Internal\Transport;
use Bfocus\Page;

/**
 * Base dos recursos.
 *
 * Convenções de todos os métodos:
 * - argumentos obrigatórios são posicionais; os opcionais vão num array associativo com as
 *   chaves da API (snake_case). Chave AUSENTE = não enviada; chave com `null` = envia `null`
 *   (nos upserts, `null` limpa o campo). Chave desconhecida → `\InvalidArgumentException`.
 * - o último argumento é `$options`: `['idempotency_key' => '...', 'timeout' => 10]`.
 *
 * @phpstan-type RequestOptions array{idempotency_key?: string, timeout?: int|float}
 *
 * @internal Os recursos são criados pelo `\Bfocus\Bfocus`; use-os pelas propriedades dele.
 */
abstract class AbstractResource
{
    /** @internal */
    public function __construct(protected readonly Transport $transport)
    {
    }

    /**
     * Codifica um parâmetro de caminho (percent-encoding por segmento).
     *
     * Vazio, `.` e `..` são recusados: viram outro caminho (listagem, diretório pai) no cURL e
     * nos proxies, mesmo codificados.
     */
    protected static function segment(string $value, string $name): string
    {
        if ($value === '' || $value === '.' || $value === '..') {
            throw new \InvalidArgumentException(sprintf('%s não pode ser vazio, "." nem "..": recebido "%s".', $name, $value));
        }

        return rawurlencode($value);
    }

    /**
     * Confere as chaves de `$input` contra as aceitas (preserva `null` explícito).
     *
     * @param array<array-key, mixed> $input
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    protected static function only(array $input, array $allowed, string $method): array
    {
        $unknown = array_diff(array_map('strval', array_keys($input)), $allowed);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                '%s: parâmetro desconhecido: %s. Aceitos: %s.',
                $method,
                implode(', ', $unknown),
                implode(', ', $allowed),
            ));
        }

        /** @var array<string, mixed> $input */
        return $input;
    }

    /**
     * Faz a chamada e devolve `data` (o envelope desembrulhado).
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $options
     * @return array<array-key, mixed>
     */
    protected function call(string $method, string $path, array $query = [], ?array $body = null, array $options = []): array
    {
        $data = $this->transport->request($method, $path, $query, $body, $options)['data'] ?? null;

        return is_array($data) ? $data : [];
    }

    /**
     * GET paginado → `Page`.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $options
     * @return Page<mixed>
     */
    protected function callPage(string $path, array $query, array $options): Page
    {
        return Page::fromEnvelope($this->transport->request('GET', $path, $query, null, $options));
    }

    /**
     * Percorre as páginas a partir da 1 (padrão `page_size` = 100) até a última.
     *
     * @param \Closure(array<string, mixed>): Page<mixed> $fetchPage
     * @param array<string, mixed> $params
     * @return \Generator<int, mixed>
     */
    protected static function iterate(\Closure $fetchPage, array $params): \Generator
    {
        $params['page_size'] ??= 100;
        for ($page = 1; ; $page++) {
            $result = $fetchPage(['page' => $page] + $params);
            foreach ($result->items as $item) {
                yield $item;
            }
            if ($result->items === [] || $result->page >= $result->pages) {
                return;
            }
        }
    }
}
