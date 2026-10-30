-- godkjent av eieren: 2026-10-30
-- Ny tekst i SMS-kvitteringen fra kassa på iPaden (eieren, 30. oktober 2026,
-- «Ny kasse på iPad»): én vare per linje ({varer}, «[navn] [beløp]»), og ikke
-- kundens navn. Sendes bare når «Send kvittering» trykkes i kassa.
--
-- Rører bare malen kassekvittering_sms. Kan kjøres to ganger.

UPDATE notification_templates
   SET tekst = CONCAT('Takk for handelen hos Lissom!', CHAR(10), '{varer}', CHAR(10),
                      'Betalt {belop} med {maate} {dato}.', CHAR(10), 'Hilsen oss i Lissom')
 WHERE navn = 'kassekvittering_sms';
