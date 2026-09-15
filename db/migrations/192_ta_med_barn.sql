-- «Ta med barn» — et tillegg paa medlemskapet.
--
-- Eieren, 15. september 2026: «medlemmene kan faa kjoepe ta med barn paa
-- sitt abonnement. Det skal koste 599,- pr maaned og gjelder for
-- kalendermaaned som medlemskapene. De maa betale det via vipps, foer det
-- blir aktivt ... Gjelder for barn opptil 12 aar. Leire og glasurer er ikke
-- inkludert ... Barnet kan ikke vaere alene i verkstedet ... den voksne maa
-- akseptere dette som vilkaar naar de kjoeper.» Ett barn per tillegg, og
-- barnets navn og alder skrives inn.
--
-- Én rad per kjoep. Kjoepet er en vanlig ordre med en Vipps-betaling
-- (orders/payments) — det er kvitteringen og oppgjoeret. Rada her sier hva
-- ordren gjaldt, og blir «aktiv» naar betalingen er i havn
-- (Booking::markerBetalt → Tillegg::aktiverForOrdre).
--
-- Prisen ligger i innstillinger, ikke i koden.

CREATE TABLE IF NOT EXISTS medlem_tillegg (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id             BIGINT UNSIGNED NOT NULL,
  type                  ENUM('barn') NOT NULL DEFAULT 'barn',
  maaned                CHAR(7) NOT NULL COMMENT 'Kalendermaaneden det gjelder, «2026-09»',
  barn_navn             VARCHAR(120) NULL,
  barn_alder            TINYINT UNSIGNED NULL,
  pris_ore              INT UNSIGNED NOT NULL,
  order_id              BIGINT UNSIGNED NULL,
  status                ENUM('venter','aktiv','avbrutt') NOT NULL DEFAULT 'venter',
  vilkaar_akseptert_at  DATETIME NOT NULL,
  betalt_at             DATETIME NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_tillegg_medlem (member_id, maaned, status),
  KEY ix_tillegg_ordre (order_id),
  CONSTRAINT fk_tillegg_medlem FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO innstillinger (nokkel, verdi) VALUES ('tillegg_barn_pris_ore', '59900');

-- Bryteren staar av til verkstedet slaar den paa (⊙ Synlighet → Paa Min side).
INSERT INTO content_blocks (nokkel, verdi)
SELECT 'Vis/tilleggbarn', 'nei' FROM (SELECT 1) AS d
 WHERE NOT EXISTS (SELECT 1 FROM content_blocks WHERE nokkel = 'Vis/tilleggbarn');
