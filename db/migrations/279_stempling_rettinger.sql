-- Forslag om feil verkstedtid. Forslag endrer aldri check_ins direkte.
CREATE TABLE IF NOT EXISTS stempling_rettinger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id BIGINT UNSIGNED NOT NULL,
  check_in_id BIGINT UNSIGNED NULL,
  foreslatt_inn DATETIME NOT NULL,
  foreslatt_ut DATETIME NOT NULL,
  forklaring TEXT NOT NULL,
  status ENUM('apen','trenger_info','rettet','avslaatt') NOT NULL DEFAULT 'apen',
  admin_notat TEXT NULL,
  behandlet_av BIGINT UNSIGNED NULL,
  behandlet_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_stempling_sak_status (status, created_at),
  KEY ix_stempling_sak_medlem (member_id, created_at),
  KEY ix_stempling_sak_okt (check_in_id),
  CONSTRAINT fk_stempling_sak_medlem FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  CONSTRAINT fk_stempling_sak_okt FOREIGN KEY (check_in_id) REFERENCES check_ins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
