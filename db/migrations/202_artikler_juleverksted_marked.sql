-- To artikler som kladd: Juleverksted og julemarkedet paa Mellom Rød Gård.
--
-- Eieren, 21. september 2026: «du bør vel også lage en artikkel om
-- juleverksted?» og «samt marked på mellom rød gård hvor vi er å finne,
-- 21 og 22 november tror jeg det var». Tekstene ble vist ham foer de ble
-- lagt inn; han valgte «GO – begge som kladd». Ingenting vises foer han
-- publiserer fra admin (Nyheter → status). Datoen for markedet er den han
-- husker — i fjor var det 29.–30. november — og rettes der om den er feil.
--
-- Slug settes naa, saa adressen er kjent; artikler.php beholder den ved
-- publisering. Bilde legges paa i admin.

INSERT INTO articles (tittel, kategori, slug, ingress, innhold, kilde, status, sortering)
SELECT 'Juleverksted: lag julepynten selv i år',
       'Events',
       'juleverksted-lag-julepynten-selv',
       'I desember åpner vi verkstedet for juleverksted – en hyggelig kveld der du lager julepynt i keramikk etter eget ønske. Ingen forkunnskaper, alle kan være med.',
       CONCAT(
         '# Hva vi gjør', CHAR(10), CHAR(10),
         'Vi jobber med håndbyggingsteknikker: plater, kuler, stempler og pynt. Du bestemmer selv hva det skal bli – kuler til treet, hjerter, stjerner, lysholdere eller små skåler til julebordet. Vi hjelper deg hele veien.', CHAR(10), CHAR(10),
         '# Hvem det passer for', CHAR(10), CHAR(10),
         'Alle. Barn under 12 år kommer sammen med en voksen. Fint for familien, venninnegjengen eller kolleger.', CHAR(10), CHAR(10),
         '# Etterpå', CHAR(10), CHAR(10),
         'Tingene tørker, brennes og glaseres i verkstedet. Vi gir beskjed når de er klare til henting.', CHAR(10), CHAR(10),
         '# Praktisk', CHAR(10), CHAR(10),
         'Pris kr. 990,- per person, alt materiale inkludert. Datoer og booking finner du under Juleverksted på lissom.no/kurs.'
       ),
       'manuell', 'kladd', 0
 WHERE NOT EXISTS (SELECT 1 FROM articles WHERE slug = 'juleverksted-lag-julepynten-selv' OR tittel = 'Juleverksted: lag julepynten selv i år');

INSERT INTO articles (tittel, kategori, slug, ingress, innhold, kilde, status, sortering)
SELECT 'Møt oss på julemarkedet på Mellom Rød Gård 21.–22. november',
       'Nyheter',
       'julemarked-mellom-rod-gard',
       'Helgen 21.–22. november står Lissom på julemarkedet på Mellom Rød Gård på Tjøme. Kom innom for en prat om kurs, se keramikken fra verkstedet og finn en julegave.',
       CONCAT(
         'Mellom Rød Gård (Barkevikveien 37, Tjøme) fyller låven og tunet med julemarked, kafé og gammeldags julestemning. Vi tar med håndlaget keramikk fra verkstedet og gavekort på kurs – et dreiekurs, Paint on Pots eller en Date Night er en gave som blir en opplevelse.', CHAR(10), CHAR(10),
         'Lurer du på hvordan et kurs foregår, eller om medlemskap er noe for deg? Kom og spør – vi står der hele helgen.', CHAR(10), CHAR(10),
         'Åpningstider på markedet kommer. Se alle kurs og datoer på lissom.no/kurs.'
       ),
       'manuell', 'kladd', 0
 WHERE NOT EXISTS (SELECT 1 FROM articles WHERE slug = 'julemarked-mellom-rod-gard' OR tittel = 'Møt oss på julemarkedet på Mellom Rød Gård 21.–22. november');
