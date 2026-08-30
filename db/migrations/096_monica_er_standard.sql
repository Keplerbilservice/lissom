-- Monica er standard kursholder.
--
-- Migrasjon 093 flyttet kursene over paa Monica og satte eieren inaktiv, men
-- ingen ble merket som verkstedets standard. Da falt vaktlista tilbake paa
-- «Ikke tildelt» paa hver eneste rad: sporringen leter etter
-- «standard = 1 AND aktiv = 1», og det fantes ingen.
--
-- Eieren 30. august: «alle kurs og vakter skal vaere default Monica».
--
-- Gjort forsiktig: bare hvis hun finnes én gang og er aktiv, og bare hvis
-- ingen andre alt staar som standard. Ellers roeres ingenting — en base som
-- ser annerledes ut enn den jeg kjenner skal ikke faa et valg trykket paa seg.
UPDATE kursholdere
   SET standard = 1
 WHERE aktiv = 1
   AND navn = 'Monica'
   AND (SELECT antall FROM (SELECT COUNT(*) AS antall FROM kursholdere WHERE navn = 'Monica' AND aktiv = 1) AS m) = 1
   AND (SELECT n FROM (SELECT COUNT(*) AS n FROM kursholdere WHERE standard = 1) AS s) = 0;

-- En som har sluttet kan ikke vaere standard. Skulle det staa igjen en slik
-- fra for, ryddes den bort — ellers ville vaktlista vist et navn som ikke
-- lenger holder kurs.
UPDATE kursholdere SET standard = 0 WHERE standard = 1 AND aktiv = 0;
