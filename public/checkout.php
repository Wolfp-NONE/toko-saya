<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$pdo = db();
$completedOrder = null;
$completedItems = [];
$waLink = null;
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string)($_POST['customer_name'] ?? ''));
    $phone = trim((string)($_POST['customer_phone'] ?? ''));
    $address = trim((string)($_POST['customer_address'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($name === '' || mb_strlen($name) > 120 || $phone === '' || !preg_match('/^[0-9+() .-]{8,30}$/', $phone)
        || $address === '' || mb_strlen($address) > 3000 || mb_strlen($notes) > 3000) {
        $errorMessage = 'Mohon isi nama, nomor telepon, dan alamat dengan benar.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'SELECT c.product_id, c.quantity, p.name, p.price, p.stock, p.is_active '
                . 'FROM cart_items c INNER JOIN products p ON p.id = c.product_id '
                . 'WHERE c.cart_token = ? FOR UPDATE'
            );
            $stmt->execute([cart_token()]);
            $items = $stmt->fetchAll();
            if (!$items) throw new RuntimeException('Keranjang kosong. Tambahkan produk sebelum checkout.');

            $total = 0.0;
            foreach ($items as $item) {
                if (!(int)$item['is_active'] || (int)$item['quantity'] > (int)$item['stock'] || (int)$item['stock'] < 1) {
                    throw new RuntimeException('Stok produk ' . $item['name'] . ' tidak mencukupi. Silakan periksa keranjang.');
                }
                $total += (float)$item['price'] * (int)$item['quantity'];
            }

            $orderCode = 'TK-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $publicToken = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare(
                'INSERT INTO orders (order_code, public_token, customer_name, customer_phone, customer_address, notes, total) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$orderCode, $publicToken, $name, $phone, $address, $notes, $total]);
            $orderId = (int)$pdo->lastInsertId();

            $insertItem = $pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, product_name, price, quantity, subtotal) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $decrementStock = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
            foreach ($items as $item) {
                $qty = (int)$item['quantity'];
                $price = (float)$item['price'];
                $insertItem->execute([$orderId, $item['product_id'], $item['name'], $price, $qty, $price * $qty]);
                $decrementStock->execute([$qty, $item['product_id'], $qty]);
                if ($decrementStock->rowCount() !== 1) throw new RuntimeException('Stok berubah saat checkout. Silakan ulangi.');
            }

            $clear = $pdo->prepare('DELETE FROM cart_items WHERE cart_token = ?');
            $clear->execute([cart_token()]);
            $pdo->commit();
            header('Location: /checkout.php?order=' . urlencode($publicToken));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorMessage = $e instanceof RuntimeException ? $e->getMessage() : 'Pesanan belum berhasil disimpan. Silakan coba lagi.';
            if (!$e instanceof RuntimeException) error_log('Checkout error: ' . $e->getMessage());
        }
    }
}

$orderToken = $_GET['order'] ?? '';
if (is_string($orderToken) && preg_match('/^[a-f0-9]{64}$/', $orderToken)) {
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE public_token = ?');
    $stmt->execute([$orderToken]);
    $completedOrder = $stmt->fetch() ?: null;
    if ($completedOrder) {
        $stmt = $pdo->prepare('SELECT product_name, price, quantity, subtotal FROM order_items WHERE order_id = ? ORDER BY id');
        $stmt->execute([$completedOrder['id']]);
        $completedItems = $stmt->fetchAll();
        $waNumber = preg_replace('/\D+/', '', (string)env_value('WA_NUMBER', ''));
        if ($waNumber !== '') {
            $message = "Halo, saya ingin konfirmasi pesanan berikut.\n\n";
            $message .= 'Kode pesanan: ' . $completedOrder['order_code'] . "\n";
            $message .= 'Nama: ' . $completedOrder['customer_name'] . "\n";
            $message .= 'No. HP: ' . $completedOrder['customer_phone'] . "\n";
            $message .= 'Alamat: ' . $completedOrder['customer_address'] . "\n\n";
            $message .= "Detail pesanan:\n";
            foreach ($completedItems as $item) {
                $message .= '- ' . $item['product_name'] . ' x' . $item['quantity'] . ' = ' . rupiah($item['subtotal']) . "\n";
            }
            $message .= "\nTotal: " . rupiah($completedOrder['total']);
            if (!empty($completedOrder['notes'])) $message .= "\nCatatan: " . $completedOrder['notes'];
            $waLink = 'https://wa.me/' . $waNumber . '?text=' . rawurlencode($message);
        }
    }
}

