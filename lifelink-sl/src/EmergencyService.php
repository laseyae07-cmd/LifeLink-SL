<?php

declare(strict_types=1);

final class EmergencyService
{
    public function create(int $hospitalId, int $actorId, array $data): int
    {
        $expiryHours = max(1, min(48, (int)($data['expiry_hours'] ?? (int)setting('default_request_expiry_hours','12'))));
        $expiresAt = date('Y-m-d H:i:s', time() + $expiryHours * 3600);
        $stmt = db()->prepare('INSERT INTO emergency_requests(hospital_id,blood_group,units_needed,required_donors,urgency,district,location_details,notes,expires_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$hospitalId,$data['blood_group'],$data['units_needed'],$data['required_donors'],$data['urgency'],$data['district'],$data['location_details'] ?: null,$data['notes'] ?: null,$expiresAt,$actorId]);
        $id = (int)db()->lastInsertId();
        audit('emergency_created','emergency',$id,['blood_group'=>$data['blood_group'],'district'=>$data['district'],'expires_at'=>$expiresAt]);
        $this->dispatchLevel($id, 1);
        return $id;
    }

    public function get(int $id): ?array
    {
        $stmt = db()->prepare('SELECT e.*,h.name AS hospital_name,h.user_id AS hospital_user_id FROM emergency_requests e JOIN hospitals h ON h.id=e.hospital_id WHERE e.id=?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function stats(int $emergencyId): array
    {
        $stmt = db()->prepare("SELECT COUNT(*) invited, SUM(status='accepted') accepted, SUM(status='declined') declined, SUM(status='pending') pending FROM emergency_invites WHERE emergency_id=?");
        $stmt->execute([$emergencyId]);
        $row = $stmt->fetch() ?: [];
        $ref = db()->prepare("SELECT COUNT(*) total, SUM(status='accepted') accepted FROM hospital_referrals WHERE emergency_id=?");
        $ref->execute([$emergencyId]);
        $rr = $ref->fetch() ?: [];
        return [
            'invited'=>(int)($row['invited'] ?? 0),
            'accepted'=>(int)($row['accepted'] ?? 0),
            'declined'=>(int)($row['declined'] ?? 0),
            'pending'=>(int)($row['pending'] ?? 0),
            'referrals'=>(int)($rr['total'] ?? 0),
            'referral_accepted'=>(int)($rr['accepted'] ?? 0),
        ];
    }

    public function dispatchLevel(int $emergencyId, int $level): array
    {
        if ($level < 1 || $level > 3) throw new InvalidArgumentException('Invalid escalation level.');
        $pdo = db();
        $emergency = $this->get($emergencyId);
        if (!$emergency || $emergency['status'] !== 'open' || $emergency['expires_at'] <= now_sql()) return ['candidates'=>0,'sent'=>0];
        $exists = $pdo->prepare('SELECT id FROM emergency_escalations WHERE emergency_id=? AND level=?');
        $exists->execute([$emergencyId,$level]);
        if ($exists->fetch()) return ['candidates'=>0,'sent'=>0];

        if ($level === 3) return $this->createHospitalReferrals($emergency);

        $districts = $level === 1 ? [$emergency['district']] : adjacent_districts((string)$emergency['district']);
        if (!$districts && $level === 2) $districts = array_values(array_diff(districts(), [$emergency['district']]));
        $limit = $level === 1 ? 12 : 25;
        $placeholders = implode(',', array_fill(0,count($districts),'?'));
        $sameDistrictClause = $level === 2 ? ' AND COALESCE(p.same_district_only,0)=0 ' : '';
        $sql = "SELECT u.id,u.full_name,u.email,u.phone,dp.district FROM users u JOIN donor_profiles dp ON dp.user_id=u.id
                LEFT JOIN donor_notification_preferences p ON p.donor_id=u.id
                WHERE u.role='donor' AND u.status='active' AND u.email_verified_at IS NOT NULL AND u.phone_verified_at IS NOT NULL
                AND dp.eligibility_status='eligible' AND dp.availability='available' AND dp.consent_status='granted' AND dp.blood_group=?
                AND COALESCE(p.emergency_alerts,1)=1 $sameDistrictClause
                AND dp.district IN ($placeholders)
                AND NOT EXISTS (SELECT 1 FROM emergency_invites ei WHERE ei.emergency_id=? AND ei.donor_id=u.id)
                ORDER BY CASE WHEN dp.district=? THEN 0 ELSE 1 END, u.last_login_at DESC, u.id ASC LIMIT $limit";
        $params = array_merge([$emergency['blood_group']], $districts, [$emergencyId,$emergency['district']]);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $candidates = $stmt->fetchAll();

        $h = $pdo->prepare('SELECT * FROM hospitals WHERE id=?');
        $h->execute([(int)$emergency['hospital_id']]);
        $hospital = $h->fetch();
        $sent = 0;
        foreach ($candidates as $donor) {
            $raw = random_token();
            $expires = min(strtotime((string)$emergency['expires_at']), time() + ((int)config('ttl','invite_minutes') * 60));
            try {
                $ins = $pdo->prepare('INSERT INTO emergency_invites(emergency_id,donor_id,escalation_level,response_token_hash,expires_at) VALUES(?,?,?,?,?)');
                $ins->execute([$emergencyId,(int)$donor['id'],$level,token_hash($raw),date('Y-m-d H:i:s',$expires)]);
                $inviteId = (int)$pdo->lastInsertId();
                (new NotificationService())->sendEmergency($donor,$emergency,$hospital,$inviteId,$raw);
                $sent++;
            } catch (Throwable $e) {
                error_log('LifeLink emergency dispatch error: '.$e->getMessage());
            }
        }
        $log = $pdo->prepare('INSERT INTO emergency_escalations(emergency_id,level,candidate_count,sent_count) VALUES(?,?,?,?)');
        $log->execute([$emergencyId,$level,count($candidates),$sent]);
        audit('emergency_escalated','emergency',$emergencyId,['level'=>$level,'candidates'=>count($candidates),'sent'=>$sent]);
        return ['candidates'=>count($candidates),'sent'=>$sent];
    }

    private function createHospitalReferrals(array $emergency): array
    {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT id,name,district FROM hospitals WHERE status='active' AND id<>? ORDER BY CASE WHEN district=? THEN 1 ELSE 0 END, district, name LIMIT 15");
        $stmt->execute([(int)$emergency['hospital_id'],(string)$emergency['district']]);
        $hospitals = $stmt->fetchAll();
        $sent = 0;
        foreach ($hospitals as $hospital) {
            try {
                $msg = sprintf('%s blood request from %s, %s. Required donors: %d.', $emergency['blood_group'], $emergency['hospital_name'], $emergency['district'], $emergency['required_donors']);
                $pdo->prepare('INSERT IGNORE INTO hospital_referrals(emergency_id,sender_hospital_id,target_hospital_id,message) VALUES(?,?,?,?)')->execute([(int)$emergency['id'],(int)$emergency['hospital_id'],(int)$hospital['id'],$msg]);
                if ((int)$pdo->lastInsertId() > 0) $sent++;
            } catch (Throwable $e) { error_log('Referral creation error: '.$e->getMessage()); }
        }
        $pdo->prepare('INSERT INTO emergency_escalations(emergency_id,level,candidate_count,sent_count) VALUES(?,?,?,?)')->execute([(int)$emergency['id'],3,count($hospitals),$sent]);
        audit('emergency_escalated','emergency',(int)$emergency['id'],['level'=>3,'hospitals'=>count($hospitals),'referrals'=>$sent]);
        return ['candidates'=>count($hospitals),'sent'=>$sent];
    }

    public function respond(string $rawToken, string $response): array
    {
        $stmt = db()->prepare('SELECT id,donor_id FROM emergency_invites WHERE response_token_hash=? LIMIT 1');
        $stmt->execute([token_hash($rawToken)]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('This response link is invalid.');
        return $this->respondForDonor((int)$row['id'], (int)$row['donor_id'], $response);
    }

    public function respondForDonor(int $inviteId, int $donorId, string $response): array
    {
        if (!in_array($response,['accepted','declined'],true)) throw new InvalidArgumentException('Invalid response.');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT ei.*,e.required_donors,e.status AS emergency_status,e.expires_at,dp.consent_status FROM emergency_invites ei JOIN emergency_requests e ON e.id=ei.emergency_id JOIN donor_profiles dp ON dp.user_id=ei.donor_id WHERE ei.id=? AND ei.donor_id=? FOR UPDATE');
            $stmt->execute([$inviteId,$donorId]);
            $invite = $stmt->fetch();
            if (!$invite) throw new RuntimeException('This response request is invalid.');
            if ($invite['expires_at'] < now_sql() || $invite['emergency_status'] !== 'open') {
                $pdo->prepare("UPDATE emergency_invites SET status='expired' WHERE id=? AND status='pending'")->execute([$inviteId]);
                throw new RuntimeException('This emergency request is no longer active.');
            }
            if ($response === 'accepted' && $invite['consent_status'] !== 'granted') throw new RuntimeException('Your donor consent is currently withdrawn. Update your profile before accepting.');
            if ($invite['status'] !== 'pending') { $pdo->commit(); return $invite; }
            $pdo->prepare('UPDATE emergency_invites SET status=?,responded_at=NOW() WHERE id=?')->execute([$response,$inviteId]);
            if ($response === 'accepted') {
                $count = $pdo->prepare("SELECT COUNT(*) FROM emergency_invites WHERE emergency_id=? AND status='accepted'");
                $count->execute([(int)$invite['emergency_id']]);
                if ((int)$count->fetchColumn() >= (int)$invite['required_donors']) {
                    $pdo->prepare("UPDATE emergency_requests SET status='fulfilled',fulfilled_at=NOW() WHERE id=? AND status='open'")->execute([(int)$invite['emergency_id']]);
                }
            }
            $pdo->commit();
            audit('donor_emergency_response','emergency',(int)$invite['emergency_id'],['response'=>$response,'donor_id'=>$donorId]);
            return $invite;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
