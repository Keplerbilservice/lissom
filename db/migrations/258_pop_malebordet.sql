-- Paint on Pots: malebordet (eieren, «ok, bygg det» 7. oktober 2026, og
-- «totalt nytt opplegg» 8. oktober 2026).
--
-- Én rad per dato verkstedet har gjort et unntak for Paint on Pots:
--   fullt  = ingen nye bookinger (fra fra_tid og ut dagen, eller hele dagen
--            naar fra_tid er NULL). Bookinger som alt er gjort, staar.
--   stengt = «Ingen PoP» den dagen. Ingen tider vises. Kursene roeres ikke.
--   tider  = andre aapningstider den dagen: fra_tid til til_tid.
-- Ingen rad = ukeplanen / aapningstidene som foer. Raden gjelder bare sin
-- egen dato, saa neste dag er som vanlig av seg selv. «Aapne igjen» sletter
-- raden.
--
-- Antall malestoler staar i courses.kapasitet, besoekslengden i
-- courses.plass_minutter og «Ingen plassgrense» i courses.uten_plassgrense
-- (migrasjon 257). Migrasjonen roerer ingen kurs, oekter eller bookinger.
CREATE TABLE IF NOT EXISTS pop_dager (
  dato      DATE NOT NULL PRIMARY KEY,
  status    ENUM('fullt','stengt','tider') NOT NULL,
  fra_tid   TIME NULL COMMENT 'fullt: fra dette klokkeslettet (NULL = hele dagen). tider: aapner.',
  til_tid   TIME NULL COMMENT 'tider: stenger.',
  satt_av   BIGINT UNSIGNED NULL,
  satt_tid  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Plassgrensen er en egen ressurs ─────────────────────────────────────
--
-- Eieren, 8. oktober 2026: PoP-plassgrensen ligger under Ressurser i admin,
-- «Paint on Pots», 20 plasser, og redigeres der. Den er adskilt fra
-- verkstedet: PoP teller bare mot denne (personer til stede samtidig), og kurs
-- og medlemmer teller ikke mot den. Ressursen slått av = ingen plassgrense.
-- Finnes den fra før (samme navn), roeres den ikke.
INSERT INTO ressurser (navn, antall, merknad, aktiv)
SELECT 'Paint on Pots', 20, 'Personer som maler samtidig. Slått av = ingen plassgrense.', 1
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM ressurser WHERE navn = 'Paint on Pots');

-- Paint on Pots-kurset kobles til ressursen. Bare kurset; ingen oekter eller
-- bookinger roeres.
UPDATE courses
   SET ressurs_id = (SELECT id FROM ressurser WHERE navn = 'Paint on Pots')
 WHERE folger_apningstid = 1
   AND (slug = 'paint-on-pots' OR tittel = 'Paint on Pots');
