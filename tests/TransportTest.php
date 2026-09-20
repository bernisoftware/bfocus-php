<?php

declare(strict_types=1);

namespace Bfocus\Tests;

use Bfocus\Bfocus;
use Bfocus\Exception\BfocusException;
use Bfocus\Exception\ConflictException;
use Bfocus\Exception\NetworkException;
use Bfocus\Exception\ServerException;
use Bfocus\Tests\Support\MockServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Detalhes de transporte: codificação, headers, novas tentativas, rede. */
final class TransportTest extends TestCase
{
    /** @var list<float> */
    private array $sleeps = [];

    public function testPathSegmentsArePercentEncoded(): void
    {
        MockServer::script([self::ok(['id' => '1']), self::ok(['id' => '2'])]);
        $bf = $this->client();

        $bf->customers->get('ERP/1042 ç');
        $bf->customers->contacts->upsert('ERP 1', 'CT/9', ['name' => 'Ana']);

        $uris = array_column(MockServer::received(), 'uri');
        $this->assertSame('/api/v1/integration/customers/ERP%2F1042%20%C3%A7', $uris[0]);
        $this->assertSame('/api/v1/integration/customers/ERP%201/contacts/CT%2F9', $uris[1]);
    }

    public function testArticleExternalIdWithSlashIsRejectedLocally(): void
    {
        MockServer::script([]);
        try {
            $this->client()->kb->articles->upsert('docs/guia', ['title' => 'x']);
            $this->fail('esperava InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('/', $e->getMessage());
        }
        $this->assertSame([], MockServer::received());
    }

    /** @return iterable<string, array{\Closure(Bfocus, string): mixed}> */
    public static function pathMethodProvider(): iterable
    {
        yield 'customers->get' => [fn (Bfocus $bf, string $v) => $bf->customers->get($v)];
        yield 'customers->delete' => [fn (Bfocus $bf, string $v) => $bf->customers->delete($v)];
        yield 'contacts->upsert (contato)' => [fn (Bfocus $bf, string $v) => $bf->customers->contacts->upsert('ERP 1', $v, ['name' => 'x'])];
        yield 'products->attach (slug)' => [fn (Bfocus $bf, string $v) => $bf->customers->products->attach('ERP 1', $v)];
        yield 'products->get' => [fn (Bfocus $bf, string $v) => $bf->products->get($v)];
        yield 'releaseNotes->get (versão)' => [fn (Bfocus $bf, string $v) => $bf->releaseNotes->get('erp-cloud', $v)];
        yield 'releaseNotes->listAll (produto)' => [fn (Bfocus $bf, string $v) => iterator_to_array($bf->releaseNotes->listAll($v))];
        yield 'kb->articles->publish' => [fn (Bfocus $bf, string $v) => $bf->kb->articles->publish($v)];
        yield 'kb->articles->batchUpsert' => [fn (Bfocus $bf, string $v) => $bf->kb->articles->batchUpsert([['external_id' => $v, 'title' => 'x']])];
        yield 'aiAgents->preview' => [fn (Bfocus $bf, string $v) => $bf->aiAgents->preview($v, 'Oi')];
    }

    /** @param \Closure(Bfocus, string): mixed $call */
    #[DataProvider('pathMethodProvider')]
    public function testEmptyDotAndDotDotPathParametersAreRejected(\Closure $call): void
    {
        MockServer::script([]);
        foreach (['', '.', '..'] as $value) {
            try {
                $call($this->client(), $value);
                $this->fail(sprintf('esperava InvalidArgumentException para "%s"', $value));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], MockServer::received(), 'nenhuma requisição sai');
    }

    public function testDotsInsideValueAreFine(): void
    {
        MockServer::script([self::ok(['id' => '1'])]);
        $this->client()->releaseNotes->get('erp-cloud', '2.3.0..rc');

        $this->assertSame('/api/v1/integration/products/erp-cloud/release-notes/2.3.0..rc', MockServer::received()[0]['uri']);
    }

    public function testQueryEncoding(): void
    {
        MockServer::script([self::okPage(), self::okPage()]);
        $bf = $this->client();

        $bf->kb->articles->list([
            'q' => 'nota fiscal & cia',
            'product' => null,
            'updated_since' => new \DateTimeImmutable('2026-09-01 09:30:00', new \DateTimeZone('America/Sao_Paulo')),
        ]);
        $bf->releaseNotes->list('erp-cloud', ['published' => false]);

        $received = MockServer::received();
        $this->assertSame(
            '/api/v1/integration/kb/articles?q=nota%20fiscal%20%26%20cia&updated_since=2026-09-01T12%3A30%3A00Z',
            $received[0]['uri'],
            'null omitido, data em UTC com Z, espaço como %20',
        );
        $this->assertSame('/api/v1/integration/products/erp-cloud/release-notes?published=false', $received[1]['uri']);
    }

    public function testStringDatePassesAsIs(): void
    {
        MockServer::script([self::okPage()]);
        $this->client()->customers->list(['updated_since' => '2026-09-01T00:00:00-03:00']);

        $this->assertSame('/api/v1/integration/customers?updated_since=2026-09-01T00%3A00%3A00-03%3A00', MockServer::received()[0]['uri']);
    }

    public function testUnknownKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nome');
        $this->client()->customers->upsert('ERP 1', ['nome' => 'Padaria']);
    }

