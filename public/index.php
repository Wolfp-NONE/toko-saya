<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$pdo = db();
$products = $pdo->query('SELECT id, name, description, price, stock, image_url FROM products WHERE is_active = 1 ORDER BY id DESC')->fetchAll();
$notice = $_GET['added'] ?? '';

site_header('Beranda');
?>
<section class="hero">
  <div>
    <p class="eyebrow">SELAMAT DATANG DI TOKO KITA</p>
    <h1>Belanja mudah,<br>pesan lewat WhatsApp.</h1>
    <p>Pilih produk, masukkan ke keranjang, lalu kirim pesanan langsung ke WhatsApp kami.</p>
    <a class="button button-light" href="#produk">Lihat produk</a>
  </div>
  <div class="hero-mark">TK<span>+</span></div>
</section>
<?php if ($notice === '1'): ?><div class="alert success">Produk berhasil dimasukkan ke keranjang.</div><?php endif; ?>
<section id="produk" class="section-head"><div><p class="eyebrow">KOLEKSI TOKO</p><h2>Produk pilihan</h2></div><a href="/cart.php" class="text-link">Lihat keranjang →</a></section>
<?php if (!$products): ?>
  <div class="empty"><h3>Belum ada produk</h3><p>Admin dapat menambahkan produk melalui halaman <code>/admin.php</code>.</p></div>
<?php else: ?>
  <div class="product-grid">
  <?php foreach ($products as $product): ?>
    <article class="product-card">
      <?php if (!empty($product['image_url'])): ?>
        <img class="product-image" src="<?= e($product['image_url']) ?>" alt="<?= e($product['name']) ?>" loading="lazy">
      <?php else: ?>
        <div class="product-placeholder"><span><?= e(mb_strtoupper(mb_substr($product['name'], 0, 1))) ?></span></div>
      <?php endif; ?>
      <div class="product-info">
        <h3><?= e($product['name']) ?></h3>
        <p class="description"><?= e($product['description']) ?></p>
        <div class="product-bottom"><strong><?= rupiah($product['price']) ?></strong><small>Stok: <?= (int)$product['stock'] ?></small></div>
        <form action="/cart.php" method="post" class="add-form">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
          <input type="number" name="quantity" value="1" min="1" max="<?= max(1, (int)$product['stock']) ?>" aria-label="Jumlah" <?= (int)$product['stock'] < 1 ? 'disabled' : '' ?>>
          <button class="button" type="submit" <?= (int)$product['stock'] < 1 ? 'disabled' : '' ?>><?= (int)$product['stock'] < 1 ? 'Stok habis' : 'Tambah +' ?></button>
        </form>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php site_footer(); ?>
