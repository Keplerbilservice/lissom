-- godkjent av eieren: 2026-10-08
--
-- Hentetid tre uker overalt, og forkle og håndkle til låns (eieren, «ok,
-- godkjent» 8. oktober 2026, fremvisningen YGp25LcNzhN9Xi3eUHSDTE):
-- «hentetid skal være 3 uker overalt», «litt mer info om at du får låne
-- håndkle av oss, og at man kan bli litt skitten, men at det går av i vask».
--
-- Bare de nøyaktige setningene byttes, med REPLACE, slik at alt annet som er
-- skrevet står. Er de alt byttet, skjer ingenting — kan kjøres flere ganger.

-- Tekstene lagret under Nettsiden → Innhold (går foran standardtekstene).
UPDATE content_blocks
   SET verdi = REPLACE(REPLACE(REPLACE(REPLACE(verdi,
         'Det tar normalt to til fire uker før', 'Det tar tre uker før'),
         'to til fire uker', 'tre uker'),
         '2–4 uker', 'tre uker'),
         '2-4 uker', 'tre uker')
 WHERE verdi LIKE '%to til fire uker%' OR verdi LIKE '%2–4 uker%' OR verdi LIKE '%2-4 uker%';

UPDATE content_blocks
   SET verdi = REPLACE(verdi, 'Ta kontakt, så finner vi ut av det.', 'Send oss en e-post til monica@lissom.no eller ring 94 13 46 01.')
 WHERE nokkel = 'Spørsmål og svar/0/Svar 4';

-- Kursene: egen ferdigtekst og det praktiske.
UPDATE courses
   SET ferdig_tid = REPLACE(REPLACE(REPLACE(REPLACE(ferdig_tid,
         'normalt klar til henting etter 2–4 uker', 'klar til henting etter tre uker'),
         'to til fire uker', 'tre uker'),
         '2–4 uker', 'tre uker'),
         '2-4 uker', 'tre uker')
 WHERE ferdig_tid LIKE '%to til fire uker%' OR ferdig_tid LIKE '%2–4 uker%' OR ferdig_tid LIKE '%2-4 uker%';

UPDATE courses
   SET praktisk = REPLACE(praktisk, 'Dere får låne forkle, men regn med å bli litt skitten.', 'Dere låner forkle og håndkle. Dere kan bli litt skitne, men leira går av i vask.')
 WHERE praktisk LIKE '%Dere får låne forkle, men regn med å bli litt skitten.%';

-- Påminnelsen før kurset.
UPDATE notification_templates
   SET tekst = REPLACE(tekst, 'Du får låne forkle av oss, men regn med å bli litt skitten.', 'Du låner forkle og håndkle av oss. Du kan bli litt skitten, men leira går av i vask.')
 WHERE tekst LIKE '%Du får låne forkle av oss, men regn med å bli litt skitten.%';

-- Artiklene om kurs og Paint on Pots (migrasjon 182 og 193).
UPDATE articles
   SET innhold = REPLACE(REPLACE(REPLACE(innhold,
         'normalt to til fire uker etter', 'tre uker etter'),
         'Etter to til fire uker henter du det', 'Etter tre uker henter du det'),
         'klart til henting etter to til fire uker', 'klart til henting etter tre uker')
 WHERE innhold LIKE '%to til fire uker%';
