-- Plasser som er tatt utenfor nettsiden.
--
-- Folk melder seg paa i verkstedet, paa telefon og paa Instagram. De plassene
-- finnes ikke som bookinger i basen, men de er opptatt. Uten et sted aa fore
-- dem ville et kurs med to paameldte staatt som helt ledig paa nettsiden, og
-- to personer for mye kunne booket seg inn.
--
-- Tallet trekkes fra sammen med de ekte bookingene naar ledige plasser regnes
-- ut. Er det like stort som kapasiteten, staar okten som fullbooket.

ALTER TABLE course_sessions
  ADD COLUMN IF NOT EXISTS manuelt_opptatt SMALLINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Plasser tatt utenfor nettsiden. Trekkes fra ledige plasser.'
    AFTER kapasitet;

-- En okt er entydig gitt kurs og starttidspunkt. Noekkelen gjor at datoene
-- kan legges inn om igjen uten aa bli liggende i to eksemplarer.
--
-- Rydd bort eventuelle dubletter forst, eldste rad vinner. Bookinger flyttes
-- over til den raden som blir staaende, saa ingen mister plassen sin.
UPDATE bookings b
  JOIN course_sessions cs1 ON cs1.id = b.course_session_id
  JOIN course_sessions cs2 ON cs2.course_id = cs1.course_id
                          AND cs2.start_tid = cs1.start_tid
                          AND cs2.id < cs1.id
   SET b.course_session_id = cs2.id;

DELETE cs1 FROM course_sessions cs1
  JOIN course_sessions cs2 ON cs1.course_id = cs2.course_id
                          AND cs1.start_tid = cs2.start_tid
                          AND cs1.id > cs2.id;

ALTER TABLE course_sessions
  ADD UNIQUE KEY IF NOT EXISTS uq_okt_kurs_start (course_id, start_tid);
