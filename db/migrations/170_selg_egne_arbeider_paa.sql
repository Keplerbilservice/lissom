-- «Selg egne arbeider» skrus paa, for aarsmedlemmene.
--
-- Eieren, 12. september 2026: «det finnes en egen medlemsbutikk, der
-- medlemmene kan selge sine egne varer, jeg vil at det er kun års medlemmer
-- som skal få denne, kan du fikse dette, og aktivere den?»
--
-- Begge bryterne sto paa «nei» live: «Selg egne arbeider» (Vis/medlemssalg,
-- under Innstillinger) og skjemaet for aa legge ut arbeider (Vis/salgsskjema,
-- under godkjenningen av medlemssalg). Her settes de til «ja», saa fanen
-- «Selg» virker fra ⚙ Kjør oppdateringer. Bryterne i admin virker som foer
-- etterpaa. Hvem som ser fanen, avgjoer nettsida og api/medlemssalg.php:
-- bare medlemmer med planen «Årsmedlemskap».

INSERT INTO content_blocks (nokkel, verdi) VALUES ('Vis/medlemssalg', 'ja')
ON DUPLICATE KEY UPDATE verdi = 'ja';
INSERT INTO content_blocks (nokkel, verdi) VALUES ('Vis/salgsskjema', 'ja')
ON DUPLICATE KEY UPDATE verdi = 'ja';
