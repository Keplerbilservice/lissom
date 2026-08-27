-- «Klar til henting».
--
-- Bestilt 26. august: når et kurs er gjennomført, skal verkstedet kunne
-- trykke én knapp som (1) sender e-post til deltakerne på den datoen, og
-- (2) legger ut en diskret melding på lissom.no/ferdigbrent om at
-- gjenstandene fra kurset er klare og oppbevares i tre uker.
--
-- Varselmalen «ferdig_brent» har ligget i basen siden migrasjon 002, men
-- ingenting har noen gang sendt den — det fantes ingen knapp. Nå gjør det.
--
-- Ingen ny tabell: meldingen hører til én kursdato, og da hører den hjemme
-- på økta. To kolonner er nok, og de svarer på de to spørsmålene som
-- faktisk stilles — er det meldt fra, og skal det fortsatt stå ute?

ALTER TABLE course_sessions
    ADD COLUMN IF NOT EXISTS hentemelding_at DATETIME NULL DEFAULT NULL
        COMMENT 'Når verkstedet meldte at arbeidene er ferdige',
    ADD COLUMN IF NOT EXISTS hentemelding_av INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Hvem som trykket';

-- Den offentlige sida leser på denne. Uten indeks må hele tabellen leses
-- for hvert besøk, og den vokser med hver eneste kursdato.
CREATE INDEX IF NOT EXISTS idx_hentemelding ON course_sessions (hentemelding_at);

-- Malen sier «klar til henting» og at vi holder av den i to uker. Bestilt er
-- tre uker, og da skal teksten si tre.
UPDATE notification_templates
   SET tekst = 'Hei {navn}! Keramikken din fra {kurs} er ferdig og klar til henting. Vi oppbevarer den hos oss i tre uker.'
 WHERE navn = 'ferdig_brent';
