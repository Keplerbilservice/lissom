-- Når Lissom avlyser en dato (eieren, GO 9. oktober 2026).
-- godkjent av eieren: 2026-10-09
--
-- 1. Malen «kurs_avlyst»: beskjeden de påmeldte (betalt og aktive
--    reservasjoner) får når «Avlys dato» trykkes i /ny-admin. SMS og e-post,
--    av/på og kanal under Innstillinger › Meldinger. Teksten er eierens,
--    ordrett. Sendes én gang per påmelding, økt og kanal
--    (varsel_utsendinger «avlyst:<booking>:<økt>:<kanal>», migrasjon 270).
--    Gammel admins avlysning sender ingenting, som før.
--
-- 2. avlyst_tilbakebetal: kunden valgte «Få pengene tilbake» på Min side for
--    en plass betalt kontant/kort i kassa. Det kan ikke gå tilbake av seg
--    selv, så det blir en sak i «Må gjøres» («Tilbakebetal X kr til NN») til
--    noen merker den som betalt tilbake. Én rad per påmelding.
--
-- Bare tillegg; tåler å kjøres to ganger. Rører ingen andre maler.

INSERT INTO notification_templates (navn, kanal, emne, tekst, gruppe, aktiv) VALUES
('kurs_avlyst', 'epost_sms', 'Avlyst: {kurs} {dato}',
 'Hei {navn}! Dessverre må vi avlyse {kurs} {dato} kl. {tid}. Velg en ny dato eller få pengene tilbake på Min side. Beklager, og velkommen tilbake! Hilsen Lissom',
 'kurs', 1)
ON DUPLICATE KEY UPDATE navn = navn;

CREATE TABLE IF NOT EXISTS avlyst_tilbakebetal (
  booking_id BIGINT UNSIGNED NOT NULL,
  belop_ore  INT NOT NULL COMMENT 'Det som skal gis tilbake for hånd, i øre',
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  ferdig_at  DATETIME NULL COMMENT 'Merket betalt tilbake (UTC)',
  ferdig_av  BIGINT UNSIGNED NULL,
  PRIMARY KEY (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Avlyst dato: kontant/kort som skal betales tilbake for hånd (Må gjøres).';
