-- Delrefusjon der plassen beholdes = prisavslag (kontrolloeren, 2. oktober
-- 2026, regel valgt for eieren). Booking::prisavslagEtterDelrefusjon() senker
-- beloepet paa plassen naar en betaling refunderes delvis, og logger hvert
-- avslag her.
--
-- Bare tabellen. Eksisterende delvis refunderte plasser endres IKKE her
-- (prod-data vi ikke har sett, kan vaere rettet for haand): de fanges av den
-- daglige datasjekken (L12 booking_betaling_uenig) og rettes for haand ved
-- behov. Trygg aa kjoere to ganger. Ingen rader endres eller slettes.

CREATE TABLE IF NOT EXISTS booking_prisavslag (
    booking_id  BIGINT UNSIGNED NOT NULL,
    payment_id  BIGINT UNSIGNED NOT NULL,
    avslag_ore  INT NOT NULL DEFAULT 0 COMMENT 'Trukket fra bookings.belop_ore for denne betalingen',
    kilde       VARCHAR(32) NOT NULL DEFAULT 'refusjon',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (booking_id, payment_id),
    KEY ix_prisavslag_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
