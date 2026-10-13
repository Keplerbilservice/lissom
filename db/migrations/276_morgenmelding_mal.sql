-- Morgenmeldingen til Monica som maler (app/lib/morgenmelding.php).
--
-- Eieren, 13. oktober 2026: SMS hver morgen kl. 07:30 med dagens kurs,
-- paameldte og kalendernotater. Teksten ble vist ordrett og godkjent samme
-- dag. Alle utsendelser skal vaere maler som kan endres og slaas av under
-- Tekst maler (tests/backend.php), derfor to maler: én for en dag med noe
-- i, og én for en dag uten noe. {dagen} er lista over kurs og notater.
-- godkjent av eieren: 2026-10-13
--
-- Roerer ingen andre maler.

INSERT INTO notification_templates (navn, kanal, emne, tekst, aktiv, gruppe) VALUES
('intern_morgenmelding', 'sms', NULL,
 'God morgen, Monica! ☀️\nHer er dagen din:\n\n{dagen}\n\nHa en nydelig dag i verkstedet! 🤍', 1, 'system'),
('intern_morgenmelding_tom', 'sms', NULL,
 'God morgen, Monica! ☀️\nIngen kurs og ingenting i kalenderen i dag. Nyt dagen! 🤍', 1, 'system')
ON DUPLICATE KEY UPDATE navn = navn;
