-- Drop-in folger aapningstidene, slik Paint on Pots gjor.
--
-- Lissom 27. august: drop-in skal bookes med de samme datoene og tidene, og
-- tilgjengeligheten skal folge kursene og innstemplinga.
--
-- Til naa har «gjenstand_i_kassa» styrt to ting paa én gang: at gjenstanden
-- betales i verkstedet, OG at datoene lages av aapningstidene. Det er to
-- forskjellige ting — drop-in betales paa nett som for — og de skilles her.
--
--   gjenstand_i_kassa   Paint on Pots. Gjenstanden velges og betales i
--                       verkstedet; plassen er gratis.
--   folger_apningstid   Paint on Pots og Drop-in. Datoene settes ikke opp
--                       for haand: de lages av de aapne tidene.
--
-- Merk hva som IKKE skjer her: drop-in-tidene under Kurs og medlemskap →
-- Drop-in staar som de staar, og de definerer fortsatt naar verkstedet er
-- aapent. Det nye er at drop-in ogsaa blir bookbart de dagene et kurs gaar,
-- eller Lissom er stemplet inn, uten at noen maa sette opp en tid.
--
-- Utleggingen hopper over dager der kurset alt har en oekt lagt inn for
-- haand, saa de to ikke staar og dubler hverandre.

ALTER TABLE courses
    ADD COLUMN IF NOT EXISTS folger_apningstid TINYINT(1) NOT NULL DEFAULT 0
    AFTER gjenstand_i_kassa;

-- Paint on Pots gjorde dette fra for, gjennom gjenstand_i_kassa.
UPDATE courses SET folger_apningstid = 1 WHERE gjenstand_i_kassa = 1;

-- Og drop-in, som er det nye.
UPDATE courses SET folger_apningstid = 1
 WHERE type = 'dropin' OR tema = 'Drop-in' OR slug = 'drop-in';
