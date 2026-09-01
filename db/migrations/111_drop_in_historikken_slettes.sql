-- Historikken etter drop-in slettes.
--
-- Eieren, 1. september: «Historikken på drop inn skal også bort».
--
-- Migrasjon 110 tok drop-in ned, men lot bookinger noen hadde betalt for staa:
-- en kunde som hadde betalt kr. 490,- skulle fortsatt se kjopet sitt, og linja
-- skulle staa i regnskapet. Det er den avgjorelsen som snus her. Eieren har
-- bedt om det, og dette er den ene delen av nedtakingen som ikke kan angres.
--
-- ── Hva som slettes ──────────────────────────────────────────────────
--
--   bookings         plassene folk hadde paa drop-in
--   payments         betalingene for dem
--   course_sessions  oekter som var igjen fordi noen hadde booket dem
--   waitlist         ventelisteoppforinger paa drop-in
--   gift_card_uses   gavekort brukt paa en drop-in-booking
--   deltaker_bilder  bilder knyttet til en drop-in-booking
--
-- ── Hva som IKKE slettes ─────────────────────────────────────────────
--
-- Kurset selv (id 6, som kladd) og ukereglene i dropin_tider blir staaende.
-- De er ikke historikk — de er oppskriften. Eieren, 31. august: «lagre
-- hvordan drop inn virker slik at jeg kan be deg hente det frem senere», og
-- den beskjeden staar. Ingen av dem vises noe sted; se docs/DROP-IN.md.
--
-- ── Sporet etter pengene ─────────────────────────────────────────────
--
-- Betalinger som slettes er penger som har vaert innom regnskapet. Summen og
-- antallet skrives derfor til audit_log foerst, saa det finnes et spor av at
-- de har eksistert, og av naar de ble tatt bort. Uten det ville en omsetning
-- ha endret seg uten at noe sa hvorfor.

INSERT INTO audit_log (member_id, handling, objekt_type, objekt_id, detaljer)
SELECT NULL,
       'dropin_historikk_slettet',
       'course',
       c.id,
       CONCAT('{"bookinger":', COUNT(DISTINCT b.id),
              ',"betalinger":', COUNT(DISTINCT p.id),
              ',"belop_ore":', COALESCE(SUM(DISTINCT p.belop_ore), 0),
              ',"migrasjon":111}')
  FROM courses c
  LEFT JOIN bookings b ON b.course_id = c.id
  LEFT JOIN payments p ON p.booking_id = b.id
 WHERE c.type = 'dropin' OR c.tema = 'Drop-in' OR c.slug = 'drop-in'
 GROUP BY c.id
HAVING COUNT(DISTINCT b.id) > 0;

-- Gavekort brukt paa en drop-in-booking. «ref_type» skiller bookinger fra
-- ordrer i den tabellen.
DELETE g FROM gift_card_uses g
 WHERE g.ref_type = 'booking'
   AND g.ref_id IN (SELECT b.id FROM bookings b
                      JOIN courses c ON c.id = b.course_id
                     WHERE c.type = 'dropin' OR c.tema = 'Drop-in' OR c.slug = 'drop-in');

DELETE d FROM deltaker_bilder d
  JOIN bookings b ON b.id = d.booking_id
  JOIN courses c ON c.id = b.course_id
 WHERE c.type = 'dropin' OR c.tema = 'Drop-in' OR c.slug = 'drop-in';

-- Bookingene foer betalingene: bookings.payment_id peker paa payments med
-- RESTRICT, saa en betaling kan ikke slettes mens en booking henger i den.
-- Betalingene fanges opp i en midlertidig tabell foerst, ellers er koblingen
-- borte naar vi kommer til dem.
CREATE TEMPORARY TABLE dropin_betalinger AS
SELECT DISTINCT p.id
  FROM payments p
  JOIN bookings b ON b.id = p.booking_id
  JOIN courses c ON c.id = b.course_id
 WHERE c.type = 'dropin' OR c.tema = 'Drop-in' OR c.slug = 'drop-in';

DELETE b FROM bookings b
  JOIN courses c ON c.id = b.course_id
 WHERE c.type = 'dropin' OR c.tema = 'Drop-in' OR c.slug = 'drop-in';

-- En betaling som ogsaa henger i en ordre eller et gavekort roeres ikke:
-- da er den ikke bare drop-in, og de to tabellene staar med RESTRICT.
DELETE p FROM payments p
  JOIN dropin_betalinger d ON d.id = p.id
 WHERE NOT EXISTS (SELECT 1 FROM orders o WHERE o.payment_id = p.id)
   AND NOT EXISTS (SELECT 1 FROM gift_cards g WHERE g.payment_id = p.id);

DROP TEMPORARY TABLE dropin_betalinger;

-- Oektene som var igjen fordi noen hadde booket dem. Naa er det ingen igjen
-- aa ta hensyn til.
DELETE cs FROM course_sessions cs
  JOIN courses c ON c.id = cs.course_id
 WHERE c.type = 'dropin' OR c.tema = 'Drop-in' OR c.slug = 'drop-in';

DELETE w FROM waitlist w
  JOIN courses c ON c.id = w.course_id
 WHERE c.type = 'dropin' OR c.tema = 'Drop-in' OR c.slug = 'drop-in';
