-- Kepler som referansekunde.
--
-- Bestilt 27. august: verkstedet ville ha den lagt inn med det samme, med
-- bildet av koppen og teksten om hvorfor Kepler byttet.
--
-- Raden legges inn her og ikke for haand, fordi databasen paa lissom.no er
-- den eneste som teller — og en rad noen skriver inn i et skjema finnes bare
-- der den ble skrevet. Migrasjonen kan kjores om igjen uten aa lage dubletter.
--
-- Logoen staar tom. Den kom som et bilde i samtalen, ikke som en fil, saa
-- den maa lastes opp i billedvelgeren og velges under «Logoen til kunden».
--
-- «samtykke» er satt fordi verkstedet ba om aa faa den ut. Feltet betyr at
-- kunden har sagt ja til aa bli vist med navn og bilde; staar det feil, slaas
-- det av under Referansekunder.

INSERT INTO referansekunder (navn, bilde, tekst, sortering, aktiv, samtykke)
SELECT 'Kepler',
       'uploads_kepler-kopp.jpg',
       'Kepler ønsket å tenke mer bærekraftig og byttet fra pappkrus til keramikkopper. Motivet er tegnet etter ønske fra kunden.',
       COALESCE((SELECT MAX(r.sortering) FROM referansekunder r), 0) + 1,
       1,
       1
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM referansekunder r2 WHERE r2.navn = 'Kepler');
