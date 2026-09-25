-- Dugnad for utvalgte medlemmer, og dugnad gitt av verkstedet.
--
-- Eieren, 25. september 2026: «jeg trenger å kunne tildele enkeltpersoner i
-- medlemmer dugnadsarbeid, at jeg kan velge hvem av medlemmene som får se at
-- det er dugnad». GO på forslaget samme dag.
--
-- - members.ser_dugnad: medlemmet ser dugnad når Synlighet → Dugnad står på
--   «Utvalgte» (content_blocks 'Vis/dugnadutvalgte' = 'ja').
-- - dugnad.tildelt: jobben er gitt av verkstedet («Gi dugnad»), ikke spurt
--   om av medlemmet. Den er godkjent med en gang.

ALTER TABLE members
    ADD COLUMN IF NOT EXISTS ser_dugnad TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Ser dugnad paa Min side naar dugnad er for utvalgte';

ALTER TABLE dugnad
    ADD COLUMN IF NOT EXISTS tildelt TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Gitt av verkstedet, ikke spurt om av medlemmet';

-- «Utvalgte» staar av til eieren velger det: da er alt som foer.
INSERT INTO content_blocks (nokkel, verdi)
SELECT 'Vis/dugnadutvalgte', 'nei' FROM (SELECT 1) AS d
 WHERE NOT EXISTS (SELECT 1 FROM content_blocks WHERE nokkel = 'Vis/dugnadutvalgte');
