-- godkjent av eieren: 2026-09-28
--
-- Prøv Lissom: sluttdato og ingen binding.
--
-- Eieren, 28. september 2026: «prøv lissom må jo ha slutt dato». Den gjelder
-- for maaneden den kjoepes i, med sluttdato paa siste dag i maaneden — samme
-- system som de andre medlemskapene. Kjoept paa nettsida foer 5. september
-- fikk proeveperioden ingen sluttdato, og avtaleraden fikk «binding_til» to
-- maaneder fram (regelen planen hadde da). Min side sa «Bundet til 2. nov.»,
-- og «Forny» ble avvist som et loepende medlemskap.
--
-- 1. Sluttdato = siste dag i kjoepsmaaneden (Medlemskap::proveSlutt()), for
--    den som staar paa en engangsplan uten sluttdato. Kjoepet er da
--    avtaleraden ble laget. Den som er byttet til en loepende plan, og den
--    som alt har en sluttdato, roeres ikke.
UPDATE members m
  JOIN membership_plans mp ON mp.navn = m.medlemskap_type AND mp.engangs = 1
  JOIN (SELECT s.member_id, MIN(s.created_at) AS kjopt
          FROM subscriptions s
          JOIN membership_plans p ON p.navn = s.plan AND p.engangs = 1
         WHERE s.status = 'aktiv'
         GROUP BY s.member_id) k ON k.member_id = m.id
   SET m.slutt_dato = LAST_DAY(k.kjopt)
 WHERE m.slutt_dato IS NULL
   AND m.status IN ('aktiv', 'prove')
   -- Unntak (eieren, 28. september 2026): medlem 10 (Ida Kristine) kjoepte
   -- Prøv Lissom paa de gamle reglene og beholder dem — ingen sluttdato,
   -- ingen endring i timer eller vilkaar.
   AND m.id <> 10;

-- 2. En engangsplan er aldri bundet.
UPDATE subscriptions s
  JOIN membership_plans p ON p.navn = s.plan AND p.engangs = 1
   SET s.binding_til = NULL
 WHERE s.binding_til IS NOT NULL;
