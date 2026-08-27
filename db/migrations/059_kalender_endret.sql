-- Naar en kursdato sist ble endret.
--
-- Apple Kalender og Outlook bruker SEQUENCE og LAST-MODIFIED for aa avgjore
-- om en hendelse de alt har er endret. Feeden var uten begge: den serverte
-- alt paa nytt hver gang, og de fleste klienter tar den likevel — men det er
-- ikke garantert, og det er den mest sannsynlige grunnen til at en flyttet
-- kursdato ikke dukker opp paa telefonen.
--
-- SEQUENCE maa telle oppover for hver endring. Uten en endringstid paa raden
-- finnes det ingenting aa telle med.
--
-- ON UPDATE CURRENT_TIMESTAMP: kolonnen holder seg selv oppdatert, uansett
-- hvilket av de mange stedene i koden som endrer en okt. Ett sted aa huske
-- er ett; femten er ingen.

ALTER TABLE course_sessions
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL
      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
      COMMENT 'Naar okta sist ble endret. Styrer SEQUENCE i kalenderfeeden.';
