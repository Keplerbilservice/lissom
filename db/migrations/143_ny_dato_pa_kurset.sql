-- Beskjed naar en deltaker flyttes til en ny dato.
--
-- «Bytt dato» i kalenderen: en deltaker som ikke kan komme likevel dras ut av
-- kurset, parkeres i sidemenyen, og dras inn paa en annen dato. Plassen,
-- betalingen og notatet foelger med — det gjorde de fra for.
--
-- Det som manglet var beskjeden. Flyttingen sendte ingenting; serveren svarte
-- bare «Husk aa gi beskjed.», og da sto det paa at noen faktisk husket det.
-- Gjorde ingen det, motte deltakeren opp paa en kveld hun ikke lenger var
-- satt opp paa.
--
-- Eieren, 6. september: e-post hver gang, og trykket i bekreftelsen er det
-- som sender den. Avbryter han, sendes ingenting.

INSERT INTO notification_templates (navn, kanal, emne, tekst, gruppe, aktiv)
VALUES (
  'pamelding_flyttet',
  'epost',
  'Ny dato på {kurs}',
  'Hei {navn}! Plassen din på {kurs} er flyttet fra {fra} til {til}. Du trenger ikke gjøre noe — betalingen følger med. Du finner den nye datoen på Min side: {lenke}',
  'kurs',
  1
)
ON DUPLICATE KEY UPDATE
  emne = VALUES(emne),
  tekst = VALUES(tekst),
  gruppe = VALUES(gruppe),
  aktiv = 1;
