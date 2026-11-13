<?php

declare(strict_types=1);

namespace AyInbox\Tests;

use AyInbox\Exception;
use AyInbox\Exception\ApiException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;

final class ErrorsTest extends TestCase
{
    /**
     * @return array<string, array{string, class-string<ApiException>, string}>
     */
    public static function envelopes(): array
    {
        return [
            '400' => ['400_bad_request.json', Exception\BadRequestException::class, 'bad_request'],
            '400 cursor' => ['400_invalid_cursor.json', Exception\BadRequestException::class, 'bad_request'],
            '401' => ['401_unauthorized.json', Exception\AuthenticationException::class, 'unauthorized'],
            '401 holder left' => ['401_api_key_holder_left.json', Exception\AuthenticationException::class, 'api_key_holder_left'],
            '402' => ['402_insufficient_credits.json', Exception\PaymentRequiredException::class, 'insufficient_credits'],
            '403' => ['403_forbidden.json', Exception\PermissionDeniedException::class, 'forbidden'],
            '403 mailbox limited' => ['403_api_key_mailbox_limited.json', Exception\PermissionDeniedException::class, 'api_key_mailbox_limited'],
            '404' => ['404_not_found.json', Exception\NotFoundException::class, 'not_found'],
            '409' => ['409_conflict.json', Exception\ConflictException::class, 'conflict'],
            '409 outcome unknown' => ['409_idempotency_outcome_unknown.json', Exception\ConflictException::class, 'idempotency_outcome_unknown'],
            '409 secret withheld' => ['409_idempotent_secret_withheld.json', Exception\ConflictException::class, 'idempotent_secret_withheld'],
            '422' => ['422_unprocessable.json', Exception\UnprocessableException::class, 'unprocessable'],
            '500' => ['500_internal_error.json', Exception\InternalServerException::class, 'internal_error'],
        ];
    }

    /**
     * @param class-string<ApiException> $class
     */
    #[DataProvider('envelopes')]
    public function testMapsEnvelopeToTypedException(string $fixture, string $class, string $code): void
    {
        $client = $this->client([self::error($fixture)], 0);
        try {
            $client->campaigns->get('11111111-1111-4111-8111-111111111111');
            self::fail('expected an exception');
        } catch (ApiException $e) {
            self::assertInstanceOf($class, $e);
            self::assertSame((int) substr($fixture, 0, 3), $e->getStatus());
            self::assertSame($code, $e->getErrorCode());
            self::assertSame('req-fixture-0001', $e->getRequestId());
            self::assertNotSame('', $e->getMessage());
            self::assertIsArray($e->getBody());
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function testValidationParentCoversBothStatuses(): void
    {
        self::assertTrue(is_subclass_of(Exception\BadRequestException::class, Exception\ValidationException::class));
        self::assertTrue(is_subclass_of(Exception\UnprocessableException::class, Exception\ValidationException::class));
    }

    public function testApiKeyRateLimitCarriesRetryAfter(): void
    {
        $client = $this->client([self::error('429_api_key_rate_limited.json')], 0);
        try {
            $client->me->get();
            self::fail('expected an exception');
        } catch (Exception\RateLimitException $e) {
            self::assertSame('rate_limit_exceeded', $e->getErrorCode());
            self::assertSame(12, $e->getRetryAfter());
            self::assertSame('req-fixture-0001', $e->getRequestId());
        }
    }

    public function testCategoryRateLimitWithoutCodeOrRequestId(): void
    {
        $client = $this->client([self::error('429_category_rate_limited.json')], 0);
        try {
            $client->me->get();
            self::fail('expected an exception');
        } catch (Exception\RateLimitException $e) {
            self::assertSame('rate_limit_exceeded', $e->getErrorCode());
            self::assertNull($e->getRequestId());
            self::assertSame(12, $e->getRetryAfter());
            self::assertSame('Rate limit exceeded for write operations', $e->getMessage());
        }
    }

    public function testRetryAfterFromMillisecondsWhenNoHeader(): void
    {
        $client = $this->client([new Response(429, [], '{"error":"rate_limit_exceeded","message":"slow down","retry_after_ms":1500}')], 0);
        try {
            $client->me->get();
            self::fail('expected an exception');
        } catch (Exception\RateLimitException $e) {
            self::assertSame(2, $e->getRetryAfter());
        }
    }

    public function testServiceUnavailable(): void
    {
        $client = $this->client([self::error('503_service_unavailable.json')], 0);
        try {
            $client->me->get();
            self::fail('expected an exception');
        } catch (Exception\ServiceUnavailableException $e) {
            self::assertSame('service_unavailable', $e->getErrorCode());
            self::assertSame(5, $e->getRetryAfter());
        }
    }

    public function testNonJsonErrorKeepsRawTextAndDefaultCode(): void
    {
        $client = $this->client([new Response(502, ['X-Request-Id' => 'edge-1'], 'Bad Gateway')], 0);
        try {
            $client->me->get();
            self::fail('expected an exception');
        } catch (Exception\InternalServerException $e) {
            self::assertSame(502, $e->getStatus());
            self::assertSame('internal_error', $e->getErrorCode());
            self::assertSame('Bad Gateway', $e->getBody());
            self::assertSame('edge-1', $e->getRequestId());
        }
    }
}
