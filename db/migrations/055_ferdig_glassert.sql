-- «Ferdig glassert» ned paa deltakernivaa.
--
-- Bestilt 26. august. Kartleggingen ligger i docs/FERDIG-GLASSERT.md; her er
-- de tre tingene som faktisk maa lagres, og hvorfor de ikke fantes fra for.
--
-- Alt annet gjenbrukes: meldingene gaar gjennom notifications som for og
-- lagrer allerede mottaker, kanal, emne, tekst, tidspunkt og om det gikk.
-- Filene gaar gjennom Bilder::taImot() som alt annet. Ingen ny meldingslogikk
-- og ingen ny lagringsloesning.

-- 1) Bildene deltakeren laster opp av keramikken sin.
--
-- Det finnes ingen struktur for flere bilder knyttet til én rad.
-- courses.bilder er en JSON-liste paa et kurs, member_sales.bilde er ett
-- bilde paa én vare, bilde_fokus er filnavn → utsnitt. En JSON-liste i
-- bookings ville gjort «hvem lastet opp dette» umulig aa svare paa.
--
-- booking_id er deltakeren, kurset og datoen i én — vi trenger ikke lagre
-- noen av delene om igjen.
CREATE TABLE IF NOT EXISTS deltaker_bilder (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id    BIGINT UNSIGNED NOT NULL,
    fil           VARCHAR(64)  NOT NULL COMMENT 'Filnavnet Bilder::taImot() ga',
    lastet_opp_av BIGINT UNSIGNED NULL   COMMENT 'Medlem, eller NULL naar verkstedet la det inn',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_booking (booking_id),
    -- Samme fil skal ikke kunne staa to ganger paa samme deltaker.
    UNIQUE KEY uniq_fil (fil)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Det interne notatet.
--
-- bookings.notat finnes, men er 255 tegn og brukes av systemet selv til aa
-- merke hvor paameldingen kom fra («Fra ventelista»). Skriver verkstedet
-- «gronn skaal, hylle 3» der, forsvinner det merket, og en manuell paamelding
-- ser ut som en nettbestilling i ettertid.
--
-- Notatet skal aldri vises til deltakeren. Ingen kundevendt endepunkt leser
-- denne kolonnen.
ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS internt_notat TEXT NULL
        COMMENT 'Bare for verkstedet. Vises aldri til deltakeren.';

-- 3) «Hentet».
--
-- Fem av de seks statusene i bestillingen kan regnes ut av det som finnes:
-- ingen varselrad = ikke sendt, siste rad sendt = sendt, siste rad feilet =
-- feilet, og saa videre. «Hentet» kan ikke regnes ut — ingen vet at noen kom
-- innom og tok med seg skaala si. Den maa noen krysse av for.
ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS hentet_at DATETIME NULL
        COMMENT 'Naar keramikken faktisk ble hentet';

CREATE INDEX idx_hentet ON bookings (hentet_at);
