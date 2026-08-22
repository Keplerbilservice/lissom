-- Soknad om medlemskap.
--
-- Vipps Login sier hvem noen er. Det sier ikke at de skal inn i verkstedet.
-- Alle som logger inn far en rad i members med status «ingen» — de er kunder,
-- ikke medlemmer. For aa bli medlem sender man en soknad her, og verkstedet
-- godkjenner den. Forst da settes status til «prove» eller «aktiv».

CREATE TABLE membership_applications (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id    BIGINT UNSIGNED NOT NULL,
  onsket_type  VARCHAR(64) NULL COMMENT 'Prov Lissom, 30 timer, Arsmedlemskap, Fri tilgang',
  navn         VARCHAR(191) NOT NULL DEFAULT '',
  epost        VARCHAR(191) NULL,
  telefon      VARCHAR(32)  NULL,
  erfaring     TEXT NULL COMMENT 'Hva soker har gjort for — verkstedet ma vite om folk kan bruke utstyret',
  melding      TEXT NULL,
  status       ENUM('venter','godkjent','avslatt') NOT NULL DEFAULT 'venter',
  behandlet_av BIGINT UNSIGNED NULL,
  behandlet_at DATETIME NULL,
  begrunnelse  VARCHAR(500) NULL COMMENT 'Vises til sokeren ved avslag',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_soknad_status (status),
  KEY ix_soknad_member (member_id),
  CONSTRAINT fk_soknad_member FOREIGN KEY (member_id) REFERENCES members (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
