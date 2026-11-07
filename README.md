# Toko Kita — PHP + Docker + MySQL + WhatsApp

Aplikasi toko sederhana untuk dua EC2 private di belakang Application Load Balancer (ALB).

## Fitur
- Daftar produk dibaca dari MySQL.
- Keranjang, data pesanan, dan stok disimpan di MySQL, sehingga EC2 A dan B memakai data yang sama.
- Checkout menyimpan pesanan dan membuat tautan `wa.me` dengan rincian pesanan.
- Admin produk sederhana di `/admin.php` (HTTP Basic Auth).
- `/health.php` untuk ALB (liveness; tidak bergantung pada database).
- `/ready.php` untuk pengecekan koneksi database (jangan gunakan sebagai ALB liveness check bila ingin aplikasi tetap dianggap hidup saat database sedang gangguan).

## 1. Buat database MySQL di Amazon RDS

Rekomendasi: RDS for MySQL di VPC yang sama dengan EC2 A dan B, `Public access = No`, dan DB subnet group menggunakan private subnets. RDS menjadi database bersama bagi dua EC2.

Contoh nilai saat membuat RDS:
- Engine: MySQL (gunakan versi yang tersedia dan didukung pada akun/region Anda).
- Template: Dev/Test untuk lab atau Production sesuai kebutuhan.
- DB instance identifier: `toko-mysql`.
- Initial database name: `toko_db` (boleh dibuat oleh SQL di bawah juga).
- Master username: buat username sendiri, jangan gunakan `root` untuk aplikasi.
- VPC: VPC yang sama dengan EC2 A dan B.
- Public access: **No**.
- Port: `3306`.
- DB security group: misalnya `sg-rds-toko`.

### Security Group yang harus benar

Misalkan:
- `sg-ec2-app` = Security Group yang dipasang pada EC2 A dan EC2 B.
- `sg-rds-toko` = Security Group yang dipasang pada RDS.

Inbound rules:

| Security Group | Type | Port | Source |
|---|---|---:|---|
| `sg-rds-toko` | MySQL/Aurora | 3306 | `sg-ec2-app` |
| `sg-ec2-app` | HTTP | 80 | Security Group ALB kamu |

Jangan membuka port 3306 ke `0.0.0.0/0`. EC2 A/B perlu dapat mengakses RDS melalui outbound TCP 3306; outbound default allow-all biasanya sudah memenuhi. RDS dan EC2 harus berada di VPC yang memiliki jalur jaringan satu sama lain.

### Jalankan schema SQL

Setelah RDS aktif, salin file `database/schema.sql` ke EC2 yang bisa mencapai RDS (bisa EC2 A/B melalui SSH/SSM/bastion). Pasang klien MySQL di EC2 tersebut, kemudian:

Debian/Ubuntu:

```bash
sudo apt update
sudo apt install -y default-mysql-client
```

Impor schema (ganti endpoint dan username):

```bash
mysql -h ENDPOINT_RDS_ANDA -P 3306 -u MASTER_USER -p < database/schema.sql
```

Masukkan password ketika diminta. SQL akan membuat database `toko_db`, tabel, dan beberapa produk contoh.

**Catatan keamanan:** untuk lab ini schema dijalankan menggunakan master user. Setelah itu, sebaiknya buat user aplikasi dengan hak akses terbatas, bukan menggunakan master user pada aplikasi:

```sql
CREATE USER 'toko_app'@'%' IDENTIFIED BY 'GANTI_PASSWORD_KUAT';
GRANT SELECT, INSERT, UPDATE, DELETE ON toko_db.* TO 'toko_app'@'%';
FLUSH PRIVILEGES;
```

Simpan password tersebut di `.env` aplikasi. RDS Security Group tetap membatasi akses jaringan hanya dari `sg-ec2-app`.

## 2. Siapkan aplikasi

Ekstrak folder `toko-wa` di komputer atau EC2. Buat `.env` dari contoh:

```bash
cp .env.example .env
nano .env
```

Isi nilai berikut:
- `DB_HOST`: endpoint RDS, contoh `toko-mysql.xxxxx.ap-southeast-1.rds.amazonaws.com` (tanpa `https://`).
- `DB_PORT`: `3306`.
- `DB_NAME`: `toko_db`.
- `DB_USER`: `toko_app`.
- `DB_PASSWORD`: password user aplikasi yang dibuat di SQL.
- `WA_NUMBER`: nomor WhatsApp toko dengan kode negara, angka saja. Contoh `6281234567890`, bukan `081234567890`.
- `APP_KEY`: string acak minimal 32 karakter. Nilainya harus **sama persis** pada EC2 A dan EC2 B.
- `ADMIN_USER` dan `ADMIN_PASSWORD`: kredensial admin yang kuat; buat sama pada kedua EC2.

Buat APP_KEY acak di Linux:

```bash
openssl rand -hex 32
```

Jangan unggah `.env` ke GitHub dan jangan masukkan password ke Dockerfile.

## 3. Build image Docker

Di direktori `toko-wa`:

```bash
docker build -t toko-wa:1.0 .
```

Jalankan container:

```bash
docker run -d \
  --name toko-wa \
  --restart unless-stopped \
  --env-file .env \
  -p 80:80 \
  toko-wa:1.0
```

Lihat status dan log:

