<?php

declare(strict_types=1);

namespace AyInbox\Exception;

/**
 * The API answered with a non-2xx status.
 */
class ApiException extends AyInboxException
{
    private const DEFAULT_CODES = [
        400 => 'bad_request',
        401 => 'unauthorized',
        402 => 'insufficient_credits',
        403 => 'forbidden',
        404 => 'not_found',
        409 => 'conflict',
        422 => 'unprocessable',
        429 => 'rate_limit_exceeded',
        500 => 'internal_error',
        501 => 'not_implemented',
        503 => 'service_unavailable',
    ];

    /**
     * @param array<string, array<int, string>> $headers
     */
    final public function __construct(
        string $message,
        private readonly int $status,
        private readonly string $errorCode,
        private readonly ?string $reason,
        private readonly ?string $requestId,
        private readonly mixed $body,
        private readonly array $headers,
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $status);
    }

    /**
     * @param array<string, array<int, string>> $headers
     */
    public static function fromResponse(int $status, mixed $body, array $headers): self
    {
        $data = is_array($body) ? $body : [];

        $code = self::str($data['code'] ?? null);
        if ($code === null) {
            $error = self::str($data['error'] ?? null);
            $code = $error !== null && preg_match('/^[a-z0-9_]+$/', $error) === 1
                ? $error
                : (self::DEFAULT_CODES[$status] ?? ($status >= 500 ? 'internal_error' : 'error'));
        }

        $requestId = self::str($data['request_id'] ?? null) ?? self::header($headers, 'X-Request-Id');
        $message = self::str($data['message'] ?? null)
            ?? (is_string($body) && $body !== '' ? $body : 'AY Inbox answered HTTP ' . $status . '.');

        $class = match (true) {
            $status === 400 => BadRequestException::class,
            $status === 401 => AuthenticationException::class,
            $status === 402 => PaymentRequiredException::class,
            $status === 403 => PermissionDeniedException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 422 => UnprocessableException::class,
            $status === 429 => RateLimitException::class,
            $status === 503 => ServiceUnavailableException::class,
            $status >= 500 => InternalServerException::class,
            default => self::class,
        };

        return new $class(
            $message,
            $status,
            $code,
            self::str($data['reason'] ?? null),
            $requestId,
            $body,
            $headers,
            self::retryAfter($headers, $data),
        );
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** The API's stable machine key, for example `not_found`. */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /** The decoded response body (array), or the raw text when it was not JSON. */
    public function getBody(): mixed
    {
        return $this->body;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** Seconds to wait before retrying, when the server said. */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * @param array<string, array<int, string>> $headers
     * @param array<mixed>                      $data
     */
    private static function retryAfter(array $headers, array $data): ?int
    {
        $header = self::header($headers, 'Retry-After');
        if ($header !== null) {
            if (is_numeric($header)) {
                return max(0, (int) ceil((float) $header));
            }
            $when = strtotime($header);
            if ($when !== false) {
                return max(0, $when - time());
            }
        }
        if (isset($data['retry_after']) && is_numeric($data['retry_after'])) {
            return max(0, (int) ceil((float) $data['retry_after']));
        }
        if (isset($data['retry_after_ms']) && is_numeric($data['retry_after_ms'])) {
            return max(0, (int) ceil(((float) $data['retry_after_ms']) / 1000));
        }

        return null;
    }

    /**
     * @param array<string, array<int, string>> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $values) {
            if (strcasecmp((string) $key, $name) === 0 && isset($values[0]) && $values[0] !== '') {
                return $values[0];
            }
        }

        return null;
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
