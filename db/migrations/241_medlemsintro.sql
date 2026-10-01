-- Fjern misvisende loefte om fri oppsigelse; endrer ikke medlemsvilkaar.
-- godkjent av eieren: 2026-10-01
-- Bevarer annen eierredigert tekst.
UPDATE content_blocks
SET verdi = 'Du må ha tatt et kurs hos oss, eller ha erfaring fra før.'
WHERE nokkel = 'Medlemskap/0/Ingress'
AND verdi = 'Abonnementet løper fra måned til måned og kan sies opp når du vil. Du må ha tatt et kurs hos oss, eller ha erfaring fra før.';
