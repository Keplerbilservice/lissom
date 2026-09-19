-- Bestille uten aa betale i forkant.
--
-- Eieren, 19. september 2026: «det maa gaa an aa bestille uten aa betale med
-- vipps, samme vilkaar, men at de betaler ved oppmoete, kontant eller vipps»
-- — foerst om kurs, saa: «da vil jeg ogsaa ha det paa butikk og medlemskap,
-- men paa medlemskap skal default vaere av, paa de andre paa».
--
-- Ingen nye begreper: en booking som er «reservert» uten «reservert_til» er
-- alt en plass som holdes til noen gjor noe med den — det er den raden
-- verkstedet lager naar det legger inn noen for haand. Og «betalt_maate»
-- med Kontant eller Vipps finnes fra migrasjon 084. Det som manglet var en
-- vei dit fra nettsida.
--
-- Standard: paa for kurs og varer, av for medlemskap. Et medlemskap loeper
-- hver maaned, og det skal vaere et bevisst valg aa la det begynne uten at
-- noe er betalt.

ALTER TABLE courses
  ADD COLUMN IF NOT EXISTS uten_forskudd TINYINT(1) NOT NULL DEFAULT 1
  COMMENT 'Kan bookes uten aa betale i forkant';

ALTER TABLE products
  ADD COLUMN IF NOT EXISTS uten_forskudd TINYINT(1) NOT NULL DEFAULT 1
  COMMENT 'Kan bestilles uten aa betale i forkant';

ALTER TABLE membership_plans
  ADD COLUMN IF NOT EXISTS uten_forskudd TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Kan tegnes uten aa betale i forkant';

-- Hvorfor et merke paa raden, naar «reservert uten frist» sier det samme:
-- fordi de to ikke betyr det samme for et menneske. En reservasjon lagt inn
-- for haand er verkstedets egen; denne er kundens valg, og kunden har faatt
-- en kvittering som sier hva som skal betales naar hen kommer. Deltakerlista
-- skal kunne si hvilken av delene det er.
ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS uten_forskudd TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Kunden valgte aa betale ved oppmoete';

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS uten_forskudd TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Kunden valgte aa betale ved henting';

-- Kvitteringa maa kunne si hva som skal betales naar man kommer.
--
-- «{betaling}» er tom for den som har betalt, saa linja endrer ingenting for
-- dem. Bare den teksten som fortsatt staar urort rettes — har eieren skrevet
-- sin egen mal, skal den ikke overskrives. Samme regel som migrasjon 023.
--
-- CONCAT, ikke en ombytting av hele teksten: har eieren skrevet sin egen mal,
-- skal den staa — men linja om penger maa med der ogsaa. Uten den ville den
-- som betaler ved oppmoete faatt en kvittering som ikke nevner at noe
-- gjenstaar. «NOT LIKE» gjor det trygt aa kjore om igjen.
UPDATE notification_templates
   SET tekst = CONCAT(tekst, ' {betaling}')
 WHERE navn = 'ordrebekreftelse'
   AND tekst NOT LIKE '%{betaling}%';

-- Det samme for butikkvitteringa. «{betaling}» er tom for den som har
-- betalt; bare den urorte teksten rettes.
UPDATE notification_templates
   SET tekst = CONCAT(tekst, ' {betaling}')
 WHERE navn = 'butikkordre'
   AND tekst NOT LIKE '%{betaling}%';

-- Innmeldinga paa nettsida gaar om «medlemsordrer», og kolonnen tok bare to
-- verdier. Uten denne ville «verksted» blitt avvist av basen — og innmeldinga
-- stoppet et sted kunden ikke kunne se hvorfor.
ALTER TABLE medlemsordrer
  MODIFY COLUMN betaling ENUM('trekk','engang','verksted') NOT NULL DEFAULT 'trekk';
