-- Påmeldingsbekreftelsen uten «Når:» og «Hvor:».
--
-- Eieren, 25. september 2026: «jeg vil heller ikke ha med teksten NÅR: og
-- HVOR: dette har jeg bedt om før så sørg for å fikse det permanent».
--
-- Datoen og adressen står der fortsatt, hver på sin linje — bare ordene
-- foran er borte. Bare i malen til kunden; beskjeden til verkstedet
-- («Ny påmelding») er en liste med Beløp, Betaling og Kontakt, og er som før.

UPDATE notification_templates
   SET tekst = REPLACE(REPLACE(tekst, 'Når: {naar}', '{naar}'), 'Hvor: Nordre Løkkevei', 'Nordre Løkkevei')
 WHERE navn = 'ordrebekreftelse';
