-- godkjent av eieren: 2026-10-04
--
-- Artikkelen /nyheter/paint-on-pots-slik-fungerer-det (migrasjon 182) sa
-- «Cirka halvannen time» og «drop-in innenfor åpningstidene». Paint on Pots
-- varer 2 timer, som kurset i systemet, og drop-in finnes ikke lenger:
-- man booker et av tidspunktene (eieren, 4. oktober 2026).
--
-- Bare de to setningene byttes, med REPLACE, slik at alt annet verkstedet
-- har skrevet i artikkelen står. Er de alt byttet, skjer ingenting — kan
-- kjøres flere ganger.

UPDATE articles
   SET innhold = REPLACE(innhold,
         'Cirka halvannen time. Du kommer når det passer i åpningstiden og booker plass på nettsiden.',
         'Cirka to timer. Du velger et av tidspunktene og booker plass på nettsiden.')
 WHERE slug = 'paint-on-pots-slik-fungerer-det';

UPDATE articles
   SET innhold = REPLACE(innhold,
         'Ja, book plass på nettsiden så vi vet at det er ledig ved bordet. Det er drop-in innenfor åpningstidene.',
         'Ja, book plass på nettsiden på et av tidspunktene, så vi vet at det er ledig ved bordet.')
 WHERE slug = 'paint-on-pots-slik-fungerer-det';
