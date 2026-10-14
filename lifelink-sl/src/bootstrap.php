<?php

declare(strict_types=1);

require_once __DIR__ . '/Env.php';
Env::load(dirname(__DIR__) . '/.env');
$config = require __DIR__ . '/config.php';
date_default_timezone_set((string)$config['app']['timezone']);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/EmailService.php';
require_once __DIR__ . '/SmsService.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/VerificationService.php';
require_once __DIR__ . '/MfaService.php';
require_once __DIR__ . '/EmergencyService.php';
require_once __DIR__ . '/QrService.php';

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('lifelink_session');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    session_start();
}

$timeoutSeconds = max(300, (int)config('app','session_timeout_minutes') * 60);
if (!empty($_SESSION['user_id'])) {
    if (!empty($_SESSION['_last_activity']) && (time() - (int)$_SESSION['_last_activity']) > $timeoutSeconds) {
        $oldId = (int)$_SESSION['user_id'];
        try { audit('session_timeout','user',$oldId); } catch (Throwable) {}
        unset($_SESSION['user_id']);
        session_regenerate_id(true);
        $_SESSION['_flash'][] = ['type'=>'info','message'=>'Your session expired after inactivity. Please sign in again.'];
    } else {
        $_SESSION['_last_activity'] = time();
    }
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(self), geolocation=(), microphone=()');
if ($secure) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
