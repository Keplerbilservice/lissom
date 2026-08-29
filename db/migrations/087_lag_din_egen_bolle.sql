-- Kurset heter «Lag din egen bolle».
--
-- Eieren ba om nytt navn paa bollekurset, og ingenting annet.
--
-- ── Hvorfor en migrasjon, og ikke bare et felt i admin ────────────────
--
-- Tittelen kan endres i Kurs og medlemskap, og datoene foelger med av seg
-- selv: «course_sessions» har ingen egen tittel, den peker paa kurset. Men
-- navnet staar ogsaa som noekkel ett sted i koden — malen for kursteksten i
-- app/lib/kursmal.php — og de to maa endres sammen. Skjer bare det ene,
-- faller kursteksten tilbake paa den generelle plateteknikk-malen, og
-- beskrivelsen paa kurssida blir en annen enn den eieren har staaende.
--
-- Derfor gaar navnet her, sammen med kodeendringen, saa de to aldri kan
-- komme i utakt.
--
-- ── Hva som IKKE endres ───────────────────────────────────────────────
--
-- * «slug» staar. Adressen /kurs/kurs-boller er delt i e-poster og indeksert
--   av Google; bytter den, blir gamle lenker doede. En adresse er ikke et
--   navn.
-- * Pris, tema, kapasitet, datoer, paameldinger og tekster staar urort.
-- * SEO-tekstene staar urort. De sier «Keramikkurs i boller», ikke
--   «Kurs boller» — de navngir ikke kurset, de beskriver det.
--
-- ── Kursbevisene ──────────────────────────────────────────────────────
--
-- Beviset tegnes naar noen ber om det, av kurset paameldingen peker paa. De
-- som har vaert paa kurset for, faar altsaa det nye navnet paa beviset sitt.
-- Det er det samme kurset, saa det er riktig — og skal et enkelt bevis si
-- noe annet, finnes «bevis_kurs» paa paameldingen fra for.

UPDATE courses
   SET tittel = 'Lag din egen bolle'
 WHERE tittel <> 'Lag din egen bolle'
   -- Slugen er den stabile identiteten. Skrivemaatene staar med i tilfelle
   -- kurset er lagt inn paa nytt en gang med en annen slug.
   AND (slug = 'kurs-boller'
        OR LOWER(TRIM(tittel)) IN
           ('kurs boller', 'kurs bolle', 'bolle kurs', 'bollekurs', 'bolle-kurs'));
