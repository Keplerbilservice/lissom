-- Drop-in tas ned.
--
-- Eieren, 31. august: «nå vil jeg at du fjerner det som har med drop in, i
-- admin, min side, og nettsiden globalt i alle steder, alle kalendere, og du
-- skal faktisk sjekke at det er borte».
--
-- Og, i samme melding, hvorfor dette er en avskruing og ikke en sletting:
-- «lagre hvordan drop inn virker slik at jeg kan be deg hente det frem
-- senere». Ingenting slettes her som ikke kan lages igjen. Tabellen
-- dropin_tider, kolonnene fast_fra/fast_til/fra_dropin_tid og selve kurset
-- blir staaende. Se docs/DROP-IN.md for hele beskrivelsen og for hvordan det
-- skrus paa igjen.
--
-- ── Hvorfor status er bryteren ────────────────────────────────────────
--
-- Apent::leggUtPaaApneTider() henter kursene sine med
--
--     WHERE folger_apningstid = 1 AND status = 'publisert'
--
-- Settes drop-in til kladd, slutter oektene aa bli laget av seg selv, og
-- kurset forsvinner samtidig fra den offentlige katalogen. Én rad gjor begge
-- deler. Paint on Pots staar ogsaa med folger_apningstid = 1 og roeres ikke
-- av dette — den blir liggende som for.

UPDATE courses
   SET status = 'kladd'
 WHERE type = 'dropin' OR tema = 'Drop-in' OR slug = 'drop-in';

-- Ukereglene har staatt inaktive siden migrasjon 102. Vi setter dem for
-- sikkerhets skyld, i tilfelle noen har slaatt en av dem paa igjen.
UPDATE dropin_tider SET aktiv = 0 WHERE aktiv = 1;

-- Tidene som ligger ute framover, og som ingen har booket, ryddes bort. Uten
-- dette ville de over hundre oektene blitt staaende i basen og dukket opp i
-- enhver telling som ikke filtrerer paa kurset.
--
-- En oekt noen HAR booket blir staaende. Plassen er deres, den er betalt, og
-- den skal fortsatt kunne finnes igjen under Betalinger og paa Min side.
DELETE cs FROM course_sessions cs
  JOIN courses c ON c.id = cs.course_id
 WHERE (c.type = 'dropin' OR c.tema = 'Drop-in' OR c.slug = 'drop-in')
   AND cs.start_tid > UTC_TIMESTAMP()
   AND NOT EXISTS (SELECT 1 FROM bookings b
                    WHERE b.course_session_id = cs.id
                      AND b.status <> 'avbestilt');

-- Merknaden paa dreieskivene nevnte drop-in. Migrasjon 103 la den inn; den
-- er rettet der ogsaa, saa en ny installasjon faar den riktige teksten.
UPDATE ressurser
   SET merknad = 'Dreiekursene og Date Night deler disse.'
 WHERE navn = 'Dreieskive'
   AND merknad = 'Dreiekursene, Date Night og drop-in deler disse.';
