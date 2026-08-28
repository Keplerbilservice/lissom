-- Manuell betaling som en ekte betaling.
--
-- Legger noen inn for haand med «Kontant» eller «Vipps i verkstedet», ble det
-- satt en tekst paa bookingen og ikke noe mer. Det fantes ingen rad i
-- payments. Foelgene:
--
--   * ingen visste HVEM som registrerte betalingen, NAAR, eller med hvilken
--     kommentar,
--   * en feilregistrering kunne ikke angres, bare skrives over,
--   * kurspenger som kom kontant eller paa faktura var usynlige i alt som
--     teller paa payments.
--
-- «type = manuell» finnes fra for og brukes i butikken (uttak.php,
-- dagsoppgjor.php). Naa brukes den ogsaa for kurs, med fem kolonner som gjor
-- den til den betalingshistorikken som manglet.
--
-- Ingen rad endres. «betalt_maate» paa bookings blir staaende slik den er paa
-- de gamle radene — den er sannheten om dem. Nye betalinger skriver til
-- payments, og bookingen peker paa raden gjennom payment_id, som alt finnes.

-- Hvem som trykket. NULL paa alt som ble laget for dette, og paa
-- Vipps-betalinger, som ingen registrerer for haand.
ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS registrert_av BIGINT UNSIGNED NULL
  COMMENT 'Admin som registrerte en manuell betaling. NULL = kom fra Vipps.'
  AFTER member_id;

-- Hvordan pengene kom inn. Samme ord som paameldingsskjemaet bruker:
-- Kontant, Vipps i verkstedet, Faktura, Betaler ved oppmote, Gratis.
ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS maate VARCHAR(32) NULL
  COMMENT 'Kontant, Vipps i verkstedet, Faktura ... Kun manuelle betalinger.'
  AFTER registrert_av;

-- Det verkstedet vil huske om akkurat denne betalingen.
ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS kommentar VARCHAR(300) NULL
  COMMENT 'Fritekst fra den som registrerte. Vises bare i admin.'
  AFTER maate;

-- Angre.
--
-- En feilregistrert betaling skal ikke slettes: raden er et bilag, og bilag
-- forsvinner ikke. Den annulleres, og da staar det bade at den var der og at
-- den ble trukket tilbake.
ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS annullert_at DATETIME NULL
  COMMENT 'Satt naar en manuell betaling ble trukket tilbake.'
  AFTER kommentar;

ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS annullert_av BIGINT UNSIGNED NULL
  COMMENT 'Admin som annullerte.'
  AFTER annullert_at;

-- Hvilken paamelding betalingen gjelder.
--
-- «bookings.payment_id» finnes fra for, men den peker én vei og holder bare
-- én rad: den betalingen som gjorde opp plassen. Historikk krever det
-- motsatte — flere betalinger kan hore til den samme paameldingen (et
-- delbeloep, resten senere, og en annullert som ble gjort om igjen).
--
-- Begge beholdes, og de sier hver sin ting:
--   bookings.payment_id  = betalingen som gjorde opp plassen (uendret bruk)
--   payments.booking_id  = alle betalingene som gjelder plassen
ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS booking_id BIGINT UNSIGNED NULL
  COMMENT 'Paameldingen betalingen gjelder. Flere rader kan peke paa den samme.'
  AFTER member_id;

-- Historikken paa én paamelding, og lista over manuelle betalinger i et
-- tidsrom. Uten indeksene leses hele tabellen.
CREATE INDEX IF NOT EXISTS ix_payments_booking ON payments (booking_id);

-- Betalingene som alt finnes, kobles til paameldingen sin.
--
-- Uten dette ville historikken paa en plass som er betalt gjennom Vipps staatt
-- tom: raden finnes, men ingen visste hvilken booking den hoerte til andre
-- veien. Koblingen leses ut av «bookings.payment_id», som har pekt riktig hele
-- tiden.
UPDATE payments p
   JOIN bookings b ON b.payment_id = p.id
    SET p.booking_id = b.id
  WHERE p.booking_id IS NULL;
CREATE INDEX IF NOT EXISTS ix_payments_manuell ON payments (type, formal, created_at);
