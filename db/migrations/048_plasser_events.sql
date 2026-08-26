-- Plassene paa events, som 037 gikk forbi.
--
-- Bestilt 24. august sto det «kurs og workshops: 12 plasser». Migrasjon 037
-- tok den bestillinga bokstavelig og filtrerte paa type kurs, workshop og
-- dropin. Paint on Pots er type event, og ble derfor staaende paa 14 — et
-- tall ingen har bedt om. Det kom fra den foerste katalogen i 003, som var
-- fylt ut fra designutkastet.
--
-- Events skal ha 12 plasser som resten. To unntak, med vilje:
--
--   * «Kun for medlemmer» roeres ikke. «Store former, viderekomne» sto paa 8
--     og var ikke nevnt i bestillinga, og en intern samling er ikke et
--     kurs som selges.
--   * Avlyste og gjennomfoerte oekter roeres ikke. Historikken skal staa som
--     den var.
--
-- GREATEST som i 037: kapasiteten settes aldri under det som alt er solgt.
-- Ellers ville en oekt med tretten paameldte staatt med negativ ledighet, og
-- den som alt har betalt kunne mistet plassen i en senere opptelling.

-- 1) Kursene selv. Nye datoer arver herfra.
UPDATE courses
   SET kapasitet = 12
 WHERE type = 'event'
   AND COALESCE(tema, '') <> 'Kun for medlemmer'
   AND kapasitet <> 12;

-- 2) Datoene som alt ligger ute.
UPDATE course_sessions cs
  JOIN courses c ON c.id = cs.course_id
   SET cs.kapasitet = GREATEST(
         12,
         (SELECT COUNT(*) FROM bookings b
           WHERE b.course_session_id = cs.id
             AND b.status IN ('betalt', 'reservert'))
       )
 WHERE cs.status = 'planlagt'
   AND c.type = 'event'
   AND COALESCE(c.tema, '') <> 'Kun for medlemmer';
