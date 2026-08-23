-- Nitten nye varer i butikken, fotografert i verkstedet i august.
--
-- Varer opprettes normalt fra Admin -> Butikk. De ligger her fordi de kom i
-- én omgang, med kategori og antall satt riktig fra start — skjemaet i admin
-- setter verken kategori eller bilde paa en ny vare.
--
-- Flere av dem kom inn med samme navn: to «Kaffekopp», fire «Kopp», to
-- «Kopp uten hank». Tittelen er unik i basen, saa de kan ikke hete det
-- samme — den andre ville overskrevet den forste, og bare én blitt liggende.
-- De har derfor faatt navn etter hvordan de ser ut. Navnet kan endres fra
-- admin; bare ikke slik at to blir like.
--
-- Bildene maa settes fra Admin -> Butikk. De ligger paa telefonen, ikke i
-- koden, og uten bilde bruker butikken standardbildet.
--
-- ON DUPLICATE KEY UPDATE fanger opp at en av dem alt finnes med samme navn.
-- Merk at 027 fjerner den noekkelen igjen — et verksted maa kunne ha ti
-- kopper som alle heter «Kopp». Denne kjores én gang, for 027, saa det gaar
-- opp; men den skal ikke kjores paa nytt etterpaa.

INSERT INTO products (tittel, beskrivelse, kategori, pris_ore, mva_prosent, lager, kun_medlemmer, status, created_at)
VALUES
  ('Kaffekopp, grønn',
   'Dreid kopp med hank, i olivengrønn glasur med mørke prikker og ubehandlet bunn i mørk leire. Håndlaget i verkstedet på Teie.',
   'Kopper', 38000, 25, 1, 0, 'publisert', UTC_TIMESTAMP()),

  ('Kaffekopp, blå',
   'Dreid kopp med hank, i lys blå glasur som samler seg mørkere mot bunnen. Håndlaget i verkstedet på Teie.',
   'Kopper', 38000, 25, 1, 0, 'publisert', UTC_TIMESTAMP()),

  ('Kaffekopp med blå strek',
   'Dreid kopp med hank, i lys grå glasur med en blå strek malt fritt rundt koppen og bort på hanken. Håndlaget i verkstedet på Teie.',
   'Kopper', 35000, 25, 5, 0, 'publisert', UTC_TIMESTAMP()),

  ('Kaffekopp, brun',
   'Dreid kopp med hank, i varm brun glasur der dreieringene står tydelig fram. Håndlaget i verkstedet på Teie.',
   'Kopper', 35000, 25, 5, 0, 'publisert', UTC_TIMESTAMP()),

  ('Kopp med skål i terrakotta',
   'Kopp med egen skål under, i hvit engobe mot rød terrakotta, med et blad risset inn i siden. Håndlaget i verkstedet på Teie.',
   'Kopper', 45000, 25, 2, 0, 'publisert', UTC_TIMESTAMP()),

  ('Tekopp med skål',
   'Tekopp med hank og egen skål, i lys glasur med prikker og grønne kløverblad malt på både kopp og skål. Håndlaget i verkstedet på Teie.',
   'Kopper', 75000, 25, 4, 0, 'publisert', UTC_TIMESTAMP()),

  ('Kopp uten hank, blågrønn',
   'Dreid kopp uten hank, i blågrønn glasur med lyse linjer lagt på i slynger. Håndlaget i verkstedet på Teie.',
   'Kopper', 32000, 25, 4, 0, 'publisert', UTC_TIMESTAMP()),

  ('Sommerfuglkopp uten hank',
   'Dreid kopp uten hank, i lys sandglasur med sommerfugler risset inn i siden. Håndlaget i verkstedet på Teie.',
   'Kopper', 30000, 25, 13, 0, 'publisert', UTC_TIMESTAMP()),

  ('Sommerfuglkopp med hank',
   'Dreid kopp med hank, i lys sandglasur med sommerfugler risset inn i siden. Håndlaget i verkstedet på Teie.',
   'Kopper', 40000, 25, 3, 0, 'publisert', UTC_TIMESTAMP()),

  ('Prikkekopp, jevne prikker',
   'Hvit kopp med hank, dekket av jevnt store svarte prikker. Håndlaget i verkstedet på Teie.',
   'Kopper', 45000, 25, 1, 0, 'publisert', UTC_TIMESTAMP()),

  ('Prikkekopp, store og små prikker',
   'Hvit kopp med hank, med svarte prikker i alle størrelser om hverandre. Håndlaget i verkstedet på Teie.',
   'Kopper', 45000, 25, 1, 0, 'publisert', UTC_TIMESTAMP()),

  ('Prikkekopp, små prikker',
   'Hvit kopp med hank, oversådd med små svarte prikker. Håndlaget i verkstedet på Teie.',
   'Kopper', 45000, 25, 1, 0, 'publisert', UTC_TIMESTAMP()),

  ('Prikkekopp, store prikker',
   'Hvit kopp med hank, med store svarte prikker med god plass mellom. Håndlaget i verkstedet på Teie.',
   'Kopper', 45000, 25, 1, 0, 'publisert', UTC_TIMESTAMP()),

  ('Rosa kopp med blomst',
   'Dreid kopp med hank, i rosa glasur med en stor blomst som står igjen i leirens egen farge. Håndlaget i verkstedet på Teie.',
   'Kopper', 45000, 25, 2, 0, 'publisert', UTC_TIMESTAMP()),

  ('Pastatallerken beige',
   'Dyp tallerken med bred kant, i rolig beige glasur. Håndlaget i verkstedet på Teie.',
   'Tallerkener', 75000, 25, 1, 0, 'publisert', UTC_TIMESTAMP()),

  ('Pastatallerken rosa',
   'Dyp tallerken med bred kant, i rosa glasur som flyter i lysere og mørkere felt. Håndlaget i verkstedet på Teie.',
   'Tallerkener', 75000, 25, 1, 0, 'publisert', UTC_TIMESTAMP()),

  ('Pastatallerken beige med blomster',
   'Dyp tallerken med blomster formet i relieff hele veien rundt kanten, i beige glasur. Håndlaget i verkstedet på Teie.',
   'Tallerkener', 75000, 25, 1, 0, 'publisert', UTC_TIMESTAMP()),

  ('Liten vase',
   'Rund liten vase med smal åpning, i mørk glasur med skiftninger i bronse og grønt. Passer til noen få stilker. Håndlaget i verkstedet på Teie.',
   'Vaser', 55000, 25, 1, 0, 'publisert', UTC_TIMESTAMP()),

  ('Vase',
   'Vase i lys sandfarget glasur, med bølget kant og et bånd av blomster formet i relieff øverst. Håndlaget i verkstedet på Teie.',
   'Vaser', 80000, 25, 1, 0, 'publisert', UTC_TIMESTAMP())

ON DUPLICATE KEY UPDATE
  pris_ore    = VALUES(pris_ore),
  lager       = VALUES(lager),
  kategori    = VALUES(kategori),
  beskrivelse = VALUES(beskrivelse),
  status      = VALUES(status);
