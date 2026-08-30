-- Signaturen skalerer på telefon.
--
-- Eieren, 30. august, med en testmelding åpnet på iPhone: «epost til mobil
-- deler signaturen, den må skaleres».
--
-- Signaturen er to spalter: logoen til venstre, kontaktopplysningene til
-- høyre. Logoen sto på 152 piksler, med 20 i luft på hver side av streken —
-- 195 piksler av bredden før teksten begynner. På en telefon er det rundt 360
-- å ta av, og da fikk «Monica Væthe-Larsen» 165 piksler å stå på. Navnet
-- brakk over to linjer, adressen over to, og signaturen så delt ut.
--
-- Logoen er nå 100 piksler med 12 i luft. Da blir det 235 til teksten, og
-- navnet står på én linje. På skjerm ser det ut som før, bare litt strammere.
--
-- Selve signaturen ligger som HTML i innstillingene — eieren limte den inn
-- derfra. Derfor rettes den her og ikke bare i e-post-signatur.html: ellers
-- ville filen vært riktig mens meldingene fortsatte å gå ut med den gamle.
--
-- Erstatningene er punktvise og treffer bare Lissom-signaturen (den med
-- lissom-signatur-logo.png). Har noen skrevet sin egen signatur, står den
-- urørt.

UPDATE innstillinger
   SET verdi = REPLACE(verdi, 'width="152" height="139"', 'width="100" height="92"')
 WHERE nokkel = 'epost_signatur'
   AND verdi LIKE '%lissom-signatur-logo.png%';

-- Bildet skal krympe med spalten sin, ikke sprenge den.
UPDATE innstillinger
   SET verdi = REPLACE(verdi,
                       'border-radius:10px;">',
                       'border-radius:10px;width:100px;max-width:100%;height:auto;">')
 WHERE nokkel = 'epost_signatur'
   AND verdi LIKE '%lissom-signatur-logo.png%'
   AND verdi NOT LIKE '%max-width:100%;height:auto%';

-- Lufta rundt streken mellom spaltene.
UPDATE innstillinger
   SET verdi = REPLACE(REPLACE(REPLACE(verdi,
                       'padding:0 20px 0 0', 'padding:0 12px 0 0'),
                       'padding-left:20px',  'padding-left:12px'),
                       'padding:0 0 0 20px', 'padding:0 0 0 12px')
 WHERE nokkel = 'epost_signatur'
   AND verdi LIKE '%lissom-signatur-logo.png%';

-- Tabellen skal aldri bli bredere enn plassen den får.
--
-- Ingen NOT LIKE-vakt her: prosenttegnet i «max-width:100%» er et jokertegn i
-- LIKE, og vakta traff derfor bildet i stedet for tabellen — og hoppet over
-- raden. Migrasjoner kjores én gang, saa REPLACE holder.
UPDATE innstillinger
   SET verdi = REPLACE(verdi,
                       "serif;color:#4D1D12;\">",
                       "serif;color:#4D1D12;max-width:100%;\">")
 WHERE nokkel = 'epost_signatur'
   AND verdi LIKE '%lissom-signatur-logo.png%';
