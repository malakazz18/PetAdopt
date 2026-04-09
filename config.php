<?php
// ── Database ────────────────────────────────────────────────────
$host     = 'localhost';
$dbname   = 'PetAdopt';
$username = 'root';
$password = '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    error_log("DB connection failed: " . $e->getMessage());
    die("Erreur de connexion à la base de données. Veuillez réessayer plus tard.");
}

// ── Session Security ─────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 1 : 0);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.gc_maxlifetime', 7200);
    session_start();
    if (empty($_SESSION['_initiated'])) {
        session_regenerate_id(true);
        $_SESSION['_initiated'] = true;
    }
}

// ── Security Headers ─────────────────────────────────────────────
if (!headers_sent()) {
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("X-XSS-Protection: 0"); // Disabled in favor of CSP
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' unpkg.com cdn.jsdelivr.net cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' unpkg.com; img-src 'self' data: https: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';");
}

// ── CSRF Protection ──────────────────────────────────────────────
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time']) || time() - $_SESSION['csrf_token_time'] > 3600) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        error_log("CSRF validation failed for IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        die("Requête invalide (CSRF). Veuillez recharger la page et réessayer.");
    }
    // Token stays valid for the session duration — needed for multi-form pages like admin
    // Token is rotated on login via session_regenerate_id()
}

// ── XSS & Input Sanitization ────────────────────────────────────
function sanitizeString(string $val, int $maxLen = 255): string {
    $val = strip_tags($val);
    return mb_substr(trim($val), 0, $maxLen);
}

function sanitizeInt($val, int $min = 0, int $max = PHP_INT_MAX): int {
    $v = filter_var($val, FILTER_VALIDATE_INT);
    if ($v === false) return $min;
    return max($min, min($max, $v));
}

function sanitizeFloat($val, float $min = 0.0, float $max = PHP_FLOAT_MAX): float {
    $v = filter_var($val, FILTER_VALIDATE_FLOAT);
    if ($v === false) return 0.0;
    return max($min, min($max, $v));
}

function sanitizeEmail(string $val): string {
    return filter_var(trim($val), FILTER_SANITIZE_EMAIL);
}

function e(string $val): string {
    return htmlspecialchars($val ?? '', ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Rate Limiting ─────────────────────────────────────────────────
function checkRateLimit(PDO $pdo, string $ip, string $email = ''): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmt->execute([$ip]);
        if ($stmt->fetchColumn() >= 10) return false;

        if ($email) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM login_attempts
                WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() >= 5) return false;
        }
        return true;
    } catch (PDOException $e) {
        return true; // Fail open if table doesn't exist
    }
}

function recordLoginAttempt(PDO $pdo, string $ip, string $email){
    try {
        $pdo->prepare("INSERT INTO login_attempts (ip_address, email) VALUES (?, ?)")
            ->execute([$ip, $email]);
        $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    } catch (PDOException $e) {
        // Silently fail if table doesn't exist
    }
}

// ── Upload Security ──────────────────────────────────────────────
function secureImageUpload(array $file, string $dir, int $maxSize = 2097152): string {
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];

    if ($file['error'] !== UPLOAD_ERR_OK) return false ;
    if ($file['size'] > $maxSize) return false;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($file['tmp_name']);
    if (!in_array($realMime, $allowedMime)) return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts)) return false;

    // Check image dimensions to prevent decompression bombs
    $dims = getimagesize($file['tmp_name']);
    if ($dims === false) return false;
    if ($dims[0] > 4096 || $dims[1] > 4096) return false;

    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = rtrim($dir, '/') . '/' . $safeName;

    // Move and strip EXIF data if possible
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        chmod($destination, 0644);
        // Strip EXIF for privacy
        if ($realMime === 'image/jpeg') {
            $image = imagecreatefromjpeg($destination);
            imagejpeg($image, $destination, 85);
            imagedestroy($image);
        }
        return $destination;
    }
    return false;
}

// ── Auth Helpers ─────────────────────────────────────────────────
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) || isset($_SESSION['vet_id']);
}

function isVet(): bool {
    return isset($_SESSION['is_vet']) && $_SESSION['is_vet'] === true;
}

function isValidatedVet(): bool {
    return isset($_SESSION['vet_status']) && $_SESSION['vet_status'] === 'VALIDE';
}

function isAdmin(): bool {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

function getCurrentUserId(): int {
    $id = $_SESSION['user_id'] ?? $_SESSION['vet_id'] ?? null;
    return $id !== null ? (int)$id : false ;
}

// ── Database Helpers ─────────────────────────────────────────────
function getRegions(): array {
    return [
        'tunis' => ['name' => 'Tunis', 'icon' => '🌆'],
        'sfax' => ['name' => 'Sfax', 'icon' => '🏛️'],
        'sousse' => ['name' => 'Sousse', 'icon' => '🌊'],
        'bizerte' => ['name' => 'Bizerte', 'icon' => '⛵'],
        'nabeul' => ['name' => 'Nabeul', 'icon' => '🍊'],
        'ariana' => ['name' => 'Ariana', 'icon' => '🏢'],
        'ben_arous' => ['name' => 'Ben Arous', 'icon' => '🌳'],
        'manouba' => ['name' => 'La Manouba', 'icon' => '🌾'],
        'zaghouan' => ['name' => 'Zaghouan', 'icon' => '⛰️'],
        'beja' => ['name' => 'Béja', 'icon' => '🌻'],
        'jendouba' => ['name' => 'Jendouba', 'icon' => '🌲'],
        'kef' => ['name' => 'Le Kef', 'icon' => '🏔️'],
        'siliana' => ['name' => 'Siliana', 'icon' => '🌄'],
        'sidi_bouzid' => ['name' => 'Sidi Bouzid', 'icon' => '🌵'],
        'kairouan' => ['name' => 'Kairouan', 'icon' => '🕌'],
        'kasserine' => ['name' => 'Kasserine', 'icon' => '🏜️'],
        'gafsa' => ['name' => 'Gafsa', 'icon' => '💎'],
        'tozeur' => ['name' => 'Tozeur', 'icon' => '🌴'],
        'kebili' => ['name' => 'Kébili', 'icon' => '🐪'],
        'gabes' => ['name' => 'Gabès', 'icon' => '🌊'],
        'medenine' => ['name' => 'Médenine', 'icon' => '🏺'],
        'tataouine' => ['name' => 'Tataouine', 'icon' => '🎬'],
        'mahdia' => ['name' => 'Mahdia', 'icon' => '⚓'],
        'monastir' => ['name' => 'Monastir', 'icon' => '🏖️'],
    ];
}

function getHealthStatusOptions(): array {
    return [
        'STABLE' => ['label' => 'Stable', 'color' => '#2c5e2a', 'icon' => '✓'],
        'URGENT' => ['label' => 'Urgent', 'color' => '#ffa500', 'icon' => '⚠️'],
        'CRITIQUE' => ['label' => 'Critique', 'color' => '#dc3545', 'icon' => '🚨']
    ];
}
?>