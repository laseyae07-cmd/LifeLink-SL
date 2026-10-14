<?php

declare(strict_types=1);

final class NotificationService
{
    public function log(int $userId, string $channel, string $type, ?int $inviteId, ?string $providerId, string $status, string $excerpt, ?string $error = null): void
    {
        $stmt = db()->prepare('INSERT INTO notification_logs(emergency_invite_id,user_id,channel,notification_type,provider_message_id,delivery_status,payload_excerpt,error_message) VALUES(?,?,?,?,?,?,?,?)');
        $stmt->execute([$inviteId,$userId,$channel,$type,$providerId,$status,substr($excerpt,0,255),$error ? substr($error,0,255) : null]);
    }

    public function sendEmergency(array $donor, array $emergency, array $hospital, int $inviteId, string $rawToken): void
    {
        $prefStmt = db()->prepare('SELECT * FROM donor_notification_preferences WHERE donor_id=? LIMIT 1');
        $prefStmt->execute([(int)$donor['id']]);
        $pref = $prefStmt->fetch() ?: ['sms_alerts'=>1,'email_alerts'=>1];
        $responseUrl = url('respond.php?token=' . urlencode($rawToken));
        $smsText = sprintf('LifeLink SL: %s blood urgently needed at %s, %s. Respond: %s', $emergency['blood_group'], $hospital['name'], $emergency['district'], $responseUrl);
        $isLocal = (string)config('app','env') === 'local';

        if (!empty($pref['sms_alerts'])) {
            try {
                if ($isLocal && ((string)config('textlk','api_token') === '' || strcasecmp((string)config('textlk','sender_id'),'TextLKDemo')===0)) {
                    $this->log((int)$donor['id'],'sms','emergency_alert',$inviteId,'local','simulated',$smsText);
                } else {
                    $id = (new SmsService())->sendSms((string)$donor['phone'], $smsText);
                    $this->log((int)$donor['id'],'sms','emergency_alert',$inviteId,$id,'submitted',$smsText);
                }
            } catch (Throwable $e) {
                $this->log((int)$donor['id'],'sms','emergency_alert',$inviteId,null,'failed',$smsText,$e->getMessage());
            }
        }

        if (!empty($pref['email_alerts'])) {
            $html = EmailService::template(
                'Urgent blood donation request',
                '<p>Hello '.e($donor['full_name']).',</p><p><strong>'.e($emergency['blood_group']).'</strong> blood has been requested by <strong>'.e($hospital['name']).'</strong> in '.e($emergency['district']).'.</p><p>Please respond only if you are currently available. Medical staff will confirm final eligibility before donation.</p>',
                'View & Respond',
                $responseUrl
            );
            try {
                if ($isLocal && ((string)config('brevo','api_key') === '' || (string)config('brevo','sender_email') === '')) {
                    $this->log((int)$donor['id'],'email','emergency_alert',$inviteId,'local','simulated','Urgent blood request');
                } else {
                    $id = (new EmailService())->send((string)$donor['email'], (string)$donor['full_name'], 'Urgent blood request - LifeLink SL', $html);
                    $this->log((int)$donor['id'],'email','emergency_alert',$inviteId,$id,'submitted','Urgent blood request');
                }
            } catch (Throwable $e) {
                $this->log((int)$donor['id'],'email','emergency_alert',$inviteId,null,'failed','Urgent blood request',$e->getMessage());
            }
        }

        $this->log((int)$donor['id'],'in_app','emergency_alert',$inviteId,'portal','available','Emergency invitation available in donor portal');
    }
}
