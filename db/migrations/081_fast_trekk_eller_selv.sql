-- Fast trekk, eller ordne det selv.
--
-- Medlemskapene ble alle behandlet likt: soknaden opprettet en Vipps-avtale,
-- og uten den kom man ikke inn. Eieren: «medlemmene maa velge, og de skal ha
-- fast trekk eller haandtere selv — i utgangspunktet er det bare
-- aarsmedlemskap som maa ha fast trekk.»
--
-- «krever_fast_trekk = 1» betyr at medlemmet ikke faar velge: avtalen i Vipps
-- maa vaere paa plass for medlemskapet kan starte. Staar den 0, velger
-- medlemmet selv mellom fast trekk og aa gjore opp for hver periode.
--
-- Verdien redigeres under Kurs og medlemskap → Medlemskap.
ALTER TABLE membership_plans
  ADD COLUMN IF NOT EXISTS krever_fast_trekk TINYINT(1) NOT NULL DEFAULT 0
  AFTER engangs;

-- Aarsmedlemskapet er det ene som krever det i dag. Det bindes i tolv
-- maaneder, og et fast trekk er hele poenget med det.
UPDATE membership_plans
   SET krever_fast_trekk = 1
 WHERE binding_mnd >= 12;

-- Hva medlemmet valgte. Staar paa soknaden, saa godkjenningen vet om den
-- skal kreve en avtale eller ikke.
ALTER TABLE membership_applications
  ADD COLUMN IF NOT EXISTS betaling VARCHAR(16) NOT NULL DEFAULT 'trekk'
  AFTER onsket_type;

-- Soknader fra for dette hadde ingen avtale i det hele tatt. De skal ikke
-- plutselig kreve en.
UPDATE membership_applications
   SET betaling = 'selv'
 WHERE status = 'venter'
   AND member_id NOT IN (SELECT member_id FROM subscriptions);
