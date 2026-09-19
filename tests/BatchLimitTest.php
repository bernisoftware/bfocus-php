<?php

declare(strict_types=1);

namespace Bfocus\Tests;

use Bfocus\Bfocus;
use Bfocus\Exception\BfocusException;
use Bfocus\Resources\Customers;
use Bfocus\Resources\People;
use Bfocus\Tests\Support\MockServer;
use PHPUnit\Framework\TestCase;

/**
 * `customers->batch` / `people->batch`: até 500 por chamada, SEM dividir; acima disso erro de
 * argumento antes de qualquer requisição.
 */
final class BatchLimitTest extends TestCase
{
    public function testBatchMaxIs500(): void
    {
        $this->assertSame(500, Customers::BATCH_MAX);
        $this->assertSame(500, People::BATCH_MAX);
    }

    public function testCustomers501IsArgumentErrorWithoutRequest(): void
    {
        MockServer::script([self::okResponse(501)]);
        try {
            self::client()->customers->batch(self::customers(501));
            $this->fail('esperava InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertNotInstanceOf(BfocusException::class, $e);
            $this->assertStringContainsString('customers->batch aceita até 500 itens por chamada (recebeu 501)', $e->getMessage());
        }
        $this->assertSame([], MockServer::received());
    }

    public function testPeople501IsArgumentErrorWithoutRequest(): void
    {
        MockServer::script([self::okResponse(501)]);
        try {
            self::client()->people->batch(self::people(501));
            $this->fail('esperava InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('people->batch aceita até 500 itens por chamada (recebeu 501)', $e->getMessage());
        }
        $this->assertSame([], MockServer::received());
    }

    public function testCustomers500IsOneRequest(): void
    {
        MockServer::script([self::okResponse(500)]);
        $out = self::client()->customers->batch(self::customers(500), ['idempotency_key' => 'carga-1']);

        $received = MockServer::received();
        $this->assertCount(1, $received);
        $this->assertSame('POST', $received[0]['method']);
        $this->assertSame('/api/v1/integration/customers/batch', $received[0]['uri']);
        $this->assertSame('carga-1', $received[0]['headers']['idempotency-key']);
        $body = json_decode($received[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::customers(500), $body['items']);
        $this->assertSame(500, $out['summary']['created']);
        $this->assertCount(500, $out['results']);
    }

    public function testPeople500IsOneRequestAndNestsThePerson(): void
    {
        MockServer::script([self::okResponse(500)]);
        // aceita qualquer iterable
        $generator = (static function (): \Generator {
            yield from self::people(500);
        })();
        self::client()->people->batch($generator);

        $received = MockServer::received();
        $this->assertCount(1, $received);
        $this->assertSame('/api/v1/integration/people/batch', $received[0]['uri']);
        $body = json_decode($received[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(500, $body['items']);
        $this->assertSame(
            ['customer_external_id' => 'erp-1', 'person' => ['external_id' => 'app-1', 'name' => 'Pessoa 1', 'phone' => null]],
            $body['items'][0],
        );
    }

    public function testEmptyMakesNoRequest(): void
    {
        MockServer::script([]);
        $zero = ['results' => [], 'summary' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'error' => 0]];

        $this->assertSame($zero, self::client()->customers->batch([]));
        $this->assertSame($zero, self::client()->people->batch([]));
        $this->assertSame([], MockServer::received());
    }

    public function testInvalidItemsAreRejectedBeforeAnyRequest(): void
    {
        $bad = [
            'customers sem external_id' => static fn (Bfocus $bf) => $bf->customers->batch([['external_id' => 'a'], ['name' => 'x']]),
            'customers chave desconhecida' => static fn (Bfocus $bf) => $bf->customers->batch([['external_id' => 'a', 'nome' => 'x']]),
            'people sem customer_external_id' => static fn (Bfocus $bf) => $bf->people->batch([['external_id' => 'app-1']]),
            'people sem external_id' => static fn (Bfocus $bf) => $bf->people->batch([['customer_external_id' => 'erp-1']]),
            'people chave desconhecida' => static fn (Bfocus $bf) => $bf->people->batch([['customer_external_id' => 'erp-1', 'external_id' => 'p', 'nome' => 'x']]),
            'item não array' => static fn (Bfocus $bf) => $bf->people->batch(['x']),
        ];
        foreach ($bad as $name => $call) {
            MockServer::script([self::okResponse(1)]);
            try {
                $call(self::client());
                $this->fail("esperava InvalidArgumentException: $name");
            } catch (\InvalidArgumentException) {
                $this->assertSame([], MockServer::received(), $name);
            }
        }
    }

    public function testPeopleUpsertWithoutFieldsSendsEmptyObject(): void
    {
        MockServer::script([['status' => 200, 'headers' => [], 'body' => ['code' => 200, 'data' => ['status' => 'unchanged'], 'message' => 'ok']]]);
        self::client()->people->upsert('erp-1', 'app-1');

        $this->assertSame('{"person":{}}', MockServer::received()[0]['body']);
    }

    private static function client(): Bfocus
    {
        return new Bfocus('bf_live_test', ['base_url' => MockServer::url(), 'sleep' => static function (float $s): void {
        }]);
    }

    /** @return list<array<string, mixed>> */
    private static function customers(int $count): array
    {
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $items[] = ['external_id' => "erp-$i", 'name' => "Cliente $i", 'document' => null];
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private static function people(int $count): array
    {
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $items[] = ['customer_external_id' => "erp-$i", 'external_id' => "app-$i", 'name' => "Pessoa $i", 'phone' => null];
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private static function okResponse(int $count): array
    {
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $results[] = ['index' => $i, 'status' => 'created', 'external_id' => "x$i", 'merged_into' => null, 'error' => null, 'code' => null];
        }

        return ['status' => 200, 'headers' => [], 'body' => ['code' => 200, 'data' => [
            'results' => $results,
            'summary' => ['created' => $count, 'updated' => 0, 'unchanged' => 0, 'error' => 0],
        ], 'message' => 'ok']];
    }
}
