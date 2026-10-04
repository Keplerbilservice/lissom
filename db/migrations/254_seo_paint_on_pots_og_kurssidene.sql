-- godkjent av eieren: 2026-10-04
--
-- SEO-gjennomgangen 4. oktober 2026, tiltak 4 («ok, gjør det»):
--
--   * /kurs/paint-on-pots blir Paint on Pots-hovedsiden (/paint-on-pots
--     sendes dit med 301, se .htaccess). Egen tittel, beskrivelse og H1.
--   * /kurs/dreiekurs: H1 «Dreiekurs i Tønsberg» i stedet for kursnavnet.
--   * /kurs/handbygging: tittel med «plateteknikk».
--
-- To nye kolonner, tomme for alle andre kurs — da gjelder kursnavnet som
-- foer. Kursnavnet selv (tittel) roeres ikke: det staar paa bookinger,
-- kvitteringer og e-poster, og kurs med paameldte skal ikke endres.
--
--   seo_h1      overskriften paa kurssida, naar den skal si mer enn navnet
--   seo_ingress én linje under overskriften, for soek og for leseren
--
-- {pris} i seo_meta byttes med kursets egen pris naar sida tegnes
-- (side.php og Robottekst), saa beskrivelsen aldri viser en gammel pris.
--
-- Bare tomme felter fylles. Har eieren skrevet noe selv i kursoppsettet,
-- staar det. Kan kjoeres flere ganger.

ALTER TABLE courses
    ADD COLUMN IF NOT EXISTS seo_h1 VARCHAR(191) NULL
        COMMENT 'Overskriften (H1) paa kurssida, naar den skal vaere en annen enn kursnavnet.'
        AFTER seo_meta,
    ADD COLUMN IF NOT EXISTS seo_ingress VARCHAR(400) NULL
        COMMENT 'Én linje under overskriften paa kurssida. Tom = ingen.'
        AFTER seo_h1;

UPDATE courses
   SET seo_tittel = 'Paint on Pots Tønsberg – mal din egen keramikk | Lissom'
 WHERE slug = 'paint-on-pots' AND (seo_tittel IS NULL OR seo_tittel = '');

UPDATE courses
   SET seo_meta = 'Mal din egen keramikk på Teie ved Tønsberg. Velg kopp eller skål og mal den selv, vi glaserer og brenner. Ingen forkunnskaper. Fra {pris}.'
 WHERE slug = 'paint-on-pots' AND (seo_meta IS NULL OR seo_meta = '');

UPDATE courses
   SET seo_h1 = 'Paint on Pots i Tønsberg'
 WHERE slug = 'paint-on-pots' AND (seo_h1 IS NULL OR seo_h1 = '');

UPDATE courses
   SET seo_ingress = 'Lissom er et selvstendig keramikkverksted på Teie ved Tønsberg, og er ikke en del av Paint on Pots-kjeden.'
 WHERE slug = 'paint-on-pots' AND (seo_ingress IS NULL OR seo_ingress = '');

UPDATE courses
   SET seo_h1 = 'Dreiekurs i Tønsberg'
 WHERE slug = 'dreiekurs' AND (seo_h1 IS NULL OR seo_h1 = '');

UPDATE courses
   SET seo_ingress = 'Et dreiekurs i Tønsberg for nybegynnere, i små grupper på Teie. Kort vei fra Sandefjord, Horten og Larvik.'
 WHERE slug = 'dreiekurs' AND (seo_ingress IS NULL OR seo_ingress = '');

UPDATE courses
   SET seo_tittel = 'Plateteknikk og håndbygging i Tønsberg | Lissom Keramikk'
 WHERE slug = 'handbygging' AND (seo_tittel IS NULL OR seo_tittel = '');