    public function testUnknownRequestOptionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->customers->get('ERP 1', ['idempotencyKey' => 'x']);
    }

    public function testEmptyUpsertSendsEmptyObject(): void
    {
        MockServer::script([self::ok(['slug' => 'p'])]);
        $this->client()->products->upsert('p');

        $this->assertSame('{}', MockServer::received()[0]['body']);
    }

    public function testUserIdempotencyKeyAndTimeoutPerCall(): void
    {
        MockServer::script([self::ok(['id' => '1'])]);
        $this->client()->customers->upsert('ERP 1', ['name' => 'Padaria'], ['idempotency_key' => 'pedido-1042', 'timeout' => 5]);

        $this->assertSame('pedido-1042', MockServer::received()[0]['headers']['idempotency-key']);
    }

    public function testPostWithoutBodyHasNoContentType(): void
    {
        MockServer::script([self::ok(['id' => '1'])]);
        $this->client()->kb->articles->publish('notion:x');

        $request = MockServer::received()[0];
        $this->assertSame('', $request['body']);
        $this->assertArrayNotHasKey('content-type', $request['headers']);
        $this->assertNotEmpty($request['headers']['idempotency-key']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidSuccessBodyProvider(): iterable
    {
        yield 'corpo vazio' => [''];
        yield 'HTML de proxy' => ['<html>proxy</html>'];
        yield 'JSON sem data' => [['ok' => true]];
        yield 'lista JSON' => [[1, 2, 3]];
    }

    #[DataProvider('invalidSuccessBodyProvider')]
    public function testSuccessWithoutEnvelopeIsInvalidResponse(mixed $body): void
    {
        MockServer::script([['status' => 200, 'headers' => [], 'body' => $body]]);

        try {
            $this->client()->products->get('erp-cloud');
            $this->fail('esperava INVALID_RESPONSE');
        } catch (BfocusException $e) {
            $this->assertSame(BfocusException::class, $e::class, 'classe base');
            $this->assertSame('INVALID_RESPONSE', $e->getErrorCode());
            $this->assertSame(200, $e->getStatus());
            $this->assertSame(MockServer::received()[0]['headers']['x-request-id'], $e->getRequestId(), 'id enviado');
        }
    }

    public function testRequestIdPrefersHeaderOverSentId(): void
    {
        MockServer::script([['status' => 404, 'headers' => ['X-Request-Id' => 'req-do-proxy'], 'body' => 'Not Found']]);

        try {
            $this->client()->customers->get('ERP 1');
            $this->fail('esperava erro');
        } catch (BfocusException $e) {
            $this->assertSame('req-do-proxy', $e->getRequestId());
        }
    }

    public function testMaxRetriesZeroDisablesRetry(): void
    {
        MockServer::script([['status' => 503, 'headers' => [], 'body' => 'Service Unavailable'], self::ok([])]);

        try {
            $this->client(['max_retries' => 0])->customers->get('ERP 1');
            $this->fail('esperava ServerException');
        } catch (ServerException $e) {
            $this->assertSame('HTTP_503', $e->getErrorCode());
        }
        $this->assertCount(1, MockServer::received());
        $this->assertSame([], $this->sleeps);
    }

    public function testRetryAfterIsCappedAt60Seconds(): void
    {
        MockServer::script([
            ['status' => 429, 'headers' => ['Retry-After' => '120'], 'body' => ['code' => 429, 'data' => null, 'message' => 'RATE_LIMITED', 'error' => 'RATE_LIMITED']],
            self::ok(['id' => '1']),
        ]);

        $this->client()->customers->get('ERP 1');

        $this->assertSame([60.0], $this->sleeps);
    }

    public function testRetryAfterIsHonoredOnRetryable5xx(): void
    {
        MockServer::script([
            ['status' => 503, 'headers' => ['Retry-After' => '3'], 'body' => 'Service Unavailable'],
            self::ok(['id' => '1']),
        ]);

        $this->client()->customers->get('ERP 1');

        $this->assertSame([3.0], $this->sleeps);
    }

    public function testErrorMessageHasCodeAndRequestId(): void
    {
        MockServer::script([['status' => 422, 'headers' => [], 'body' => [
            'code' => 422, 'data' => null, 'message' => 'Dados inválidos', 'error' => 'VALIDATION_ERROR',
            'validation' => ['email' => 'inválido'], 'request_id' => 'req-1',
        ]]]);

        try {
            $this->client()->customers->upsert('ERP 1', ['email' => 'x']);
            $this->fail('esperava BfocusException');
        } catch (BfocusException $e) {
            $this->assertSame('VALIDATION_ERROR (HTTP 422): email: inválido [request_id=req-1]', $e->getMessage());
        }
    }

    public function testConflictDataCarriesContactOwner(): void
    {
        // 409 acionável: `data` diz de QUEM é o contato (e a API repete em `validation`).
        $dono = [
            'field' => 'email',
            'owner_external_id' => 'app-12',
            'owner_name' => 'Paula Reis',
            'owner_customer_external_id' => 'erp-1042',
        ];
        MockServer::script([
            ['status' => 409, 'headers' => [], 'body' => [
                'code' => 409, 'data' => $dono, 'message' => 'PERSON_EMAIL_TAKEN',
                'error' => 'PERSON_EMAIL_TAKEN', 'validation' => $dono, 'request_id' => 'req-1',
            ]],
            ['status' => 404, 'headers' => [], 'body' => ['code' => 404, 'data' => null, 'error' => 'CUSTOMER_NOT_FOUND']],
        ]);

        $bf = $this->client();
        try {
            $bf->people->upsert('erp-1042', 'app-77', ['email' => 'paula@padaria.example']);
            $this->fail('esperava ConflictException');
        } catch (ConflictException $e) {
            $this->assertSame('PERSON_EMAIL_TAKEN', $e->getErrorCode());
            $this->assertSame($dono, $e->getData());
            $this->assertSame('erp-1042', $e->getData()['owner_customer_external_id']);
            $this->assertSame($dono, $e->getValidation());
        }

        try {
            $bf->customers->get('erp-1042');
            $this->fail('esperava NotFoundException');
        } catch (BfocusException $e) {
            $this->assertSame([], $e->getData());
        }
    }

    public function testUnknownStatusIsBaseException(): void
    {
        MockServer::script([['status' => 418, 'headers' => [], 'body' => ['code' => 418, 'data' => null, 'message' => 'TEAPOT', 'error' => 'TEAPOT']]]);

        try {
            $this->client()->customers->get('ERP 1');
            $this->fail('esperava BfocusException');
        } catch (BfocusException $e) {
            $this->assertSame(BfocusException::class, $e::class);
            $this->assertSame('TEAPOT', $e->getErrorCode());
        }
    }

    public function testNetworkErrorWhenServerIsDown(): void
    {
        $bf = $this->client(['base_url' => 'http://127.0.0.1:' . MockServer::freePort(), 'timeout' => 2]);

        try {
            $bf->customers->get('ERP 1');
            $this->fail('esperava NetworkException');
        } catch (NetworkException $e) {
            $this->assertSame(0, $e->getStatus());
            $this->assertSame('NETWORK_ERROR', $e->getErrorCode());
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $e->getRequestId(), 'o X-Request-Id enviado');
            $this->assertStringContainsString((string) $e->getRequestId(), $e->getMessage());
        }
        $this->assertCount(2, $this->sleeps, 'rede é repetida max_retries vezes');
        $this->assertGreaterThanOrEqual(0.5, $this->sleeps[0]);
        $this->assertLessThanOrEqual(0.625, $this->sleeps[0]);
        $this->assertGreaterThanOrEqual(1.0, $this->sleeps[1]);
        $this->assertLessThanOrEqual(1.25, $this->sleeps[1]);
    }

    public function testTimeoutIsNetworkErrorWithSentRequestId(): void
    {
        MockServer::script([['status' => 200, 'headers' => [], 'body' => ['code' => 200, 'data' => [], 'message' => 'ok'], 'delay_ms' => 1500]]);
        $started = microtime(true);

        try {
            $this->client(['timeout' => 0.3, 'max_retries' => 0])->customers->get('ERP 1');
            $this->fail('esperava NetworkException');
        } catch (NetworkException $e) {
            $this->assertSame('NETWORK_ERROR', $e->getErrorCode());
            $this->assertSame(MockServer::received()[0]['headers']['x-request-id'], $e->getRequestId(), 'mesmo id que chegou ao servidor');
        }
        $this->assertLessThan(1.2, microtime(true) - $started);
        usleep(1_300_000); // deixa o servidor (single-thread) terminar a resposta atrasada
    }

    /** @param array<string, mixed> $options */
    private function client(array $options = []): Bfocus
    {
        $this->sleeps = [];

        return new Bfocus('bf_live_test', $options + [
            'base_url' => MockServer::url(),
            'sleep' => function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        ]);
    }

    /** @return array<string, mixed> */
    private static function ok(array $data): array
    {
        return ['status' => 200, 'headers' => [], 'body' => ['code' => 200, 'data' => $data, 'message' => 'ok']];
    }

    /** @return array<string, mixed> */
    private static function okPage(): array
    {
        return ['status' => 200, 'headers' => [], 'body' => [
            'code' => 200, 'data' => [], 'message' => 'ok',
            'pagination' => ['page' => 1, 'page_size' => 50, 'total' => 0, 'pages' => 0],
        ]];
    }
}
