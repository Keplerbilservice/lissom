-- godkjent av eieren: 2026-10-14
-- Driftvakten varsler Joakim gjennom de vanlige, redigerbare e-postmalene.

INSERT INTO notification_templates (navn, kanal, emne, tekst, gruppe) VALUES
('intern_driftavvik', 'epost', 'Lissom: driftsavvik',
 'Driftsvakta fant {antall} avvik:\n\n{avvik}\n\nKontrollen er lesende og har ikke endret noe.', 'system'),
('intern_drift_normal', 'epost', 'Lissom: driften er normal igjen',
 'Driftsvakta finner ikke lenger avvik. Ingen data er endret av kontrollen.', 'system')
ON DUPLICATE KEY UPDATE navn = navn;
