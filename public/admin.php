<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_admin();
$pdo = db();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
            $stock = filter_var($_POST['stock'] ?? null, FILTER_VALIDATE_INT);
            $imageUrl = trim((string)($_POST['image_url'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150 || $price === false || $price < 0 || $stock === false || $stock < 0) {
                throw new RuntimeException('Nama, harga, atau stok produk tidak valid.');
            }
            if ($imageUrl !== '' && (!filter_var($imageUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($imageUrl, PHP_URL_SCHEME)), ['http', 'https'], true))) {
                throw new RuntimeException('URL gambar harus menggunakan http atau https.');
            }
            $stmt = $pdo->prepare('INSERT INTO products (name, description, price, stock, image_url) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$name, $description, $price, $stock, $imageUrl !== '' ? $imageUrl : null]);
            $message = 'Produk berhasil ditambahkan.';
        } elseif ($action === 'update') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
            $stock = filter_var($_POST['stock'] ?? null, FILTER_VALIDATE_INT);
            $active = isset($_POST['is_active']) ? 1 : 0;
            if (!$id || $price === false || $price < 0 || $stock === false || $stock < 0) throw new RuntimeException('Harga atau stok tidak valid.');
            $stmt = $pdo->prepare('UPDATE products SET price = ?, stock = ?, is_active = ? WHERE id = ?');
            $stmt->execute([$price, $stock, $active, $id]);
            $message = 'Produk berhasil diperbarui.';
        }
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Terjadi kesalahan saat menyimpan produk.';
        if (!$e instanceof RuntimeException) error_log('Admin error: ' . $e->getMessage());
    }
}

$products = $pdo->query('SELECT * FROM products ORDER BY id DESC')->fetchAll();
site_header('Admin Produk');
?>
<div class="page-title"><p class="eyebrow">PENGELOLAAN TOKO</p><h1>Admin produk</h1><p>Tambah produk atau ubah harga, stok, dan status tayang.</p></div>
<?php if ($message): ?><div class="alert success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert warning"><?= e($error) ?></div><?php endif; ?>
<section class="form-card admin-add"><h2>Tambah produk baru</h2><form method="post" class="admin-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create">
  <label>Nama produk<input name="name" maxlength="150" required></label><label>Harga (Rupiah)<input type="number" name="price" min="0" step="1" required></label><label>Stok<input type="number" name="stock" min="0" step="1" required></label><label>URL gambar (opsional)<input type="url" name="image_url" placeholder="https://..."></label><label class="wide">Deskripsi<textarea name="description" rows="2"></textarea></label><div class="wide"><button class="button" type="submit">Tambah produk</button></div>
</form></section>
<section class="admin-products"><h2>Daftar produk</h2><?php foreach ($products as $product): ?><form method="post" class="admin-product-row"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$product['id'] ?>"><div class="admin-product-name"><strong><?= e($product['name']) ?></strong><small>ID #<?= (int)$product['id'] ?> · <?= e($product['description']) ?></small></div><label>Harga<input type="number" name="price" min="0" step="1" value="<?= e((string)$product['price']) ?>" required></label><label>Stok<input type="number" name="stock" min="0" step="1" value="<?= (int)$product['stock'] ?>" required></label><label class="check-label"><input type="checkbox" name="is_active" value="1" <?= (int)$product['is_active'] ? 'checked' : '' ?>> Tayang</label><button class="button button-small" type="submit">Simpan</button></form><?php endforeach; ?></section>
<?php site_footer(); ?>
