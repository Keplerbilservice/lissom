-- Paint on Pots: plassen bookes, gjenstanden betales i verkstedet.
--
-- Paint on Pots laa som et vanlig kurs til fast pris: alle betalte 690 for
-- en plass, uansett hva de valgte aa male. Men konseptet er ikke en plass —
-- det er en gjenstand. En sommerfuglkopp til 300 og en tekopp med skaal til
-- 750 kostet det samme, og prislista paa nettsida sto skrevet inn i koden
-- med tall som ikke fantes i butikken.
--
-- Naa er det to ting: plassen, som bookes paa en dato som for, og
-- gjenstanden, som velges i verkstedet og slaas inn i kassa. Gjenstandene er
-- helt vanlige butikkvarer — da trekkes lageret, og salget foeres paa
-- butikkontoen i regnskapet, uten et eget regelverk ved siden av.
--
-- Kolonnen er valgfri og staar av paa alt annet. Et kurs uten den tegnes
-- noeyaktig som for.

ALTER TABLE courses
    ADD COLUMN IF NOT EXISTS gjenstand_i_kassa TINYINT(1) NOT NULL DEFAULT 0 AFTER varighet_tekst;

-- Paint on Pots er den ene vi vet om. Prisen paa plassen roeres ikke her —
-- den eier verkstedet, og skjermen sier fra naar den ser ut til aa vaere
-- gjenstandsprisen som er blitt staaende.
UPDATE courses SET gjenstand_i_kassa = 1
 WHERE tema = 'Paint on pots' OR tittel = 'Paint on Pots';
