<?php
require_once dirname(__DIR__).'/src/bootstrap.php';
if (Auth::check()) redirect(Auth::dashboardPath(Auth::user()));
$errors=[];
if(request_method('POST')){
    Csrf::verify(); set_old($_POST);
    $name=trim($_POST['full_name']??''); $email=strtolower(trim($_POST['email']??'')); $phone=normalize_sl_phone($_POST['phone']??'');
    $blood=$_POST['blood_group']??''; $district=$_POST['district']??''; $dob=$_POST['date_of_birth']??''; $password=$_POST['password']??''; $confirm=$_POST['password_confirmation']??'';
    if(strlen($name)<3)$errors[]='Enter your full name.';
    if(!filter_var($email,FILTER_VALIDATE_EMAIL))$errors[]='Enter a valid email address.';
    if(!$phone)$errors[]='Enter a valid Sri Lankan mobile number.';
    if(!in_array($blood,blood_groups(),true))$errors[]='Select a valid blood group.';
    if(!validate_district($district))$errors[]='Select a valid district.';
    if(!$dob || strtotime($dob)===false || strtotime($dob)>=time())$errors[]='Enter a valid date of birth.';
    if(strlen($password)<10)$errors[]='Password must contain at least 10 characters.';
    if($password!==$confirm)$errors[]='Passwords do not match.';
    if(empty($_POST['consent']))$errors[]='You must consent to the donor coordination data processing described below.';
    if(!$errors){
        try{
            $pdo=db();$pdo->beginTransaction();
            $stmt=$pdo->prepare("INSERT INTO users(role,full_name,email,phone,password_hash,status,mfa_enabled) VALUES('donor',?,?,?,?, 'pending_verification',1)");
            $stmt->execute([$name,$email,$phone,password_hash($password,PASSWORD_DEFAULT)]);$uid=(int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO donor_profiles(user_id,blood_group,district,date_of_birth,consent_status,consent_at) VALUES(?,?,?,?, 'granted',NOW())")->execute([$uid,$blood,$district,$dob]);
            $pdo->prepare('INSERT INTO donor_notification_preferences(donor_id) VALUES(?)')->execute([$uid]);
            $pdo->commit();clear_old();
            $u=$pdo->prepare('SELECT * FROM users WHERE id=?');$u->execute([$uid]);$user=$u->fetch();
            try{$dev=(new VerificationService())->sendEmailVerification($user);if($dev)$_SESSION['dev_email_verify_url']=$dev;else unset($_SESSION['dev_email_verify_url']);flash('success','Account created. Verify your email to continue.');}
            catch(Throwable $e){flash('error','Account created, but the verification message could not be prepared. Contact the administrator.');}
            audit('donor_registered','user',$uid);redirect('verification-pending.php');
        }catch(PDOException $e){if(db()->inTransaction())db()->rollBack();if((string)$e->getCode()==='23000')$errors[]='That email address or phone number is already registered.';else $errors[]='Registration failed. Please try again.';}
    }
}
$pageTitle='Donor Registration';require dirname(__DIR__).'/src/templates/header.php';?>
<div class="form-card"><div class="eyebrow">Volunteer donor registration</div><h1>Create your donor account</h1><p class="help">Email and phone verification are required. Your eligibility status is confirmed separately before emergency matching.</p><?php if($errors):?><div class="error-list"><?php foreach($errors as $x):?><div><?=e($x)?></div><?php endforeach;?></div><?php endif;?><form method="post"><?=Csrf::field()?><div class="form-grid"><div class="field"><label>Full name</label><input name="full_name" required value="<?=old('full_name')?>"></div><div class="field"><label>Email</label><input type="email" name="email" required value="<?=old('email')?>"></div><div class="field"><label>Mobile number</label><input name="phone" required placeholder="0771234567" value="<?=old('phone')?>"></div><div class="field"><label>Date of birth</label><input type="date" name="date_of_birth" required value="<?=old('date_of_birth')?>"></div><div class="field"><label>Blood group</label><select name="blood_group" required><option value="">Select</option><?php foreach(blood_groups() as $g):?><option <?=($_SESSION['_old']['blood_group']??'')===$g?'selected':''?>><?=e($g)?></option><?php endforeach;?></select></div><div class="field"><label>District</label><select name="district" required><option value="">Select</option><?php foreach(districts() as $d):?><option <?=($_SESSION['_old']['district']??'')===$d?'selected':''?>><?=e($d)?></option><?php endforeach;?></select></div><div class="field"><label>Password</label><input type="password" name="password" required minlength="10"></div><div class="field"><label>Confirm password</label><input type="password" name="password_confirmation" required minlength="10"></div></div><label class="check-row"><input type="checkbox" name="consent" value="1" required><span>I consent to LifeLink SL storing the minimum information needed to operate my donor account and contact me for eligible coordination requests. I understand I can withdraw donor matching consent later from my profile.</span></label><button class="btn" type="submit">Create Account</button></form></div>
<?php clear_old();require dirname(__DIR__).'/src/templates/footer.php';?>
