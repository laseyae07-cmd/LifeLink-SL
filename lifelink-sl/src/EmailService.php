<?php

declare(strict_types=1);

final class EmailService
{
    public function send(string $toEmail, string $toName, string $subject, string $html): string
    {
        $apiKey = (string)config('brevo','api_key');
        $senderEmail = (string)config('brevo','sender_email');
        if ($apiKey === '' || $senderEmail === '') {
            throw new RuntimeException('Brevo is not configured. Add BREVO_API_KEY and BREVO_SENDER_EMAIL to .env.');
        }
        $payload = [
            'sender' => ['name'=>(string)config('brevo','sender_name'), 'email'=>$senderEmail],
            'to' => [['email'=>$toEmail, 'name'=>$toName]],
            'subject' => $subject,
            'htmlContent' => $html,
            'tags' => ['lifelink-transactional'],
        ];
        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['accept: application/json','content-type: application/json','api-key: '.$apiKey],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Brevo send failed: '.($error ?: 'HTTP '.$status));
        }
        $json = json_decode((string)$body, true);
        return (string)($json['messageId'] ?? 'submitted');
    }

    public static function template(string $heading, string $bodyHtml, ?string $buttonText = null, ?string $buttonUrl = null): string
    {
        $button = ($buttonText && $buttonUrl)
            ? '<p style="margin:28px 0"><a href="'.e($buttonUrl).'" style="background:#b4232f;color:white;text-decoration:none;padding:13px 20px;border-radius:8px;display:inline-block">'.e($buttonText).'</a></p>'
            : '';
        return '<!doctype html><html><body style="font-family:Arial,sans-serif;background:#f6f7f9;padding:24px;color:#20242a"><div style="max-width:620px;margin:auto;background:white;border-radius:12px;padding:28px"><div style="font-size:24px;font-weight:700;color:#a61f2b">LifeLink SL</div><h2>'.e($heading).'</h2>'.$bodyHtml.$button.'<p style="font-size:12px;color:#6b7280;margin-top:28px">This is a transactional message from LifeLink SL.</p></div></body></html>';
    }
}
