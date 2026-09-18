<?php

declare(strict_types=1);

namespace Bfocus\Tests;

use Bfocus\Bfocus;
use Bfocus\Page;
use Bfocus\Tests\Support\MockServer;
use PHPUnit\Framework\TestCase;

final class ListAllTest extends TestCase
{
    public function testWalksEveryPageInOrder(): void
    {
        MockServer::script([
            self::page([['external_id' => 'a'], ['external_id' => 'b']], 1, 2, 5, 3),
            self::page([['external_id' => 'c'], ['external_id' => 'd']], 2, 2, 5, 3),
            self::page([['external_id' => 'e']], 3, 2, 5, 3),
        ]);

        $items = iterator_to_array(self::client()->kb->articles->listAll(['product' => 'erp-cloud', 'page_size' => 2]), false);

        $this->assertSame(['a', 'b', 'c', 'd', 'e'], array_column($items, 'external_id'));
        $uris = array_column(MockServer::received(), 'uri');
        $this->assertSame([
            '/api/v1/integration/kb/articles?page=1&product=erp-cloud&page_size=2',
            '/api/v1/integration/kb/articles?page=2&product=erp-cloud&page_size=2',
            '/api/v1/integration/kb/articles?page=3&product=erp-cloud&page_size=2',
        ], $uris);
    }

    public function testDefaultPageSizeIs100(): void
    {
        MockServer::script([self::page([['id' => '1']], 1, 100, 1, 1)]);

        $items = iterator_to_array(self::client()->customers->interactions->listAll('ERP 1042'), false);

        $this->assertCount(1, $items);
        $this->assertSame('/api/v1/integration/customers/ERP%201042/interactions?page=1&page_size=100', MockServer::received()[0]['uri']);
    }

    public function testStopsOnEmptyPage(): void
    {
        MockServer::script([self::page([], 1, 100, 0, 0)]);

        $this->assertSame([], iterator_to_array(self::client()->releaseNotes->listAll('erp-cloud', ['published' => true]), false));
        $this->assertCount(1, MockServer::received());
        $this->assertStringContainsString('published=true', MockServer::received()[0]['uri']);
    }

    public function testIsLazyUntilIterated(): void
    {
        MockServer::script([]);
        $iterator = self::client()->customers->listAll();

        $this->assertInstanceOf(\Generator::class, $iterator);
        $this->assertSame([], MockServer::received(), 'nenhuma requisição antes de iterar');
    }

    public function testPageParamIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::client()->customers->listAll(['page' => 2]);
    }

    public function testPageObject(): void
    {
        MockServer::script([self::page([['id' => '1'], ['id' => '2']], 2, 2, 5, 3)]);

        $page = self::client()->customers->list(['page' => 2, 'page_size' => 2]);

        $this->assertInstanceOf(Page::class, $page);
        $this->assertCount(2, $page);
        $this->assertSame(['1', '2'], array_column(iterator_to_array($page), 'id'));
        $this->assertSame([2, 2, 5, 3], [$page->page, $page->pageSize, $page->total, $page->pages]);
        $this->assertTrue($page->hasNextPage());
        $this->assertSame(['items', 'page', 'page_size', 'total', 'pages'], array_keys($page->toArray()));
    }

    private static function client(): Bfocus
    {
        return new Bfocus('bf_live_test', ['base_url' => MockServer::url()]);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private static function page(array $items, int $page, int $pageSize, int $total, int $pages): array
    {
        return ['status' => 200, 'headers' => [], 'body' => [
            'code' => 200,
            'data' => $items,
            'message' => 'ok',
            'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total, 'pages' => $pages],
        ]];
    }
}
