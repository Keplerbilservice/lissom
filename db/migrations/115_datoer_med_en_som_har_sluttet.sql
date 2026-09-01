-- Datoer som staar paa en som har sluttet.
--
-- Migrasjon 113 fylte datoene som sto TOMME. Men flere sto ikke tomme — de
-- sto paa en kursholder som ikke jobber der lenger. Kalenderen viser ingen
-- som har sluttet (den ser etter «aktiv = 1»), saa de havnet i spalta «Uten
-- kursholder» selv om feltet var fylt ut.
--
-- Eieren, 1. september: «flere paint on pots kurs ligger paa kolonnen uten
-- kursholdere» — etter at 113 var kjort og Monica sto som standard.
--
-- Verre: regelen i koden arvet det videre. «Den som staar paa kurset» ble
-- lest uten aa sporre om hen fortsatt holder kurs, saa hver nye dato fikk
-- den samme personen paa seg. Det er rettet i app/lib/kursholder.php; denne
-- filen tar dem som alt ligger der.
--
-- Fra og med i gaar og framover. Det som har vaert er historie: sto det en
-- person paa et kurs som er holdt, er det hen som holdt det, og da skal ikke
-- en migrasjon skrive over navnet.

SET @standard := (SELECT id FROM kursholdere WHERE standard = 1 AND aktiv = 1 LIMIT 1);

-- ── 1. Kursene forst ─────────────────────────────────────────────────
-- Datoene arver fra kurset, saa kurset maa vaere riktig for datoene rettes.
UPDATE courses c
   SET c.kursholder_id = @standard
 WHERE @standard IS NOT NULL
   AND c.kursholder_id IS NOT NULL
   AND NOT EXISTS (
         SELECT 1 FROM kursholdere k
          WHERE k.id = c.kursholder_id AND k.aktiv = 1
       );

-- ── 2. Datoene ───────────────────────────────────────────────────────
-- Bade de tomme og de som peker paa en som har sluttet.
UPDATE course_sessions cs
  JOIN courses c ON c.id = cs.course_id
   SET cs.kursholder_id = COALESCE(c.kursholder_id, @standard)
 WHERE cs.start_tid >= DATE_SUB(UTC_DATE(), INTERVAL 1 DAY)
   AND cs.status <> 'avlyst'
   AND COALESCE(c.kursholder_id, @standard) IS NOT NULL
   AND (
         cs.kursholder_id IS NULL
         OR NOT EXISTS (
              SELECT 1 FROM kursholdere k
               WHERE k.id = cs.kursholder_id AND k.aktiv = 1
            )
       );
