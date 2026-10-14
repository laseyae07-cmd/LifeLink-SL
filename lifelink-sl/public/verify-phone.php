<?php
require_once dirname(__DIR__).'/src/bootstrap.php';
$userId=(int)($_SESSION['pending_phone_user_id']??Auth::id()??0);
if(!$userId) redirect('login.php');
$stmt=db()->prepare("SELECT * FROM users WHERE id=? AND role='donor'");
$stmt->execute([$userId]);
$pendingUser=$stmt->fetch();
if(!$pendingUser) redirect('login.php');
$error=null;
if(request_method('POST')){
    Csrf::verify();
    $action=$_POST['action']??'verify';
    if($action==='resend'){
        try{
            $devOtp=(new VerificationService())->sendPhoneOtp($pendingUser);
            if($devOtp!==null) $_SESSION['dev_phone_otp']=$devOtp;
            else unset($_SESSION['dev_phone_otp']);
            flash('success',$devOtp!==null?'A new local development OTP has been generated.':'A new OTP has been sent.');
            redirect('verify-phone.php');
        }catch(Throwable $e){
            $error=$e->getMessage();
        }
    }else{
        try{
            if((new VerificationService())->verifyPhoneOtp($userId,trim($_POST['otp']??''))){
                unset($_SESSION['pending_phone_user_id'],$_SESSION['dev_phone_otp']);
                flash('success','Phone verified. Your donor account is active. Eligibility remains pending until verified by an administrator.');
                redirect('login.php');
            }
            $error='The OTP is invalid or expired.';
        }catch(Throwable $e){
            $error=$e->getMessage();
        }
    }
}
$pageTitle='Verify Phone';
require dirname(__DIR__).'/src/templates/header.php';
?>
<div class="form-card narrow">
<h1>Verify your mobile</h1>
<p class="help">Enter the 6-digit OTP for <?=e((string)$pendingUser['phone'])?>.</p>
<?php if((string)config('app','env')==='local' && !empty($_SESSION['dev_phone_otp'])):?><div class="flash success"><strong>Local development OTP:</strong> <?=e((string)$_SESSION['dev_phone_otp'])?><br><small>This is shown only in local development because TextLKDemo is not approved for OTP delivery. Production uses a real approved sender ID.</small></div><?php endif;?>
<?php if($error):?><div class="error-list"><?=e($error)?></div><?php endif;?>
<form method="post"><?=Csrf::field()?><input type="hidden" name="action" value="verify"><div class="field"><label>OTP</label><input name="otp" inputmode="numeric" maxlength="6" pattern="\d{6}" required></div><button class="btn" type="submit">Verify Phone</button></form>
<form method="post" style="margin-top:12px"><?=Csrf::field()?><input type="hidden" name="action" value="resend"><button class="btn btn-outline" type="submit">Resend OTP</button></form>
</div>
<?php require dirname(__DIR__).'/src/templates/footer.php';?>
