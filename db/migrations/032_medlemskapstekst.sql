-- Teksten paa medlemskapskortene.
--
-- Prisen og timetallet laa i basen, men alt kunden faktisk leser — merket,
-- linja under prisen, beskrivelsen og punktlista — sto som fast tekst i
-- lissom-2108.html. Verkstedet kunne endre prisen paa «30 timer», men ikke
-- ett ord om hva den inneholder. Klikket de «Rediger», fikk de fire tall og
-- ingen setning.
--
-- Prov Lissom sto med «for 30 dager». Det er riktig, men det viktige er de
-- ti timene og at de maa brukes i lopet av maaneden. Den linja er nettopp
-- en som maa kunne skrives om uten en migrasjon.

ALTER TABLE membership_plans
  ADD COLUMN IF NOT EXISTS merke       VARCHAR(40)  NULL COMMENT 'Merkelappen oeverst paa kortet'      AFTER navn,
  ADD COLUMN IF NOT EXISTS undertekst  VARCHAR(120) NULL COMMENT 'Linja under prisen'                  AFTER merke,
  ADD COLUMN IF NOT EXISTS beskrivelse VARCHAR(400) NULL                                              AFTER undertekst,
  ADD COLUMN IF NOT EXISTS punkter     TEXT         NULL COMMENT 'Ett punkt per linje'                AFTER beskrivelse,
  ADD COLUMN IF NOT EXISTS passer_for  VARCHAR(200) NULL COMMENT '«Passer for deg som …»'             AFTER punkter,
  ADD COLUMN IF NOT EXISTS bilde       VARCHAR(200) NULL                                              AFTER passer_for,
  ADD COLUMN IF NOT EXISTS fremhevet   TINYINT(1)   NOT NULL DEFAULT 0                                AFTER bilde;

-- Teksten som sto i designfila flyttes inn. Bare der ingen har skrevet noe
-- selv — kjores dette to ganger, skal ikke verkstedets egne ord forsvinne.
UPDATE membership_plans SET
  merke       = COALESCE(merke, 'Prøveperiode'),
  undertekst  = COALESCE(undertekst, '10 timer, brukes i løpet av 30 dager'),
  beskrivelse = COALESCE(beskrivelse, 'En måned med verkstedtilgang, så du finner ut om dette er noe for deg.'),
  punkter     = COALESCE(punkter, '10 verkstedtimer\nLeire og brenning kjøpes i tillegg\nKan kun benyttes én gang\nGår ikke automatisk over til abonnement'),
  passer_for  = COALESCE(passer_for, 'deg som er nysgjerrig etter et kurs'),
  bilde       = COALESCE(bilde, 'uploads_shutterstock_2829104351.jpg')
 WHERE navn = 'Prøv Lissom';

UPDATE membership_plans SET
  merke       = COALESCE(merke, 'Mest valgt'),
  undertekst  = COALESCE(undertekst, '30 timer i måneden'),
  beskrivelse = COALESCE(beskrivelse, 'Tretti timer i måneden, brukt når det passer deg. Fornyes automatisk.'),
  punkter     = COALESCE(punkter, '30 verkstedtimer i måneden\nEgen hylle i verkstedet\nTilgang 24/7 med dørkode\nTa med en venn én gang i måneden'),
  passer_for  = COALESCE(passer_for, 'deg som vil ha fast plass i uka'),
  bilde       = COALESCE(bilde, 'uploads_shutterstock_2829103797.jpg'),
  fremhevet   = 1
 WHERE navn = '30 timer';

UPDATE membership_plans SET
  merke       = COALESCE(merke, 'Mest for pengene'),
  undertekst  = COALESCE(undertekst, '35 timer i måneden · årsavtale'),
  beskrivelse = COALESCE(beskrivelse, 'Få mer, betal mindre: 35 timer i måneden til lavere pris, med årsavtale.'),
  punkter     = COALESCE(punkter, '35 verkstedtimer i måneden\nEgen hylle i verkstedet\nTilgang 24/7 med dørkode\nBinding i 12 måneder'),
  passer_for  = COALESCE(passer_for, 'deg som bruker verkstedet fast hele året'),
  bilde       = COALESCE(bilde, 'uploads_shutterstock_2830613711.jpg')
 WHERE navn = 'Årsmedlemskap';

UPDATE membership_plans SET
  merke       = COALESCE(merke, 'Proff'),
  undertekst  = COALESCE(undertekst, 'Ingen timebegrensning'),
  beskrivelse = COALESCE(beskrivelse, 'Ubegrenset verkstedtid for deg som jobber med keramikk fast.'),
  punkter     = COALESCE(punkter, 'Ingen timebegrensning\nEgen hylle og lagerplass\nTilgang 24/7 med dørkode\nRabatt på leire og brenning'),
  passer_for  = COALESCE(passer_for, 'deg som blir bitt av basillen'),
  bilde       = COALESCE(bilde, 'uploads_shutterstock_2829104157.jpg')
 WHERE navn = 'Fri tilgang';
