<?php
require_once dirname(dirname(__DIR__)).'/src/bootstrap.php';
$user=Auth::requireRole('hospital');
if(empty($user['organ_access_enabled'])){security_event('organ_access_denied','warning','Restricted organ module access denied.',(int)$user['id']);http_response_code(403);exit('Restricted organ access is not enabled for this account.');}
$hospital=current_hospital($user); if(!$hospital){http_response_code(403);exit('No active hospital assigned.');}
$errors=[];
if(request_method('POST')){
    Csrf::verify();
    $action=$_POST['action']??'';
    if($action==='create_request'){
        $organ=$_POST['organ_type']??''; $urgency=$_POST['urgency']??'routine'; $district=$_POST['district']??''; $note=trim($_POST['coordination_note']??''); $hours=(int)($_POST['expiry_hours']??24);
        if(!in_array($organ,organ_types(),true))$errors[]='Select a valid organ-interest type.';
        if(!in_array($urgency,['routine','urgent'],true))$errors[]='Select a valid coordination priority.';
        if(!validate_district($district))$errors[]='Select a valid district.';
        if($hours<1||$hours>72)$errors[]='Expiry must be between 1 and 72 hours.';
        if(mb_strlen($note)>500)$errors[]='Coordination note is too long.';
        if(!$errors){
            $expires=date('Y-m-d H:i:s',time()+$hours*3600);
            $q=db()->prepare('INSERT INTO organ_coordination_requests(hospital_id,organ_type,urgency,district,coordination_note,expires_at,created_by) VALUES(?,?,?,?,?,?,?)');
            $q->execute([(int)$hospital['id'],$organ,$urgency,$district,$note?:null,$expires,(int)$user['id']]);
            $id=(int)db()->lastInsertId(); audit('organ_coordination_request_created','organ_request',$id,['organ_type'=>$organ,'district'=>$district]);
            flash('success','Restricted organ coordination request recorded. No medical matching or transplant allocation has been performed.'); redirect('hospital/organ-records.php');
        }
    }elseif($action==='request_status'){
        $id=(int)($_POST['request_id']??0); $status=$_POST['status']??'';
        if(in_array($status,['closed','cancelled'],true)){
            $q=db()->prepare("UPDATE organ_coordination_requests SET status=?,closed_at=NOW() WHERE id=? AND hospital_id=? AND status='open'");
            $q->execute([$status,$id,(int)$hospital['id']]);
            if($q->rowCount())audit('organ_coordination_request_'.$status,'organ_request',$id);
            flash('success','Coordination request status updated.'); redirect('hospital/organ-records.php');
        }
    }
}
// Keep expired records traceable without treating them as active coordination.
db()->prepare("UPDATE organ_coordination_requests SET status='closed',closed_at=COALESCE(closed_at,NOW()) WHERE hospital_id=? AND status='open' AND expires_at<NOW()")->execute([(int)$hospital['id']]);
$type=(string)($_GET['organ_type']??''); $params=[]; $where="oi.status='active' AND dp.consent_status='granted'";
if($type!==''&&in_array($type,organ_types(),true)){$where.=' AND oi.organ_type=?';$params[]=$type;}
$q=db()->prepare("SELECT oi.donor_id,oi.organ_type,oi.updated_at,dp.district FROM organ_interests oi JOIN donor_profiles dp ON dp.user_id=oi.donor_id JOIN users u ON u.id=oi.donor_id WHERE $where AND u.status='active' ORDER BY oi.updated_at DESC LIMIT 250");$q->execute($params);$rows=$q->fetchAll();
$rq=db()->prepare("SELECT * FROM organ_coordination_requests WHERE hospital_id=? ORDER BY created_at DESC LIMIT 100");$rq->execute([(int)$hospital['id']]);$requests=$rq->fetchAll();
$pageTitle='Organ Access';require dirname(dirname(__DIR__)).'/src/templates/header.php';
?>
<section class="dashboard"><div class="container"><div class="dash-head"><div><div class="eyebrow">Restricted hospital access</div><h1>Organ interest & coordination</h1><p class="page-note">Voluntary interest records and a minimal hospital coordination tracker. This module does not determine medical suitability, legal approval, compatibility or transplant allocation.</p></div></div><?=hospital_nav($user)?>
<div class="danger-note"><strong>Privacy control:</strong> donor records remain anonymous here. Do not enter patient names, diagnoses, national IDs or clinical records in coordination notes.</div>
<div class="grid-2">
<div class="card"><h2>Record a coordination request</h2><p class="small">Use this only as an internal coordination marker. Clinical and legal transplant processes remain outside LifeLink SL.</p><?php if($errors):?><div class="error-list"><?=e(implode(' ',$errors))?></div><?php endif;?><form method="post"><?=Csrf::field()?><input type="hidden" name="action" value="create_request"><div class="field"><label>Interest type</label><select name="organ_type" required><option value="">Select</option><?php foreach(organ_types() as $o):?><option><?=e($o)?></option><?php endforeach;?></select></div><div class="form-grid"><div class="field"><label>Priority</label><select name="urgency"><option value="routine">Routine coordination</option><option value="urgent">Urgent coordination</option></select></div><div class="field"><label>District</label><select name="district"><?php foreach(districts() as $d):?><option <?=$hospital['district']===$d?'selected':''?>><?=e($d)?></option><?php endforeach;?></select></div><div class="field"><label>Expires after</label><select name="expiry_hours"><option value="12">12 hours</option><option value="24" selected>24 hours</option><option value="48">48 hours</option><option value="72">72 hours</option></select></div></div><div class="field"><label>Non-clinical coordination note</label><textarea name="coordination_note" maxlength="500" placeholder="Example: contact the authorized transplant coordination team. Do not include patient-identifying data."></textarea></div><button class="btn">Record Request</button></form></div>
<div class="card"><h2>Active voluntary interest records</h2><div class="toolbar"><form method="get"><select name="organ_type"><option value="">All interest types</option><?php foreach(organ_types() as $o):?><option value="<?=e($o)?>" <?=$type===$o?'selected':''?>><?=e($o)?></option><?php endforeach;?></select><button class="btn btn-sm">Filter</button></form></div><div class="table-wrap"><table class="table"><thead><tr><th>Anonymous donor</th><th>Interest</th><th>District</th><th>Updated</th></tr></thead><tbody><?php if(!$rows):?><tr><td colspan="4" class="empty">No active records match the filter.</td></tr><?php endif;?><?php foreach($rows as $r):?><tr><td>Donor #<?=e((string)$r['donor_id'])?></td><td><?=e($r['organ_type'])?></td><td><?=e($r['district'])?></td><td><?=e($r['updated_at'])?></td></tr><?php endforeach;?></tbody></table></div></div></div>
<h2>My hospital coordination requests</h2><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Type</th><th>Priority</th><th>District</th><th>Status</th><th>Expires</th><th>Action</th></tr></thead><tbody><?php if(!$requests):?><tr><td colspan="7" class="empty">No restricted coordination requests recorded.</td></tr><?php endif;?><?php foreach($requests as $r):?><tr><td>#<?=e((string)$r['id'])?></td><td><?=e($r['organ_type'])?></td><td><?=e(ucfirst($r['urgency']))?></td><td><?=e($r['district'])?></td><td><span class="badge <?=$r['status']==='open'?'amber':'gray'?>"><?=e($r['status'])?></span></td><td><?=e($r['expires_at'])?></td><td><?php if($r['status']==='open'):?><form method="post" class="mini-actions"><?=Csrf::field()?><input type="hidden" name="action" value="request_status"><input type="hidden" name="request_id" value="<?=e((string)$r['id'])?>"><button class="btn btn-sm" name="status" value="closed">Close</button><button class="btn btn-outline btn-sm" name="status" value="cancelled">Cancel</button></form><?php else:?>—<?php endif;?></td></tr><?php endforeach;?></tbody></table></div>
</div></section>
<?php require dirname(dirname(__DIR__)).'/src/templates/footer.php';?>
