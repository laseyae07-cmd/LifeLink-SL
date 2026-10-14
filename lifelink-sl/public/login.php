<?php
require_once dirname(__DIR__).'/src/bootstrap.php';

$currentUser = Auth::user();

if ($currentUser) {
    redirect(Auth::dashboardPath($currentUser));
}

if (Auth::id() !== null && !$currentUser) {
    unset($_SESSION['user_id']);
}
$error=null;
if(request_method('POST')){
    Csrf::verify();
    try{
        $result=Auth::attempt($_POST['email']??'',$_POST['password']??'');
        if($result==='authenticated') redirect(Auth::dashboardPath(Auth::user()));
        if($result==='mfa') redirect('mfa.php');
        $error='Invalid credentials or the account is not active yet.';
    }catch(Throwable $e){$error=$e->getMessage();}
}
$pageTitle='Sign In';require dirname(__DIR__).'/src/templates/header.php';?>
<div class="form-card narrow"><div class="eyebrow">Secure access</div><h1>Sign in to your workspace</h1><p class="help">After your password is accepted, MFA confirms the sign-in before the dashboard opens.</p><?php if($error):?><div class="error-list"><?=e($error)?></div><?php endif;?><form method="post"><?=Csrf::field()?><div class="field"><label>Email</label><input type="email" name="email" autocomplete="username" required></div><div class="field"><label>Password</label><input type="password" name="password" autocomplete="current-password" required></div><button class="btn" type="submit">Continue securely</button></form><p class="small"><a href="<?=e(url('forgot-password.php'))?>">Forgot password?</a> · <a href="<?=e(url('resend-verification.php'))?>">Resend verification</a></p><p class="small">New donor? <a href="<?=e(url('register.php'))?>">Create an account</a></p></div>
<?php require dirname(__DIR__).'/src/templates/footer.php';?>
