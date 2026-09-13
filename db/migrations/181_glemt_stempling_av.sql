-- «Glemt aa stemple ut» slaas av for medlemmene.
--
-- Eieren, 13. september 2026: «jeg vil ha bryter til aa skru av glemte aa
-- stemple for medlemmene. Og den skal og skrus av.»
--
-- Feltet lot medlemmet skrive inn klokkeslettet det faktisk gikk, og rette
-- oekta selv. Bryteren staar naa i ⊙ Synlighet sammen med de ti andre, og
-- denne raden setter den AV fra start — ellers ville den vaert paa til noen
-- husket aa trykke.
--
-- Veien er ikke stengt: er tida feil, sier medlemmet fra med «Feil tid — si
-- fra» i ruta ved utstempling, og henvendelsen havner i admin. Verkstedet
-- retter den derfra, som for.
--
-- «INSERT IGNORE» og ikke «REPLACE»: har noen alt slaatt den paa med vilje,
-- skal ikke en migrasjon som kjores en gang til slaa den av igjen.

INSERT IGNORE INTO content_blocks (nokkel, verdi) VALUES ('Vis/glemtstempling', 'nei');
