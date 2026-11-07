<?php
declare(strict_types=1);

function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function app_key(): string
{
    $key = env_value('APP_KEY', '');
    if (strlen((string)$key) < 32) {
        http_response_code(500);
        exit('Konfigurasi APP_KEY belum benar. Isi APP_KEY minimal 32 karakter di file .env.');
    }
    return (string)$key;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env_value('DB_HOST', '');
    $port = env_value('DB_PORT', '3306');
    $name = env_value('DB_NAME', 'toko_db');
    $user = env_value('DB_USER', '');
    $pass = env_value('DB_PASSWORD', '');

    if ($host === '' || $user === '') {
        http_response_code(500);
        exit('Konfigurasi DB_HOST atau DB_USER belum diisi. Periksa file .env.');
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        http_response_code(503);
        exit('Database belum dapat dihubungi. Periksa endpoint RDS, username/password, dan Security Group.');
    }
}

function is_https_request(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function cart_token(): string
{
    $token = $_COOKIE['toko_cart'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        $token = bin2hex(random_bytes(32));
        setcookie('toko_cart', $token, [
            'expires' => time() + (30 * 24 * 60 * 60),
            'path' => '/',
            'secure' => is_https_request(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['toko_cart'] = $token;
    }
    return $token;
}

function csrf_token(): string
{
    return hash_hmac('sha256', 'csrf|' . cart_token(), app_key());
}

function verify_csrf(): void
{
    $received = $_POST['csrf_token'] ?? '';
    if (!is_string($received) || !hash_equals(csrf_token(), $received)) {
        http_response_code(419);
        exit('Token formulir tidak valid. Muat ulang halaman dan coba lagi.');
    }
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rupiah(string|int|float $amount): string
{
    return 'Rp' . number_format((float)$amount, 0, ',', '.');
}

function cart_items(): array
{
    $stmt = db()->prepare(
        'SELECT c.product_id, c.quantity, p.name, p.description, p.price, p.stock, p.image_url '
        . 'FROM cart_items c INNER JOIN products p ON p.id = c.product_id '
        . 'WHERE c.cart_token = ? AND p.is_active = 1 ORDER BY p.name'
    );
    $stmt->execute([cart_token()]);
    return $stmt->fetchAll();
}

function cart_quantity(): int
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE cart_token = ?');
    $stmt->execute([cart_token()]);
    return (int)$stmt->fetchColumn();
}

function site_header(string $title): void
{
    $cartCount = cart_quantity();
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' - Toko Kita</title>';
    echo '<link rel="stylesheet" href="/assets/style.css"></head><body>';
    echo '<header class="topbar"><a class="brand" href="/">TOKO KITA</a>';
    echo '<nav><a href="/">Produk</a><a href="/cart.php">Keranjang (' . $cartCount . ')</a></nav></header>';
    echo '<main class="container">';
}

function site_footer(): void
{
    echo '</main><footer class="footer">Toko Kita · Pemesanan melalui WhatsApp</footer></body></html>';
}

function require_admin(): void
{
    $expectedUser = (string)env_value('ADMIN_USER', '');
    $expectedPass = (string)env_value('ADMIN_PASSWORD', '');
    $providedUser = $_SERVER['PHP_AUTH_USER'] ?? '';
    $providedPass = $_SERVER['PHP_AUTH_PW'] ?? '';

    if ($expectedUser === '' || $expectedPass === ''
        || !hash_equals($expectedUser, (string)$providedUser)
        || !hash_equals($expectedPass, (string)$providedPass)) {
        header('WWW-Authenticate: Basic realm="Admin Toko", charset="UTF-8"');
        http_response_code(401);
        exit('Login admin diperlukan. Pastikan ADMIN_USER dan ADMIN_PASSWORD sudah disetel.');
    }
}
