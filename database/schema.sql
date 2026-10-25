-- ======================================================================
--  POS Kuliner — skema database + data DEMO
--
--  Semua data di bawah ini fiktif. Akun demo:
--      admin   / Admin@12345
--      kasir1  / Kasir@12345
--  GANTI password ini kalau aplikasi dipasang di server yang bisa diakses orang lain.
--
--  Cara pakai: import file ini lewat HeidiSQL / phpMyAdmin, atau
--      mysql -u root < database/schema.sql
--  Kompatibel dengan MySQL 5.7+/8 dan MariaDB 10.4+.
-- ======================================================================

CREATE DATABASE IF NOT EXISTS pos_kuliner CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pos_kuliner;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS detail_transaksi, transaksi, order_aktif, shift, menu_varian, menu,
                     promo, meja, pengaturan, users, login_attempts;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  id_user    INT NOT NULL AUTO_INCREMENT,
  username   VARCHAR(50)  NOT NULL,
  password   VARCHAR(255) NOT NULL,
  role       ENUM('admin','kasir') NOT NULL DEFAULT 'kasir',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_user),
  UNIQUE KEY username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pengaturan (
  id          INT NOT NULL AUTO_INCREMENT,
  nama_toko   VARCHAR(100) NOT NULL DEFAULT 'POS Kuliner',
  tagline     VARCHAR(150) NOT NULL DEFAULT 'Ayam Goreng & Kulit Krispi',
  alamat      VARCHAR(255) NOT NULL DEFAULT '',
  no_telp     VARCHAR(20)  NOT NULL DEFAULT '',
  footer_struk VARCHAR(150) NOT NULL DEFAULT 'Terima kasih',
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE meja (
  id_meja    INT NOT NULL AUTO_INCREMENT,
  nomor_meja INT NOT NULL,
  status     ENUM('kosong','terisi') NOT NULL DEFAULT 'kosong',
  PRIMARY KEY (id_meja)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu (
  id_menu       INT NOT NULL AUTO_INCREMENT,
  nama_menu     VARCHAR(100) NOT NULL,
  kategori      ENUM('Makanan','Minuman','Paket') NOT NULL DEFAULT 'Makanan',
  harga         INT NOT NULL,
  gambar        VARCHAR(255) DEFAULT NULL,
  status        ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  stok          INT DEFAULT NULL,
  is_stok_aktif TINYINT(1) NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_menu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_varian (
  id_varian     INT NOT NULL AUTO_INCREMENT,
  id_menu       INT NOT NULL,
  nama_varian   VARCHAR(100) NOT NULL,
  harga_tambah  INT NOT NULL DEFAULT 0,
  stok          INT DEFAULT NULL,
  is_stok_aktif TINYINT(1) NOT NULL DEFAULT 0,
  status        ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  PRIMARY KEY (id_varian),
  KEY id_menu (id_menu),
  CONSTRAINT menu_varian_ibfk_1 FOREIGN KEY (id_menu) REFERENCES menu (id_menu) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE promo (
  id_promo      INT NOT NULL AUTO_INCREMENT,
  kode_promo    VARCHAR(20)  NOT NULL,
  nama_promo    VARCHAR(100) NOT NULL,
  tipe_diskon   ENUM('persen','nominal') NOT NULL DEFAULT 'persen',
  nilai_diskon  INT NOT NULL DEFAULT 0,
  min_transaksi INT NOT NULL DEFAULT 0,
  maks_diskon   INT DEFAULT NULL,
  kuota         INT DEFAULT NULL,
  terpakai      INT NOT NULL DEFAULT 0,
  tgl_mulai     DATE DEFAULT NULL,
  tgl_selesai   DATE DEFAULT NULL,
  status        ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_promo),
  UNIQUE KEY kode_promo (kode_promo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE shift (
  id_shift      INT NOT NULL AUTO_INCREMENT,
  id_user       INT NOT NULL,
  nama_user     VARCHAR(50) NOT NULL,
  waktu_mulai   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  waktu_selesai DATETIME DEFAULT NULL,
  status        ENUM('aktif','selesai') NOT NULL DEFAULT 'aktif',
  catatan       VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id_shift),
  KEY id_user (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_aktif (
  id_order   INT NOT NULL AUTO_INCREMENT,
  id_meja    INT NOT NULL,
  id_shift   INT DEFAULT NULL,
  id_user    INT NOT NULL,
  cart       LONGTEXT NOT NULL,
  total      INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_order),
  UNIQUE KEY id_meja (id_meja),
  KEY id_shift (id_shift),
  KEY id_user (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transaksi (
  id_transaksi         INT NOT NULL AUTO_INCREMENT,
  kode_transaksi       VARCHAR(30) NOT NULL,
  id_shift             INT DEFAULT NULL,
  id_user              INT DEFAULT NULL,
  id_meja              INT DEFAULT NULL,
  nomor_meja           INT DEFAULT NULL,
  tanggal              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  total                INT NOT NULL,
  bayar                INT NOT NULL,
  kembalian            INT NOT NULL,
  metode_bayar         ENUM('Cash','QRIS','Debit','Kredit','Gojek','GrabFood','ShopeeFood') NOT NULL DEFAULT 'Cash',
  id_promo             INT DEFAULT NULL,
  diskon               INT NOT NULL DEFAULT 0,
  total_sebelum_diskon INT NOT NULL DEFAULT 0,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_transaksi),
  UNIQUE KEY kode_transaksi (kode_transaksi),
  KEY idx_tanggal (tanggal),
  KEY id_shift (id_shift)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE detail_transaksi (
  id_detail    INT NOT NULL AUTO_INCREMENT,
  id_transaksi INT NOT NULL,
  id_menu      INT NOT NULL,
  nama_menu    VARCHAR(100) NOT NULL,
  harga        INT NOT NULL,
  qty          INT NOT NULL,
  subtotal     INT NOT NULL,
  PRIMARY KEY (id_detail),
  KEY id_transaksi (id_transaksi),
  KEY id_menu (id_menu),
  CONSTRAINT detail_transaksi_ibfk_1 FOREIGN KEY (id_transaksi) REFERENCES transaksi (id_transaksi) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT detail_transaksi_ibfk_2 FOREIGN KEY (id_menu) REFERENCES menu (id_menu) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pembatas percobaan login (lihat login.php)
CREATE TABLE login_attempts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_address   VARCHAR(45) NOT NULL,
  attempted_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ip_waktu (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------- DATA DEMO -------------------------------
INSERT INTO users (username, password, role) VALUES
 ('admin',  '$2y$10$GDSAWji2H6.K.uE0.SjpOeHGXOgP/VvfwWWHFsvrqL.nMOVAQusGe', 'admin'),
 ('kasir1', '$2y$10$2/jiGuEltthQTykjtpJ6.uxTqwfOJBhfTrYV8h47fERn2qLav5E6W', 'kasir');

INSERT INTO pengaturan (nama_toko, tagline, alamat, no_telp, footer_struk) VALUES
 ('POS Kuliner Demo', 'Ayam Goreng & Kulit Krispi', 'Jl. Contoh No. 1', '08123456789', 'Terima kasih, selamat menikmati!');

INSERT INTO meja (nomor_meja, status) VALUES (1,'kosong'),(2,'kosong'),(3,'kosong'),(4,'kosong'),(5,'kosong'),
                                             (6,'kosong'),(7,'kosong'),(8,'kosong'),(9,'kosong'),(10,'kosong');

INSERT INTO menu (nama_menu, kategori, harga) VALUES
 ('Ayam Goreng Original', 'Makanan', 12000),
 ('Ayam Geprek Pedas',    'Makanan', 15000),
 ('Kulit Krispi',         'Makanan', 10000),
 ('Nasi Putih',           'Makanan',  5000),
 ('Paket Ayam + Nasi',    'Paket',   18000),
 ('Paket Geprek + Es Teh','Paket',   22000),
 ('Es Teh Manis',         'Minuman',  5000),
 ('Es Jeruk',             'Minuman',  7000);

INSERT INTO menu_varian (id_menu, nama_varian, harga_tambah) VALUES
 (8, 'Reguler', 0),
 (8, 'Large',   2000);

-- Tanggal promo dibuat relatif terhadap hari import, supaya selalu aktif saat dicoba
INSERT INTO promo (kode_promo, nama_promo, tipe_diskon, nilai_diskon, min_transaksi, maks_diskon, kuota, tgl_mulai, tgl_selesai) VALUES
 ('DISKON10', 'Diskon 10%',    'persen',  10, 20000, 15000, 100, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR)),
 ('HEMAT5K',  'Hemat 5 Ribu',  'nominal', 5000, 15000, NULL,  50, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR));
