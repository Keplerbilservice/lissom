-- Kurs som gaar over flere dager faar dagene sine.
--
-- Eieren, 10. september 2026: «Dreiekurs, 16 og 17 september, vises kun 16
-- september i kalender?» Og, da det ble klart hva raden faktisk inneholdt:
-- «Den kan jo ikke gaa over natten, den maa jo bare legge slik» — «Dag 1
-- dato + fra kl - til kl. Dag 2 dato + fra kl - til kl».
--
-- Feltet «Sluttdato» i «Ny kursdato» lagde én oekt fra 16. kl 15 til 17. kl
-- 18 — en kveld paa 27 timer. Kalenderen gir hver oekt én dato, den den
-- starter paa, saa dag to fantes ikke noe sted. Veiviseren er lagt om og
-- lagrer dagene som samlinger naa; denne tar dem som alt ligger inne.
--
-- Hva den gjor: lager to samlinger paa hver slik oekt — dag én med oektas
-- egne klokkeslett, dag to med de samme. Da staar begge dagene i kalenderen
-- og paa nettsida, uten at noe annet roeres.
--
-- Hva den IKKE gjor:
--
--   * Ingen paamelding, pris, plass eller kursholder roeres.
--   * start_tid og slutt_tid staar som de staar. Med samlinger SKAL oekta
--     spenne fra forste til siste dag — det er slik kunden ser
--     «7.–8. oktober», se Samlinger::speilOkt().
--   * Oekter som alt har samlinger roeres ikke. De er riktige.
--   * En kveld som slutter tidligere paa doegnet enn den begynner — 20:00
--     til 00:00, eller en nattevakt 22:00 til 02:00 — er én kveld, ikke to
--     dager. Den staar som den er. Den samme regelen staar i speilOkt().
--   * Oekter over mer enn to dager roeres ikke: da er det ikke aapenbart
--     hvilke dager kurset faktisk gaar, og en gjetning er verre enn ingen.
--     De maa settes opp for haand.
--
-- Klokkeslettene: radene i course_sessions staar i UTC, samlingene i lokal
-- tid. CONVERT_TZ regner om. Har ikke serveren tidssonetabellene, gir den
-- NULL — og da settes klokkeslettet til NULL, som betyr «bruk oektas eget»
-- i Samlinger. Datoen blir riktig uansett.

INSERT INTO okt_samlinger (session_id, nummer, dato, fra, til)
SELECT cs.id, 1,
       DATE(COALESCE(CONVERT_TZ(cs.start_tid, '+00:00', 'Europe/Oslo'), cs.start_tid)),
       TIME(CONVERT_TZ(cs.start_tid, '+00:00', 'Europe/Oslo')),
       TIME(CONVERT_TZ(cs.slutt_tid, '+00:00', 'Europe/Oslo'))
  FROM course_sessions cs
 WHERE cs.slutt_tid IS NOT NULL
   AND DATE(cs.slutt_tid) = DATE(cs.start_tid) + INTERVAL 1 DAY
   AND TIME(cs.slutt_tid) > TIME(cs.start_tid)
   AND NOT EXISTS (SELECT 1 FROM okt_samlinger s WHERE s.session_id = cs.id);

INSERT INTO okt_samlinger (session_id, nummer, dato, fra, til)
SELECT cs.id, 2,
       DATE(COALESCE(CONVERT_TZ(cs.slutt_tid, '+00:00', 'Europe/Oslo'), cs.slutt_tid)),
       TIME(CONVERT_TZ(cs.start_tid, '+00:00', 'Europe/Oslo')),
       TIME(CONVERT_TZ(cs.slutt_tid, '+00:00', 'Europe/Oslo'))
  FROM course_sessions cs
 WHERE cs.slutt_tid IS NOT NULL
   AND DATE(cs.slutt_tid) = DATE(cs.start_tid) + INTERVAL 1 DAY
   AND TIME(cs.slutt_tid) > TIME(cs.start_tid)
   AND (SELECT COUNT(*) FROM okt_samlinger s WHERE s.session_id = cs.id) = 1;
