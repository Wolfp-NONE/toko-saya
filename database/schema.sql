CREATE DATABASE IF NOT EXISTS toko_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE toko_db;

CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  price DECIMAL(15,2) NOT NULL DEFAULT 0,
  stock INT UNSIGNED NOT NULL DEFAULT 0,
  image_url VARCHAR(1000) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_products_active (is_active, id)
) ENGINE=InnoDB;

-- Keranjang disimpan di MySQL sehingga dapat dibaca dari EC2 A maupun EC2 B.
CREATE TABLE IF NOT EXISTS cart_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cart_token CHAR(64) NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cart_product (cart_token, product_id),
  INDEX idx_cart_token (cart_token),
  CONSTRAINT fk_cart_product FOREIGN KEY (product_id) REFERENCES products(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_code VARCHAR(40) NOT NULL,
  public_token CHAR(64) NOT NULL,
  customer_name VARCHAR(120) NOT NULL,
  customer_phone VARCHAR(30) NOT NULL,
  customer_address TEXT NOT NULL,
  notes TEXT NULL,
  total DECIMAL(15,2) NOT NULL,
  status ENUM('pending','processed','cancelled','completed') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_code (order_code),
  UNIQUE KEY uq_order_public_token (public_token),
  INDEX idx_orders_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NULL,
  product_name VARCHAR(150) NOT NULL,
  price DECIMAL(15,2) NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  subtotal DECIMAL(15,2) NOT NULL,
  PRIMARY KEY (id),
  INDEX idx_order_items_order (order_id),
  CONSTRAINT fk_order_item_order FOREIGN KEY (order_id) REFERENCES orders(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_order_item_product FOREIGN KEY (product_id) REFERENCES products(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Contoh produk. Silakan ubah dari halaman /admin.php atau melalui SQL.
INSERT INTO products (name, description, price, stock, image_url, is_active)
SELECT 'Kaos Basic', 'Kaos nyaman untuk penggunaan sehari-hari.', 75000, 20, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM products WHERE name = 'Kaos Basic');

INSERT INTO products (name, description, price, stock, image_url, is_active)
SELECT 'Hoodie Polos', 'Hoodie bahan fleece dengan desain sederhana.', 165000, 12, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM products WHERE name = 'Hoodie Polos');

INSERT INTO products (name, description, price, stock, image_url, is_active)
SELECT 'Totebag', 'Tas serbaguna untuk aktivitas harian.', 45000, 30, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM products WHERE name = 'Totebag');