```bash
docker ps
docker logs --tail 100 toko-wa
```

Tes dari EC2:

```bash
curl -i http://localhost/health.php
curl -i http://localhost/ready.php
```

`health.php` harus membalas `200` dan `ok`. `ready.php` harus membalas `200` dan `ready` jika database terhubung.

## 4. Deploy ke EC2 A dan EC2 B

Kedua EC2 harus menjalankan **image versi yang sama** dan file `.env` yang memiliki nilai sama (khususnya `APP_KEY`, DB endpoint, dan pengaturan aplikasi).

Cara sederhana untuk lab:
1. Salin folder aplikasi ke EC2 A dan build image dengan nama `toko-wa:1.0`.
2. Salin folder yang sama ke EC2 B dan build image dengan nama `toko-wa:1.0`.
3. Buat `.env` pada masing-masing EC2 dengan konfigurasi yang sama.
4. Jalankan container dengan nama `toko-wa` pada keduanya.

Jika EC2 berada di private subnet dan tidak memiliki NAT/VPC endpoint, EC2 mungkin tidak dapat mengunduh base image Docker atau mengakses GitHub. Dalam kondisi itu, build image di mesin yang memiliki internet lalu ekspor dan pindahkan image ke EC2 melalui jalur akses yang kamu miliki (misalnya bastion/SSM/S3):

```bash
# Mesin build
docker save toko-wa:1.0 -o toko-wa-1.0.tar

# Pada EC2 A dan EC2 B setelah file tar dipindahkan
docker load -i toko-wa-1.0.tar
```

Pastikan Docker sudah terpasang pada kedua EC2. Jangan menjalankan container di host yang sama-sama memakai port 80 untuk service lain.

## 5. Target Group dan ALB

Untuk Target Group instance/port aplikasi:
- Protocol: HTTP.
- Port: `80`.
- Health check protocol: HTTP.
- Health check path: `/health.php`.
- Success code: `200`.
- Daftarkan EC2 A dan EC2 B sebagai targets.

Security Group ALB menerima HTTP/HTTPS dari pengguna. Security Group EC2 hanya menerima port 80 dari Security Group ALB, bukan dari seluruh internet. `/health.php` sengaja tidak mengecek MySQL; gunakan `/ready.php` untuk tes DB saat troubleshooting.

## 6. Akses halaman

Buka DNS ALB, misalnya:

```text
http://NAMA-LOAD-BALANCER-ANDA
```

Halaman admin:

```text
http://NAMA-LOAD-BALANCER-ANDA/admin.php
```

Browser akan meminta username dan password Basic Auth sesuai `ADMIN_USER` dan `ADMIN_PASSWORD`.

Tambahkan produk, kemudian uji alur: tambah produk → keranjang → checkout → simpan pesanan → klik tombol WhatsApp → tekan Kirim di WhatsApp.

**Penting:** aktifkan HTTPS pada ALB sebelum dipakai publik. HTTP Basic Auth dan data pelanggan tidak aman dikirim tanpa TLS. Untuk penggunaan produksi, tambahkan pengelolaan secret yang lebih baik, backup RDS, monitoring, dan kebijakan privasi.

## 7. Menghapus container dan image Docker lama

Lihat container dan image:

```bash
docker ps -a
docker images
```

Hentikan lalu hapus container yang memakai image lama:

```bash
docker stop NAMA_CONTAINER

docker rm NAMA_CONTAINER
```

Hapus image berdasarkan repository:tag:

```bash
docker rmi NAMA_IMAGE:TAG
```

Contoh untuk image bernama `web-hallo-docker`:

```bash
docker ps -a
docker stop web-hallo-docker
docker rm web-hallo-docker
docker images
docker rmi web-hallo-docker:latest
```

Sesuaikan `latest` dengan TAG yang benar-benar terlihat pada output `docker images`. Jika ada beberapa container yang masih memakai image tersebut, hapus container-container itu terlebih dahulu. Untuk membersihkan semua image yang tidak sedang dipakai container:

```bash
docker image prune -a
```

**Hati-hati:** perintah prune menghapus semua image lokal yang tidak sedang dipakai container, bukan hanya image toko. Jangan jalankan jika masih ada image lain yang ingin disimpan.

Jika image lama berada di Amazon ECR, hapus repository/image dari halaman **ECR → Private repositories → pilih repository → Images → Delete**. Menghapus image di EC2 tidak otomatis menghapus image di ECR.

## Troubleshooting koneksi database

Dari EC2 A atau B, cek DNS dan port RDS:

```bash
getent hosts ENDPOINT_RDS_ANDA
nc -vz ENDPOINT_RDS_ANDA 3306
```

Jika `nc` gagal, periksa:
1. RDS dan EC2 berada di VPC yang benar.
2. RDS tidak perlu public access jika diakses dari EC2 dalam VPC yang sama.
3. Inbound `sg-rds-toko` mengizinkan TCP 3306 dari `sg-ec2-app`.
4. Outbound Security Group EC2 mengizinkan koneksi ke RDS.
5. `DB_HOST`, `DB_NAME`, `DB_USER`, dan `DB_PASSWORD` benar.

Jika halaman tampil tetapi pesanan/produk tidak sama di A dan B, pastikan kedua container menggunakan endpoint RDS yang sama, bukan database lokal masing-masing.
# toko-saya
