-- Dashboardet paa telefon husker hva du bruker.
--
-- Eieren, 15. september 2026: «kan dashboard ogsaa laere? At det viser de
-- knappene her som brukes mest i hele admin?» — og etterpaa: «de som har
-- aktive varslinger, deretter de mest brukte. Om de ikke brukes paa 1 maaned
-- saa fjernes de slik at lista holdes saa ren».
--
-- Én rad per admin per kort. «antall» er hvor mange ganger kortet er trykket,
-- «sist_brukt» naar det sist skjedde. Rekkefoelgen paa telefonen blir:
--
--   1. alt som har et aktivt varsel — dugnad, ubetalt, paamelding og andre
--      med et tall som venter. De staar foerst uansett hvor lite de brukes.
--   2. saa de mest brukte, etter «antall».
--   3. har et kort ikke vaert brukt paa 30 dager, faller det ut og ligger i
--      menyen til det brukes igjen. Et varsel henter det tilbake med en gang.
--
-- Per admin, ikke per verksted: Monica og eieren jobber ulikt, og skal ikke
-- dra hverandres rekkefoelge med seg. Telefon og PC deler den samme, fordi
-- den ligger paa kontoen og ikke i nettleseren.
--
-- «kort» er en fast noekkel koden kjenner («ubetalt», «kasse», «dugnad»), ikke
-- teksten paa skjermen. Doepes et kort om, foelger tellingen med.

CREATE TABLE IF NOT EXISTS admin_kortbruk (
    member_id  BIGINT UNSIGNED NOT NULL,
    kort       VARCHAR(64)     NOT NULL COMMENT 'Fast noekkel, ikke teksten paa skjermen.',
    antall     INT UNSIGNED    NOT NULL DEFAULT 0,
    sist_brukt DATETIME        NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (member_id, kort),
    KEY ix_kortbruk_sist (member_id, sist_brukt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
