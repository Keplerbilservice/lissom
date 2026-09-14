-- Titlene og beskrivelsene i soeket nevner teknikkene med navn.
--
-- Standardtekstene i koden ble byttet 14. september 2026 (del B, GO fra
-- eieren). Men det verkstedet har lagret under Nettsiden → SEO gaar foran
-- koden — og maalt paa den ekte sida etter deployen sto de gamle titlene
-- igjen paa /kurs, /paint-on-pots, /medlemskap og /bedrift. De lagrede
-- verdiene oppdateres derfor her, felt for felt, saa alt annet verkstedet
-- har skrevet i den samme blokka staar som foer.
--
-- Bare rader som finnes og er gyldig JSON roeres. Mangler raden, gjelder
-- koden alt.

UPDATE content_blocks SET verdi = JSON_SET(verdi,
  '$.tittel',        'Dreiekurs, plateteknikk og håndbygging i Tønsberg | Lissom',
  '$.meta',          'Keramikkurs på Teie ved Tønsberg: dreiekurs over to kvelder, boller og store fat med plateteknikk, fransk smørklokke. Alt inkludert. Book med Vipps.',
  '$.h1',            'Dreiekurs, plateteknikk og håndbygging i Vestfold',
  '$.ogTittel',      'Dreiekurs, plateteknikk og håndbygging i Tønsberg | Lissom')
WHERE nokkel = 'SEO/kurs' AND JSON_VALID(verdi);

UPDATE content_blocks SET verdi = JSON_SET(verdi,
  '$.tittel',        'Paint on Pots i Tønsberg — mal din egen keramikk | Lissom',
  '$.meta',          'Paint on Pots: velg en ferdigbrent kopp eller skål og mal den selv. Vi glaserer og brenner. Barn fra 6 år med voksen. Drop-in på Teie ved Tønsberg — book plass.',
  '$.ogTittel',      'Paint on Pots i Tønsberg — mal din egen keramikk')
WHERE nokkel = 'SEO/paintonpots' AND JSON_VALID(verdi);

UPDATE content_blocks SET verdi = JSON_SET(verdi,
  '$.meta',          'Bli medlem i keramikkverkstedet på Teie: egen hylle, dørkode 24/7 og verkstedtimer hver måned. Prøv én måned uten binding — se prisene og meld deg inn.')
WHERE nokkel = 'SEO/medlemskap' AND JSON_VALID(verdi);

UPDATE content_blocks SET verdi = JSON_SET(verdi,
  '$.tittel',        'Keramikk til bedriften og teambuilding | Lissom Tønsberg',
  '$.meta',          'Bedriftsevent ved dreieskiva, og keramikk laget for bedrifter i Vestfold — kopper og servise med eget motiv. Se hva vi laget for Grenseløs, Spire Regnskap og Kepler Bilservice.',
  '$.ogTittel',      'Keramikk til bedriften og teambuilding | Lissom Tønsberg')
WHERE nokkel = 'SEO/bedrift' AND JSON_VALID(verdi);
