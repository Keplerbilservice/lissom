-- Plassene følger kategorien.
--
-- Bestilt 26. august:
--   * Dreiing: 8 deltakere
--   * Plateteknikk, Workshop, Sip & Clay, Date Night, Paint on pots: 12
--
-- Migrasjon 037 gjorde det samme, men kjente kursene på tittelen — «alt som
-- heter dreiekurs får 8». Da fantes det ingen kategori å gå etter. Nå gjør
-- det: kategorien velges i kursveiviseren og lagres i courses.tema, og et
-- nytt dreiekurs som ikke heter «dreiekurs» havner ikke lenger utenfor.
--
-- 048 tok events som 037 gikk forbi, men også den gjettet på type framfor
-- kategori. Denne er den første som følger det Lissom faktisk velger.
--
-- To ting røres ikke, med vilje:
--
--   * «Kun for medlemmer» og «Drop-in» er ikke nevnt i bestillingen.
--     Medlemssamlingene står på 8, 12 og 20, og drop-in på 8.
--   * Avlyste og gjennomførte økter. Historikken skal stå som den var.
--
-- GREATEST som i 037 og 048: kapasiteten settes aldri under det som alt er
-- solgt. Ellers ville en økt med ti påmeldte stått med negativ ledighet, og
-- den som har betalt kunne mistet plassen i en senere opptelling.

-- 1) To kurs sto igjen i en kategori som ikke finnes lenger.
--
-- «Events» var en kategori til 25. august, da den ble fjernet fordi Sip &
-- Clay, Paint on Pots og Date Night har sine egne. Kursene ble ikke flyttet,
-- og har stått med tema «Events» siden. Filteret «Date Night» på Våre kurs
-- fant dem derfor aldri, og de var bare synlige under «Vis alle».

UPDATE courses SET tema = 'Date Night'
 WHERE slug = 'date-night' AND COALESCE(tema, '') = 'Events';

UPDATE courses SET tema = 'Paint on pots'
 WHERE slug = 'paint-on-pots' AND COALESCE(tema, '') = 'Events';

-- Skulle det ligge flere igjen, kjennes de på typen. Et event uten kategori
-- er ikke synlig i noe filter, og det er verre enn å havne litt feil.
UPDATE courses SET tema = 'Paint on pots'
 WHERE COALESCE(tema, '') = 'Events' AND LOWER(tittel) LIKE '%paint%';

UPDATE courses SET tema = 'Date Night'
 WHERE COALESCE(tema, '') = 'Events' AND LOWER(tittel) LIKE '%date%';

UPDATE courses SET tema = 'Sip & Clay'
 WHERE COALESCE(tema, '') = 'Events' AND LOWER(tittel) LIKE '%sip%';

-- 2) Kursene selv. Nye datoer arver herfra.
UPDATE courses SET kapasitet = 8
 WHERE COALESCE(tema, '') = 'Dreiing';

UPDATE courses SET kapasitet = 12
 WHERE COALESCE(tema, '') IN ('Plateteknikk', 'Workshop', 'Sip & Clay',
                              'Date Night', 'Paint on pots');

-- 3) Datoene som alt ligger ute.
UPDATE course_sessions cs
  JOIN courses c ON c.id = cs.course_id
   SET cs.kapasitet = GREATEST(
         CASE WHEN COALESCE(c.tema, '') = 'Dreiing' THEN 8 ELSE 12 END,
         (SELECT COUNT(*) FROM bookings b
           WHERE b.course_session_id = cs.id
             AND b.status IN ('betalt', 'reservert'))
       )
 WHERE cs.status = 'planlagt'
   AND COALESCE(c.tema, '') IN ('Dreiing', 'Plateteknikk', 'Workshop',
                                'Sip & Clay', 'Date Night', 'Paint on pots');

-- 4) De faste ukedagene legger ut nye datoer framover, og har sitt eget
--    plasstall. Sto det 8 der på et plateteknikk-kurs, ville datoene som
--    kommer av seg selv i natt hatt 8 igjen.
UPDATE kurs_serier s
  JOIN courses c ON c.id = s.course_id
   SET s.kapasitet = CASE WHEN COALESCE(c.tema, '') = 'Dreiing' THEN 8 ELSE 12 END
 WHERE COALESCE(c.tema, '') IN ('Dreiing', 'Plateteknikk', 'Workshop',
                                'Sip & Clay', 'Date Night', 'Paint on pots');
