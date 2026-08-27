-- Retter tekst som ble skrevet uten norske bokstaver.
--
-- Kommentarene i koden er skrevet med «aa», «oe» og «ae» med vilje — de er
-- for meg, ikke for kunden. Men det har lekket ut i innholdet: beskrivelsen
-- paa Keramikk Workshop sto med «faar veiledning» og «verktoy», og det er
-- tekst kundene leser.
--
-- Bare de ordene som staar der. Ingen generell erstatning: «noe» og «aloe»
-- er ekte ord, og en bred regel ville odelagt dem.

UPDATE courses SET beskrivelse = REPLACE(beskrivelse, 'faar', 'får')
 WHERE beskrivelse LIKE '%faar%';
UPDATE courses SET beskrivelse = REPLACE(beskrivelse, 'Faar', 'Får')
 WHERE beskrivelse LIKE '%Faar%';
UPDATE courses SET beskrivelse = REPLACE(beskrivelse, 'verktoy', 'verktøy')
 WHERE beskrivelse LIKE '%verktoy%';
UPDATE courses SET beskrivelse = REPLACE(beskrivelse, 'Verktoy', 'Verktøy')
 WHERE beskrivelse LIKE '%Verktoy%';

UPDATE courses SET punkter = REPLACE(REPLACE(punkter, 'faar', 'får'), 'verktoy', 'verktøy')
 WHERE punkter LIKE '%faar%' OR punkter LIKE '%verktoy%';
UPDATE courses SET laerer = REPLACE(REPLACE(laerer, 'faar', 'får'), 'verktoy', 'verktøy')
 WHERE laerer LIKE '%faar%' OR laerer LIKE '%verktoy%';
UPDATE courses SET praktisk = REPLACE(REPLACE(praktisk, 'faar', 'får'), 'verktoy', 'verktøy')
 WHERE praktisk LIKE '%faar%' OR praktisk LIKE '%verktoy%';
