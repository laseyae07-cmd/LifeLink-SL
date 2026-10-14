<?php

declare(strict_types=1);

final class SmsService
{
    private function request(array $payload): array
    {
        $apiToken = (string) config('textlk', 'api_token');
        if ($apiToken === '') {
            throw new RuntimeException('Text.lk is not configured. Add TEXTLK_API_TOKEN to .env.');
        }

        $ch = curl_init('https://app.text.lk/api/v3/sms/send');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 20,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Text.lk request failed: ' . ($error ?: 'HTTP ' . $status));
        }

        $json = json_decode((string) $body, true);
        $ok = is_array($json) && (($json['status'] ?? false) === true || ($json['status'] ?? '') === 'success');
        if (!$ok) {
            $message = is_array($json) ? (string) ($json['message'] ?? 'Unknown provider error') : 'Invalid provider response';
            throw new RuntimeException('Text.lk returned an unsuccessful response: ' . $message);
        }

        return $json;
    }

    public function sendSms(string $phone, string $message): string
    {
        $normalized = normalize_sl_phone($phone);
        if (!$normalized) {
            throw new InvalidArgumentException('Invalid Sri Lankan mobile number.');
        }

        $senderId = (string) config('textlk', 'sender_id');
        if ($senderId === '') {
            throw new RuntimeException('TEXTLK_SENDER_ID is not configured.');
        }

        $json = $this->request([
            'recipient' => $normalized,
            'sender_id' => $senderId,
            'type' => 'plain',
            'message' => $message,
        ]);

        return (string) ($json['data']['sms_id'] ?? $json['data']['uid'] ?? $json['data']['id'] ?? 'submitted');
    }

    /**
     * OTPs are delivered by the configured sender in production. During local
     * development only, ALLOW_DEV_OTP=true allows a test OTP to be generated
     * without sending it through TextLKDemo. The plaintext OTP is never stored
     * in the database; only its SHA-256 hash is stored by VerificationService.
     */
    public function sendOtp(string $phone): array
    {
        $otp = (string) random_int(100000, 999999);
        $senderId = (string) config('textlk', 'sender_id');
        $isLocal = (string) config('app', 'env') === 'local';
        $allowDevOtp = (bool) config('textlk', 'allow_dev_otp');

        if (strcasecmp($senderId, 'TextLKDemo') === 0) {
            if (!$isLocal || !$allowDevOtp) {
                throw new RuntimeException('TextLKDemo cannot be used for phone-verification OTPs. For local development set ALLOW_DEV_OTP=true, or use an approved sender ID for real OTP delivery.');
            }
            return [
                'referenceId' => 'dev-' . substr(random_token(8), 0, 16),
                'otpHash' => token_hash($otp),
                'debugOtp' => $otp,
                'delivery' => 'local',
            ];
        }

        $minutes = (int) config('ttl', 'phone_otp_minutes');
        $message = sprintf('LifeLink SL verification code: %s. Expires in %d minutes. Do not share this code.', $otp, $minutes);
        $messageId = $this->sendSms($phone, $message);

        return [
            'referenceId' => $messageId,
            'otpHash' => token_hash($otp),
            'delivery' => 'sms',
        ];
    }
}
