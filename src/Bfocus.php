<?php

declare(strict_types=1);

namespace Bfocus;

use Bfocus\Internal\Transport;
use Bfocus\Resources\AiAgents;
use Bfocus\Resources\Customers;
use Bfocus\Resources\Kb;
use Bfocus\Resources\People;
use Bfocus\Resources\Products;
use Bfocus\Resources\ReleaseNotes;

/**
 * Cliente oficial da API pública do bFocus.
 *
 * ```php
 * $bf = new \Bfocus\Bfocus(getenv('BFOCUS_API_KEY'));
 * $cliente = $bf->customers->upsert('ERP 1042', ['name' => 'Padaria Estrela']);
 * echo $cliente['id'];
 * ```
 *
 * Nenhuma chamada de rede acontece na construção. Sem dependências de runtime além de
 * `ext-curl` e `ext-json`.
 */
final class Bfocus
{
    /** Versão da SDK. Vai no header `X-Bfocus-Client` de toda requisição. */
    public const VERSION = '0.2.1';

    /** Identificação da SDK enviada em `X-Bfocus-Client` e `User-Agent`. */
    public const CLIENT_ID = 'bfocus-php/' . self::VERSION;

    /** URL base padrão (produção). Em dev: `http://localhost:8000`. */
    public const DEFAULT_BASE_URL = 'https://api.bfocus.com.br';

    private const OPTIONS = ['base_url', 'timeout', 'max_retries', 'sleep'];

    /** Clientes, contatos, produtos vinculados e interações. */
    public readonly Customers $customers;

    /** Pessoas dos clientes (acesso ao widget/portal), lote e identificadores extras. */
    public readonly People $people;

    /** Catálogo de produtos. */
    public readonly Products $products;

    /** Release notes por produto. */
    public readonly ReleaseNotes $releaseNotes;

    /** Base de conhecimento (artigos e busca). */
    public readonly Kb $kb;

    /** Agentes de IA. */
    public readonly AiAgents $aiAgents;

    /**
     * @param string $apiKey Chave de API (Integrações → Chaves de API). Obrigatória.
     * @param array{
     *     base_url?: string,
     *     timeout?: int|float,
     *     max_retries?: int,
     *     sleep?: callable(float): void
     * } $options
     *     - `base_url`: sem barra final. Padrão `https://api.bfocus.com.br`.
     *     - `timeout`: segundos, por tentativa. Padrão 30.
     *     - `max_retries`: novas tentativas além da primeira. Padrão 2; `0` desliga.
     *     - `sleep`: função que espera N segundos entre tentativas (padrão `usleep`).
     *       Útil em testes para não dormir de verdade.
     *
     * @throws \InvalidArgumentException chave vazia ou opção inválida.
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('Bfocus: a chave de API (apiKey) não pode ser vazia.');
        }
        $unknown = array_diff(array_keys($options), self::OPTIONS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Bfocus: opção desconhecida: %s. Aceitas: %s.',
                implode(', ', $unknown),
                implode(', ', self::OPTIONS),
            ));
        }

        $baseUrl = $options['base_url'] ?? self::DEFAULT_BASE_URL;
        if (!is_string($baseUrl) || rtrim($baseUrl, '/') === '') {
            throw new \InvalidArgumentException('Bfocus: base_url precisa ser uma URL.');
        }
        $timeout = $options['timeout'] ?? 30;
        if ((!is_int($timeout) && !is_float($timeout)) || $timeout <= 0) {
            throw new \InvalidArgumentException('Bfocus: timeout precisa ser um número de segundos maior que zero.');
        }
        $maxRetries = $options['max_retries'] ?? 2;
        if (!is_int($maxRetries) || $maxRetries < 0) {
            throw new \InvalidArgumentException('Bfocus: max_retries precisa ser um inteiro >= 0.');
        }
        $sleep = $options['sleep'] ?? null;
        if ($sleep !== null && !is_callable($sleep)) {
            throw new \InvalidArgumentException('Bfocus: sleep precisa ser callable(float $segundos): void.');
        }

        $transport = new Transport(
            $apiKey,
            rtrim($baseUrl, '/'),
            (float) $timeout,
            $maxRetries,
            $sleep === null ? null : \Closure::fromCallable($sleep),
        );

        $this->customers = new Customers($transport);
        $this->people = new People($transport);
        $this->products = new Products($transport);
        $this->releaseNotes = new ReleaseNotes($transport);
        $this->kb = new Kb($transport);
        $this->aiAgents = new AiAgents($transport);
    }
}
