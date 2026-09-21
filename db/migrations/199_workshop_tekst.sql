-- Keramikk Workshop hadde smoerklokke-kursets korttekst.
--
-- Sett paa lissom.no/kurs 21. september 2026: kortet «Keramikk Workshop» sa
-- «Smoeret holder seg ferskt uten kjoeleskap ...» — setningen som hoerer til
-- «Vi lager fransk smoerklokke». Kurset har ogsaa samme bilde som smoerklokka;
-- bildet byttes i admin, det er ikke en migrasjon.
--
-- Teksten under er den eieren godkjente for workshop 15. september 2026
-- (migrasjon 186), og som han bekreftet paa nytt 21. september. Bare raden
-- som faktisk har feil tekst roeres — har verkstedet skrevet noe eget i
-- mellomtida, gjelder det.

UPDATE courses
   SET kort_beskrivelse = 'Du bestemmer hva du vil lage, vi hjelper deg underveis. Alt av leire, glasur og brenning er med.'
 WHERE slug = 'workshop'
   AND kort_beskrivelse = 'Smøret holder seg ferskt uten kjøleskap. Du lager klokka selv, i to deler, på én kveld.';
