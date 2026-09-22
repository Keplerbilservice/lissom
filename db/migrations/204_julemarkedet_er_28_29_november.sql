-- Julemarkedet paa Mellom Rød Gård er 28. og 29. november, ikke 21. og 22.
--
-- Eieren, 22. september 2026: «Julemarked, er 28 og 29 novmber❤️, kan du
-- sjekke om det er riktig dato du skrev i artikkel». Det var den ikke.
--
-- Da artikkelen ble skrevet (migrasjon 202) sa han «21 og 22 november tror
-- jeg det var», og det stod i kommentaren der at datoen var den han husket
-- og skulle rettes om den var feil. Naa er den rettet.
--
-- Datoen stod to steder: i tittelen og i ingressen. Den staar ikke i
-- adressen (julemarked-mellom-rod-gard), saa ingen lenke brytes.
--
-- Artikkelen ligger som kladd og er ikke vist noe sted enda. Statusen
-- roeres ikke her — han publiserer selv fra admin naar han vil.

UPDATE articles
   SET tittel  = 'Møt oss på julemarkedet på Mellom Rød Gård 28.–29. november',
       ingress = 'Helgen 28.–29. november står Lissom på julemarkedet på Mellom Rød Gård på Tjøme. Kom innom for en prat om kurs, se keramikken fra verkstedet og finn en julegave.'
 WHERE slug = 'julemarked-mellom-rod-gard';
