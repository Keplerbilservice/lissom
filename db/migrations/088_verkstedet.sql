-- Verkstedet: notatene, paaminnelsene, vaktene og brenningene.
--
-- ── Notatene og paaminnelsene ─────────────────────────────────────────
--
-- De sto i kalenderen og virket: du skrev et notat, og det sto der neste
-- gang. Men det laa i «localStorage» — i nettleseren paa den maskinen du
-- skrev det paa. Skrev du en paaminnelse paa telefonen, fantes den ikke paa
-- PC-en, og toemte du nettleserdataene var den borte. Det er ikke til aa se
-- paa skjermen, og det er nettopp derfor det er farlig: eieren skrev noe hun
-- trodde var lagret.
--
-- Notatet er personlig — «Notater til meg selv» — saa det hoerer til den som
-- skrev det. Én rad per person; teksten er ett felt, slik den er paa skjermen.
--
-- ── Vaktene og brenningene ────────────────────────────────────────────
--
-- Kalenderen har hatt farger for «vakt» og «brenning» siden den ble hentet
-- inn, men ingen av delene fantes i basen. En ovn som er opptatt til fredag
-- er noe man maa vite naar man setter opp et kurs — og hvem som er i
-- verkstedet naar, sto ingen steder.

-- Ett notat per person.
CREATE TABLE IF NOT EXISTS verksted_notater (
  member_id  BIGINT UNSIGNED NOT NULL,
  tekst      TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (member_id),
  CONSTRAINT fk_notat_medlem FOREIGN KEY (member_id)
    REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Notater til meg selv, i kalenderen. Ett per person.';

-- Paaminnelsene. «gjort» krysses av; raden blir staaende til den slettes,
-- slik lista i kalenderen alltid har virket.
CREATE TABLE IF NOT EXISTS verksted_paaminnelser (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id  BIGINT UNSIGNED NOT NULL,
  tekst      VARCHAR(300) NOT NULL,
  gjort      TINYINT(1) NOT NULL DEFAULT 0,
  frist      DATE NULL COMMENT 'Valgfri dato. NULL = ingen frist.',
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY ix_pamin_medlem (member_id, gjort, id),
  CONSTRAINT fk_pamin_medlem FOREIGN KEY (member_id)
    REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Paaminnelser i kalenderen. Personlige.';

-- Hvem som er i verkstedet naar.
--
-- Peker paa kursholderregisteret, som oektene gjor. «ON DELETE CASCADE» her,
-- ikke SET NULL: en vakt uten noen paa er ingen vakt — i motsetning til en
-- okt, som gikk selv om vi ikke lenger vet hvem som holdt den.
CREATE TABLE IF NOT EXISTS vakter (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kursholder_id BIGINT UNSIGNED NOT NULL,
  start_tid     DATETIME NOT NULL COMMENT 'UTC, som resten av kalenderen',
  slutt_tid     DATETIME NOT NULL,
  notat         VARCHAR(300) NULL,
  created_at    DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY ix_vakt_tid (start_tid),
  KEY ix_vakt_holder (kursholder_id, start_tid),
  CONSTRAINT fk_vakt_holder FOREIGN KEY (kursholder_id)
    REFERENCES kursholdere (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Vakter i verkstedet.';

-- Brenningene. Ovnen er opptatt fra den settes paa til den er kald.
CREATE TABLE IF NOT EXISTS brenninger (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slag       VARCHAR(24) NOT NULL DEFAULT 'raabrann'
             COMMENT 'raabrann | glasurbrann | annet',
  ovn        VARCHAR(64) NULL COMMENT 'Hvilken ovn, naar det er flere',
  start_tid  DATETIME NOT NULL COMMENT 'UTC',
  slutt_tid  DATETIME NOT NULL COMMENT 'Naar den er ute igjen — ikke naar den slaas av',
  notat      VARCHAR(300) NULL,
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY ix_brenning_tid (start_tid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Raabrann og glasurbrann. Viser naar ovnen er opptatt.';
