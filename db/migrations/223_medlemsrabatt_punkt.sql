-- Medlemsrabatt paa kurs, som punkt paa alle medlemskap.
--
-- Eieren, 26. september 2026: «legg til paa alle medlemskap at vi tilbyr
-- 20 % rabatt til alle medlemmer paa alle vaare kurs», og om teksten:
-- «medlemmer faar 20 %». Rabatten trekkes i bookingen for aktive medlemmer
-- (Booking::faarMedlemsrabatt / belopFor).
--
-- Punktet settes inn som linje nummer to, etter timetallet. Bare der det
-- ikke staar fra foer, saa migrasjonen kan kjores to ganger. Tomme lister
-- faar punktet alene — nettsida viser da det i stedet for reservepunktene.

UPDATE membership_plans
   SET punkter = CASE
         WHEN punkter IS NULL OR TRIM(punkter) = '' THEN 'Medlemmer får 20 % rabatt på alle våre kurs'
         WHEN LOCATE('\n', punkter) = 0 THEN CONCAT(punkter, '\nMedlemmer får 20 % rabatt på alle våre kurs')
         ELSE CONCAT(SUBSTRING_INDEX(punkter, '\n', 1), '\nMedlemmer får 20 % rabatt på alle våre kurs\n',
                     SUBSTRING(punkter, LOCATE('\n', punkter) + 1))
       END
 WHERE COALESCE(punkter, '') NOT LIKE '%20 % rabatt%';
