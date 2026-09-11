-- Teksten AI-en leser, paa de importerte dokumentene.
--
-- Eieren, 11. september 2026: «Spør verkstedet» svarte «Dette står ikke i
-- dokumentene» om alt. Grunnen: modellen leser tekst, ikke filer, og de
-- 191 importerte dokumentene hadde ingen tekst. Naa foelger teksten med i
-- importpakka (db/dokumenter/**.txt), og importen legger den paa.
--
-- Denne migrasjonen endrer ingen struktur — kolonnen finnes fra 154. Den
-- er her fordi «⚙ Kjør oppdateringer» bare kjoerer naar det staar en
-- migrasjon og venter, og det er den knappen som starter importen. Uten
-- den maatte eieren funnet en annen vei inn.
--
-- Det samme trykket fjerner malkort som er tatt ut av pakka (Lyshus og
-- Buet espressokopp, som mangler malfil): «dersom det mangler maler, saa vil
-- jeg at disse slettes og ikke vises i admin».

ALTER TABLE verksted_dokumenter
    MODIFY COLUMN tekst MEDIUMTEXT NULL
        COMMENT 'Teksten AI-en kan lese. Fra importpakka, eller limt inn av eieren. Tom = ikke lesbar for AI-en.';
