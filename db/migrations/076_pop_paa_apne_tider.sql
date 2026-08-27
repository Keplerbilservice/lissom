-- Paint on Pots paa de aapne tidene.
--
-- Lissom ba 27. august om at Paint on Pots skal kunne bookes naar hun
-- allerede er der: naar det gaar et planlagt kurs, eller naar hun har
-- stemplet inn. Da skal ikke datoene settes opp for haand — de folger
-- aapningstidene.
--
-- Oektene lages av Apent::leggUtPaaApneTider(). Kolonnen her sier hvilke som
-- er laget slik, og den er noedvendig av to grunner:
--
--   1. Rydding. En generert oekt som ingen har booket, og som ikke lenger
--      svarer til en aapen dag, skal bort igjen. En oekt lagt inn for haand
--      skal aldri roeres.
--
--   2. Sirkelen. Aapningstida regnes av oektene som staar ute. Talte de
--      genererte med, ville verkstedet holdt seg aapent av sin egen skygge:
--      en oekt laget fordi det var aapent, som deretter gjor at det er
--      aapent. Apent::dager() ser bort fra dem.

ALTER TABLE course_sessions
    ADD COLUMN IF NOT EXISTS fra_apningstid TINYINT(1) NOT NULL DEFAULT 0
    AFTER fra_dropin_tid;

CREATE INDEX IF NOT EXISTS idx_okt_apningstid ON course_sessions (fra_apningstid);
