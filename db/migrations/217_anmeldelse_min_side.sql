-- «Be om en anmeldelse»: Min side-avsnittet også i tekstutgaven.
-- Eieren, 25. september 2026 (GO): «at de også finner historikk og kursbevis
-- og gode tilbud på min side, send med log in knapp». HTML-utgaven har
-- knappen (app/epost/anmeldelse.html); dette er reserven for ren tekst.

UPDATE notification_templates
   SET tekst = REPLACE(tekst,
         '{kursbevis}\n\nVi setter stor pris',
         '{kursbevis}\n\nPå Min side finner du også historikken din, kursbevisene og gode tilbud:\nhttps://lissom.no/min-side\n\nVi setter stor pris')
 WHERE navn = 'anmeldelse'
   AND tekst NOT LIKE '%lissom.no/min-side%';
