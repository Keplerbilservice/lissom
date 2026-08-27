-- Kursoppsettet: punktlista og merkingen.
--
-- Bestilt 26. august, punkt 4, og punkt 1b paa den gamle lista over aapne
-- punkter. To ting:
--
-- 1) Punktlista under «Alt som er inkludert» staar fast i koden. Verkstedet
--    ba i juni om aa fjerne «verktoy» fra Kurs boller. Det kan ikke gjores
--    fra admin — det maa gjores av meg, i koden, hver gang. Naa staar den paa
--    kurset.
--
--    Tom kolonne betyr «som for»: da skriver nettsida den samme linja som i
--    dag. Ingen kurs endrer seg fordi migrasjonen kjores.
--
--    «Maks N deltakere» staar ikke her. Den regnes fortsatt av kapasiteten
--    paa kurset, saa tallet ikke kan bli uenig med det som staar rett under
--    — det var nettopp den feilen som ble rettet i juni.
--
-- 2) Merkingen: hvem kurset passer for, hvilken metode, hvor lenge det varer.
--    Ingen slike felter finnes. courses.tema er kategorien som styrer
--    filteret ute paa nettsida — «Dreiing», «Plateteknikk», «Events» — og kan
--    ikke ogsaa bety «passer for nybegynnere» uten at filteret gaar i
--    stykker.
--
--    Kommaseparerte lister framfor koblingstabeller: et kurs har to-tre av
--    hver, listene er korte og faste, og de skal bare leses samlet. Fire
--    koblingstabeller til ville ikke svart paa noe mer.
--
-- Ingen eksisterende kolonne endres. Et kurs uten noe av dette oppfoerer seg
-- noeyaktig som i dag.

ALTER TABLE courses
  ADD COLUMN IF NOT EXISTS punkter TEXT NULL
      COMMENT 'Alt som er inkludert. Ett punkt per linje. Tom = som for.',
  ADD COLUMN IF NOT EXISTS laerer TEXT NULL
      COMMENT 'Dette laerer deltakerne',
  ADD COLUMN IF NOT EXISTS praktisk TEXT NULL
      COMMENT 'Praktisk informasjon: oppmote, klaer, parkering',
  ADD COLUMN IF NOT EXISTS allergener TEXT NULL
      COMMENT 'Allergener og kommentarer som gjelder dette kurset',
  ADD COLUMN IF NOT EXISTS passer_nivaa VARCHAR(60) NULL
      COMMENT 'nybegynner,litt,erfaren',
  ADD COLUMN IF NOT EXISTS passer_hvem VARCHAR(80) NULL
      COMMENT 'alene,par,venner,familie,firma,barn',
  ADD COLUMN IF NOT EXISTS metode VARCHAR(20) NULL
      COMMENT 'dreiing,handbygging,maling,begge',
  ADD COLUMN IF NOT EXISTS varighet VARCHAR(20) NULL
      COMMENT 'kort,medium,lang';

-- Det ene som faktisk ble bestilt endret: «verktoy» ut av Kurs boller.
--
-- Bare denne raden roeres, og bare om den finnes. Alle andre kurs staar med
-- tom kolonne og viser den samme linja som for.
UPDATE courses
   SET punkter = 'Leire, glasur og brenning er inkludert.'
 WHERE punkter IS NULL
   AND tittel LIKE '%boller%';
