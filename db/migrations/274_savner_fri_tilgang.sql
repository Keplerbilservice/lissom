-- godkjent av eieren: 2026-10-12
--
-- «Vi savner deg»-SMS til medlemmer med fri tilgang (uten timetak), eieren
-- 12. oktober 2026. Teksten ordrett. Samme regler og samme bryter som
-- medlem_savner_deg_sms (migrasjon 273): Oppfolging::sendSavner() sender
-- bare når den malen er på (AV fra start). Denne malen står på, så den ene
-- bryteren styrer begge.
--
-- Bare tillegg; tåler å kjøres to ganger. Rører ingen andre maler.

INSERT INTO notification_templates (navn, kanal, emne, tekst, aktiv, gruppe) VALUES
('medlem_savner_deg_fri_sms', 'sms', NULL,
 'Hei {fornavn}! Vi savner deg i verkstedet 🙂 Velkommen innom når det passer. Hilsen Monica, Lissom', 1, 'system')
ON DUPLICATE KEY UPDATE navn = navn;
