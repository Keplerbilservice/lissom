-- Eierens egen tekst paa Date Night.
--
-- Migrasjon 122 satte inn en tekst jeg skrev. Eieren, 2. september, med sin
-- egen: «endre teksten».
--
-- Teksten har fire avsnitt. Kurssida slo dem sammen til én klump for dette —
-- «white-space: pre-wrap» er lagt paa i samme slengen, saa avsnittene staar
-- slik de er skrevet, og slik feltet i admin alltid har vist dem.
--
-- Feltet er «05 · Beskrivelse» i kursoppsettet og kan endres i admin.
--
-- Bare teksten fra 122 byttes. Har verkstedet skrevet noe eget etterpaa,
-- staar deres ord.

UPDATE courses
   SET beskrivelse = CONCAT(
         'Date Night i keramikkverkstedet 💕',
         CHAR(10), '',
         CHAR(10), 'Ta en pause fra hverdagen og gi hverandre tid. På Date Night inviterer vi dere til en lun og romantisk kveld hvor dere skaper noe sammen med egne hender. Mens leiren formes, får samtalene flyte fritt, latteren sitter løst, og tiden går litt saktere.',
         CHAR(10), '',
         CHAR(10), 'Her handler det ikke om å prestere, men om å være sammen. En kveld fylt med nærhet, kreativitet og små øyeblikk dere vil huske lenge etter at keramikken er ferdig. ❤️🏺',
         CHAR(10), '',
         CHAR(10), 'Den perfekte daten for dere som ønsker en annerledes og meningsfull kveld sammen.'
       )
 WHERE (slug = 'date-night' OR tittel = 'Date Night')
   AND beskrivelse = 'En romantisk og kreativ kveld for to. Nyt gode samtaler, felles glede og kvalitetstid mens dere skaper noe sammen i leire. En avslappende opplevelse hvor praten går lett og minnene varer lenge. ❤️';
