-- Bildetekst og alt-tekst paa toppbildet i en artikkel.
--
-- Bildene inne i teksten fikk bildetekst og alt-tekst med migrasjon 064.
-- Toppbildet — det som staar over ingressen og foelger med naar noen deler
-- lenken — sto uten. Det er det bildet flest ser, og i alle aviser og
-- fagblad staar det en linje under som sier hva vi ser paa.
--
-- Alt-teksten er ikke det samme som bildeteksten. Bildeteksten er noe alle
-- leser; alt-teksten er det en skjermleser sier hoyt til den som ikke ser
-- bildet. For sto tittelen paa artikkelen der, og den sier ingenting om hva
-- bildet viser.
--
-- Begge er valgfrie. En artikkel uten dem tegnes som for.

ALTER TABLE articles
    ADD COLUMN IF NOT EXISTS bilde_tekst VARCHAR(255) NULL AFTER bilde;

ALTER TABLE articles
    ADD COLUMN IF NOT EXISTS bilde_alt VARCHAR(255) NULL AFTER bilde_tekst;
