-- Spoersmaalene ut av «Om oss» i innholdsredigeringen.
--
-- Eieren: «vi har en lenke med om oss og en med spoersmaal og svar, disse
-- skal vaere adskilt — rydd opp».
--
-- Spoersmaalene fikk egen side i forrige utgave, men tekstene laa fortsatt
-- som en seksjon under «Om oss» i skjemaet. Da sto to sider i det samme
-- skjemaet, og man matte lete i Om oss for aa rette et spoersmaal.
--
-- Seksjonene under «Om oss» var:
--
--   0  Om verkstedet
--   1  Spoersmaal og svar   → egen oppforing
--   2  Finn fram            → rykker til 1
--
-- Noeklene i content_blocks folger nummeret, saa de maa flyttes med. Uten
-- dette ville alt eieren har redigert blitt liggende igjen paa noekler ingen
-- leser — teksten ville sett ut som slettet, og standardteksten kommet
-- tilbake paa sida.
--
-- Rekkefolgen er ikke tilfeldig: 1 maa toemmes for 2 flyttes dit.

UPDATE content_blocks
   SET nokkel = CONCAT('Spørsmål og svar/0/', SUBSTRING(nokkel, LENGTH('Om oss/1/') + 1))
 WHERE nokkel LIKE 'Om oss/1/%';

UPDATE content_blocks
   SET nokkel = CONCAT('Om oss/1/', SUBSTRING(nokkel, LENGTH('Om oss/2/') + 1))
 WHERE nokkel LIKE 'Om oss/2/%';

-- SEO-blokka for sida er ikke roert: den staar paa «SEO/sporsmal», satt av
-- seo-kart.json, og har aldri ligget under «Om oss».
