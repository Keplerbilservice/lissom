-- Notater rett i kalenderen.
--
-- Eieren, 11. september 2026: «kalender, kan jeg dra aa legge til notater,
-- ikke bare kurs? rett i kalender». Spurt hvordan, valgte han «Trykk paa
-- dagen»; spurt hvem som skal se dem, «Bare admin»; spurt om klokkeslett,
-- «Med klokkeslett».
--
-- Det fantes notater for, i «verksted_notater». De var noe annet: ETT notat
-- per person, uten dato, i en egen rute ved siden av kalenderen. Den ruta ba
-- han selv om aa fjerne 8. september. Dette er en hendelse paa en dag, og
-- hoerer derfor hjemme i sin egen tabell — ikke som en ny kolonne paa den
-- gamle.
--
-- Ingen kobling til kurs, kursholder eller medlem. Et notat er verkstedets
-- egen beskjed til seg selv: «bestille leire», «ovnen til service».
CREATE TABLE IF NOT EXISTS kalender_notater (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dato       DATE NOT NULL,
  -- Lokal tid, slik den staar i kalenderen. Oektene lagres i UTC fordi de
  -- sendes ut i kalenderfila og i e-post; et notat gaar ingen steder, og en
  -- «08:30» som blir «07:30» etter sommertida ville vaert en feil ingen
  -- forstaar.
  fra        TIME NOT NULL,
  til        TIME NULL COMMENT 'Tom = vi setter en time i skjermen',
  tekst      VARCHAR(500) NOT NULL,
  -- Hvem som skrev det. Notatene er felles for dem som er inne i admin —
  -- eieren valgte «Bare admin», ikke «bare mine» — men det skal gaa an aa se
  -- hvem som la det inn.
  skrevet_av BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_notat_dato (dato, fra),
  CONSTRAINT fk_notat_skrevet_av FOREIGN KEY (skrevet_av)
    REFERENCES members (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Notater paa en dato i adminkalenderen. Bare admin ser dem.';
