<?php

declare(strict_types=1);

namespace Bfocus\Tests;

use Bfocus\Bfocus;
use Bfocus\Exception\AuthenticationException;
use Bfocus\Exception\BfocusException;
use Bfocus\Exception\ConflictException;
use Bfocus\Exception\NetworkException;
use Bfocus\Exception\NotFoundException;
use Bfocus\Exception\PermissionDeniedException;
use Bfocus\Exception\RateLimitException;
use Bfocus\Exception\ServerException;
use Bfocus\Exception\ValidationException;
use Bfocus\Page;
use Bfocus\Tests\Support\Cases;
use Bfocus\Tests\Support\MockServer;
use Bfocus\WidgetIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Conformidade (BRIEF §7): cada caso de `cases.json` roda contra o servidor HTTP local, que
 * responde o roteiro do caso; depois conferimos o que chegou e o que a SDK devolveu/lançou.
 */
final class ConformanceTest extends TestCase
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Em `expect.error.request_id`: "o X-Request-Id que a SDK enviou". */
    private const SENT = '$sent';

    /**
     * Tabela op → chamada idiomática da SDK. Op de caso fora daqui (e fora de
     * `sdk_excluded_ops`) FALHA a suíte: endpoint novo sem método quebra o build.
     *
     * @return array<string, \Closure(Bfocus, array<string, mixed>): mixed>
     */
    public static function operations(): array
    {
        $rest = static fn (array $args, string ...$keys): array => array_diff_key($args, array_flip($keys));

        return [
            'customers.upsert' => fn (Bfocus $bf, array $a) => $bf->customers->upsert($a['external_id'], $rest($a, 'external_id')),
            'customers.get' => fn (Bfocus $bf, array $a) => $bf->customers->get($a['external_id']),
            'customers.list' => fn (Bfocus $bf, array $a) => $bf->customers->list($a),
            'customers.list_all' => fn (Bfocus $bf, array $a) => iterator_to_array($bf->customers->listAll($a), false),
            'customers.delete' => fn (Bfocus $bf, array $a) => $bf->customers->delete($a['external_id']),
            'customers.batch' => fn (Bfocus $bf, array $a) => $bf->customers->batch($a['items']),
            'customers.identifiers.add' => fn (Bfocus $bf, array $a) => $bf->customers->identifiers->add(
                $a['external_id'],
                $a['extra_id'],
                $rest($a, 'external_id', 'extra_id'),
            ),
            'customers.identifiers.remove' => fn (Bfocus $bf, array $a) => $bf->customers->identifiers->remove($a['external_id'], $a['extra_id']),
            'people.upsert' => fn (Bfocus $bf, array $a) => $bf->people->upsert(
                $a['customer_external_id'],
                $a['person_external_id'],
                $rest($a, 'customer_external_id', 'person_external_id'),
            ),
            'people.list' => fn (Bfocus $bf, array $a) => $bf->people->list($a['customer_external_id']),
            'people.delete' => fn (Bfocus $bf, array $a) => $bf->people->delete($a['customer_external_id'], $a['person_external_id']),
            'people.batch' => fn (Bfocus $bf, array $a) => $bf->people->batch($a['items']),
            'people.identifiers.add' => fn (Bfocus $bf, array $a) => $bf->people->identifiers->add(
                $a['person_external_id'],
                $a['extra_id'],
                $rest($a, 'person_external_id', 'extra_id'),
            ),
            'people.identifiers.remove' => fn (Bfocus $bf, array $a) => $bf->people->identifiers->remove($a['person_external_id'], $a['extra_id']),
            'customers.contacts.list' => fn (Bfocus $bf, array $a) => $bf->customers->contacts->list($a['external_id']),
            'customers.contacts.upsert' => fn (Bfocus $bf, array $a) => $bf->customers->contacts->upsert(
                $a['external_id'],
                $a['contact_external_id'],
                $rest($a, 'external_id', 'contact_external_id'),
            ),
            'customers.contacts.delete' => fn (Bfocus $bf, array $a) => $bf->customers->contacts->delete($a['external_id'], $a['contact_external_id']),
            'customers.products.list' => fn (Bfocus $bf, array $a) => $bf->customers->products->list($a['external_id']),
            'customers.products.attach' => fn (Bfocus $bf, array $a) => $bf->customers->products->attach($a['external_id'], $a['product_slug']),
            'customers.products.detach' => fn (Bfocus $bf, array $a) => $bf->customers->products->detach($a['external_id'], $a['product_slug']),
            'customers.interactions.list' => fn (Bfocus $bf, array $a) => $bf->customers->interactions->list($a['external_id'], $rest($a, 'external_id')),
            'customers.interactions.list_all' => fn (Bfocus $bf, array $a) => iterator_to_array(
                $bf->customers->interactions->listAll($a['external_id'], $rest($a, 'external_id')),
                false,
            ),
            'customers.interactions.create' => fn (Bfocus $bf, array $a) => $bf->customers->interactions->create(
                $a['external_id'],
                $a['content'],
                $rest($a, 'external_id', 'content'),
            ),
            'products.list' => fn (Bfocus $bf, array $a) => $bf->products->list($a),
            'products.get' => fn (Bfocus $bf, array $a) => $bf->products->get($a['slug']),
            'products.upsert' => fn (Bfocus $bf, array $a) => $bf->products->upsert($a['slug'], $rest($a, 'slug')),
            'products.archive' => fn (Bfocus $bf, array $a) => $bf->products->archive($a['slug']),
            'release_notes.list' => fn (Bfocus $bf, array $a) => $bf->releaseNotes->list($a['product_slug'], $rest($a, 'product_slug')),
            'release_notes.list_all' => fn (Bfocus $bf, array $a) => iterator_to_array(
                $bf->releaseNotes->listAll($a['product_slug'], $rest($a, 'product_slug')),
                false,
            ),
            'release_notes.get' => fn (Bfocus $bf, array $a) => $bf->releaseNotes->get($a['product_slug'], $a['version']),
            'release_notes.upsert' => fn (Bfocus $bf, array $a) => $bf->releaseNotes->upsert(
                $a['product_slug'],
                $a['version'],
                $rest($a, 'product_slug', 'version'),
            ),
            'release_notes.publish' => fn (Bfocus $bf, array $a) => $bf->releaseNotes->publish($a['product_slug'], $a['version']),
            'kb.articles.list' => fn (Bfocus $bf, array $a) => $bf->kb->articles->list($a),
            'kb.articles.list_all' => fn (Bfocus $bf, array $a) => iterator_to_array($bf->kb->articles->listAll($a), false),
            'kb.articles.get' => fn (Bfocus $bf, array $a) => $bf->kb->articles->get($a['external_id']),
            'kb.articles.upsert' => fn (Bfocus $bf, array $a) => $bf->kb->articles->upsert($a['external_id'], $rest($a, 'external_id')),
            'kb.articles.batch_upsert' => fn (Bfocus $bf, array $a) => $bf->kb->articles->batchUpsert($a['articles']),
            'kb.articles.publish' => fn (Bfocus $bf, array $a) => $bf->kb->articles->publish($a['external_id']),
            'kb.articles.unpublish' => fn (Bfocus $bf, array $a) => $bf->kb->articles->unpublish($a['external_id']),
            'kb.articles.delete' => fn (Bfocus $bf, array $a) => $bf->kb->articles->delete($a['external_id']),
            'kb.search' => fn (Bfocus $bf, array $a) => $bf->kb->search($a['q'], $rest($a, 'q')),
            'ai_agents.list' => fn (Bfocus $bf, array $a) => $bf->aiAgents->list(),
            'ai_agents.get' => fn (Bfocus $bf, array $a) => $bf->aiAgents->get($a['agent_id']),
            'ai_agents.preview' => fn (Bfocus $bf, array $a) => $bf->aiAgents->preview($a['agent_id'], $a['message'], $rest($a, 'agent_id', 'message')),
        ];
    }

    /** @return iterable<string, array{int}> */
    public static function caseProvider(): iterable
    {
        foreach (Cases::assoc()['cases'] as $index => $case) {
            yield $case['id'] => [$index];
        }
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function signatureProvider(): iterable
    {
        foreach (Cases::assoc()['signatures'] as $i => $v) {
            yield 'vetor ' . $i => [$v['secret'], $v['user_external_id'], $v['customer_external_id'], $v['expected']];
        }
    }

    /** @return iterable<string, array{string, string, string, int, string}> */
    public static function signatureV2Provider(): iterable
    {
        foreach (Cases::assoc()['signatures_v2'] as $i => $v) {
            yield 'vetor v2 ' . $i => [$v['secret'], $v['user_external_id'], $v['customer_external_id'], $v['timestamp'], $v['expected']];
        }
    }

    #[DataProvider('caseProvider')]
    public function testCase(int $index): void
    {
        $data = Cases::assoc();
        $case = $data['cases'][$index];
        $caseObjects = Cases::objects()->cases[$index];
        $id = $case['id'];
        $op = $case['op'];

        $operations = self::operations();
        if (!isset($operations[$op])) {
            if (in_array($op, $data['sdk_excluded_ops'], true)) {
                $this->addToAssertionCount(1); // fora da SDK por decisão (BRIEF §6)

                return;
            }
            $this->fail(sprintf('[%s] op "%s" não tem método na SDK PHP: implemente e registre em ConformanceTest::operations().', $id, $op));
        }

        MockServer::script(array_map(static fn (\stdClass $ex): \stdClass => $ex->response, $caseObjects->exchanges));
        $sleeps = [];
        $bf = new Bfocus($data['api_key'], [
            'base_url' => MockServer::url(),
            'sleep' => static function (float $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        ]);

        $result = null;
        $error = null;
        try {
            $result = $operations[$op]($bf, $case['args']);
        } catch (BfocusException $e) {
            $error = $e;
        }

        $received = MockServer::received();
        $this->assertRequests($data['api_key'], $case, $caseObjects, $received);
        $this->assertSleeps($id, $case['exchanges'], $sleeps);

        if (array_key_exists('error', $case['expect'])) {
            $expected = $case['expect']['error'];
            $this->assertNotNull($error, sprintf('[%s] esperava erro %s, veio resultado.', $id, $expected['code']));
            $this->assertSame($expected['type'], self::errorType($error), "[$id] tipo do erro");
            $this->assertSame($expected['code'], $error->getErrorCode(), "[$id] code");
            $this->assertSame($expected['status'], $error->getStatus(), "[$id] status");
            $this->assertSame($expected['status'], $error->getCode(), "[$id] getCode() = status");
            if (array_key_exists('request_id', $expected)) {
                $expectedRequestId = $expected['request_id'] === self::SENT
                    ? ($received[count($received) - 1]['headers']['x-request-id'] ?? null)
                    : $expected['request_id'];
                $this->assertNotNull($expectedRequestId, "[$id] X-Request-Id enviado");
                $this->assertSame($expectedRequestId, $error->getRequestId(), "[$id] request_id");
            }
            if (array_key_exists('retry_after', $expected)) {
                $this->assertSame($expected['retry_after'], $error->getRetryAfter(), "[$id] retry_after");
            }
            if (array_key_exists('required_scope', $expected)) {
                $this->assertSame($expected['required_scope'], $error->getRequiredScope(), "[$id] required_scope");
            }
            if (array_key_exists('validation', $expected)) {
                $this->assertSame(self::sortKeys($expected['validation']), self::sortKeys($error->getValidation()), "[$id] validation");
            }
            $this->assertStringContainsString($expected['code'], $error->getMessage(), "[$id] mensagem cita o code");

            return;
        }

        if ($error !== null) {
            $this->fail(sprintf('[%s] erro inesperado: %s: %s', $id, $error::class, $error->getMessage()));
        }
        $this->assertSame(self::sortKeys($case['expect']['result']), self::sortKeys(self::neutral($result)), "[$id] resultado");
    }

    #[DataProvider('signatureProvider')]
    public function testWidgetIdentitySignature(string $secret, string $user, string $customer, string $expected): void
    {
        $this->assertSame($expected, WidgetIdentity::sign($secret, $user, $customer));
    }

    #[DataProvider('signatureV2Provider')]
    public function testWidgetIdentitySignatureV2(string $secret, string $user, string $customer, int $timestamp, string $expected): void
    {
        $this->assertSame($expected, WidgetIdentity::signV2($secret, $user, $customer, $timestamp));
        $this->assertSame($expected, WidgetIdentity::signV2($secret, $user, $customer, (new \DateTimeImmutable('@' . $timestamp))));
    }

    public function testHelperOpsAreMapped(): void
    {
        foreach (Cases::assoc()['sdk_helper_ops'] ?? [] as $helper => $base) {
            $this->assertArrayHasKey($helper, self::operations(), "helper $helper (sobre $base) sem método na SDK PHP");
        }
    }

    public function testEveryPublicOperationHasAMethod(): void
    {
        $spec = dirname(__DIR__, 3) . '/api/openapi/public.json';
        if (!is_file($spec)) {
            $this->markTestSkipped('public.json só existe no monorepo.');
        }
        $known = array_merge(array_keys(self::operations()), Cases::assoc()['sdk_excluded_ops']);
        $doc = json_decode((string) file_get_contents($spec), true, 512, JSON_THROW_ON_ERROR);
        foreach ($doc['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (is_array($operation) && isset($operation['operationId'])) {
                    $this->assertContains($operation['operationId'], $known, sprintf('%s %s (%s) sem método na SDK PHP', strtoupper($method), $path, $operation['operationId']));
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $case
     * @param list<array{method: string, uri: string, headers: array<string, string>, body: string}> $received
     */
    private function assertRequests(string $apiKey, array $case, \stdClass $caseObjects, array $received): void
    {
        $id = $case['id'];
        $this->assertCount(count($case['exchanges']), $received, "[$id] número de requisições");

        foreach ($case['exchanges'] as $i => $exchange) {
            $expected = $exchange['request'];
            $got = $received[$i];
            $headers = $got['headers'];
            $ctx = "[$id #$i]";

            $this->assertSame($expected['method'], $got['method'], "$ctx método");
            [$path, $queryString] = array_pad(explode('?', $got['uri'], 2), 2, '');
            $this->assertSame($expected['path'], $path, "$ctx caminho (exatamente como codificado)");
            $this->assertSame(self::expectedPairs($expected['query']), self::parseQuery($queryString), "$ctx query");

            $expectedBody = $caseObjects->exchanges[$i]->request->body;
            if ($expectedBody === null) {
                $this->assertSame('', $got['body'], "$ctx sem corpo");
                $this->assertArrayNotHasKey('content-type', $headers, "$ctx Content-Type só com corpo");
            } else {
                $this->assertSame(
                    self::canon($expectedBody),
                    self::canon(json_decode($got['body'], false, 512, JSON_THROW_ON_ERROR)),
                    "$ctx corpo",
                );
                $this->assertSame('application/json', $headers['content-type'] ?? null, "$ctx Content-Type");
            }

            $this->assertSame('Bearer ' . $apiKey, $headers['authorization'] ?? null, "$ctx Authorization");
            $this->assertSame('application/json', $headers['accept'] ?? null, "$ctx Accept");
            $this->assertMatchesRegularExpression('#^bfocus-php/' . preg_quote(Bfocus::VERSION, '#') . '$#', $headers['x-bfocus-client'] ?? '', "$ctx X-Bfocus-Client");
            $this->assertSame($headers['x-bfocus-client'] ?? null, $headers['user-agent'] ?? null, "$ctx User-Agent");
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $headers['x-request-id'] ?? '', "$ctx X-Request-Id");
            $isWrite = in_array($expected['method'], self::WRITE_METHODS, true);
            if ($isWrite) {
                $this->assertNotSame('', $headers['idempotency-key'] ?? '', "$ctx Idempotency-Key em escrita");
            } else {
                $this->assertArrayNotHasKey('idempotency-key', $headers, "$ctx Idempotency-Key só em escrita");
            }

            // "retry": true = nova tentativa da troca anterior (mesmos ids); false = chamada nova (ids novos)
            $retry = $exchange['retry'] ?? false;
            if ($i === 0) {
                $this->assertFalse($retry, "$ctx a primeira troca não pode ser retry");
                continue;
            }
            $previous = $received[$i - 1]['headers'];
            if ($retry) {
                $this->assertSame($previous['x-request-id'] ?? null, $headers['x-request-id'] ?? null, "$ctx X-Request-Id repetido na nova tentativa");
                $this->assertSame($previous['idempotency-key'] ?? null, $headers['idempotency-key'] ?? null, "$ctx Idempotency-Key repetida na nova tentativa");
            } else {
                $this->assertNotSame($previous['x-request-id'] ?? null, $headers['x-request-id'] ?? null, "$ctx X-Request-Id novo por chamada");
                if ($isWrite) {
                    $this->assertNotSame($previous['idempotency-key'] ?? null, $headers['idempotency-key'] ?? null, "$ctx Idempotency-Key nova por chamada");
                }
            }
        }
    }

    /**
     * Uma espera por troca com `retry: true`: o Retry-After da resposta anterior (teto 60 s) ou o
     * backoff da tentativa.
     *
     * @param list<array<string, mixed>> $exchanges
     * @param list<float> $sleeps
     */
    private function assertSleeps(string $id, array $exchanges, array $sleeps): void
    {
        $expected = [];
        $attempt = 0;
        foreach ($exchanges as $i => $exchange) {
            if ($i > 0 && ($exchange['retry'] ?? false)) {
                $expected[] = [$attempt++, $exchanges[$i - 1]['response']['headers']['Retry-After'] ?? null];
            } else {
                $attempt = 0;
            }
        }

        $this->assertCount(count($expected), $sleeps, "[$id] esperas entre tentativas");
        foreach ($expected as $k => [$retry, $retryAfter]) {
            if ($retryAfter !== null) {
                $this->assertEqualsWithDelta(min(60.0, (float) $retryAfter), $sleeps[$k], 1e-9, "[$id] espera = Retry-After");
            } else {
                $base = min(8.0, 0.5 * (2 ** $retry));
                $this->assertGreaterThanOrEqual($base, $sleeps[$k], "[$id] backoff mínimo");
                $this->assertLessThanOrEqual($base * 1.25, $sleeps[$k], "[$id] backoff + jitter <= 25%");
            }
        }
    }

    private static function errorType(BfocusException $e): string
    {
        return match (true) {
            $e instanceof AuthenticationException => 'authentication',
            $e instanceof PermissionDeniedException => 'permission_denied',
            $e instanceof NotFoundException => 'not_found',
            $e instanceof ConflictException => 'conflict',
            $e instanceof ValidationException => 'validation',
            $e instanceof RateLimitException => 'rate_limit',
            $e instanceof ServerException => 'server',
            $e instanceof NetworkException => 'network',
            default => 'api',
        };
    }

    /** Retorno da SDK → JSON neutro (Page vira {items, page, page_size, total, pages}). */
    private static function neutral(mixed $value): mixed
    {
        if ($value instanceof Page) {
            return $value->toArray();
        }
        if ($value instanceof \Traversable) {
            return iterator_to_array($value, false);
        }

        return $value;
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map([self::class, 'sortKeys'], $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** Forma canônica que distingue objeto (`{}`) de lista (`[]`) e ignora ordem de chaves. */
    private static function canon(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $props = get_object_vars($value);
            ksort($props);

            return ['{}' => array_map([self::class, 'canon'], $props)];
        }
        if (is_array($value)) {
            return array_map([self::class, 'canon'], $value);
        }

        return $value;
    }

    /**
     * @param array<string, string> $query
     * @return list<array{string, string}>
     */
    private static function expectedPairs(array $query): array
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[] = [(string) $name, (string) $value];
        }
        sort($pairs);

        return $pairs;
    }

    /** @return list<array{string, string}> */
    private static function parseQuery(string $queryString): array
    {
        if ($queryString === '') {
            return [];
        }
        $pairs = [];
        foreach (explode('&', $queryString) as $part) {
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $pairs[] = [urldecode($name), urldecode($value)];
        }
        sort($pairs);

        return $pairs;
    }
}
