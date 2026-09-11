-- Alt-tekst paa kursbildet: hva bildet viser, for den som ikke ser det —
-- og for Google.
--
-- Eieren, 11. september 2026, SEO-instruksen: «alt-tekster på bilder med
-- beskrivende norsk». Maalt paa lissom.no: alle innholdsbilder hadde alt-
-- tekst, men teksten var kursnavnet («Nybegynner dreiekurs»), ikke hva
-- bildet viser. Artiklene har hatt et eget felt for dette (migrasjon 070);
-- kursene faar det samme. Tomt = kursnavnet, som foer.

ALTER TABLE courses
    ADD COLUMN IF NOT EXISTS bilde_alt VARCHAR(191) NULL
        COMMENT 'Alt-tekst paa kursbildet: hva bildet viser. Tom = kursnavnet.'
        AFTER bilde;
