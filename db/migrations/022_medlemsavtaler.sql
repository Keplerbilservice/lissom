-- Manedstrekk for medlemskap (Vipps Recurring).
--
-- Medlemskapet ble til naa gjort opp direkte med verkstedet. «Fornyes 1.
-- september» sto som fast tekst paa Min side — en paastand om et trekk som
-- ikke fantes noe sted.
--
-- En avtale i Vipps er noe kunden godkjenner én gang. Deretter belastes den
-- hver maaned til noen stopper den. Vi lagrer avtale-ID-en, og hvert trekk
-- blir en helt vanlig rad i payments.

-- Prisene laa i designfila. Serveren maa kjenne dem — det er den som ber
-- Vipps om et belop.
CREATE TABLE IF NOT EXISTS membership_plans (
  navn        VARCHAR(64) NOT NULL,
  pris_ore    INT UNSIGNED NOT NULL,
  intervall   ENUM('maaned','aar') NOT NULL DEFAULT 'maaned',
  timer       SMALLINT UNSIGNED NULL COMMENT 'NULL = fri tilgang',
  binding_mnd SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  engangs     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Proveperiode: trekkes bare én gang',
  sortering   INT NOT NULL DEFAULT 0,
  aktiv       TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (navn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO membership_plans (navn, pris_ore, timer, binding_mnd, engangs, sortering) VALUES
('Prøv Lissom',    99000,  8,    0,  1, 1),
('30 timer',      259000,  30,   0,  0, 2),
('Årsmedlemskap', 199000,  35,  12,  0, 3),
('Fri tilgang',   499000,  NULL, 0,  0, 4);

CREATE TABLE IF NOT EXISTS subscriptions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id     BIGINT UNSIGNED NOT NULL,
  plan          VARCHAR(64) NOT NULL,
  pris_ore      INT UNSIGNED NOT NULL COMMENT 'Prisen da avtalen ble inngaatt',
  -- Avtale-ID-en fra Vipps. Den er noekkelen til alt senere: status,
  -- trekk og oppsigelse.
  vipps_agreement_id VARCHAR(64) NULL,
  status        ENUM('venter','aktiv','stoppet','avslaatt','utlopt') NOT NULL DEFAULT 'venter',
  -- Naar neste trekk skal skje. Trekket kjores av cron, ikke av et
  -- sidevisning — ellers ville det avhengt av at noen var innom.
  neste_trekk   DATE NULL,
  siste_trekk   DATE NULL,
  binding_til   DATE NULL,
  sagt_opp_at   DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subs_agreement (vipps_agreement_id),
  KEY ix_subs_member (member_id, status),
  KEY ix_subs_trekk (status, neste_trekk),
  CONSTRAINT fk_subs_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trekket knyttes til avtalen det kom fra.
ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS subscription_id BIGINT UNSIGNED NULL
    AFTER member_id;
