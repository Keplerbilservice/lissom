-- «Dette kan du lage»: karusellen paa ett kurs henter bilde og korttekst
-- fra andre kurs.
--
-- Eieren, 21. september 2026: «et kurs som heter haandbygging. i dette
-- kurset saa henter den info og bilder fra andre kurs, og kjoerer en
-- karusell med bilder og tekst, slik at vi faar fram alt som kan lages».
-- Ikke Tre paa rad, ikke Sip & Clay og Date Night — og Keramikk Workshop
-- skal fronte. Derfor er det ikke kategorien som velger: verkstedet haker
-- av kursene selv i kursoppsettet, steg 3, og rekkefoelgen er den de haker
-- av i.
--
-- Kolonnen er en JSON-liste med kurs-id-er, i rekkefoelge. NULL eller tom
-- liste betyr som foer: kursets egne bilder.

ALTER TABLE courses
    ADD COLUMN IF NOT EXISTS karusell_fra TEXT NULL COMMENT 'JSON: kurs-id-er, i rekkefoelge — «Dette kan du lage»' AFTER bilder;
