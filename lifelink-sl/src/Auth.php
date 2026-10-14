<?php

declare(strict_types=1);

final class Auth
{
    public static function user(): ?array
    {
        static $cacheId = null;
        static $cachedUser = null;
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) return null;
        if ($cacheId === (int)$id && $cachedUser) return $cachedUser;
        $stmt = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
        $stmt->execute([(int)$id]);
        $cachedUser = $stmt->fetch() ?: null;
        $cacheId = (int)$id;
        return $cachedUser;
    }

    public static function id(): ?int { return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null; }
    public static function check(): bool { return self::id() !== null; }

    /** @return 'authenticated'|'mfa'|'invalid' */
    public static function attempt(string $email, string $password): string
    {
        $email = strtolower(trim($email));
        $pdo = db();
        $cutoff = date('Y-m-d H:i:s', time() - 15 * 60);
        $q = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email=? AND ip_address=? AND succeeded=0 AND created_at>=?');
        $q->execute([$email, client_ip(), $cutoff]);
        if ((int)$q->fetchColumn() >= 5) {
            security_event('login_lockout','high','Login blocked after repeated failed attempts.',null,$email);
            throw new RuntimeException('Too many failed login attempts. Try again in about 15 minutes.');
        }

        $stmt = $pdo->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        $ok = $user && password_verify($password, $user['password_hash']) && $user['status'] === 'active';
        $log = $pdo->prepare('INSERT INTO login_attempts(email,ip_address,succeeded) VALUES(?,?,?)');
        $log->execute([$email, client_ip(), $ok ? 1 : 0]);
        if (!$ok) {
            security_event('login_failed','warning','Invalid credentials or inactive account.', $user ? (int)$user['id'] : null, $email);
            return 'invalid';
        }

        if (!empty($user['mfa_enabled'])) {
            $_SESSION['mfa_pending_user_id'] = (int)$user['id'];
            $_SESSION['mfa_pending_started_at'] = time();
            $devOtp = (new MfaService())->begin($user);
            if ($devOtp !== null) $_SESSION['dev_mfa_otp'] = $devOtp;
            else unset($_SESSION['dev_mfa_otp']);
            return 'mfa';
        }

        self::completeLogin((int)$user['id']);
        return 'authenticated';
    }

    public static function completeLogin(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['_last_activity'] = time();
        unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending_started_at'], $_SESSION['dev_mfa_otp']);
        db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$userId]);
        audit('login','user',$userId);
        security_event('login_success','info','Successful authenticated login.',$userId);
    }

    public static function logout(): void
    {
        if (self::id()) {
            try { audit('logout','user',self::id()); } catch (Throwable) {}
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function requireRole(string ...$roles): array
    {
        $user = self::user();
        if (!$user) redirect('login.php');
        if (!in_array($user['role'], $roles, true)) {
            security_event('role_denied','warning','Role-based access denied.',(int)$user['id']);
            http_response_code(403);
            exit('Forbidden');
        }
        return $user;
    }

    public static function dashboardPath(array $user): string
    {
        return match ($user['role']) {
            'admin' => 'admin/dashboard.php',
            'hospital' => 'hospital/dashboard.php',
            default => 'donor/dashboard.php',
        };
    }
}
