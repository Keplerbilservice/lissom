-- Auto-godkjenn av medlemmenes varer, og malen som hoerer til.
--
-- Eieren, 12. september 2026: «Jeg vil fortsatt godkjenne eller sette auto
-- godkjenn».
--
-- Bryteren settes til «nei» her, eksplisitt. De andre bryterne staar paa naar
-- raden mangler — det gaar bra for noe som bare skjuler en boks. Her ville en
-- manglende rad betydd at varer gaar ut i butikken uten at noen har sett paa
-- dem. Serveren krever ogsaa 'ja', ikke «alt annet enn nei», se
-- api/medlemssalg.php.

INSERT INTO content_blocks (nokkel, verdi) VALUES ('Vis/autogodkjenn', 'nei')
ON DUPLICATE KEY UPDATE verdi = verdi;

-- Gaar en vare rett ut, er det mer verdt aa faa vite om, ikke mindre. Malen
-- kan endres under Maler, som de andre.
INSERT INTO notification_templates (navn, kanal, emne, tekst, gruppe) VALUES
('intern_ny_vare_ute', 'epost', 'Ny vare ute i butikken',
 '{produsent} har lagt ut «{tittel}» til {pris}.\n\nDen gikk rett ut, fordi auto-godkjenn står på. Du finner den under Admin → Butikk.', 'system')
ON DUPLICATE KEY UPDATE navn = navn;
