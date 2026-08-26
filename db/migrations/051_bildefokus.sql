-- Hvilken del av bildet som skal vises.
--
-- Rammene på nettsiden har faste former: kurskortene er 16:10, butikken 1:1.
-- Et bilde som ikke har den formen blir beskåret, og uten noe å styre etter
-- klipper nettleseren fra midten. Velger du et stående portrett, får du en
-- hals — motivet er der, men ikke i ramma.
--
-- Fokuspunktet er ikke en beskjæring: originalen ligger urørt, og punktet
-- sier bare hvor i bildet ramma skal sentreres. Da kan valget gjøres om
-- igjen når som helst, og det samme bildet kan stå riktig i to ulike rammer
-- den dagen vi trenger det.
--
-- Nøkkelen er filnavnet. Ett bilde, ett punkt — det er nok så lenge samme
-- bilde ikke brukes i to rammer med ulik form.
--
-- Verdien er to prosenttall, som i CSS: «50% 50%» er midten, «50% 20%»
-- flytter utsnittet opp mot toppen av bildet.

CREATE TABLE IF NOT EXISTS bilde_fokus (
    fil        VARCHAR(191) NOT NULL PRIMARY KEY,
    fokus      VARCHAR(24)  NOT NULL DEFAULT '50% 50%',
    endret_av  INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
