-- Morgenmeldingen til kursholderne (app/lib/morgenmelding.php).
--
-- Eieren, 2. november 2026 («ja, sms er godkjent» paa visningen
-- ELZJvxhMw3AnGcuhmfB7mW): den som holder kurs i dag (ikke Monica) faar en
-- SMS samme morgen med bare sine kurs. Teksten er godkjent ordrett. {navn} er
-- fornavnet til kursholderen, {dagen} er kurslinjene. Malen kan endres og
-- slaas av under Tekst maler.
-- godkjent av eieren: 2026-11-02
--
-- Legger bare til én mal. Kjores den to ganger, skjer ingenting andre gang.

INSERT INTO notification_templates (navn, kanal, emne, tekst, aktiv, gruppe) VALUES
('intern_morgenmelding_kursholder', 'sms', NULL,
 'God morgen, {navn}! ☀️\nDu holder kurs i dag:\n\n{dagen}\n\nLykke til, og ha en fin dag i verkstedet! 🤍', 1, 'system')
ON DUPLICATE KEY UPDATE navn = navn;
