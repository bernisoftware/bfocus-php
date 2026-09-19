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
     * Lote de upserts (`customers->batch`, `people->batch`): até `$max` itens, SEM dividir.
     *
     * Acima do limite → `\InvalidArgumentException` antes de qualquer requisição (o `index` de
     * cada resultado é a posição no lote enviado; quem chama divide). Lista vazia → resultado
     * zerado sem requisição. `$serialize` valida e converte cada item para o formato do fio.
     *
     * @param iterable<mixed> $items
     * @param \Closure(mixed, int): array<string, mixed> $serialize
     * @param array<string, mixed> $options
     * @return array{
     *     results: list<array<string, mixed>>,
     *     summary: array{created: int, updated: int, unchanged: int, error: int}
     * }
     */
    protected function callBatch(string $path, iterable $items, int $max, \Closure $serialize, string $method, array $options): array
    {
        $list = [];
        foreach ($items as $item) {
            $list[] = $item;
        }
        if (count($list) > $max) {
            throw new \InvalidArgumentException(sprintf(
                '%s aceita até %d itens por chamada (recebeu %d); divida em lotes de %d.',
                $method,
                $max,
                count($list),
                $max,
            ));
        }
        if ($list === []) {
            return ['results' => [], 'summary' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'error' => 0]];
        }

        // Valida TUDO antes da requisição: item inválido não sai.
        $wire = [];
        foreach ($list as $position => $item) {
            $wire[] = $serialize($item, $position);
        }

        /** @var array{results: list<array<string, mixed>>, summary: array{created: int, updated: int, unchanged: int, error: int}} */
        return $this->call('POST', $path, [], ['items' => $wire], $options);
    }

    /**
     * Confere que o item do lote é um array com `$key` string não vazia.
     *
     * @return array<array-key, mixed>
     */
    protected static function batchItem(mixed $item, int $position, string $key, string $method): array
    {
        if (!is_array($item)) {
            throw new \InvalidArgumentException(sprintf('%s: o item #%d precisa ser um array.', $method, $position));
        }
        $value = $item[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException(sprintf('%s: o item #%d precisa de %s.', $method, $position, $key));
        }

        return $item;
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
