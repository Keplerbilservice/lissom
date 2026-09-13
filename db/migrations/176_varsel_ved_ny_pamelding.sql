-- E-post til verkstedet naar noen melder seg paa.
--
-- Eieren, 13. september 2026: «Det er varsel paa ny paamelding, men det er
-- ikke kommet noen mail til admin, dette maa fikses».
--
-- Det var ingen feil: den er aldri bygget. Verkstedet fikk e-post ved nytt
-- medlem, ny foresporsel, ny vare, gave som skal pakkes og gave lost inn —
-- men ikke ved ny paamelding. Varselet han saa er tallet paa Oversikt.
--
-- Han ba ogsaa om betalingsstatus: «Jeg vil ha betalingsstatus paa mailen
-- ogsaa». «Betalt» og «Ubetalt» er de samme to ordene som staar i
-- Paameldte-lista fra for; en plass som er reservert uten at noe er betalt
-- er «Ubetalt».
--
-- Malen kan endres under Verkstedet -> Tekst maler, som de andre.

INSERT INTO notification_templates (navn, kanal, emne, tekst, gruppe) VALUES
('intern_ny_pamelding', 'epost', 'Ny påmelding: {kurs}',
 '{navn} har meldt seg på {kurs}.\n\nNår: {naar}\nBeløp: {belop}\nBetaling: {betaling}\nKontakt: {epost} · {telefon}\n\nDu finner påmeldinga under Admin → Påmeldte.', 'system')
ON DUPLICATE KEY UPDATE navn = navn;
