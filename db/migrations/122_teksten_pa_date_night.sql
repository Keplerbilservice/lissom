-- Teksten paa Date Night-kortet.
--
-- Kortet sto med «En kveld for dere to. Leire, god stemning og noe aa ta med
-- hjem.» — en linje jeg skrev da kurset ble lagt inn, ikke verkstedets egne
-- ord. Den staar oeverst paa kurssida og i «Kursene vaare», og det er den
-- foerste setningen noen leser om kvelden.
--
-- Eieren, 1. september, med teksten ferdig skrevet: «Legg til tekst paa
-- datenight kortet.»
--
-- Feltet er «05 · Beskrivelse» i kursoppsettet — «vises paa kurssiden og
-- under Kursene vaare» — saa teksten kan endres i admin etterpaa uten at
-- noen roerer denne fila.
--
-- Bare den gamle linja byttes. Har verkstedet skrevet noe eget i mellomtida,
-- staar deres ord.

UPDATE courses
   SET beskrivelse = 'En romantisk og kreativ kveld for to. Nyt gode samtaler, felles glede og kvalitetstid mens dere skaper noe sammen i leire. En avslappende opplevelse hvor praten går lett og minnene varer lenge. ❤️'
 WHERE (slug = 'date-night' OR tittel = 'Date Night')
   AND beskrivelse = 'En kveld for dere to. Leire, god stemning og noe å ta med hjem.';
