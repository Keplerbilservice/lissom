-- Bollekurset ligger to ganger. Det ene tas av nettsiden — hvis det er tomt.
--
-- ── Hva som er galt ───────────────────────────────────────────────────
--
-- Paa den ekte siden 29. august ligger to kurs med det samme navnet:
--
--   id 2   «Lag din egen bolle»  /kurs/kurs-boller         3. sep 17:00, 22. sep 17:00
--   id 13  «Lag din egen bolle»  /kurs/lag-din-egen-bolle  3. sep 17:00
--
-- Begge har en oekt torsdag 3. september klokka 17. Kalenderen ute viser
-- derfor «Lag din egen bolle · 17:00» to ganger den dagen — det var dette
-- eieren meldte fra om, og som jeg foerst trodde var en tellefeil i
-- kalenderen. Tellefeilen fantes og er rettet, men den var i admin. Dette er
-- noe annet: det ER to kurs.
--
-- Verre: /kurs/lag-din-egen-bolle svarer 200, har riktig tittel og ligger i
-- sitemap, men viser «siden finnes ikke» — og Google sender folk dit.
--
-- ── Hvorfor id 2 beholdes ─────────────────────────────────────────────
--
-- Det er id 2 som har paameldte (10 av 12 ledige 3. september mot 12 av 12
-- paa id 13), som har den ekstra datoen 22. september, og som ligger paa
-- adressen som er indeksert. Migrasjon 087 doepte nettopp det kurset om, og
-- lot adressen staa med vilje saa gamle lenker virket.
--
-- ── Hva som skjer, og hva som ikke skjer ──────────────────────────────
--
-- Kurset slettes ikke, og ingen oekt avlyses. Statusen settes til «kladd»:
-- da er kurset borte fra nettsiden og fra sitemap, mens alt som staar paa det
-- blir liggende. Angrer eieren, settes det tilbake til «publisert» fra
-- kursoppsettet — ett trykk, ingenting tapt.
--
-- Og bare hvis det er tomt. Er det én eneste paamelding eller én paa
-- ventelista, roerer migrasjonen ingenting: da er det et kurs noen har et
-- forhold til, og det skal et menneske se paa. Jeg ser bare den lokale basen
-- herfra, saa betingelsen maa staa i SQL-en og ikke i hodet mitt.

SET @id := (
  SELECT c.id FROM courses c
   WHERE c.slug = 'lag-din-egen-bolle'
     AND EXISTS (SELECT 1 FROM courses d
                  WHERE d.slug = 'kurs-boller' AND d.tittel = c.tittel)
   LIMIT 1
);

-- Alt som henger paa kurset: paameldinger som ikke er avbestilt, og
-- ventelista. Er summen null, er kurset trygt aa ta ned.
SET @henger := IF(@id IS NULL, 1, (
  SELECT (SELECT COUNT(*) FROM bookings b
           WHERE b.course_id = @id AND b.status <> 'avbestilt')
       + (SELECT COUNT(*) FROM waitlist w WHERE w.course_id = @id)
));

SET @sql := IF(@id IS NOT NULL AND @henger = 0,
  'UPDATE courses SET status = ''kladd'' WHERE id = @id',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
