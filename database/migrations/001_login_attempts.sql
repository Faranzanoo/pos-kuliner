-- Migrasi untuk database LAMA (yang dibuat dari pos_kuliner.sql sebelum perbaikan keamanan).
-- Menambah tabel pembatas percobaan login. Aman dijalankan berulang.
USE pos_kuliner;

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_address   VARCHAR(45)  NOT NULL,
  attempted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ip_waktu (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
