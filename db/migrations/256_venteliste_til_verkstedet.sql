-- godkjent av eieren: 2026-10-04
--
-- Beskjeden verkstedet faar naar noen setter seg paa venteliste. Eieren, 4.
-- oktober 2026: «Og er det fult og det vil komme fler saa send meg beskjed.»
--
-- Fram til naa gikk det bare e-post til den som ventet. Verkstedet fikk
-- ingenting, og visste derfor ikke at noen banket paa en dato som var meldt
-- full — heller ikke de gangene den slett ikke var full. Det var nettopp det
-- som skjedde med Paint on Pots torsdag 8. oktober.
--
-- Kontaktopplysningene staar i beskjeden: er det plass likevel, loeses det
-- med en telefon, ikke med et nytt skjermbilde i admin.
--
-- Gruppa er «system», som de andre intern_-malene (migrasjon 112). Kolonna er
-- en ENUM('system','ordre','kurs','nyhetsbrev') fra migrasjon 062 — «intern»
-- er navnet paa signaturgruppa malTilAdmin() sender med, ikke en verdi her.
-- Malen kan endres og slaas av under Markedsfoering › Tekst maler, som resten.
INSERT INTO notification_templates (navn, kanal, emne, tekst, gruppe) VALUES
('intern_venteliste', 'epost', 'Venteliste: {kurs}{dato}',
 '{navn} står som nummer {posisjon} på ventelisten for {kurs}{dato}.\n\nE-post: {epost}\nTelefon: {telefon}', 'system')
ON DUPLICATE KEY UPDATE navn = navn;
