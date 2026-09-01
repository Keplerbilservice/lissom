-- Paint on Pots koster 500 kroner. Punktum.
--
-- Kurset viste tre forskjellige tall paa den samme sida:
--
--   «Fra kr. 800,-»   prislinja under overskriften
--   «kr. 690,-»       bookingknappen
--   kr. 500,-         det serveren faktisk trakk (courses.pris_ore)
--
-- 800 kom av flagget «gjenstand_i_kassa»: sto det paa, la api/kurs.php den
-- billigste malbare gjenstanden i butikken (300) oppaa plassen, og kalte
-- summen en «fra»-pris. 690 kom av en hardkodet pristabell i skjermkoden.
--
-- Eieren, 1. september: «Kr 500 er kr 500 alt annet fjernes».
--
-- Denne filen tar de to som ligger i basen. Den hardkodete tabellen er tatt
-- i lissom-2108.html, og prisAv() slaar naa opp i katalogen fra serveren for
-- den ser paa tabellen i det hele tatt.

UPDATE courses
   SET pris_ore = 50000,
       gjenstand_i_kassa = 0
 WHERE slug = 'paint-on-pots';
