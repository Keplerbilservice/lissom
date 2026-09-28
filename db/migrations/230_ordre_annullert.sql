-- godkjent av eieren: 2026-09-28
--
-- E-posten kunden faar naar en ubetalt henteordre annulleres i admin
-- («Annuller» under Kasse › Ikke betalt). Eieren, 28. september 2026: «lag
-- Annuller for ubetalte henteordrer, som legger varen tilbake paa lager og
-- sender kunden e-post».
--
-- Malen kan endres og slaas av under Markedsfoering › Tekst maler, som de
-- andre. Oppsettet (overskrift, avsnitt, kort) er det samme som malene fra
-- migrasjon 227 bruker.
INSERT INTO notification_templates (navn, kanal, emne, tekst, gruppe) VALUES
('ordre_annullert', 'epost', 'Bestillingen din er annullert',
 'Hei {navn}.\n\nVi har annullert bestillingen din ({ordre}), og varene er ikke lenger lagt til side. Du har ikke betalt noe, så det er ingenting å betale tilbake.\n\n{varelinjer}\nSum: {sum}\n\nEr dette feil, eller vil du bestille på nytt, er du velkommen til å ta kontakt.\n\nHilsen Lissom Keramikk', 'ordre')
ON DUPLICATE KEY UPDATE navn = navn;

UPDATE notification_templates SET
    overskrift = 'Bestillingen er annullert',
    avsnitt = '["Hei {navn}.","Vi har annullert bestillingen din, og varene er ikke lenger lagt til side. Du har ikke betalt noe, så det er ingenting å betale tilbake.","Er dette feil, eller vil du bestille på nytt, er du velkommen til å ta kontakt.","Hilsen Lissom Keramikk"]',
    kort = '[["Bestilling","{ordre}"],["Varer","{varelinjer}"],["Sum","{sum}"]]',
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'ordre_annullert' AND overskrift IS NULL;
