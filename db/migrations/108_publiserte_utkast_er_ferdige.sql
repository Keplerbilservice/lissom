-- Utkast som alt ligger ute er ferdige.
--
-- Tavla under Markedsforing lister utkast med status «godkjent». Meningen er
-- at et nyhetsbrev eller et innlegg ikke skal bli borte for det er sendt
-- eller limt inn — de gaar ingen steder av seg selv, og teksten maa vaere
-- for haanden.
--
-- En artikkel som ligger ute paa nettsida er derimot brukt. Men statusen ble
-- aldri satt til «publisert», verken av «Publiser naa» eller av knappen paa
-- tavla, saa den ble staaende under «Godkjent — klar til bruk» for alltid.
-- Eieren, 31. august: «disse ligger her og jeg kan trykke publiser, men de er
-- publisert».
--
-- Koden setter statusen naa. Denne rydder dem som alt er lagt ut.
--
-- Bare utkast som PEKER paa en artikkel som FAKTISK er publisert. Et utkast
-- uten resultat, eller med en artikkel som ligger som kladd, blir staaende —
-- det er fortsatt noe aa gjore med dem.

UPDATE ai_utkast u
   JOIN articles a ON a.id = u.resultat_id
    SET u.status = 'publisert'
  WHERE u.status = 'godkjent'
    AND a.status = 'publisert';
