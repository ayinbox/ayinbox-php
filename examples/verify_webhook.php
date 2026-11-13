<?php

// A webhook receiver. Run: AYINBOX_WEBHOOK_SECRET=whsec_... php -S localhost:8000 examples/verify_webhook.php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use AyInbox\Exception\SignatureVerificationException;
use AyInbox\Webhook;

$secret = (string) getenv('AYINBOX_WEBHOOK_SECRET');
$payload = (string) file_get_contents('php://input');
$header = $_SERVER['HTTP_X_AYINBOX_SIGNATURE'] ?? '';

try {
    $event = Webhook::constructEvent($payload, $header, $secret);
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

// Deduplicate on $event['id'] (equal to X-Ayinbox-Event-Id): retries reuse it.
error_log(sprintf('AY Inbox event %s: %s', $event['id'], $event['event_type']));
http_response_code(200);
