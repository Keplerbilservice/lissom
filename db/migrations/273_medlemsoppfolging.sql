-- godkjent av eieren: 2026-10-12
--
-- Medlemmer og oppfølging (eieren 12. oktober 2026, «ja, fint» til visningen
-- «Medlemmer og oppfølging», alle sju arkene).
--
--   medlem_oppfolging        «Snakket med» med notat på oppfølgingssakene
--                            (Prøv Lissom og Lav aktivitet). Notatet står på
--                            medlemskortet, og saken forsvinner fra Må gjøres.
--   members.paaminnelse_sms  Bryteren per medlem på medlemskortet:
--                            «Påminnelse-SMS når hen ikke kommer». På fra start
--                            — den globale bryteren (malen) er av.
--   tre SMS-maler            Tekstene ordrett fra visningen. Malens av/på er
--                            bryteren under Innstillinger › Varsler:
--                            intern_prove_slutter_sms  på  (til admin-mobilen kl. 09)
--                            prove_slutter_sms         AV  (til prøvemedlemmet, 3 dager før)
--                            medlem_savner_deg_sms     AV  (til medlemmer som ikke kommer)
--
-- Bare tillegg; tåler å kjøres to ganger. Rører ingen andre maler.

CREATE TABLE IF NOT EXISTS medlem_oppfolging (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id   BIGINT UNSIGNED NOT NULL,
  sak         VARCHAR(16)     NOT NULL COMMENT 'prove = Prøv Lissom slutter snart, lav = lav aktivitet',
  notat       TEXT            NULL,
  av          BIGINT UNSIGNED NULL,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_medlem_oppfolging (member_id, sak, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE members
  ADD COLUMN IF NOT EXISTS paaminnelse_sms TINYINT(1) NOT NULL DEFAULT 1
  COMMENT 'Påminnelse-SMS når medlemmet ikke kommer (migrasjon 273). Den globale bryteren er malen medlem_savner_deg_sms.';

INSERT INTO notification_templates (navn, kanal, emne, tekst, aktiv, gruppe) VALUES
('intern_prove_slutter_sms', 'sms', NULL,
 'Prøv Lissom slutter {dato} for {antall} stk: {liste}. Se I dag i admin.', 1, 'system'),
('prove_slutter_sms', 'sms', NULL,
 'Hei {navn}! Prøv Lissom varer til {dag} {dato}. Vil du fortsette, velger du medlemskap her: lissom.no/min-side Hilsen Monica', 0, 'system'),
('medlem_savner_deg_sms', 'sms', NULL,
 'Hei {navn}! Vi savner deg i verkstedet 🙂 Du har {timer} igjen denne måneden. Velkommen innom når det passer. Hilsen Monica, Lissom', 0, 'system')
ON DUPLICATE KEY UPDATE navn = navn;
