-- godkjent av eieren: 2026-11-06
-- Ventelisten sender SMS i tillegg til e-posten (eieren 06.11.2026: «de må få
-- SMS, ikke nå, men fremover»). Tekstene er godkjent ordrett, uten æøå, så
-- hver blir én SMS. {dato} fylles inn med mellomrom foran (« 7.11», punktumet står i malen), og er
-- tom når ventelisten gjelder hele kurset.
--
-- Bare nye maler; e-postmalene røres ikke. Kan kjøres to ganger.
INSERT INTO notification_templates (navn, kanal, emne, tekst, gruppe) VALUES
('venteliste_ledig_sms', 'sms', NULL,
 'Hei {fornavn}, det ble ledig plass pa {kurs}{dato}. Forste som booker far plassen: lissom.no/kurs Hilsen Lissom', 'kurs'),
('venteliste_tildelt_sms', 'sms', NULL,
 'Hei {fornavn}, du har fatt plass pa {kurs}{dato}. Se plassen pa Min side: lissom.no/min-side Hilsen Lissom', 'kurs')
ON DUPLICATE KEY UPDATE navn = navn;
