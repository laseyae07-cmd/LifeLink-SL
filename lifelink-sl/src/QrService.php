<?php

declare(strict_types=1);

final class QrService
{
    public function issue(int $donorId): string
    {
        $pdo = db();
        $pdo->prepare('UPDATE qr_tokens SET revoked_at=NOW() WHERE donor_id=? AND revoked_at IS NULL')->execute([$donorId]);
        $raw = random_token(24);
        $expires = date('Y-m-d H:i:s', strtotime('+'.(int)config('ttl','qr_days').' days'));
        $pdo->prepare('INSERT INTO qr_tokens(donor_id,token_hash,expires_at) VALUES(?,?,?)')->execute([$donorId,token_hash($raw),$expires]);
        audit('qr_issued','user',$donorId);
        return $raw;
    }
    public function currentOrIssue(int $donorId): string { return $this->issue($donorId); }
    public function verify(string $raw): ?array
    {
        $stmt = db()->prepare("SELECT u.id,u.full_name,u.email,u.phone,dp.blood_group,dp.district,dp.eligibility_status,dp.availability,dp.consent_status,u.email_verified_at,u.phone_verified_at,qt.expires_at FROM qr_tokens qt JOIN users u ON u.id=qt.donor_id JOIN donor_profiles dp ON dp.user_id=u.id WHERE qt.token_hash=? AND qt.revoked_at IS NULL AND qt.expires_at>NOW() AND u.role='donor' LIMIT 1");
        $stmt->execute([token_hash($raw)]);
        return $stmt->fetch() ?: null;
    }
}
