-- MIDLERTIDIG: Paint on Pots settes til én krone for å teste betalingsflyten
-- mot ekte Vipps i produksjon.
--
-- Kurslista på nettsiden er fortsatt designdata, så testkurset fra 004 vises
-- ikke. Derfor låner vi et kurs som allerede står i lista.
--
-- Prisen settes tilbake til 690 av migrasjon 006, som kjøres straks testen er
-- verifisert. Testkurset fra 004 avlyses samtidig.
UPDATE courses SET pris_ore = 100 WHERE slug = 'paint-on-pots';
