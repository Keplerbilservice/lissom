-- Bilder paa kurset.
--
-- Steg 3 i «Nytt kurs» viser tre ruter merket «Bilder — vises som karusell paa
-- kurssiden», og et felt for aa slippe en videofil. Ingen av dem gjorde noe:
-- ingen filopplasting, ingen handler, og api/admin/kurs.php tok ikke imot et
-- bilde i det hele tatt. Du kunne lage et kurs og tro du hadde gitt det bilder.
--
-- Kolonnen `bilde` fantes fra 001_init, men ble aldri skrevet til fra admin.
-- Karusellen paa kurssida leser en liste, saa her kommer den: filnavnene som
-- JSON, i den rekkefoelgen de skal vises.
--
-- Filene selv ligger der de alltid har ligget — api/admin/bilder.php laster
-- dem opp, og de samme bildene brukes av varer og artikler. Ingen ny
-- opplasting, ingen ny mappe.

ALTER TABLE courses
    ADD COLUMN bilder TEXT NULL COMMENT 'JSON-liste med filnavn, i visningsrekkefolge'
    AFTER bilde;
