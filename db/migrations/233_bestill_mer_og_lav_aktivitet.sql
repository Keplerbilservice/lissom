-- godkjent av eieren: 2026-09-29
--
-- To beskjeder til verkstedet, begge bare i admin — ingenting paa kundesiden
-- og ingenting til medlemmene.
--
-- 1) «Bestill mer». Eieren, 28. september 2026: «Legg inn antall leirer paa
--    lager, saa man faar beskjed om at hun maa bestille mer», og saa: minimum
--    og maksimum per vare. Paa eller under min: beskjed. Beskjeden sier hvor
--    mange som maa bestilles: maks − lager. NULL = ikke satt. Se
--    app/lib/lager.php.
--
-- 2) «Lav aktivitet». Eieren samme dag: «medlemmer som ikke bruker timene
--    sine, vil jeg gjerne faa beskjed om ... lav aktivitet etter 14 dager».
--    Se app/lib/aktivitet.php. Dagene staar i innstillingen
--    lav_aktivitet_dager (standard 14 i koden).
--
-- Begge e-postene er maler med egen bryter under Varsler, som de andre
-- interne.

ALTER TABLE products
    ADD COLUMN IF NOT EXISTS lager_min INT NULL DEFAULT NULL
        COMMENT 'Beskjed til admin naar lager er paa eller under dette. NULL = ingen beskjed.',
    ADD COLUMN IF NOT EXISTS lager_maks INT NULL DEFAULT NULL
        COMMENT 'Hvor mange verkstedet vil ha. Beskjeden sier bestill maks - lager.';

INSERT INTO notification_templates (navn, kanal, emne, tekst, gruppe) VALUES
('intern_bestill_mer', 'epost', '{linje}',
 '{linje}\n\nDet er {antall} igjen på lager. For å fylle opp til maks må du bestille {bestill}.', 'system'),
('intern_lav_aktivitet', 'epost', 'Lav aktivitet: {antall} medlemmer',
 'Disse medlemmene har ikke vært i verkstedet på {dager} dager eller mer:\n\n{medlemmer}\n\nHele lista står under Medlemmer › Lav aktivitet i admin.', 'system')
ON DUPLICATE KEY UPDATE navn = navn;

-- Samme oppsett som de andre interne e-postene (migrasjon 227): overskrift,
-- avsnitt og et faktakort.
UPDATE notification_templates SET
    overskrift = 'Bestill mer',
    avsnitt = '["{linje}"]',
    kort = '[["Vare","{vare}"],["Igjen på lager","{antall}"],["Bestill","{bestill}"]]',
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'intern_bestill_mer' AND overskrift IS NULL;

UPDATE notification_templates SET
    overskrift = 'Lav aktivitet',
    avsnitt = '["Disse medlemmene har ikke vært i verkstedet på {dager} dager eller mer:","{medlemmer}","Hele lista står under Medlemmer › Lav aktivitet i admin."]',
    kort = NULL,
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'intern_lav_aktivitet' AND overskrift IS NULL;
