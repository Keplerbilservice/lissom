-- Hvem holdt kurset?
--
-- Kursbeviset skal signeres av den som faktisk hadde kurset, ikke av den
-- samme uansett. Navnet og signaturfila ligger paa kurset; er de tomme,
-- brukes Monica og signaturen fra malen.
--
-- Signaturen er et filnavn, ikke en sti. Endepunktet slipper bare gjennom
-- rene filnavn, saa ingen kan peke den ut av mappa.

ALTER TABLE courses
  ADD COLUMN instruktor VARCHAR(191) NULL COMMENT 'Navnet paa kursbeviset. Tomt = Monica.' AFTER beskrivelse,
  ADD COLUMN instruktor_signatur VARCHAR(191) NULL COMMENT 'Filnavn, f.eks. signatur-monica.png' AFTER instruktor;

-- «Workshop» ble lagt til som egen kategori paa nettsiden, men kolonnen tillot
-- bare kurs, event og dropin. Uten dette ville en workshop blitt lagret som
-- kurs. «Sip & Clay» trenger ingen endring — den skilles paa tema, som er fritt
-- tekstfelt.
ALTER TABLE courses
  MODIFY COLUMN type ENUM('kurs','event','workshop','dropin') NOT NULL DEFAULT 'kurs';