site_header('Checkout');
?>
<?php if ($completedOrder): ?>
  <div class="success-panel"><div class="success-icon">✓</div><p class="eyebrow">PESANAN TERSIMPAN</p><h1>Terima kasih, <?= e($completedOrder['customer_name']) ?>!</h1><p>Kode pesanan kamu <strong><?= e($completedOrder['order_code']) ?></strong>. Pesanan sudah masuk ke database.</p></div>
  <section class="summary-card order-receipt"><h2>Rincian pesanan</h2>
    <?php foreach ($completedItems as $item): ?><div class="summary-row"><span><?= e($item['product_name']) ?> × <?= (int)$item['quantity'] ?></span><strong><?= rupiah($item['subtotal']) ?></strong></div><?php endforeach; ?>
    <div class="summary-total"><span>Total</span><strong><?= rupiah($completedOrder['total']) ?></strong></div>
    <?php if ($waLink): ?><a class="button button-whatsapp button-block" href="<?= e($waLink) ?>" target="_blank" rel="noopener">Kirim pesanan ke WhatsApp</a><?php else: ?><div class="alert warning">WA_NUMBER belum disetel. Minta admin mengisi nomor WhatsApp toko di file .env.</div><?php endif; ?>
    <p class="muted small">WhatsApp akan terbuka dengan pesan pesanan yang sudah disiapkan. Tekan tombol Kirim di WhatsApp untuk mengirimkannya.</p>
    <a class="back-link" href="/">Kembali ke toko</a>
  </section>
<?php else: ?>
  <div class="page-title"><p class="eyebrow">SATU LANGKAH LAGI</p><h1>Checkout</h1><p>Isi data penerima. Pesanan akan disimpan sebelum WhatsApp dibuka.</p></div>
  <?php if ($errorMessage !== ''): ?><div class="alert warning"><?= e($errorMessage) ?></div><?php endif; ?>
  <?php $items = cart_items(); $total = 0; foreach ($items as $item) $total += (float)$item['price'] * (int)$item['quantity']; ?>
  <?php if (!$items): ?><div class="empty"><h2>Keranjang kosong</h2><a href="/" class="button">Pilih produk</a></div><?php else: ?>
    <div class="checkout-layout"><form method="post" class="form-card">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <label>Nama lengkap<input name="customer_name" maxlength="120" required value="<?= e($_POST['customer_name'] ?? '') ?>"></label>
      <label>Nomor HP / WhatsApp<input name="customer_phone" maxlength="30" required placeholder="08xxxxxxxxxx" value="<?= e($_POST['customer_phone'] ?? '') ?>"></label>
      <label>Alamat pengiriman<textarea name="customer_address" rows="3" maxlength="3000" required><?= e($_POST['customer_address'] ?? '') ?></textarea></label>
      <label>Catatan (opsional)<textarea name="notes" rows="2" maxlength="3000" placeholder="Warna, ukuran, atau catatan lain"><?= e($_POST['notes'] ?? '') ?></textarea></label>
      <button class="button button-block" type="submit">Simpan pesanan</button>
    </form><aside class="summary-card"><h2>Ringkasan</h2><?php foreach ($items as $item): ?><div class="summary-row"><span><?= e($item['name']) ?> × <?= (int)$item['quantity'] ?></span><strong><?= rupiah((float)$item['price'] * (int)$item['quantity']) ?></strong></div><?php endforeach; ?><div class="summary-total"><span>Total</span><strong><?= rupiah($total) ?></strong></div><p class="muted small">Stok akan dikurangi setelah pesanan berhasil disimpan.</p></aside></div>
  <?php endif; ?>
<?php endif; ?>
<?php site_footer(); ?>
