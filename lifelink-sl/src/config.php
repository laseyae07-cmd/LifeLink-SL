<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => Env::get('APP_NAME', 'LifeLink SL'),
        'env' => Env::get('APP_ENV', 'local'),
        'url' => rtrim((string) Env::get('APP_URL', 'http://localhost/lifelink-sl/public'), '/'),
        'timezone' => Env::get('APP_TIMEZONE', 'Asia/Colombo'),
        'key' => Env::get('APP_KEY', ''),
        'session_timeout_minutes' => (int) Env::get('SESSION_TIMEOUT_MINUTES', '20'),
    ],
    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => Env::get('DB_PORT', '3306'),
        'name' => Env::get('DB_NAME', 'lifelink_sl'),
        'user' => Env::get('DB_USER', 'root'),
        'pass' => Env::get('DB_PASS', ''),
    ],
    'brevo' => [
        'api_key' => Env::get('BREVO_API_KEY', ''),
        'sender_email' => Env::get('BREVO_SENDER_EMAIL', ''),
        'sender_name' => Env::get('BREVO_SENDER_NAME', 'LifeLink SL'),
        'webhook_token' => Env::get('BREVO_WEBHOOK_TOKEN', ''),
    ],
    'textlk' => [
        'api_token' => Env::get('TEXTLK_API_TOKEN', ''),
        'sender_id' => Env::get('TEXTLK_SENDER_ID', 'TextLKDemo'),
        'allow_dev_otp' => strtolower((string) Env::get('ALLOW_DEV_OTP', 'true')) === 'true',
    ],
    'ttl' => [
        'email_verify_minutes' => (int) Env::get('EMAIL_VERIFY_TTL_MINUTES', '1440'),
        'password_reset_minutes' => (int) Env::get('PASSWORD_RESET_TTL_MINUTES', '60'),
        'phone_otp_minutes' => (int) Env::get('PHONE_OTP_TTL_MINUTES', '10'),
        'mfa_otp_minutes' => (int) Env::get('MFA_OTP_TTL_MINUTES', '10'),
        'invite_minutes' => (int) Env::get('EMERGENCY_INVITE_TTL_MINUTES', '120'),
        'qr_days' => (int) Env::get('QR_TTL_DAYS', '90'),
    ],
];
