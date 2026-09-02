-- Paint on Pots foelger ikke lenger aapningstidene.
--
-- Eieren, 2. september: «hvorfor vises paint on pots i kalenderen naar det
-- ikke er kurs?» og «hvordan kan det vises bare paint on pots i kalendern».
-- Deretter: «jeg vil ikke at kurset skal foelge automatisk, kan du slette det
-- og gjore det saa jeg maa legge ut tid selv?»
--
-- Kurset sto med folger_apningstid = 1. Da satte Apent::leggUtPaaApneTider()
-- opp oektene selv — halvannen time om gangen gjennom hele den aapne tida, i
-- sprekkene mellom kursene som alt sto der. Alle oektene kurset hadde var
-- laget slik; ikke én var satt opp for haand.
--
-- Foelgen i kalenderen: én aapen dag ga fire til seks Paint on Pots-linjer,
-- mot én for et ekte kurs. Ukevisningen samler dem ikke (den leser oektene
-- raatt), saa de sto som hver sin blokk og fylte dagen.
--
-- Naa settes datoene opp for haand, som paa alle andre kurs.

-- Slaa av automatikken.
--
-- Utleggingen henter «WHERE folger_apningstid = 1 AND status = 'publisert'».
-- Med nullen her er kurset ute av den, og ingen nye oekter lages.
UPDATE courses
   SET folger_apningstid = 0
 WHERE tittel = 'Paint on Pots';

-- Rydd bort lukene som alt er lagt ut.
--
-- Bare de genererte (fra_apningstid = 1), og bare de ingen har booket. En
-- plass noen har kjopt er deres, og skal ikke forsvinne under foettene paa
-- dem — den blir staaende akkurat som den er.
--
-- Dette er den samme regelen utleggingen selv bruker naar den rydder: «en
-- generert oekt som ingen har booket ... tas bort igjen». Forskjellen er at
-- den ikke kommer til aa kjore mer paa dette kurset.
--
-- Bare det som ikke er over. En luke som har vaert og gaatt er en del av
-- historikken, og slettes ikke her.
DELETE cs
  FROM course_sessions cs
  JOIN courses c ON c.id = cs.course_id
 WHERE c.tittel = 'Paint on Pots'
   AND cs.fra_apningstid = 1
   AND COALESCE(cs.slutt_tid, cs.start_tid) > UTC_TIMESTAMP()
   AND NOT EXISTS (
         SELECT 1 FROM bookings b
          WHERE b.course_session_id = cs.id
            AND b.status <> 'avbestilt'
       );
