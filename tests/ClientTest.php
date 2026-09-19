<?php

declare(strict_types=1);

namespace Bfocus\Tests;

use Bfocus\Bfocus;
use Bfocus\Exception\BfocusException;
use Bfocus\Resources\AiAgents;
use Bfocus\Resources\CustomerContacts;
use Bfocus\Resources\CustomerIdentifiers;
use Bfocus\Resources\Customers;
use Bfocus\Resources\Kb;
use Bfocus\Resources\KbArticles;
use Bfocus\Resources\People;
use Bfocus\Resources\PeopleIdentifiers;
use Bfocus\Resources\Products;
use Bfocus\Resources\ReleaseNotes;
use Bfocus\Tests\Support\Cases;
use Bfocus\WidgetIdentity;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function testEmptyApiKeyIsAnArgumentErrorNotBfocusException(): void
    {
        try {
            new Bfocus('');
            $this->fail('esperava InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertNotInstanceOf(BfocusException::class, $e);
        }
    }

    public function testUnknownOptionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Bfocus('bf_live_x', ['baseUrl' => 'http://localhost:8000']);
    }

    public function testInvalidOptionsAreRejected(): void
    {
        foreach ([['timeout' => 0], ['max_retries' => -1], ['sleep' => 'nao-existe'], ['base_url' => '']] as $options) {
            try {
                new Bfocus('bf_live_x', $options);
                $this->fail('esperava InvalidArgumentException para ' . json_encode($options));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testConstructionMakesNoNetworkCallAndExposesResources(): void
    {
        $bf = new Bfocus('bf_live_x', ['base_url' => 'http://127.0.0.1:1/']);

        $this->assertInstanceOf(Customers::class, $bf->customers);
        $this->assertInstanceOf(CustomerContacts::class, $bf->customers->contacts);
        $this->assertInstanceOf(CustomerIdentifiers::class, $bf->customers->identifiers);
        $this->assertInstanceOf(People::class, $bf->people);
        $this->assertInstanceOf(PeopleIdentifiers::class, $bf->people->identifiers);
        $this->assertInstanceOf(Products::class, $bf->products);
        $this->assertInstanceOf(ReleaseNotes::class, $bf->releaseNotes);
        $this->assertInstanceOf(Kb::class, $bf->kb);
        $this->assertInstanceOf(KbArticles::class, $bf->kb->articles);
        $this->assertInstanceOf(AiAgents::class, $bf->aiAgents);
    }

    public function testResourcesAreReadonly(): void
    {
        $bf = new Bfocus('bf_live_x');
        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line */
        $bf->customers = $bf->customers;
    }

    public function testWidgetIdentityMatchesSpec(): void
    {
        $this->assertSame(
            hash_hmac('sha256', 'v1:USR-1:ACME-1', 'bf_whs_x'),
            WidgetIdentity::sign('bf_whs_x', 'USR-1', 'ACME-1'),
        );
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', WidgetIdentity::sign('s', 'u', 'c'));
    }

    public function testWidgetIdentityRejectsEmptySecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WidgetIdentity::sign('', 'USR-1', 'ACME-1');
    }

    public function testSignatureVectors(): void
    {
        foreach (Cases::assoc()['signatures'] as $vector) {
            $this->assertSame(
                $vector['expected'],
                WidgetIdentity::sign($vector['secret'], $vector['user_external_id'], $vector['customer_external_id']),
            );
        }
    }

    public function testWidgetIdentityV2Format(): void
    {
        $this->assertSame(
            'v2.1789000000.' . hash_hmac('sha256', 'v2:1789000000:USR-1:ACME-1', 'bf_whs_x'),
            WidgetIdentity::signV2('bf_whs_x', 'USR-1', 'ACME-1', 1789000000),
        );
        $this->assertMatchesRegularExpression('/^v2\.\d+\.[0-9a-f]{64}$/', WidgetIdentity::signV2('s', 'u', 'c'));
    }

    public function testWidgetIdentityV2DefaultsToNow(): void
    {
        $before = time();
        $signature = WidgetIdentity::signV2('s', 'u', 'c');
        [, $ts] = explode('.', $signature);
        $this->assertEqualsWithDelta($before, (int) $ts, 5);
        $this->assertSame(WidgetIdentity::signV2('s', 'u', 'c', (int) $ts), $signature);
    }

    public function testWidgetIdentityV2AcceptsDateTime(): void
    {
        $this->assertSame(
            WidgetIdentity::signV2('s', 'u', 'c', 1790000123),
            WidgetIdentity::signV2('s', 'u', 'c', new \DateTimeImmutable('2026-09-21T11:15:23.900-03:00')),
            'DateTimeInterface vira segundos unix (fração descartada), independente do fuso',
        );
    }

    public function testWidgetIdentityV2RejectsColonInUser(): void
    {
        try {
            WidgetIdentity::signV2('s', 'app:77', 'erp-1042');
            $this->fail('esperava InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString(':', $e->getMessage());
        }
        $this->assertStringStartsWith('v2.', WidgetIdentity::signV2('s', 'app-77', 'erp-1042', 0));
    }

    public function testWidgetIdentityV2RejectsEmptySecretAndNegativeInstant(): void
    {
        foreach ([['', 1], ['s', -1]] as [$secret, $at]) {
            try {
                WidgetIdentity::signV2($secret, 'u', 'c', $at);
                $this->fail('esperava InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
