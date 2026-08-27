-- Kursnivaa, varighet og de redigerbare tekstene paa hvert kurs.
--
-- Nivaaet var ikke et felt i det hele tatt. Kortet viste «tema» — «Dreiing»,
-- «Plateteknikk» — under overskriften «Nivaa», og det er ikke et nivaa.
-- Skillet som mangler er mellom det interne, som sorterer og filtrerer, og
-- det kunden leser. De to er ikke det samme: alt vi har er for nybegynnere,
-- men ute skal det staa «For alle».
--
-- Varigheten sto som fast tekst, skrevet inn for haand. Den regnes naa av
-- start- og sluttida paa oektene; «varighet_tekst» er bare for de kursene
-- som trenger en annen formulering.
--
-- Merk: «varighet» fra migrasjon 065 er noe annet — en merkelapp (kort,
-- medium, lang) som staar blant «Passer for». Den roeres ikke.
--
-- Alle kolonnene er valgfrie. Et kurs uten dem tegnes noeyaktig som for;
-- standardtekstene ligger i app/lib/kursmal.php og fylles inn naar eieren
-- ber om det, ikke av seg selv.

ALTER TABLE courses
    ADD COLUMN IF NOT EXISTS nivaa_intern VARCHAR(32) NULL AFTER tema;

ALTER TABLE courses
    ADD COLUMN IF NOT EXISTS nivaa_tekst VARCHAR(120) NULL AFTER nivaa_intern;

ALTER TABLE courses
    ADD COLUMN IF NOT EXISTS kort_beskrivelse VARCHAR(500) NULL AFTER nivaa_tekst;

ALTER TABLE courses
    ADD COLUMN IF NOT EXISTS lager_du TEXT NULL AFTER laerer;

ALTER TABLE courses
    ADD COLUMN IF NOT EXISTS med_hjem TEXT NULL AFTER lager_du;

ALTER TABLE courses
    ADD COLUMN IF NOT EXISTS ferdig_tid VARCHAR(160) NULL AFTER med_hjem;

ALTER TABLE courses
    ADD COLUMN IF NOT EXISTS tillegg TEXT NULL AFTER ferdig_tid;

ALTER TABLE courses
    ADD COLUMN IF NOT EXISTS varighet_tekst VARCHAR(120) NULL AFTER varighet;

-- Alt vi har i dag er nybegynnerkurs. Det staar internt; ute staar det
-- «For alle», og den teksten kan endres per kurs.
UPDATE courses SET nivaa_intern = 'nybegynner'
 WHERE nivaa_intern IS NULL OR nivaa_intern = '';
