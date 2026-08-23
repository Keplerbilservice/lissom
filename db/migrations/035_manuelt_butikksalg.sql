-- Salg over disk.
--
-- Butikken kunne bare selge gjennom nettbutikken, med Vipps. Selger Monica
-- en kopp til noen som staar i verkstedet, fantes det ikke noe sted aa
-- registrere det: hverken lageret eller omsetningen fikk vite om det, og
-- tallene i Okonomi var derfor lavere enn det som faktisk var solgt.
--
-- Salget lagres som en helt vanlig ordre med en betaling, saa det dukker opp
-- i omsetningen, i betalingslista og i transaksjonsuttrekket som alt annet.
-- Ingen egen tabell, ingen dobbeltforing.

-- «epayment» og «recurring_charge» er begge Vipps. Et kontantsalg er ingen
-- av delene, og skal ikke se ut som et Vipps-trekk i regnskapet.
ALTER TABLE payments
  MODIFY COLUMN type ENUM('epayment','recurring_charge','manuell')
    NOT NULL DEFAULT 'epayment';

-- Hvordan det ble gjort opp. Bookinger har det fra for (betalt_maate);
-- ordrene hadde det ikke, fordi alt gikk gjennom Vipps.
ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS betalt_maate VARCHAR(32) NULL
    COMMENT 'Kontant, Vipps, ... — bare satt paa salg lagt inn for haand'
    AFTER status;
