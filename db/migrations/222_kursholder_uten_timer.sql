-- Kursholder uten timeføring: «Ingen» ved siden av «Lønn» og «Timer».
--
-- Eieren, 26. september 2026: Monicas timer skal ikke være med i
-- timelisten — «holdes utenfor». Kursene hennes vises fortsatt på Min side.

ALTER TABLE kursholdere
    MODIFY COLUMN betaling ENUM('lonn','timer','ingen') NOT NULL DEFAULT 'lonn'
        COMMENT 'Lønn (timelisten), timer (verkstedtimene) eller ingen';

UPDATE kursholdere SET betaling = 'ingen'
 WHERE standard = 1 AND navn LIKE 'Monica%';
