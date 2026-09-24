-- «Vis som fra-pris» paa kurset.
--
-- Eieren, 24. september 2026: «paint on pots fra kr 450, ikke fast pris må
-- endre» — og valgte en fra-pris han setter selv, framfor den som regnes av
-- butikken (den ville blitt kr 350: billigste vare av alle).
--
-- Med haken paa vises kursprisen som «Fra kr. X,-» paa kort, kursside og
-- booking, uten grupperabatt. Kunden betaler i verkstedet etter hva hen
-- velger; beloepet paa bookingen er et minimum og rettes med «Ta betalt».

ALTER TABLE courses
    ADD COLUMN IF NOT EXISTS fra_pris TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Prisen vises som «Fra kr. X»';

UPDATE courses SET fra_pris = 1, pris_ore = 45000
 WHERE slug = 'paint-on-pots';
