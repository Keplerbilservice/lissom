-- «Glasurkveld for medlemmer» finnes ikke.
--
-- Kurset ble lagt inn av migrasjon 003, sammen med resten av katalogen, den
-- gangen innholdet var gjettet framfor hentet. Lissom sa 27. august at hun
-- ikke har noen glasurkveld — da skal den ikke staa i kalenderen, og den skal
-- ikke gjore verkstedet «aapent» en kveld ingen er der.
--
-- To utfall, og det avhenger av om noen faktisk har meldt seg paa:
--
--   * Ingen paameldte: kurset slettes. Datoene, ventelista og gjentakelsen
--     folger med av seg selv (CASCADE).
--   * Noen paameldte: kurset avlyses i stedet. Da blir det borte fra
--     nettsiden og fra aapningstidene, men paameldingen staar igjen — en
--     booking er noen sin, og et regnskap. bookings.course_id staar dessuten
--     paa RESTRICT, saa en sletting ville stanset her uansett.

UPDATE courses c
   SET c.status = 'avlyst'
 WHERE c.slug = 'glasurkveld-medlemmer'
   AND (
        EXISTS (SELECT 1 FROM bookings b WHERE b.course_id = c.id)
     OR EXISTS (SELECT 1 FROM bookings b
                  JOIN course_sessions s ON s.id = b.course_session_id
                 WHERE s.course_id = c.id)
   );

DELETE c FROM courses c
 WHERE c.slug = 'glasurkveld-medlemmer'
   AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.course_id = c.id)
   AND NOT EXISTS (SELECT 1 FROM bookings b
                     JOIN course_sessions s ON s.id = b.course_session_id
                    WHERE s.course_id = c.id);
