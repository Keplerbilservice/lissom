-- Rydder opp etter betalingstesten.
--
-- Paint on Pots sto midlertidig til én krone for å kunne prøve betaling mot
-- ekte Vipps. Testkurset fra 004 tas ut av sirkulasjon.
UPDATE courses SET pris_ore = 69000 WHERE slug = 'paint-on-pots';

-- Testkurset fra 004 tas ut av sirkulasjon. Avlyses framfor å slettes:
-- bookinger og betalinger er bokføringspliktige, og fremmednøklene må
-- fortsatt peke et sted.
UPDATE course_sessions cs JOIN courses c ON c.id = cs.course_id
   SET cs.status = 'avlyst' WHERE c.slug = 'testkurs';
UPDATE courses SET status = 'avlyst' WHERE slug = 'testkurs';
