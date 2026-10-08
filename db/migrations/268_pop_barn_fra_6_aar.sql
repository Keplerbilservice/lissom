-- godkjent av eieren: 2026-10-08
--
-- Paint on Pots: «Barn fra 6 år» (eieren, «ok, godkjent» 8. oktober 2026,
-- fremvisningen YGp25LcNzhN9Xi3eUHSDTE). Svaret på «Kan barn være med?»
-- byttes bare der det står ordrett som før.
UPDATE content_blocks
   SET verdi = 'Ja, barn fra 6 år er velkomne sammen med en voksen.'
 WHERE nokkel = 'Paint on Pots/8/Svar 2'
   AND verdi = 'Ja, Paint on Pots passer godt for barn i følge med voksen.';
