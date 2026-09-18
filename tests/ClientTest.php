<?php

declare(strict_types=1);

namespace Bfocus\Tests;

use Bfocus\Bfocus;
use Bfocus\Exception\BfocusException;
use Bfocus\Resources\AiAgents;
use Bfocus\Resources\CustomerContacts;
use Bfocus\Resources\Customers;
use Bfocus\Resources\Kb;
use Bfocus\Resources\KbArticles;
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
}
