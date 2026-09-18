<?php

declare(strict_types=1);

namespace Bfocus\Tests;

use Bfocus\Bfocus;
use Bfocus\Resources\KbArticles;
use Bfocus\Tests\Support\MockServer;
use PHPUnit\Framework\TestCase;

final class BatchUpsertTest extends TestCase
{
    public function testSplitsIntoChunksOf100AndAggregatesInOrder(): void
    {
        $articles = self::articles(250);
        $responses = [];
        foreach (array_chunk($articles, 100) as $n => $chunk) {
            $results = [];
            $counters = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];
            foreach ($chunk as $j => $article) {
                $ok = !($n === 2 && $j === 0); // um item do 3º lote falha
                $action = $ok ? ($n === 1 ? 'updated' : 'created') : null;
                $results[] = [
                    'external_id' => $article['external_id'],
                    'ok' => $ok,
                    'action' => $action,
                    'error' => $ok ? null : 'KB_ARTICLE_TITLE_REQUIRED',
                    'article' => null,
                ];
                $counters[$action ?? 'failed']++;
            }
            $responses[] = ['status' => 200, 'headers' => [], 'body' => ['code' => 200, 'data' => ['results' => $results] + $counters, 'message' => 'ok']];
        }
        MockServer::script($responses);

        // aceita qualquer iterable (ex.: gerador lendo arquivos)
        $generator = (static function () use ($articles): \Generator {
            yield from $articles;
        })();
        $out = self::client()->kb->articles->batchUpsert($generator);

        $received = MockServer::received();
        $this->assertCount(3, $received);
        $sent = [];
        $keys = [];
        foreach ($received as $request) {
            $this->assertSame('POST', $request['method']);
            $this->assertSame('/api/v1/integration/kb/articles/batch', $request['uri']);
            $body = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);
            $sent[] = $body['articles'];
            $keys[] = $request['headers']['idempotency-key'];
        }
        $this->assertSame([100, 100, 50], array_map('count', $sent));
        $this->assertSame($articles, array_merge(...$sent), 'artigos enviados na ordem, sem campos extras');
        $this->assertCount(3, array_unique($keys), 'cada lote é uma chamada com a sua Idempotency-Key');

        $this->assertSame(149, $out['created']);
        $this->assertSame(100, $out['updated']);
        $this->assertSame(0, $out['unchanged']);
        $this->assertSame(1, $out['failed']);
        $this->assertCount(250, $out['results']);
        $this->assertSame(array_column($articles, 'external_id'), array_column($out['results'], 'external_id'));
        $this->assertSame('KB_ARTICLE_TITLE_REQUIRED', $out['results'][200]['error']);
    }

    public function testUserIdempotencyKeyFirstChunkAsIsThenSuffixed(): void
    {
        MockServer::script([self::okResponse(100), self::okResponse(100), self::okResponse(50)]);
        self::client()->kb->articles->batchUpsert(self::articles(250), ['idempotency_key' => 'sync-42']);

        // BRIEF §10.6: 1º lote com a chave como veio; seguintes "<chave>:<n>", n = 2, 3…
        $this->assertSame(['sync-42', 'sync-42:2', 'sync-42:3'], array_map(
            static fn (array $r): string => $r['headers']['idempotency-key'],
            MockServer::received(),
        ));
    }

    public function testSingleChunkKeepsUserIdempotencyKey(): void
    {
        MockServer::script([self::okResponse(3)]);
        self::client()->kb->articles->batchUpsert(self::articles(3), ['idempotency_key' => 'sync-42']);

        $this->assertSame('sync-42', MockServer::received()[0]['headers']['idempotency-key']);
    }

    public function testExactly100IsOneRequest(): void
    {
        MockServer::script([self::okResponse(KbArticles::BATCH_SIZE)]);
        $out = self::client()->kb->articles->batchUpsert(self::articles(100));

        $this->assertCount(1, MockServer::received());
        $this->assertSame(100, $out['created']);
    }

    public function testEmptyInputMakesNoRequest(): void
    {
        MockServer::script([]);
        $out = self::client()->kb->articles->batchUpsert([]);

        $this->assertSame(['results' => [], 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0], $out);
        $this->assertSame([], MockServer::received());
    }

    public function testItemWithoutExternalIdIsRejectedBeforeAnyRequest(): void
    {
        MockServer::script([self::okResponse(100)]);
        $articles = self::articles(150);
        unset($articles[120]['external_id']);

        try {
            self::client()->kb->articles->batchUpsert($articles);
            $this->fail('esperava InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('#120', $e->getMessage());
        }
        $this->assertSame([], MockServer::received(), 'nenhum lote sai se algum item é inválido');
    }

    public function testExternalIdWithSlashIsRejected(): void
    {
        MockServer::script([]);
        $this->expectException(\InvalidArgumentException::class);
        self::client()->kb->articles->batchUpsert([['external_id' => 'git:docs/guia.md', 'title' => 'x']]);
    }

    public function testUnknownItemKeyIsRejected(): void
    {
        MockServer::script([]);
        $this->expectException(\InvalidArgumentException::class);
        self::client()->kb->articles->batchUpsert([['external_id' => 'a', 'titulo' => 'x']]);
    }

    public function testNullProductIsSentExplicitly(): void
    {
        MockServer::script([self::okResponse(1)]);
        self::client()->kb->articles->batchUpsert([['external_id' => 'a', 'product' => null]]);

        $body = json_decode(MockServer::received()[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([['external_id' => 'a', 'product' => null]], $body['articles']);
    }

    private static function client(): Bfocus
    {
        return new Bfocus('bf_live_test', ['base_url' => MockServer::url(), 'sleep' => static function (float $s): void {
        }]);
    }

    /** @return list<array{external_id: string, title: string, body_markdown: string, status: string}> */
    private static function articles(int $count): array
    {
        $articles = [];
        for ($i = 1; $i <= $count; $i++) {
            $articles[] = ['external_id' => "git:doc-$i", 'title' => "Doc $i", 'body_markdown' => "# Doc $i", 'status' => 'published'];
        }

        return $articles;
    }

    /** @return array<string, mixed> */
    private static function okResponse(int $count): array
    {
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $results[] = ['external_id' => "x$i", 'ok' => true, 'action' => 'created', 'error' => null, 'article' => null];
        }

        return ['status' => 200, 'headers' => [], 'body' => ['code' => 200, 'data' => [
            'results' => $results, 'created' => $count, 'updated' => 0, 'unchanged' => 0, 'failed' => 0,
        ], 'message' => 'ok']];
    }
}
