-- Ventelista løsnes fra avlyste økter
--
-- Eieren, 9. september 2026, med kalenderen og Oversikt side om side:
-- «venteliste på oversikt kortet venteliste, er det to på venteliste, men på
-- sidden i kalenderen, er det kun en på venteliste?????????????»
--
-- Samme rot som «hun er borte fra venteliste» tidligere samme dag. Panelet i
-- kalenderen filtrerer bort alt som hører til en avlyst økt; kortet på
-- Oversikt teller alle rader med status «venter», uansett. Den ene sto på en
-- kveld som var avlyst, og var derfor usynlig det ene stedet.
--
-- api/admin/kurs.php løsner dem nå av seg selv når en økt avlyses. Rader som
-- ble avlyst FØR den rettelsen gikk ut, henger fortsatt fast. Denne tar dem
-- igjen, én gang.
--
-- Hva den gjør: setter «course_session_id» til NULL på dem som fortsatt
-- venter. Da venter de på KURSET i stedet, og den som venter på kurset står
-- på hver kommende dato — se api/admin/kalender.php.
--
-- Hva den ikke gjør: ingen rad slettes, ingen status endres, ingen mister
-- køplassen sin. «booket», «utløpt» og «fjernet» røres ikke — de er ferdige,
-- og skal ikke vekkes til live.
UPDATE waitlist w
  JOIN course_sessions cs ON cs.id = w.course_session_id
   SET w.course_session_id = NULL
 WHERE cs.status = 'avlyst'
   AND w.status IN ('venter', 'varslet');
