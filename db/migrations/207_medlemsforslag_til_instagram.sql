-- Medlemmer foreslaar Instagram-innlegg.
--
-- Eieren, 24. september 2026: «Kan medlemmer faa en funksjon, som jeg maa
-- kunne skru av og paa i synlighet. De kan lage et forslag til instagram
-- post, enten en video paa maks 15 sekunder, eller et bilde […] maa
-- godkjennes av admin, paa denne maaten saa vil jeg faa mange fler til aa
-- legge ut og skryte av lissom keramikk, de maa skrive litt tekst ogsaa.»
--
-- Et godkjent forslag legges ut paa @lissom_keramikk, ikke paa medlemmets
-- egen konto (eierens valg samme dag). Innlegget faar medlemmets tekst, en
-- fast linje fra malen under, og hashtaggene — alltid med #lissomkeramikk.
--
-- «fil» er bare navnet (32 hex + .jpg eller .mp4) i opplastinger/forslag.
-- Fila er privat til forslaget er godkjent: Meta maa kunne hente den i det
-- oeyeblikket den legges ut, og ikke foer.

CREATE TABLE IF NOT EXISTS medlemsforslag (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id     BIGINT UNSIGNED NOT NULL,
  type          ENUM('bilde','video') NOT NULL,
  fil           VARCHAR(64)  NOT NULL,
  tekst         TEXT         NOT NULL,
  hashtags      VARCHAR(500) NOT NULL DEFAULT '',
  instagram     VARCHAR(64)  NOT NULL DEFAULT '',
  status        ENUM('venter','godkjent','publisert','avvist') NOT NULL DEFAULT 'venter',
  bildetekst    TEXT         NULL COMMENT 'Teksten som faktisk ble lagt ut',
  lenke         VARCHAR(255) NOT NULL DEFAULT '',
  behandlet_av  BIGINT UNSIGNED NULL,
  behandlet_at  DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_medlemsforslag_status (status, created_at),
  KEY ix_medlemsforslag_medlem (member_id, status),
  UNIQUE KEY uq_medlemsforslag_fil (fil),
  CONSTRAINT fk_medlemsforslag_medlem FOREIGN KEY (member_id) REFERENCES members (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bryteren i ⊙ Synlighet. AV fra start: eieren slaar den paa naar han har
-- sett funksjonen. Samme moenster som Vis/handleliste (migrasjon 184).
INSERT INTO content_blocks (nokkel, verdi)
SELECT 'Vis/medlemsforslag', 'nei' FROM (SELECT 1) AS d
 WHERE NOT EXISTS (SELECT 1 FROM content_blocks WHERE nokkel = 'Vis/medlemsforslag');

-- Den faste linja i innlegget. Eieren redigerer den i admin; {medlem} blir
-- byttet ut med @brukernavnet eller fornavnet.
INSERT INTO content_blocks (nokkel, verdi)
SELECT 'Marked/Medlemsforslag mal', '🏺 Laget av {medlem}, medlem hos Lissom Keramikk' FROM (SELECT 1) AS d
 WHERE NOT EXISTS (SELECT 1 FROM content_blocks WHERE nokkel = 'Marked/Medlemsforslag mal');
