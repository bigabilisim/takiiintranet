<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

$result = $leaveStore->sendDueLeaveBookSignatureFollowups();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit(((int) ($result['failed'] ?? 0)) > 0 ? 1 : 0);
