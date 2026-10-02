-- Fra Prøv Lissom til et vanlig medlemskap etter den 20. gjelder neste maaned.
--
-- Eieren, 2. oktober 2026: Johanna betalte Prøv Lissom (2. september) og
-- saa Mini 15 (29. september). Migrasjon 244 hoppet over henne fordi hun
-- hadde en annen betalt medlemskapsbetaling — Prøv-betalingen. Koden
-- (Medlemskap::gjelderFraNytt) regner Prøv Lissom → vanlig medlemskap som
-- NYTT: proeveperioden er ikke en loepende periode. Denne gjoer det samme
-- for betalingene fra foer koden kom ut.
--
-- Treffer bare betalinger som er ALLE disse (som 244):
--   * formal = 'medlemskap', gjelder_fra IS NULL, knyttet til et medlem
--   * betalt (betalt / delvis_refundert) og ikke annullert
--   * ikke en timepakke, ikke paa en engangsplan, ikke fast trekk
--   * opprettet 21.–30. september 2026 norsk tid (dag 21+). Etter det setter
--     koden gjelder_fra selv, saa senere rader roeres ikke.
-- Og i tillegg:
--   * medlemmet har minst én ELDRE betalt medlemskapsbetaling, og ALLE de
--     eldre er paa en engangsplan (Prøv Lissom, sett gjennom avtalen). En
--     eldre betaling uten avtale kan ikke kjennes igjen som Prøv, og stopper
--     raden (som i koden).
--   * ingen betalt medlemskapsbetaling ETTER den (ellers sees det paa for
--     haand, saa ingen maaned staar betalt to ganger)
--
-- gjelder_fra = 2026-10-01 (den 1. i maaneden etter betalingsdagen).
-- Trygg aa kjoere to ganger: andre gang er gjelder_fra satt. Bare én UPDATE;
-- ingen tekst, ingen beloep, ingen status. September 2026 er sommertid (+2).

UPDATE payments p
JOIN (
    SELECT x.id,
           DATE_ADD(LAST_DAY(x.oslo), INTERVAL 1 DAY) AS fra
      FROM (
        SELECT p2.id,
               p2.created_at + INTERVAL 2 HOUR AS oslo
          FROM payments p2
          JOIN members m ON m.id = p2.member_id
     LEFT JOIN subscriptions s ON s.id = p2.subscription_id
          JOIN membership_plans mp ON mp.navn = COALESCE(s.plan, m.medlemskap_type)
         WHERE p2.formal = 'medlemskap'
           AND p2.gjelder_fra IS NULL
           AND p2.status IN ('betalt', 'delvis_refundert')
           AND p2.annullert_at IS NULL
           AND mp.engangs = 0
           AND p2.type <> 'recurring_charge'
           AND COALESCE(s.vipps_agreement_id, '') = ''
           AND p2.created_at >= '2026-09-20 22:00:00'
           AND p2.created_at <  '2026-09-30 22:00:00'
           AND NOT EXISTS (SELECT 1 FROM timepakker tp WHERE tp.payment_id = p2.id)
           -- minst én eldre betaling, og alle eldre er Prøv Lissom
           AND EXISTS (
               SELECT 1 FROM payments e
                 JOIN subscriptions es ON es.id = e.subscription_id
                 JOIN membership_plans ep ON ep.navn = es.plan AND ep.engangs = 1
                WHERE e.member_id = p2.member_id AND e.id < p2.id
                  AND e.formal = 'medlemskap'
                  AND e.status IN ('betalt', 'delvis_refundert')
                  AND e.annullert_at IS NULL)
           AND NOT EXISTS (
               SELECT 1 FROM payments e
            LEFT JOIN subscriptions es ON es.id = e.subscription_id
            LEFT JOIN membership_plans ep ON ep.navn = es.plan
                WHERE e.member_id = p2.member_id AND e.id <> p2.id
                  AND e.formal = 'medlemskap'
                  AND e.status IN ('betalt', 'delvis_refundert')
                  AND e.annullert_at IS NULL
                  AND NOT EXISTS (SELECT 1 FROM timepakker te WHERE te.payment_id = e.id)
                  AND (e.id > p2.id OR COALESCE(ep.engangs, 0) = 0))
      ) x
     WHERE DAYOFMONTH(x.oslo) >= 21
) n ON n.id = p.id
   SET p.gjelder_fra = n.fra
 WHERE p.gjelder_fra IS NULL;
