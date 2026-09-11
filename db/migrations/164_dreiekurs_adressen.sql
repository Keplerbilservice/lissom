-- Dreiekurset faar adressen /kurs/dreiekurs, og egen tittel og meta.
--
-- Eieren, 11. september 2026, SEO-instruks: «Dreiekurs (/kurs/dreiekurs) –
-- «Dreiekurs i Tønsberg – nybegynnere velkommen | Lissom». Meta: Lær å
-- dreie på Teie ved Tønsberg. Små grupper, alt inkludert. For hele Vestfold
-- – kort vei fra Sandefjord, Horten og Larvik.»
--
-- Samme grep som bolleadressen (migrasjon 092): slug-en byttes her, og
-- .htaccess sender den gamle («nybegynner-dreiekurs») videre med 301, saa
-- det Google har indeksert og det som er delt foelger med.
--
-- Tittel og meta per kurs fantes ikke: kurssida fikk «<navn> i Tønsberg |
-- Lissom Keramikk» og foerste setning av beskrivelsen. To kolonner, tomme
-- for alle andre kurs — da gjelder det gamle. side.php og nettsida leser
-- dem naar de er satt.

ALTER TABLE courses
    ADD COLUMN IF NOT EXISTS seo_tittel VARCHAR(191) NULL
        COMMENT 'Tittelen i soeket, naar den skal vaere en annen enn «<navn> i Tønsberg | Lissom Keramikk».'
        AFTER kort_beskrivelse,
    ADD COLUMN IF NOT EXISTS seo_meta VARCHAR(320) NULL
        COMMENT 'Beskrivelsen i soeket, naar den skal vaere en annen enn foerste setning av beskrivelsen.'
        AFTER seo_tittel;

UPDATE courses
   SET slug       = 'dreiekurs',
       seo_tittel = 'Dreiekurs i Tønsberg – nybegynnere velkommen | Lissom',
       seo_meta   = 'Lær å dreie på Teie ved Tønsberg. Små grupper, alt inkludert. For hele Vestfold – kort vei fra Sandefjord, Horten og Larvik.'
 WHERE slug = 'nybegynner-dreiekurs'
   AND NOT EXISTS (SELECT 1 FROM (SELECT slug FROM courses) c2 WHERE c2.slug = 'dreiekurs');
