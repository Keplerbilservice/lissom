-- Nytt medlemskap kjoept den 21. eller senere gjelder NESTE kalendermaaned.
--
-- Eieren, 2. oktober 2026: Johanna kjoepte Mini 15 (gjoer opp selv) 29.
-- september, betalte full pris for to dager i september og sto «Forfalt 1.
-- oktober». Koden setter naa gjelder_fra paa foerste betaling (se
-- Medlemskap::gjelderFraNytt()). Denne retter betalingene som alt er gjort.
--
-- Treffer bare betalinger som er ALLE disse:
--   * formal = 'medlemskap', gjelder_fra IS NULL, knyttet til et medlem
--   * betalt (betalt / delvis_refundert) og ikke annullert
--   * ikke en timepakke, og ikke paa en engangsplan (Prøv Lissom)
--   * opprettet fra og med 21. september 2026 kl. 00.00 norsk tid, og paa
--     dag 21 eller senere i maaneden (norsk tid)
--   * medlemmets FOERSTE medlemskapsbetaling (ingen betalt foer den)
--   * og ingen betalt medlemskapsbetaling etter den — har hen alt betalt
--     igjen (f.eks. «Forny» etter «Forfalt»), maa den sees paa for haand,
--     ellers ville den samme maaneden staatt betalt to ganger
--   * IKKE fast trekk i Vipps (avtale med vipps_agreement_id eller et
--     recurring_charge). Der staar neste trekk alt for maaneden etter
--     kjoepet; flyttes foerste betaling, ville den maaneden blitt trukket to
--     ganger. De sees paa for haand.
--
-- gjelder_fra = den 1. i maaneden etter betalingsdagen (norsk tid).
--
-- Trygg aa kjoere to ganger: andre gang er gjelder_fra satt, og ingen rad
-- treffes. Rorer ingen tekst, ingen beloep og ingen status.
--
-- Norsk tid uten tidssonetabeller (CONVERT_TZ krever dem): sommertid +2
-- til 25.10.2026 01:00 UTC, vintertid +1 til 28.03.2027 01:00 UTC, sommertid
-- +2 til 31.10.2027 01:00 UTC, deretter +1.

UPDATE payments p
JOIN (
    SELECT x.id,
           DATE_ADD(LAST_DAY(x.oslo), INTERVAL 1 DAY) AS fra
      FROM (
        SELECT p2.id, p2.member_id,
               p2.created_at + INTERVAL (CASE
                   WHEN p2.created_at < '2026-10-25 01:00:00' THEN 2
                   WHEN p2.created_at < '2027-03-28 01:00:00' THEN 1
                   WHEN p2.created_at < '2027-10-31 01:00:00' THEN 2
                   ELSE 1 END) HOUR AS oslo
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
           AND NOT EXISTS (SELECT 1 FROM timepakker tp WHERE tp.payment_id = p2.id)
           AND NOT EXISTS (
               SELECT 1 FROM payments e
                WHERE e.member_id = p2.member_id AND e.id <> p2.id
                  AND e.formal = 'medlemskap'
                  AND e.status IN ('betalt', 'delvis_refundert')
                  AND e.annullert_at IS NULL
                  AND NOT EXISTS (SELECT 1 FROM timepakker te WHERE te.payment_id = e.id))
      ) x
     WHERE DAYOFMONTH(x.oslo) >= 21
) n ON n.id = p.id
   SET p.gjelder_fra = n.fra
 WHERE p.gjelder_fra IS NULL;
