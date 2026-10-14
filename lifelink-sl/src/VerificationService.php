<?php

declare(strict_types=1);

final class VerificationService
{
    public function sendEmailVerification(array $user): ?string
    {
        $token = random_token();
        $hash = token_hash($token);
        $expires = date('Y-m-d H:i:s', time() + ((int)config('ttl','email_verify_minutes') * 60));
        db()->prepare('DELETE FROM email_verifications WHERE user_id=? AND used_at IS NULL')->execute([(int)$user['id']]);
        db()->prepare('INSERT INTO email_verifications(user_id,token_hash,expires_at) VALUES(?,?,?)')->execute([(int)$user['id'],$hash,$expires]);
        $verifyUrl = url('verify-email.php?token=' . urlencode($token));

        if ((string)config('app','env') === 'local' && ((string)config('brevo','api_key') === '' || (string)config('brevo','sender_email') === '')) {
            (new NotificationService())->log((int)$user['id'],'in_app','email_verification',null,'local','simulated','Local email verification link generated');
            return $verifyUrl;
        }

        $html = EmailService::template('Verify your email','<p>Hello '.e($user['full_name']).',</p><p>Verify your email address to continue setting up your LifeLink SL account.</p>','Verify Email',$verifyUrl);
        try {
            $id = (new EmailService())->send((string)$user['email'], (string)$user['full_name'], 'Verify your LifeLink SL email', $html);
            (new NotificationService())->log((int)$user['id'],'email','email_verification',null,$id,'submitted','Email verification');
            return null;
        } catch (Throwable $e) {
            (new NotificationService())->log((int)$user['id'],'email','email_verification',null,null,'failed','Email verification',$e->getMessage());
            throw $e;
        }
    }

    public function verifyEmailToken(string $raw): ?array
    {
        $stmt = db()->prepare('SELECT ev.id AS verification_id,ev.user_id,ev.expires_at,u.role,u.full_name,u.email,u.phone,u.status FROM email_verifications ev JOIN users u ON u.id=ev.user_id WHERE ev.token_hash=? AND ev.used_at IS NULL AND ev.expires_at>NOW() LIMIT 1');
        $stmt->execute([token_hash($raw)]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE email_verifications SET used_at=NOW() WHERE id=?')->execute([(int)$row['verification_id']]);
            $pdo->prepare('UPDATE users SET email_verified_at=COALESCE(email_verified_at,NOW()) WHERE id=?')->execute([(int)$row['user_id']]);
            $pdo->commit();
            audit('email_verified','user',(int)$row['user_id']);
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        return $row;
    }

    public function sendPhoneOtp(array $user): ?string
    {
        if (!$user['phone']) throw new RuntimeException('No phone number is available.');
        $data = (new SmsService())->sendOtp((string)$user['phone']);
        $ref = (string)($data['referenceId'] ?? '');
        $otpHash = (string)($data['otpHash'] ?? '');
        if ($ref === '' || $otpHash === '') throw new RuntimeException('SMS provider did not return the required OTP delivery information.');
        $expires = date('Y-m-d H:i:s', time() + ((int)config('ttl','phone_otp_minutes') * 60));
        db()->prepare('DELETE FROM phone_verifications WHERE user_id=? AND verified_at IS NULL')->execute([(int)$user['id']]);
        db()->prepare('INSERT INTO phone_verifications(user_id,provider_reference,otp_hash,expires_at) VALUES(?,?,?,?)')->execute([(int)$user['id'],$ref,$otpHash,$expires]);
        (new NotificationService())->log((int)$user['id'],'sms','phone_otp',null,$ref,isset($data['debugOtp'])?'simulated':'submitted','Phone verification OTP');
        return isset($data['debugOtp']) ? (string)$data['debugOtp'] : null;
    }

    public function verifyPhoneOtp(int $userId, string $otp): bool
    {
        if (!preg_match('/^\d{6}$/', $otp)) return false;
        $stmt = db()->prepare('SELECT * FROM phone_verifications WHERE user_id=? AND verified_at IS NULL AND expires_at>NOW() ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['attempt_count'] >= 5) return false;
        db()->prepare('UPDATE phone_verifications SET attempt_count=attempt_count+1 WHERE id=?')->execute([(int)$row['id']]);
        if (!hash_equals((string)$row['otp_hash'], token_hash($otp))) return false;
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE phone_verifications SET verified_at=NOW() WHERE id=?')->execute([(int)$row['id']]);
            $pdo->prepare("UPDATE users SET phone_verified_at=NOW(), status=CASE WHEN role='donor' THEN 'active' ELSE status END WHERE id=?")->execute([$userId]);
            $pdo->commit();
            audit('phone_verified','user',$userId);
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        return true;
    }

    public function createPasswordReset(array $user, string $type = 'password_reset'): ?string
    {
        $token = random_token();
        $hash = token_hash($token);
        $expires = date('Y-m-d H:i:s', time() + ((int)config('ttl','password_reset_minutes') * 60));
        db()->prepare('DELETE FROM password_resets WHERE user_id=? AND used_at IS NULL')->execute([(int)$user['id']]);
        db()->prepare('INSERT INTO password_resets(user_id,token_hash,expires_at) VALUES(?,?,?)')->execute([(int)$user['id'],$hash,$expires]);
        $resetUrl = url('reset-password.php?token=' . urlencode($token));
        $heading = $type === 'hospital_invite' ? 'Set up your hospital account' : 'Reset your password';
        $subject = $type === 'hospital_invite' ? 'Your LifeLink SL hospital account' : 'Reset your LifeLink SL password';

        if ((string)config('app','env') === 'local' && ((string)config('brevo','api_key') === '' || (string)config('brevo','sender_email') === '')) {
            (new NotificationService())->log((int)$user['id'],'in_app',$type,null,'local','simulated',$heading);
            return $resetUrl;
        }

        $html = EmailService::template($heading,'<p>Hello '.e($user['full_name']).',</p><p>Use the secure link below to choose a new password.</p>','Set Password',$resetUrl);
        $id = (new EmailService())->send((string)$user['email'], (string)$user['full_name'], $subject, $html);
        (new NotificationService())->log((int)$user['id'],'email',$type,null,$id,'submitted',$heading);
        return null;
    }
}
