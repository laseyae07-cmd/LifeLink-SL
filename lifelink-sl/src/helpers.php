<?php

declare(strict_types=1);

function db(): PDO { return Database::connection(); }
function e(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function config(string $section, ?string $key = null): mixed {
    global $config;
    return $key === null ? ($config[$section] ?? null) : ($config[$section][$key] ?? null);
}
function url(string $path = ''): string { return rtrim((string)config('app','url'), '/') . '/' . ltrim($path, '/'); }
function redirect(string $path): never { header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path))); exit; }
function request_method(string $method): bool { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === strtoupper($method); }
function flash(string $type, string $message): void { $_SESSION['_flash'][] = ['type'=>$type,'message'=>$message]; }
function pull_flashes(): array { $f = $_SESSION['_flash'] ?? []; unset($_SESSION['_flash']); return $f; }
function old(string $key, string $default = ''): string { return e($_SESSION['_old'][$key] ?? $default); }
function set_old(array $data): void { $_SESSION['_old'] = $data; }
function clear_old(): void { unset($_SESSION['_old']); }
function client_ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 64); }
function now_sql(): string { return date('Y-m-d H:i:s'); }
function random_token(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }
function token_hash(string $token): string { return hash('sha256', $token); }
function app_secret_hash(string $value): string { return hash_hmac('sha256', $value, (string)config('app','key')); }
function blood_groups(): array { return ['A+','A-','B+','B-','AB+','AB-','O+','O-']; }
function organ_types(): array { return ['Kidney','Liver','Cornea','Heart','Lung','Pancreas','Tissue']; }
function districts(): array { return ['Ampara','Anuradhapura','Badulla','Batticaloa','Colombo','Galle','Gampaha','Hambantota','Jaffna','Kalutara','Kandy','Kegalle','Kilinochchi','Kurunegala','Mannar','Matale','Matara','Monaragala','Mullaitivu','Nuwara Eliya','Polonnaruwa','Puttalam','Ratnapura','Trincomalee','Vavuniya']; }
function validate_district(string $district): bool { return in_array($district, districts(), true); }
function normalize_sl_phone(string $phone): ?string {
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (preg_match('/^0(7\d{8})$/', $digits, $m)) return '94' . $m[1];
    if (preg_match('/^94(7\d{8})$/', $digits)) return $digits;
    return null;
}
function audit(string $action, ?string $entityType = null, ?int $entityId = null, array $metadata = []): void {
    $stmt = db()->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,ip_address,user_agent,metadata_json) VALUES(?,?,?,?,?,?,?)');
    $stmt->execute([Auth::id(), $action, $entityType, $entityId, client_ip(), substr($_SERVER['HTTP_USER_AGENT'] ?? '',0,255), $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null]);
}
function security_event(string $eventType, string $severity, string $description, ?int $userId = null, ?string $emailAttempt = null): void {
    try {
        $stmt = db()->prepare('INSERT INTO security_events(user_id,email_attempt,event_type,severity,ip_address,description) VALUES(?,?,?,?,?,?)');
        $stmt->execute([$userId, $emailAttempt ? strtolower(trim($emailAttempt)) : null, $eventType, $severity, client_ip(), substr($description,0,500)]);
    } catch (Throwable $e) {
        error_log('LifeLink security event log failed: '.$e->getMessage());
    }
}
function setting(string $key, string $default = ''): string {
    try {
        $stmt = db()->prepare('SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string)$value;
    } catch (Throwable) {
        return $default;
    }
}
function set_setting(string $key, string $value): void {
    $stmt = db()->prepare('INSERT INTO system_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $stmt->execute([$key,$value]);
}
function current_hospital(?array $user = null): ?array {
    $user = $user ?? Auth::user();
    if (!$user || $user['role'] !== 'hospital') return null;
    if (!empty($user['hospital_id'])) {
        $stmt = db()->prepare('SELECT * FROM hospitals WHERE id=? AND status=\'active\' LIMIT 1');
        $stmt->execute([(int)$user['hospital_id']]);
        $row = $stmt->fetch();
        if ($row) return $row;
    }
    $stmt = db()->prepare('SELECT * FROM hospitals WHERE user_id=? AND status=\'active\' LIMIT 1');
    $stmt->execute([(int)$user['id']]);
    return $stmt->fetch() ?: null;
}
function donor_nav(): string {
    $items = [
        'donor/dashboard.php'=>'Dashboard',
        'donor/profile.php'=>'Profile',
        'donor/preferences.php'=>'Preferences',
        'donor/alerts.php'=>'Alerts',
        'donor/organ-interest.php'=>'Organ Interest',
        'donor/campaigns.php'=>'Campaigns',
        'donor/card.php'=>'QR Card',
    ];
    return nav_html($items);
}
function hospital_nav(?array $user = null): string {
    $user = $user ?? Auth::user();
    $items = [
        'hospital/dashboard.php'=>'Dashboard',
        'hospital/emergency-create.php'=>'New Request',
        'hospital/coverage.php'=>'Coverage',
        'hospital/network.php'=>'Hospital Network',
        'hospital/inventory.php'=>'Inventory & Transfers',
        'hospital/campaigns.php'=>'Campaigns',
        'hospital/reports.php'=>'Reports',
        'hospital/scan.php'=>'Verify QR',
    ];
    if (!empty($user['organ_access_enabled'])) $items['hospital/organ-records.php']='Organ Access';
    return nav_html($items);
}
function admin_nav(): string {
    return nav_html([
        'admin/dashboard.php'=>'Overview',
        'admin/hospitals.php'=>'Hospitals',
        'admin/users.php'=>'Users',
        'admin/donors.php'=>'Donors',
        'admin/security.php'=>'Security',
        'admin/audit.php'=>'Audit',
        'admin/reports.php'=>'Reports',
        'admin/settings.php'=>'Settings',
        'admin/system-health.php'=>'System Health',
        'admin/backup.php'=>'Backup',
    ]);
}
function nav_html(array $items): string {
    $html = '<nav class="side-nav" aria-label="Workspace navigation">';
    $current = str_replace('\\','/', (string)($_SERVER['PHP_SELF'] ?? ''));
    foreach ($items as $path=>$label) {
        $active = str_ends_with($current, '/'.$path) ? ' active' : '';
        $html .= '<a class="'.$active.'" href="'.e(url($path)).'">'.e($label).'</a>';
    }
    return $html.'</nav>';
}
function adjacent_districts(string $district): array {
    $map = [
        'Colombo'=>['Gampaha','Kalutara'], 'Gampaha'=>['Colombo','Kegalle','Kurunegala'], 'Kalutara'=>['Colombo','Ratnapura','Galle'],
        'Kandy'=>['Matale','Kegalle','Nuwara Eliya','Badulla'], 'Matale'=>['Kandy','Kurunegala','Anuradhapura','Polonnaruwa'], 'Nuwara Eliya'=>['Kandy','Badulla','Ratnapura'],
        'Galle'=>['Kalutara','Matara','Ratnapura'], 'Matara'=>['Galle','Hambantota'], 'Hambantota'=>['Matara','Monaragala','Ratnapura'],
        'Jaffna'=>['Kilinochchi'], 'Kilinochchi'=>['Jaffna','Mullaitivu','Mannar'], 'Mannar'=>['Kilinochchi','Vavuniya','Anuradhapura'],
        'Vavuniya'=>['Mannar','Mullaitivu','Anuradhapura'], 'Mullaitivu'=>['Kilinochchi','Vavuniya','Trincomalee'],
        'Batticaloa'=>['Trincomalee','Polonnaruwa','Ampara'], 'Ampara'=>['Batticaloa','Monaragala','Badulla'], 'Trincomalee'=>['Mullaitivu','Anuradhapura','Polonnaruwa','Batticaloa'],
        'Kurunegala'=>['Gampaha','Kegalle','Matale','Anuradhapura','Puttalam'], 'Puttalam'=>['Kurunegala','Anuradhapura'],
        'Anuradhapura'=>['Puttalam','Kurunegala','Matale','Polonnaruwa','Trincomalee','Vavuniya','Mannar'], 'Polonnaruwa'=>['Anuradhapura','Matale','Trincomalee','Batticaloa'],
        'Badulla'=>['Kandy','Nuwara Eliya','Monaragala','Ampara'], 'Monaragala'=>['Badulla','Ampara','Hambantota','Ratnapura'],
        'Ratnapura'=>['Kalutara','Galle','Hambantota','Monaragala','Nuwara Eliya','Kegalle'], 'Kegalle'=>['Gampaha','Kurunegala','Kandy','Ratnapura'],
    ];
    return $map[$district] ?? [];
}
