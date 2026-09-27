-- godkjent av eieren: 2026-09-27
--
-- Google-teksten for Paint on Pots skal si «Bestill tid» i stedet for
-- «Drop-in» — drop-in finnes ikke lenger. Eieren godkjente teksten 27.
-- september 2026. seo-kart.json ble rettet samme dag, men teksten som er
-- lagret under Nettsiden → Innhold (content_blocks «SEO/paintonpots») gaar
-- foran kartet i side.php, og der sto «Drop-in» fortsatt. Vakta fant det.
--
-- Bare den ene setningen byttes. Resten av det eieren har lagret, staar.
UPDATE content_blocks
   SET verdi = JSON_SET(verdi, '$.meta',
       REPLACE(JSON_UNQUOTE(JSON_EXTRACT(verdi, '$.meta')), 'Drop-in på Teie', 'Bestill tid på Teie'))
 WHERE nokkel = 'SEO/paintonpots'
   AND JSON_VALID(verdi)
   AND JSON_UNQUOTE(JSON_EXTRACT(verdi, '$.meta')) LIKE '%Drop-in på Teie%';
