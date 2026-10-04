-- godkjent av eieren: 2026-10-04
--
-- Paint on Pots, slik eieren beskrev den 4. oktober 2026:
-- «hver onsdag og torsdag 17-20 er jeg der, drop in eller bestill time, ikke
-- ta hensyn til plasser», og «la meg velge dager og til og fra i admin».
--
-- Fire ting trengs for det.

-- ── 1. Ukeplanen ────────────────────────────────────────────────────────
--
-- Hvilke ukedager kurset er aapent, og mellom hvilke klokkeslett. Staar i en
-- tabell og ikke i koden, av samme grunn som ressursene i migrasjon 103:
-- eieren skal kunne endre dagene uten at nettsida legges ut paa nytt.
--
-- Har kurset ingen rader her, gjelder verkstedets aapningstider som foer.
CREATE TABLE IF NOT EXISTS kurs_ukeplan (
  id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id BIGINT UNSIGNED NOT NULL,
  ukedag    TINYINT NOT NULL COMMENT '1 = mandag … 7 = soendag (ISO-8601)',
  fra       TIME NOT NULL,
  til       TIME NOT NULL,
  UNIQUE KEY kurs_dag (course_id, ukedag),
  CONSTRAINT fk_ukeplan_kurs FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 2. Hvor lenge en plass varer, og om den har en grense ───────────────
--
-- Lengden sto som Apent::PLASS_MINUTTER = 120, én konstant for alle kurs.
-- Det var grunnen til at et vindu paa 17-19 bare ga ett mulig oppmoete:
-- to timer i et to timers vindu. NULL = bruk konstanten som foer.
--
-- «uten_plassgrense» tar kurset ut av ressursregnskapet. Det kan da verken
-- bli fullt eller sperre noe annet — eieren: «ikke ta hensyn til plasser».
ALTER TABLE courses
  ADD COLUMN IF NOT EXISTS plass_minutter INT NULL
      COMMENT 'Hvor lenge en plass varer. NULL = Apent::PLASS_MINUTTER.',
  ADD COLUMN IF NOT EXISTS uten_plassgrense TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = ingen plassgrense, og ingen plass i ressursregnskapet.';

-- ── 3. «Aapne for Paint on Pots» paa en planlagt oekt ───────────────────
--
-- Eieren: «naar jeg har planlagte kurs, kan jeg faa knapp, vis ogsaa paint
-- on pots tilgjengelig» — en knapp paa kurskortet i kalenderen som aapner
-- Paint on Pots i noeyaktig det tidsrommet kurset gaar, paa en dag som ikke
-- staar i ukeplanen.
--
-- Tallet er Paint on Pots sine egne plasser den kvelden, paa toppen av
-- kursets egne. NULL = av.
ALTER TABLE course_sessions
  ADD COLUMN IF NOT EXISTS apen_plass_antall INT NULL
      COMMENT 'Aapent for det aapne kurset i denne oektas tidsrom, med N plasser. NULL = av.';

-- ── 4. Meldinga kunden skriver ──────────────────────────────────────────
--
-- Eieren: «de kan jo skrive antall personer og litt tekst». Antallet staar
-- fra foer i bookings.antall. Teksten hadde ingen plass: bookings.notat er
-- systemets eget felt (migrasjon 055), ikke kundens.
ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS gjest_melding VARCHAR(255) NULL
      COMMENT 'Fritekst fra kunden ved bestilling. «Noe vi boer vite?»';

-- ── Utgangspunktet for Paint on Pots ────────────────────────────────────
--
-- Eieren sa hvilke dager han er der. Han kan endre dem i admin med det
-- samme; dette er bare raden som gjoer at skjermen ikke staar tom.
UPDATE courses SET uten_plassgrense = 1 WHERE tittel = 'Paint on Pots';

INSERT INTO kurs_ukeplan (course_id, ukedag, fra, til)
SELECT c.id, d.ukedag, '17:00:00', '20:00:00'
  FROM courses c
  JOIN (SELECT 3 AS ukedag UNION ALL SELECT 4) d
 WHERE c.tittel = 'Paint on Pots'
ON DUPLICATE KEY UPDATE course_id = kurs_ukeplan.course_id;
