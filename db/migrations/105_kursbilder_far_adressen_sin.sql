-- Kursbildene fikk adressen sin tilbake.
--
-- Eieren, 31. august: «bilde fra mitt nye kurs vises ikke».
--
-- Bildet lå der hele tiden. Det var adressen som var klippet: lagringa i
-- api/admin/kurs.php kjørte basename() på verdien fra billedvelgeren, og
-- basename() tar siste ledd av en sti. «api/bilde.php?artikkel=b8e795….jpg»
-- ble til «bilde.php?artikkel=b8e795….jpg» — uten «api/» finnes det ingen
-- slik fil, og ruteren svarte med hele nettsida i stedet for et bilde.
--
-- Målt på lissom.no: den klippede adressen ga 1,2 MB HTML. Den hele ga
-- 180 kB image/jpeg. Bildet var altså aldri borte.
--
-- Vakta er rettet, og her får radene som alt er lagret adressen sin igjen.
-- Bare de som mangler nettopp «api/» foran — en verdi som er noe annet, er
-- ikke vår å gjette på.

UPDATE courses
   SET bilde = CONCAT('api/', bilde)
 WHERE bilde LIKE 'bilde.php?artikkel=%';

UPDATE courses
   SET bilder = REPLACE(bilder, '"bilde.php?artikkel=', '"api/bilde.php?artikkel=')
 WHERE bilder LIKE '%"bilde.php?artikkel=%';
