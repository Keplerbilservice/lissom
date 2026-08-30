-- Kategoriene ryddet: Håndbygging og Events.
--
-- Ute sto seks piller ved siden av hverandre — Dreiing, Plateteknikk,
-- Workshop, Sip & Clay, Date Night og Paint on pots. Tre av dem er det samme
-- slaget kveld, og to av dem er det samme håndverket under hvert sitt navn.
--
-- Eieren, 30. august:
--   «sip&clay, datenights og en paint on pots skal ligge under en ny pille
--    som heter events»
--   «lag din egen bolle, og workshop, skal ligge under pillen med det nye
--    navnet håndbygging»
--
-- Events er en gruppering i visningen: de tre arrangementene beholder temaet
-- sitt, så Paint on Pots fortsatt kjennes igjen på sitt eget. Det er bare
-- pilleraden som er slått sammen — se KATEGORI i kursKort().
--
-- Håndbygging er derimot et nytt navn på et tema som fantes, og da må radene
-- følge med. Ellers ville et kurs lagret som «Workshop» falt utenfor sin egen
-- kategori i det noen rettet prisen på det.

UPDATE courses
   SET tema = 'Håndbygging'
 WHERE tema IN ('Workshop', 'Plateteknikk');

-- «Lag din egen bolle» sto med temaet «Kurs» — altså ingen kategori i det
-- hele tatt, og dermed synlig bare under «Vis alle». Eieren vil ha det under
-- Håndbygging, og det er nettopp det kurset er: plateteknikk, en kveld.
--
-- Bare når temaet er tomt eller det generiske «Kurs». Har noen alt gitt det
-- en kategori, er det den som gjelder.
UPDATE courses
   SET tema = 'Håndbygging'
 WHERE tittel = 'Lag din egen bolle'
   AND (tema IS NULL OR tema = '' OR tema = 'Kurs');
