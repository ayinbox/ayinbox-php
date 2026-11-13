<?php

declare(strict_types=1);

namespace AyInbox;

use AyInbox\Exception\SignatureVerificationException;

/**
 * Verifies the X-Ayinbox-Signature header on webhook deliveries.
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'X-Ayinbox-Signature';
    public const DEFAULT_TOLERANCE = 300;

    /**
     * True when $header signs $payload with $secret within $toleranceSeconds of $now.
     *
     * @param string   $payload The raw request body, exactly as received.
     * @param string   $header  The X-Ayinbox-Signature value: `t=<unix>,v1=<hex>`.
     * @param string   $secret  The endpoint secret, including its `whsec_` prefix.
     * @param int|null $now     Unix seconds; defaults to the current time.
     */
    public static function verifySignature(
        string $payload,
        string $header,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): bool {
        if ($secret === '' || $toleranceSeconds <= 0) {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$name, $value] = $pair;
            if ($name === 't' && preg_match('/^\d+$/', $value) === 1) {
                $timestamp = (int) $value;
            } elseif ($name === 'v1' && $value !== '') {
                $signatures[] = strtolower($value);
            }
        }
        if ($timestamp === null || $signatures === []) {
            return false;
        }

        $now ??= time();
        if (abs($now - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $matched = false;
        foreach ($signatures as $signature) {
            // Compare every candidate so timing does not reveal which one matched.
            if (hash_equals($expected, $signature)) {
                $matched = true;
            }
        }

        return $matched;
    }

    /**
     * Verifies the signature, then decodes the event envelope.
     *
     * @return array<string, mixed> `id`, `event_type`, `organization_id`, `created_at`, `data`.
     *
     * @throws SignatureVerificationException
     */
    public static function constructEvent(
        string $payload,
        string $header,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): array {
        if (!self::verifySignature($payload, $header, $secret, $toleranceSeconds, $now)) {
            throw new SignatureVerificationException('The webhook signature did not verify.');
        }
        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SignatureVerificationException('The webhook body is not valid JSON.', 0, $e);
        }
        if (!is_array($event)) {
            throw new SignatureVerificationException('The webhook body is not a JSON object.');
        }

        /** @var array<string, mixed> $event */
        return $event;
    }
}
