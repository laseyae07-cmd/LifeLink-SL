<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$phone = $argv[1] ?? '';
if ($phone === '') {
    fwrite(STDERR, "Usage: php scripts/test_sms.php 0771234567\n");
    exit(1);
}

try {
    $id = (new SmsService())->sendSms($phone, 'LifeLink SL API test successful.');
    echo "[OK] Text.lk accepted the SMS. Provider ID: {$id}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[FAIL] " . $e->getMessage() . "\n");
    exit(1);
}
