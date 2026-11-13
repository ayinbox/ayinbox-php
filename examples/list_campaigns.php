<?php

// Lists every campaign in the workspace. Run: AYINBOX_API_KEY=aybox_... php examples/list_campaigns.php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use AyInbox\AyInbox;
use AyInbox\Exception\ApiException;

$client = new AyInbox();

try {
    foreach ($client->campaigns->iterate(['limit' => 100]) as $campaign) {
        printf("%s  %-10s  %s\n", $campaign['id'], $campaign['status'], $campaign['name']);
    }
} catch (ApiException $e) {
    fwrite(STDERR, sprintf("%d %s: %s (request %s)\n", $e->getStatus(), $e->getErrorCode(), $e->getMessage(), $e->getRequestId() ?? '-'));
    exit(1);
}
