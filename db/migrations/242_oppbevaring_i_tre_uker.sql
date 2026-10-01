-- godkjent av eieren: 2026-10-01, hentefrist endres til tre uker overalt.
-- Hentefrist: tre uker. Bevar øvrige tilpasninger og historiske utsendelser.
UPDATE notification_templates
 SET tekst = REPLACE(REPLACE(tekst, 'i to uker', 'i tre uker'), 'i 2 uker', 'i 3 uker'),
     kort = REPLACE(REPLACE(kort, 'Innen to uker', 'Innen tre uker'), 'Innen 2 uker', 'Innen 3 uker'),
     avsnitt = REPLACE(REPLACE(avsnitt, 'i to uker', 'i tre uker'), 'i 2 uker', 'i 3 uker')
 WHERE navn = 'ferdig_brent';
-- Standardteksten er «Vi oppbevarer den hos oss i tre uker.»
UPDATE content_blocks
 SET verdi = REPLACE(verdi, 'Vi oppbevarer ferdige arbeider i to uker', 'Vi oppbevarer ferdige arbeider i tre uker')
 WHERE verdi LIKE '%Vi oppbevarer ferdige arbeider i to uker%';
