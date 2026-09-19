-- «Betal ved oppmoete» flyttes til ⊙ Synlighet — og bare dit.
--
-- Eieren, 19. september 2026: «La du disse bryterne et annet sted enn avtalt
-- sted?», «Vi har jo alle som skal vise og skru av og paa paa synlighet»,
-- «Ja, og flytt hakene dit ogsaa» — og paa spoersmaalet om unntak per kurs:
-- «Alt styres kun fra Synlighet».
--
-- Foer dette sto valget tre steder til: en hake i kursredigeringa, en i
-- vareskjemaet og en i planredigeringa. Det er den samme innstillingen fortalt
-- fire ganger, og da er det ingen som vet hvilken som gjelder. Naa er det tre
-- rader i ⊙ Synlighet — kurs, butikk, medlemskap — og ingen andre steder.
--
-- Standarden er den samme som kolonnene hadde: paa for kurs og varer, av for
-- medlemskap. Et medlemskap loeper hver maaned, og det skal vaere et bevisst
-- valg aa la det begynne uten at noe er betalt.

-- Kurs og butikk: paa. Raden legges inn saa panelet viser noe fra foerste
-- stund, men koden taaler at den mangler — da er den paa (!= 'nei').
INSERT INTO content_blocks (nokkel, verdi)
SELECT 'Vis/oppmotekurs', 'ja' FROM (SELECT 1) AS d
 WHERE NOT EXISTS (SELECT 1 FROM content_blocks WHERE nokkel = 'Vis/oppmotekurs');

INSERT INTO content_blocks (nokkel, verdi)
SELECT 'Vis/oppmotebutikk', 'ja' FROM (SELECT 1) AS d
 WHERE NOT EXISTS (SELECT 1 FROM content_blocks WHERE nokkel = 'Vis/oppmotebutikk');

-- Medlemskap: av, eksplisitt. De andre bryterne staar paa naar raden mangler;
-- her ville en manglende rad betydd at medlemskap begynner aa loepe uten at
-- noe er betalt. Derfor krever baade serveren og skjermen 'ja' her, ikke «alt
-- annet enn nei» — samme regel som Vis/autogodkjenn (migrasjon 174).
INSERT INTO content_blocks (nokkel, verdi)
SELECT 'Vis/oppmotemedlemskap', 'nei' FROM (SELECT 1) AS d
 WHERE NOT EXISTS (SELECT 1 FROM content_blocks WHERE nokkel = 'Vis/oppmotemedlemskap');

-- Hakene per kurs, per vare og per plan (migrasjon 197). Sto de igjen, ville
-- to steder sagt hva som gjelder — og bare ett av dem ville blitt lest.
-- Samme grunn som migrasjon 103 droppet «ressurs».
--
-- Raden paa bookinga og paa ordren blir staaende: de sier hva KUNDEN valgte,
-- og det er ikke en innstilling men et faktum kvitteringa og deltakerlista
-- skal kunne vise.
ALTER TABLE courses          DROP COLUMN IF EXISTS uten_forskudd;
ALTER TABLE products         DROP COLUMN IF EXISTS uten_forskudd;
ALTER TABLE membership_plans DROP COLUMN IF EXISTS uten_forskudd;
