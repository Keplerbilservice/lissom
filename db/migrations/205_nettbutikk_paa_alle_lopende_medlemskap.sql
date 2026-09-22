-- Egen nettbutikk og plass i butikken paa Teie — paa alle loepende medlemskap.
--
-- Eieren, 22. september 2026: «Jeg vil at alle medlemskap skal faa denne
-- muligheten, men ikke proev lissom», «Tilgang til egen nettbutikk» og «Dine
-- egne produkter i vaar butikk paa teie».
--
-- Det motsatte sto her fra 2. september: «fjern selg egne arbeider gjennom
-- lissom paa alle medlemskap bortsett fra aarsmedlemskap» (migrasjon 124).
-- Denne snur det, og legger til den fysiske butikken, som ikke sto noe sted.
--
-- ── Hvem som faar den ─────────────────────────────────────────────────
--
-- Ikke etter navn. Migrasjon 124 gikk etter «binding_mnd», og sperrene i
-- koden gikk etter plan-navnet «Aarsmedlemskap» — begge deler brister naar
-- verkstedet doper om en plan eller legger til en ny. Her, som i migrasjon
-- 034, ser vi paa hva planen ER: «engangs = 1» er proeveperioden, alt annet
-- er et loepende medlemskap. Samme regel som Medlemskap::kanSelge() bruker.

-- ── Ut med den gamle linja, overalt ───────────────────────────────────
--
-- To formuleringer har vaert i bruk. Begge staar paa egen linje i feltet, og
-- linjeskiftet foran tas med saa det ikke blir staaende en tom linje igjen.
UPDATE membership_plans
   SET punkter = TRIM(BOTH CHAR(10) FROM
         REPLACE(REPLACE(REPLACE(REPLACE(punkter,
           CONCAT(CHAR(10), 'Mulighet til å selge egne arbeider gjennom lissom.no'), ''),
           'Mulighet til å selge egne arbeider gjennom lissom.no', ''),
           CONCAT(CHAR(10), 'Selg egne arbeider gjennom lissom.no'), ''),
           'Selg egne arbeider gjennom lissom.no', ''))
 WHERE punkter LIKE '%elg%egne arbeider gjennom lissom.no%';

-- ── Inn med de to nye, paa hvert loepende medlemskap ──────────────────
UPDATE membership_plans
   SET punkter = CONCAT(TRIM(BOTH CHAR(10) FROM punkter), CHAR(10),
                        'Tilgang til egen nettbutikk')
 WHERE engangs = 0
   AND punkter NOT LIKE '%Tilgang til egen nettbutikk%';

UPDATE membership_plans
   SET punkter = CONCAT(TRIM(BOTH CHAR(10) FROM punkter), CHAR(10),
                        'Dine egne produkter i vår butikk på Teie')
 WHERE engangs = 0
   AND punkter NOT LIKE '%Dine egne produkter i vår butikk på Teie%';

-- ── Langteksten paa aarsmedlemskapet ──────────────────────────────────
--
-- Der sto salget som noe bare aarsmedlemmer fikk: «En ekstra fordel med
-- Årsmedlemskap er muligheten til å selge egne arbeider gjennom lissom.no.»
-- Det er ikke sant lenger, og et avsnitt som lover noe eksklusivt til alle
-- er verre enn ingen tekst. Avsnittet byttes, ikke fjernes — det staar midt
-- i en tekst som ellers gaar sin gang.
UPDATE membership_plans
   SET langtekst = REPLACE(langtekst,
         'En ekstra fordel med Årsmedlemskap er muligheten til å selge egne arbeider gjennom lissom.no. Dersom du lager produkter du ønsker å tilby andre, kan du få vist frem arbeidene dine gjennom vår nettbutikk og bli en del av det kreative fellesskapet rundt Lissom.',
         'Med et løpende medlemskap får du din egen nettbutikk på lissom.no, og du kan ha dine egne produkter i butikken vår på Teie. Lager du noe du vil tilby andre, har du et sted å gjøre det fra – og blir en del av det kreative fellesskapet rundt Lissom.')
 WHERE langtekst LIKE '%En ekstra fordel med Årsmedlemskap er muligheten til å selge egne arbeider%';
