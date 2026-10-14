<?php

declare(strict_types=1);

final class MfaService
{
    public function begin(array $user): ?string
    {
        $otp = (string)random_int(100000, 999999);
        $expires = date('Y-m-d H:i:s', time() + ((int)config('ttl','mfa_otp_minutes') * 60));
        db()->prepare('DELETE FROM mfa_otps WHERE user_id=? AND used_at IS NULL')->execute([(int)$user['id']]);
        db()->prepare('INSERT INTO mfa_otps(user_id,otp_hash,expires_at) VALUES(?,?,?)')->execute([(int)$user['id'], token_hash($otp), $expires]);

        $isLocal = (string)config('app','env') === 'local';
        $allowDev = (bool)config('textlk','allow_dev_otp');
        if ($isLocal && $allowDev) {
            (new NotificationService())->log((int)$user['id'],'in_app','mfa_otp',null,'local','simulated','Local MFA OTP generated');
            security_event('mfa_challenge','info','Local development MFA challenge generated.',(int)$user['id']);
            return $otp;
        }

        if (!empty($user['phone'])) {
            try {
                $id = (new SmsService())->sendSms((string)$user['phone'], 'LifeLink SL sign-in code: '.$otp.'. Expires in '.(int)config('ttl','mfa_otp_minutes').' minutes.');
                (new NotificationService())->log((int)$user['id'],'sms','mfa_otp',null,$id,'submitted','MFA sign-in code');
                return null;
            } catch (Throwable $e) {
                error_log('MFA SMS failed: '.$e->getMessage());
            }
        }

        $html = EmailService::template('Your sign-in code','<p>Hello '.e($user['full_name']).',</p><p>Your LifeLink SL sign-in code is <strong>'.e($otp).'</strong>. It expires shortly.</p>');
        $id = (new EmailService())->send((string)$user['email'], (string)$user['full_name'], 'LifeLink SL sign-in code', $html);
        (new NotificationService())->log((int)$user['id'],'email','mfa_otp',null,$id,'submitted','MFA sign-in code');
        return null;
    }

    public function verify(int $userId, string $otp): bool
    {
        if (!preg_match('/^\d{6}$/', $otp)) return false;
        $stmt = db()->prepare('SELECT * FROM mfa_otps WHERE user_id=? AND used_at IS NULL AND expires_at>NOW() ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['attempt_count'] >= 5) return false;
        db()->prepare('UPDATE mfa_otps SET attempt_count=attempt_count+1 WHERE id=?')->execute([(int)$row['id']]);
        if (!hash_equals((string)$row['otp_hash'], token_hash($otp))) {
            security_event('mfa_failed','warning','Invalid MFA code entered.',$userId);
            return false;
        }
        db()->prepare('UPDATE mfa_otps SET used_at=NOW() WHERE id=?')->execute([(int)$row['id']]);
        security_event('mfa_verified','info','MFA challenge completed.',$userId);
        return true;
    }
}
