<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $token = cart_token();

    if ($action === 'add') {
        $productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
        $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
        $quantity = max(1, min(99, (int)$quantity));
        $stmt = $pdo->prepare('SELECT id, stock, is_active FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product || !(int)$product['is_active'] || (int)$product['stock'] < 1) {
            header('Location: /?error=unavailable'); exit;
        }
        $current = $pdo->prepare('SELECT quantity FROM cart_items WHERE cart_token = ? AND product_id = ?');
        $current->execute([$token, $productId]);
        $existing = (int)($current->fetchColumn() ?: 0);
        if ($existing + $quantity > (int)$product['stock']) {
            header('Location: /cart.php?error=stock'); exit;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO cart_items (cart_token, product_id, quantity) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
        );
        $stmt->execute([$token, $productId, $quantity]);
        header('Location: /?added=1#produk'); exit;
    }

    if ($action === 'update') {
        $productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
        $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
        $quantity = (int)$quantity;
        if ($quantity <= 0) {
            $stmt = $pdo->prepare('DELETE FROM cart_items WHERE cart_token = ? AND product_id = ?');
            $stmt->execute([$token, $productId]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE cart_items c INNER JOIN products p ON p.id = c.product_id '
                . 'SET c.quantity = LEAST(?, p.stock) WHERE c.cart_token = ? AND c.product_id = ? AND p.is_active = 1 AND p.stock > 0'
            );
            $stmt->execute([min(99, $quantity), $token, $productId]);
        }
        header('Location: /cart.php'); exit;
    }

    if ($action === 'remove') {
        $productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare('DELETE FROM cart_items WHERE cart_token = ? AND product_id = ?');
        $stmt->execute([$token, $productId]);
        header('Location: /cart.php'); exit;
    }

    header('Location: /cart.php'); exit;
}

$items = cart_items();
$total = 0;
foreach ($items as $item) $total += (float)$item['price'] * (int)$item['quantity'];
$error = $_GET['error'] ?? '';

site_header('Keranjang');
?>
<div class="page-title"><p class="eyebrow">RINGKASAN BELANJA</p><h1>Keranjang kamu</h1><p>Periksa jumlah dan produk sebelum melanjutkan pesanan.</p></div>
<?php if ($error === 'stock'): ?><div class="alert warning">Jumlah melebihi stok yang tersedia.</div><?php endif; ?>
<?php if (!$items): ?>
  <div class="empty"><div class="empty-icon">🛒</div><h2>Keranjang masih kosong</h2><p>Yuk, pilih produk yang kamu suka.</p><a class="button" href="/">Mulai belanja</a></div>
<?php else: ?>
  <div class="cart-layout"><section class="cart-list">
    <?php foreach ($items as $item): ?>
      <article class="cart-item">
        <div class="cart-thumb"><?= e(mb_strtoupper(mb_substr($item['name'], 0, 1))) ?></div>
        <div class="cart-detail"><h3><?= e($item['name']) ?></h3><p><?= rupiah($item['price']) ?> / produk</p>
          <form method="post" class="quantity-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="product_id" value="<?= (int)$item['product_id'] ?>">
            <label>Jumlah <input type="number" name="quantity" value="<?= (int)$item['quantity'] ?>" min="0" max="<?= (int)$item['stock'] ?>"></label>
            <button class="button button-small button-outline" type="submit">Perbarui</button>
          </form>
          <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="product_id" value="<?= (int)$item['product_id'] ?>"><button class="remove-link" type="submit">Hapus</button></form>
        </div>
        <strong class="line-total"><?= rupiah((float)$item['price'] * (int)$item['quantity']) ?></strong>
      </article>
    <?php endforeach; ?>
  </section><aside class="summary-card"><h2>Total belanja</h2><div class="summary-row"><span><?= count($items) ?> jenis produk</span><span><?= rupiah($total) ?></span></div><div class="summary-total"><span>Total</span><strong><?= rupiah($total) ?></strong></div><a href="/checkout.php" class="button button-block">Lanjut ke checkout</a><a href="/" class="back-link">← Lanjut belanja</a></aside></div>
<?php endif; ?>
<?php site_footer(); ?>
