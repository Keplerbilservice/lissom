-- Logoen til kunden, ved siden av bildet av det som ble laget.
--
-- Referansekunden hadde ett bilde. Det holder til «her er en kopp», men ikke
-- til det verkstedet faktisk vil vise: at koppen ble laget for Kepler, og
-- vintallerkenen for Grenseloes. Logoen sier hvem, bildet sier hva.
--
-- Kolonnen er valgfri. En referanse uten logo vises som for, med navnet i
-- tekst — ikke alle kunder har en logo, og ikke alle vil se sin brukt.

ALTER TABLE referansekunder
    ADD COLUMN IF NOT EXISTS logo VARCHAR(255) NULL AFTER bilde;

-- «bilde» var 64 tegn. Et bilde eieren har lastet opp selv lagres som
-- «api/bilde.php?artikkel=<navn>.jpg», og det er lengre enn det. Filnavnet
-- ble klippet, og bildet pekte ingen steder.
ALTER TABLE referansekunder
    MODIFY COLUMN bilde VARCHAR(255) NULL;
