-- Signaturen i e-postene systemet selv sender.
--
-- e-post-signatur.html har ligget ved siden av nettsiden siden i vaar. Den
-- er laget for aa limes inn i e-postprogrammet for haand, og gjelder
-- meldingene Monica skriver selv. Kvitteringene, paaminnelsene og
-- ventelistevarslene systemet sender har ingen signatur i det hele tatt.
--
-- To ting maa til:
--
-- 1. Malene maa vite hvilken gruppe de hoerer til, saa signaturen kan staa
--    paa ordrebekreftelsene og av paa systemmeldingene — eller omvendt.
--    Gruppa hoerer til malen, ikke til en liste ved siden av.
-- 2. Koen maa kunne baere en HTML-utgave. E-postene sendes i dag som ren
--    tekst, og en HTML-signatur i en ren-tekst-melding blir en klump med
--    taggkode hos mottakeren. Med begge deler sender vi
--    multipart/alternative: tekst til den som leser tekst, HTML til den som
--    leser HTML, og de sier det samme.

ALTER TABLE notification_templates
  ADD COLUMN IF NOT EXISTS gruppe ENUM('system','ordre','kurs','nyhetsbrev')
      NOT NULL DEFAULT 'system';

-- Det som gjelder et kjop.
UPDATE notification_templates SET gruppe = 'ordre'
 WHERE navn IN ('ordrebekreftelse', 'betaling_feilet', 'medlemskap_fornyet',
                'avbestilling', 'gavekort', 'refusjon');

-- Det som gjelder et kurs man staar paa.
UPDATE notification_templates SET gruppe = 'kurs'
 WHERE navn IN ('kurspaaminnelse', 'ferdig_brent', 'ferdig_glassert',
                'venteliste_ledig', 'venteliste_tildelt', 'paamelding_bekreftet',
                'kurs_avlyst', 'kurs_flyttet');

-- HTML-utgaven av meldingen. NULL betyr ren tekst, som foer.
ALTER TABLE notifications
  ADD COLUMN IF NOT EXISTS html MEDIUMTEXT NULL;

-- Startverdien er signaturen som alt er laget, hentet fra
-- e-post-signatur.html slik den staar i dag. Ingen ny signatur der det
-- finnes en. INSERT IGNORE: har noen alt skrevet sin egen, blir den staaende.
INSERT IGNORE INTO innstillinger (nokkel, verdi) VALUES
  ('epost_signatur', '<table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-family:Georgia,\'Times New Roman\',serif;color:#4D1D12;">
  <tr>
    <td style="padding:0 20px 0 0;vertical-align:top;">
      <a href="https://lissom.no" style="text-decoration:none;">
        <img src="https://lissom.no/lissom-signatur-logo.png" alt="Lissom Keramikk &amp; Håndverk" width="152" height="139" style="display:block;border:0;border-radius:10px;">
      </a>
    </td>
    <td style="padding:0;vertical-align:top;border-left:3px solid #FFCF38;padding-left:20px;">
      <div style="font-size:17px;font-weight:bold;color:#4D1D12;padding-bottom:2px;">Monica Væthe-Larsen</div>
      <div style="font-size:13px;color:#7a5c50;padding-bottom:10px;">Keramiker &amp; daglig leder</div>

      <div style="font-size:13px;color:#4D1D12;padding-bottom:2px;">
        <a href="tel:+4794134601" style="color:#4D1D12;text-decoration:none;">+47 94 13 46 01</a>
        &nbsp;·&nbsp;
        <a href="mailto:post@lissom.no" style="color:#4D1D12;text-decoration:none;">post@lissom.no</a>
      </div>
      <div style="font-size:13px;color:#7a5c50;padding-bottom:10px;">
        Nordre Løkkevei 15, 3120 Nøtterøy
      </div>

      <div style="font-size:13px;padding-bottom:10px;">
        <a href="https://lissom.no" style="color:#4D1D12;font-weight:bold;text-decoration:none;">lissom.no</a>
        &nbsp;·&nbsp;
        <a href="https://instagram.com/lissom_keramikk" style="color:#4D1D12;text-decoration:none;">Instagram</a>
      </div>

      <div style="font-size:12px;color:#8a6a5c;">
        Lissom Keramikk &amp; Håndverk AS · Org.nr. 938 280 819 MVA
      </div>
    </td>
  </tr>
</table>');

-- Paa fra start i alle fire gruppene. Det var det som ble bestilt, og hver
-- av dem kan skrus av for seg under Innstillinger -> E-post og varsler.
INSERT IGNORE INTO innstillinger (nokkel, verdi) VALUES
  ('epost_signatur_system', '1'),
  ('epost_signatur_ordre', '1'),
  ('epost_signatur_kurs', '1'),
  ('epost_signatur_nyhetsbrev', '1');
