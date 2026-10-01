-- Skill koppene med eksisterende bekreftede produktegenskaper.
-- godkjent av eieren: 2026-10-01
-- Bare de to bekreftede produktene; bevarer senere navneendringer.
UPDATE products SET tittel = 'Kaffekopp, blågrønn'
WHERE id = 8 AND tittel = 'Kaffekopp, blå';
UPDATE products SET tittel = 'Kaffekopp med hank, blå'
WHERE id = 9 AND tittel = 'Kaffekopp, blå';
