-- Hvem som slettet meldingen.
--
-- Eieren, 11. september 2026: «min side kan angre meldingen som admin har
-- slettet!»
--
-- Slettingen er myk: teksten blir staaende, raden faar et tidspunkt, og den
-- som leser ser «Meldingen er slettet». Det gjorde at den kunne hentes
-- tilbake — som eieren ba om dagen for. Men reglen var «din egen melding,
-- din egen knapp», og da kunne medlemmet hente tilbake det verkstedet
-- nettopp hadde ryddet vekk.
--
-- «slettet_at» sier NAAR, ikke AV HVEM. Uten det kan ingen skille de to
-- tilfellene fra hverandre. Denne kolonnen sier hvem.
--
-- Regelen blir: slettet du den selv, kan du hente den tilbake. Slettet
-- verkstedet den, er det bare verkstedet som kan det.
--
-- Gamle slettinger har ingen verdi her. De teller som «ikke mine», og bare
-- admin kan hente dem tilbake. Det er den trygge veien: heller én knapp for
-- lite hos et medlem enn én for mye.

ALTER TABLE chat_meldinger
    ADD COLUMN slettet_av BIGINT UNSIGNED NULL
        COMMENT 'Hvem som slettet den. NULL = ikke slettet, eller slettet for migrasjon 156.'
        AFTER slettet_at;
