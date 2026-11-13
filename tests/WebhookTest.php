<?php

declare(strict_types=1);

namespace AyInbox\Tests;

use AyInbox\Exception\SignatureVerificationException;
use AyInbox\Webhook;

final class WebhookTest extends TestCase
{
    /** @var array{secret: string, timestamp: int, body: string, expected_v1: string, header: string} */
    private array $vector;

    protected function setUp(): void
    {
        $vector = json_decode(self::fixture('webhook_signature.json'), true);
        self::assertIsArray($vector);
        /** @var array{secret: string, timestamp: int, body: string, expected_v1: string, header: string} $vector */
        $this->vector = $vector;
    }

    public function testVectorVerifies(): void
    {
        $v = $this->vector;
        self::assertTrue(Webhook::verifySignature($v['body'], $v['header'], $v['secret'], 300, $v['timestamp']));
        self::assertSame($v['expected_v1'], hash_hmac('sha256', $v['timestamp'] . '.' . $v['body'], $v['secret']));
    }

    public function testTamperedBodyFails(): void
    {
        $v = $this->vector;
        $body = str_replace('campaign.reply_received', 'campaign.reply_receivez', $v['body']);
        self::assertFalse(Webhook::verifySignature($body, $v['header'], $v['secret'], 300, $v['timestamp']));
    }

    public function testWrongSecretFails(): void
    {
        $v = $this->vector;
        self::assertFalse(Webhook::verifySignature($v['body'], $v['header'], 'whsec_wrong', 300, $v['timestamp']));
        self::assertFalse(Webhook::verifySignature($v['body'], $v['header'], 'test_secret', 300, $v['timestamp']));
    }

    public function testToleranceWindow(): void
    {
        $v = $this->vector;
        self::assertTrue(Webhook::verifySignature($v['body'], $v['header'], $v['secret'], 300, $v['timestamp'] + 300));
        self::assertFalse(Webhook::verifySignature($v['body'], $v['header'], $v['secret'], 300, $v['timestamp'] + 301));
        self::assertFalse(Webhook::verifySignature($v['body'], $v['header'], $v['secret'], 300, $v['timestamp'] - 301));
    }

    public function testMalformedHeadersFail(): void
    {
        $v = $this->vector;
        foreach (['', 't=1700000000', 'v1=' . $v['expected_v1'], 'garbage', 't=abc,v1=' . $v['expected_v1']] as $header) {
            self::assertFalse(Webhook::verifySignature($v['body'], $header, $v['secret'], 300, $v['timestamp']));
        }
    }

    public function testAnyMatchingV1PassesAndOtherSchemesAreIgnored(): void
    {
        $v = $this->vector;
        $header = 't=' . $v['timestamp'] . ',v0=abc,v1=' . str_repeat('0', 64) . ',v1=' . $v['expected_v1'];
        self::assertTrue(Webhook::verifySignature($v['body'], $header, $v['secret'], 300, $v['timestamp']));
    }

    public function testConstructEvent(): void
    {
        $v = $this->vector;
        $event = Webhook::constructEvent($v['body'], $v['header'], $v['secret'], 300, $v['timestamp']);
        self::assertSame('campaign.reply_received', $event['event_type']);

        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent($v['body'], $v['header'], 'whsec_wrong', 300, $v['timestamp']);
    }
}
