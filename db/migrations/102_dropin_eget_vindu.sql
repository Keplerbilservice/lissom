-- Drop-in følger sitt eget vindu, ikke åpningstidene.
--
-- Eieren, 30. august:
--   «1. det kan bookes hele døgnet
--    2. det skal ikke følge kurs eller åpningstider»
-- og, presisert: «det skal kunne bookes tid mellom kl 08:00 og 22:00».
--
-- Slik det har vært: drop-in sto med folger_apningstid = 1, og plassene ble
-- klippet ut av åpningstidene — som igjen regnes av kursene som går den
-- dagen. Gikk det ikke noe kurs, var det ingen åpningstid, og da fantes det
-- ingen drop-in å booke. Verkstedet var altså bare åpent for drop-in de
-- dagene det gikk et kurs, som er den motsatte logikken av hva drop-in er.
--
-- Nå: to klokkeslett på kurset selv. Står de, gjelder de hver dag, uavhengig
-- av kurs, åpningstider og innstempling. Står de ikke, gjelder åpningstidene
-- som før — Paint on Pots ligger fortsatt der.

ALTER TABLE courses
  ADD COLUMN fast_fra TIME NULL COMMENT 'Fast vindu: fra dette klokkeslettet, hver dag',
  ADD COLUMN fast_til TIME NULL COMMENT 'Fast vindu: til dette klokkeslettet, hver dag';

UPDATE courses
   SET folger_apningstid = 1,
       fast_fra = '08:00:00',
       fast_til = '22:00:00'
 WHERE type = 'dropin' OR tema = 'Drop-in';

-- Ett sted å bestemme fra, ikke to.
--
-- Drop-in hadde fra før ukeregler i `dropin_tider` — «tirsdag 10–13» og
-- lignende — som lager sine egne økter. Med det faste vinduet ville de to
-- generatorene lagt plasser oppi hverandre: en tre timers regelplass midt i
-- rekka av halvannen time, med hvert sitt plasstall.
--
-- Reglene settes derfor inaktive. Selve radene blir stående, så ingenting er
-- tapt om vinduet skal bort igjen.
UPDATE dropin_tider SET aktiv = 0 WHERE aktiv = 1;

-- Øktene reglene har laget, og som ingen har booket, ryddes bort. En økt noen
-- har booket blir stående — plassen er deres.
DELETE cs FROM course_sessions cs
  JOIN courses c ON c.id = cs.course_id
 WHERE cs.fra_dropin_tid IS NOT NULL
   AND (c.type = 'dropin' OR c.tema = 'Drop-in')
   AND cs.start_tid > UTC_TIMESTAMP()
   AND NOT EXISTS (SELECT 1 FROM bookings b
                    WHERE b.course_session_id = cs.id
                      AND b.status <> 'avbestilt');
