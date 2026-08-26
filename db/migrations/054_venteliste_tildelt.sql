-- «Tildel kurs» fra ventelista.
--
-- Bestilt 26. august: staar noen paa venteliste og sier at hun heller vil paa
-- et annet planlagt kurs, skal verkstedet kunne sette henne rett inn der.
--
-- Selve tildelingen fantes fra for — men den sendte malen «venteliste_ledig»,
-- som sier «forst til molla — book her». Det er riktig naar du varsler om at
-- noe er blitt ledig, og feil naar plassen alt er gitt: hun har stolen, og
-- beskjeden ba henne kappes om den.
--
-- Derfor en egen mal. Den sier at plassen er hennes, hva den koster aa gjore
-- opp, og hvor hun ser den.

INSERT INTO notification_templates (navn, kanal, emne, tekst, aktiv)
VALUES (
  'venteliste_tildelt',
  'epost',
  'Du har fått plass på {kurs}',
  'Hei {navn}! Du har fått plass på {kurs} {dato}. Plassen er satt av til deg og står som reservert til betalingen er gjort opp. Du finner den på Min side: {lenke}',
  1
)
ON DUPLICATE KEY UPDATE
  emne = VALUES(emne),
  tekst = VALUES(tekst),
  aktiv = 1;
