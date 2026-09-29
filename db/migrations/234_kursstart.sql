-- Kursstart: det du sier til deltakerne før dere begynner.
--
-- Eieren, 29. september 2026: «Jeg skulle hatt en slags onboarding til admin,
-- ved oppstart av kurs, i enkel karusellform som er mobilvennlig» — praktisk
-- info, betaling, hva vi skal gjøre i dag, når arbeidene er ferdig glasert, og
-- e-posten med kursbevis etterpå.
--
-- Fem kort. Teksten står her og ikke i koden, fordi eieren skal kunne endre
-- den selv (Markedsføring → Tekst maler → Kursstart). Kort 2 har ingen tekst
-- å lagre — der er innholdet betalingslista for akkurat den økta, hentet fra
-- påmeldingene idet kortet åpnes.
--
-- «paa» per kort: kort 5 er det eieren kalte «eventuelt». Alle står på fra
-- start; slår man ett av, hoppes det over i karusellen.

INSERT INTO innstillinger (nokkel, verdi) VALUES
  ('kursstart_1_tittel', 'Velkommen — det praktiske først'),
  ('kursstart_1_tekst',  'Toalettet er gjennom døra til venstre.\nRømningsveien er den samme veien du kom inn, og bakdøra ved ovnsrommet.\nKaffe, te og litt å bite i står framme — forsyn dere når som helst.'),
  ('kursstart_1_paa',    '1'),

  ('kursstart_2_tittel', 'Betaling'),
  ('kursstart_2_tekst',  'Er det noen som ikke har gjort opp, tar vi det nå — så slipper vi å tenke på det resten av kvelden.'),
  ('kursstart_2_paa',    '1'),

  ('kursstart_3_tittel', 'Dette skal vi gjøre i dag'),
  ('kursstart_3_tekst',  'Vi begynner med en kort gjennomgang, så setter dere i gang selv.\nDet blir leire på hendene og sannsynligvis på klærne — forkle får dere låne av oss.\nDere trenger ikke å få det til med én gang. Det er helt greit å begynne på nytt.'),
  ('kursstart_3_paa',    '1'),

  ('kursstart_4_tittel', 'Når får dere med dere arbeidene hjem?'),
  ('kursstart_4_tekst',  'Arbeidene skal tørke, brennes, glaseres og brennes en gang til. Det tar noen uker.\nDere får beskjed på Min side og på e-post når de er ferdige, og da kan dere hente dem hos oss.'),
  ('kursstart_4_paa',    '1'),

  ('kursstart_5_tittel', 'Etter kurset'),
  ('kursstart_5_tekst',  'Dere får en e-post med litt info i etterkant, og kursbeviset deres ligger på Min side.'),
  ('kursstart_5_paa',    '1')
ON DUPLICATE KEY UPDATE nokkel = nokkel;
