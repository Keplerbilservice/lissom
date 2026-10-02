-- Delrefusjon der plassen beholdes = prisavslag (kontrolloeren, 2. oktober
-- 2026, regel valgt for eieren). Booking::prisavslagEtterDelrefusjon() senker
-- beloepet paa plassen naar en betaling refunderes delvis; denne gjoer det
-- samme én gang for plasser som alt var delvis refundert.
--
-- Trygg aa kjoere to ganger: hvert avslag foeres i booking_prisavslag, og
-- bare det som ikke alt er foert, trekkes. Ingen rader slettes. Ingen tekst
-- i e-postmaler eller innhold endres.

CREATE TABLE IF NOT EXISTS booking_prisavslag (
    booking_id  BIGINT UNSIGNED NOT NULL,
    payment_id  BIGINT UNSIGNED NOT NULL,
    avslag_ore  INT NOT NULL DEFAULT 0 COMMENT 'Trukket fra bookings.belop_ore for denne betalingen',
    venter_ore  INT NOT NULL DEFAULT 0 COMMENT 'Bare under migrasjonen: regnet ut, ikke trukket ennaa',
    kilde       VARCHAR(32) NOT NULL DEFAULT 'refusjon',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (booking_id, payment_id),
    KEY ix_prisavslag_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

-- 1) Det som mangler per plass og betaling: refundert minus det som er foert.
INSERT INTO booking_prisavslag (booking_id, payment_id, avslag_ore, venter_ore, kilde)
SELECT b.id, p.id, 0, p.refundert_ore, 'migrasjon 248'
  FROM bookings b
  JOIN payments p ON (p.id = b.payment_id OR p.booking_id = b.id)
 WHERE p.status = 'delvis_refundert'
   AND p.refundert_ore > 0
   AND b.status IN ('betalt', 'reservert')
ON DUPLICATE KEY UPDATE venter_ore = GREATEST(0, VALUES(venter_ore) - avslag_ore);

-- 2) Beloepet senkes med det som mangler (aldri under 0).
UPDATE bookings b
  JOIN (SELECT booking_id, SUM(venter_ore) AS v
          FROM booking_prisavslag
         WHERE venter_ore > 0
      GROUP BY booking_id) a ON a.booking_id = b.id
   SET b.belop_ore = GREATEST(0, b.belop_ore - a.v);

-- 3) Foert. Neste kjoering finner ingenting aa trekke.
UPDATE booking_prisavslag
   SET avslag_ore = avslag_ore + venter_ore, venter_ore = 0
 WHERE venter_ore > 0;

COMMIT;
