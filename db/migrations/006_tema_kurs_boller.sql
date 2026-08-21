-- Kurs boller sto oppfort under Dreiing, men horer til Plateteknikk.
-- Rettet etter beskjed fra verkstedet.
UPDATE courses SET tema = 'Plateteknikk' WHERE slug = 'kurs-boller';

-- Beskrivelsen sa at man dreide bollene. Den er skrevet om til plateteknikk,
-- saa teksten ikke motsier merkelappen.
UPDATE courses
   SET beskrivelse = 'En kveld med plateteknikk. Du kjevler ut leira, former boller i flere størrelser og lærer hvordan du får jevne kanter og rene sammenføyninger. Du velger glasur til slutt.'
 WHERE slug = 'kurs-boller';
