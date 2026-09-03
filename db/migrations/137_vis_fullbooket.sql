-- «Fullbooket» selv om det er plasser igjen.
--
-- Eieren, 3. september: «pillen på kortet som viser hvor mange plasser det
-- er, denne vil jeg ha mulighet til å overstyre med en hake på kortet så det
-- står fullbooket eller fult eller så klart likt som de andre fulle kursene».
--
-- Det finnes grunner til å stenge en dato uten å avlyse den: kursholderen er
-- usikker, verkstedet er lovet bort, eller de siste plassene er holdt av til
-- noen som skal svare. Fram til nå var valget enten å avlyse — som sier fra
-- til de påmeldte at kurset ikke går — eller å la nettsiden selge plasser
-- verkstedet ikke ville selge.
--
-- ── Hvorfor ikke «manuelt_opptatt» ────────────────────────────────────
--
-- Den kolonna finnes fra oppdatering 014 og ville gitt samme utslag: er den
-- like stor som kapasiteten, står økta som fullbooket. Men den betyr noe
-- annet — «plasser tatt utenfor nettsiden», altså telefonpåmeldingene. Delte
-- de felt, kunne de ikke skilles fra hverandre, og å slå av «fullbooket»
-- ville strøket telefonpåmeldingene med.
--
-- ── Hvorfor ikke status «fullt» ───────────────────────────────────────
--
-- ENUM-en på course_sessions har hatt verdien «fullt» siden 001, men
-- ingenting skriver eller leser den. Å ta den i bruk ville vært verre enn en
-- ny kolonne: tolv spørringer filtrerer på «planlagt», så økta ville falt ut
-- av kurslista, kalenderen, ventelista og påminnelsene. Kurset skal stå der
-- som før — det er bare pilla som skal si noe annet.

ALTER TABLE course_sessions
  ADD COLUMN IF NOT EXISTS vis_fullt TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Vis økta som fullbooket selv om det er ledige plasser'
    AFTER manuelt_opptatt;
