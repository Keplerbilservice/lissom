-- To kort til: «Håndbygging» og «HMS og vedlikehold».
--
-- Eieren, 11. september 2026, runde to (GO): fjorten dokumenter til i mappa
-- «Nye dokumenter». Elleve gaar i kort som finnes (Dreiing, Glassering,
-- Brenning, Leire). Seks passet ikke i noe kort: tre teknikkark om
-- haandbygging (klyping, poelse, plate), og HMS, vedlikehold og «Keramikk –
-- vanlige spoersmaal». Foreslaatt to nye kort med disse navnene; GO.
--
-- «Regler i verkstedet (plakat)» fra samme mappe er ikke med: den sa det
-- samme som «Ordensregler og HMS» paa Min side — og ikke det samme
-- («ovnsansvarlig» mot «bare Monica», «gjenvinnes» mot «kastes etter to
-- maaneder»). Eieren: «Ikke legg den inn».
--
-- Samme bryter som de andre kortene, av til han slaar den paa. Dokumentene
-- ligger i importpakka og legges inn av «⚙ Kjør oppdateringer».

INSERT IGNORE INTO verksted_kategorier (slug, navn, sortering) VALUES
    ('handbygging', 'Håndbygging',        9),
    ('hms',         'HMS og vedlikehold', 10);
