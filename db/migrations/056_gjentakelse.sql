-- Gjentakelse i steg 2 gjor endelig noe.
--
-- «Ukentlig / Manedlig / Egendefinert» og «Antall ganger» sto i veiviseren
-- og lot seg trykke paa, men ble aldri sendt til serveren. Den som satte
-- «Ukentlig · 10 ganger» og trodde hosten var planlagt, fikk en tom
-- kalender. Fritekstfeltet «Egendefinert» kunne uansett ikke legge ut
-- datoer — en maskin kan ikke lese «forste lordag i maneden».
--
-- Reglene ligger fra for i kurs_serier, som legger ut oktene framover og
-- fylles paa av cron. Den kan i dag én ting: samme ukedag hver uke. Vi
-- utvider den framfor aa lage et system nummer to ved siden av — da ville to
-- steder laget datoer paa hvert sitt kurs, og for eller siden sagt hver sitt.
--
-- Fire nye kolonner, og hver av dem svarer paa noe tabellen ikke kan i dag:
--
--   monster        hvilken av de tre gjentakelsene. Radene som ligger der
--                  fra for far 'ukentlig', som er nettopp det de gjor i dag.
--   dag_i_maaned   den 6. i maneden. ukedag kan ikke uttrykke en dato.
--   antall         «10 ganger», og saa stopper den. uker_fram er et vindu,
--                  ikke et antall — den sier hvor langt fram datoene skal
--                  ligge ute, ikke hvor mange det skal bli til slutt.
--   start_dato     hvilken uke som er «denne» uka naar noe gaar annenhver.
--                  Uten et fast holdepunkt ville svaret endret seg med
--                  dagen cron kjorer.

ALTER TABLE kurs_serier
  ADD COLUMN IF NOT EXISTS monster ENUM('ukentlig','annenhver','manedlig') NOT NULL DEFAULT 'ukentlig' AFTER course_id,
  ADD COLUMN IF NOT EXISTS dag_i_maaned TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER ukedag,
  ADD COLUMN IF NOT EXISTS antall SMALLINT UNSIGNED NULL AFTER uker_fram,
  ADD COLUMN IF NOT EXISTS start_dato DATE NULL AFTER antall;

-- Den gamle noekkelen var (kurs, ukedag, fra). En manedlig regel har ingen
-- ukedag — den star med 0 — og to ulike datoer i samme maned ville kollidert
-- paa den. Monster og dag i maneden ma vaere med.
--
-- Fremmednoekkelen til courses lener seg paa uq_serie, fordi course_id er
-- forste kolonne i den og det ikke finnes noen annen indeks aa bruke. Uten
-- en egen indeks forst nekter basen aa fjerne den gamle noekkelen.
ALTER TABLE kurs_serier ADD KEY IF NOT EXISTS ix_serie_kurs (course_id);
ALTER TABLE kurs_serier DROP INDEX IF EXISTS uq_serie;
ALTER TABLE kurs_serier
  ADD UNIQUE KEY IF NOT EXISTS uq_serie (course_id, monster, ukedag, dag_i_maaned, fra);

-- Hvilken regel som la ut datoen.
--
-- «10 ganger» kan ikke telles uten dette. Cron kjorer om og om igjen, og maa
-- vite hvor mange denne regelen alt har laget — ikke hvor mange datoer
-- kurset har, for de kan vaere lagt inn for haand ogsaa.
--
-- ON DELETE SET NULL: fjernes regelen, blir datoene staaende. Folk kan ha
-- booket dem. Det er slik det virker fra for, og skal fortsette aa gjore det.
ALTER TABLE course_sessions
  ADD COLUMN IF NOT EXISTS serie_id BIGINT UNSIGNED NULL AFTER course_id,
  ADD KEY IF NOT EXISTS idx_okt_serie (serie_id);

-- Fremmednoekkelen legges paa for seg. Er den der fra for, feiler dette
-- alene og resten av migrasjonen staar.
ALTER TABLE course_sessions
  ADD CONSTRAINT fk_okt_serie FOREIGN KEY (serie_id) REFERENCES kurs_serier (id) ON DELETE SET NULL;
