-- godkjent av eieren: 2026-10-03
-- Meldingene, eierens valg 3. oktober 2026 (skjema YbLC6SsCPPVP99toBZGLhh).
--
-- 1) Tolv maler slaas AV (aktiv = 0). Bare aktiv endres; teksten staar, saa
--    de kan slaas paa igjen under Tekst maler. Varsel::mal() og
--    Varsel::malTilAdmin() henter bare aktive maler, og en mal som er av gir
--    ingen «Varsel må sendes for hånd».
--
-- 2) Ventelistebekreftelsen: «Skulle det bli en ledig plass, sender vi deg en
--    e-post eller forsøker å ringe deg.» blir «Skulle det bli en ledig plass,
--    kontakter vi deg.» Resten likt. REPLACE treffer bare den noeyaktige
--    setningen, i avsnittene (oppsettet fra migrasjon 227) og i teksten.
--
-- Bare oppdateringer. Trygg aa kjoere to ganger: andre gang er alt alt gjort.

UPDATE notification_templates
   SET aktiv = 0
 WHERE navn IN (
       'butikkordre_pakke',
       'intern_dugnad_ferdig',
       'intern_dugnad_sporsmal',
       'intern_gave_lost_inn',
       'intern_gave_pakkes',
       'intern_nytt_medlem',
       'intern_nytt_medlem_sms',
       'intern_ny_foresporsel',
       'intern_ny_pamelding',
       'intern_ny_vare',
       'ordre_annullert',
       'soknad_godkjent_sms'
 )
   AND aktiv <> 0;

UPDATE notification_templates
   SET avsnitt = REPLACE(avsnitt,
       'Skulle det bli en ledig plass, sender vi deg en e-post eller forsøker å ringe deg.',
       'Skulle det bli en ledig plass, kontakter vi deg.')
 WHERE navn = 'venteliste_satt'
   AND avsnitt LIKE '%Skulle det bli en ledig plass, sender vi deg en e-post eller forsøker å ringe deg.%';

UPDATE notification_templates
   SET tekst = REPLACE(tekst,
       'Skulle det bli en ledig plass, sender vi deg en e-post eller forsøker å ringe deg.',
       'Skulle det bli en ledig plass, kontakter vi deg.')
 WHERE navn = 'venteliste_satt'
   AND tekst LIKE '%Skulle det bli en ledig plass, sender vi deg en e-post eller forsøker å ringe deg.%';
