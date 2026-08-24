-- Plasser paa kursene, og en samling som skal ut.
--
-- Bestilt 24. august:
--   * kurs og workshops: 12 plasser
--   * dreiekurs: 8 — det er antall dreieskiver som setter grensa
--   * drop-in: 8
--   * Glasurkveld for medlemmer: 12
--   * Medlemsfrokost: strykes
--
-- «Store former, viderekomne» er ikke nevnt og roeres ikke.
--
-- Medlemsfrokosten slettes ikke, den avlyses. Bookinger og betalinger peker
-- paa raden, og de er bokfoeringspliktige — en slettet rad ville tatt med seg
-- historikken. Det er ogsaa slik «Slett kurs» i admin allerede virker naar
-- noen er paameldt.
--
-- Avlyste og gjennomfoerte oekter roeres ikke: historikken skal staa som den
-- var.
--
-- «Dreiekurs» kjennes paa tittelen. Det er den eneste maerkingen som finnes;
-- det er ingen egen kolonne for teknikk.

-- 1) Kursene selv. Nye datoer arver herfra.
UPDATE courses SET kapasitet = 8
 WHERE type IN ('kurs', 'workshop') AND LOWER(tittel) LIKE '%dreiekurs%';

UPDATE courses SET kapasitet = 12
 WHERE type IN ('kurs', 'workshop') AND LOWER(tittel) NOT LIKE '%dreiekurs%';

UPDATE courses SET kapasitet = 8  WHERE type = 'dropin';
UPDATE courses SET kapasitet = 12 WHERE LOWER(tittel) LIKE '%glasurkveld%';

-- 2) Medlemsfrokosten ut av listene.
UPDATE courses SET status = 'avlyst' WHERE LOWER(tittel) LIKE '%medlemsfrokost%';
UPDATE course_sessions cs JOIN courses c ON c.id = cs.course_id
   SET cs.status = 'avlyst'
 WHERE LOWER(c.tittel) LIKE '%medlemsfrokost%' AND cs.status = 'planlagt';

-- 3) Datoene som ligger ute.
--
-- Aldri under det som alt er solgt. Settes kapasiteten lavere enn antall
-- paameldte, staar oekta med negativ ledighet, og den som alt har betalt kan
-- miste plassen sin i en senere opptelling. Derfor GREATEST: en oekt med ni
-- paameldte paa et dreiekurs blir staaende paa ni.
UPDATE course_sessions cs
  JOIN courses c ON c.id = cs.course_id
   SET cs.kapasitet = GREATEST(
         CASE
           WHEN LOWER(c.tittel) LIKE '%dreiekurs%'     THEN 8
           WHEN LOWER(c.tittel) LIKE '%glasurkveld%'   THEN 12
           WHEN c.type = 'dropin'                      THEN 8
           ELSE 12
         END,
         (SELECT COUNT(*) FROM bookings b
           WHERE b.course_session_id = cs.id
             AND b.status IN ('betalt', 'reservert'))
       )
 WHERE cs.status = 'planlagt'
   AND (c.type IN ('kurs', 'workshop', 'dropin')
        OR LOWER(c.tittel) LIKE '%glasurkveld%');
