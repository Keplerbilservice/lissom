-- «Selg egne arbeider gjennom lissom.no» hoerer til aarsmedlemskapet.
--
-- Linja ble lagt paa av skjermkoden, paa hver loepende plan. Den sto dermed
-- paa Basis 30, Aarsmedlemskap og Fri tilgang uansett hva som var skrevet
-- inn — og paa Basis 30, som har den i basen fra for, sto den to ganger.
--
-- Eieren, 2. september: «fjern selg egne arbeider gjennom lissom paa alle
-- medlemskap bortsett fra aarsmedlemskap».
--
-- Koden legger den ikke paa lenger. Punktlista kommer fra basen, som alle de
-- andre punktene, og verkstedet bestemmer selv hvilke planer som har den —
-- fra Kurs og medlemskap → Medlemskap.
--
-- Denne filen flytter den dit den skal: av Basis 30, paa Aarsmedlemskap.

-- ── Av alle andre enn aarsmedlemskapet ────────────────────────────────
--
-- Linja staar paa sin egen linje i feltet. Vi tar den, og linjeskiftet foran
-- den, saa det ikke blir staaende en tom linje igjen.
UPDATE membership_plans
   SET punkter = TRIM(BOTH CHAR(10) FROM
         REPLACE(REPLACE(punkter,
           CONCAT(CHAR(10), 'Selg egne arbeider gjennom lissom.no'), ''),
           'Selg egne arbeider gjennom lissom.no', ''))
 WHERE binding_mnd < 12
   AND punkter LIKE '%Selg egne arbeider gjennom lissom.no%';

-- ── Paa aarsmedlemskapet ──────────────────────────────────────────────
--
-- Bare der den ikke staar fra for.
UPDATE membership_plans
   SET punkter = CONCAT(TRIM(BOTH CHAR(10) FROM punkter), CHAR(10),
                        'Selg egne arbeider gjennom lissom.no')
 WHERE binding_mnd >= 12
   AND punkter NOT LIKE '%Selg egne arbeider gjennom lissom.no%';
