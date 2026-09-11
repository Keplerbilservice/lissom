-- To dokumenter flyttes, og ett kort skifter navn.
--
-- Eieren, 11. september 2026: «under hms, så ligger det generell kunnskap om
-- leire, jeg vil du tar en grundig sjekk». Gjennomgang av alle 33, og tre
-- spoersmaal med svar:
--
--   «Keramikk – vanlige spørsmål» laa i HMS og vedlikehold. Fem av aatte
--   kapitler er leire og brenning for nybegynnere. → Leire.
--
--   Glasur laa paa to kort: Glasurhaandboken i «Dekorasjonsteknikker og
--   glasurhåndbok», resten (lagvis glasering, Glasurfeil, Matsikker) i
--   Glassering. → Glasurhaandboken til Glassering.
--
--   Da har det foerste kortet bare dekorteknikkene igjen. → «Dekorteknikker».
--
-- Radene flyttes paa kilde (stien i importpakka), saa bryter, id og
-- eventuelle egne opplastinger staar. Manifestet er rettet tilsvarende for
-- nye installasjoner.

UPDATE verksted_dokumenter d
  JOIN verksted_kategorier k ON k.slug = 'leire'
   SET d.kategori_id = k.id
 WHERE d.kilde = 'haandboker/hms/keramikk-vanlige-sporsmal.pdf';

UPDATE verksted_dokumenter d
  JOIN verksted_kategorier k ON k.slug = 'glassering'
   SET d.kategori_id = k.id
 WHERE d.kilde = 'haandboker/dekorasjon/glasurhandbok-for-keramikere.pdf';

UPDATE verksted_kategorier
   SET navn = 'Dekorteknikker'
 WHERE slug = 'dekorasjon' AND navn = 'Dekorasjonsteknikker og glasurhåndbok';
