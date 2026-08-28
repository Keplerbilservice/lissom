-- Oppfoelgingen etter kurset, med lenke til aa legge igjen en anmeldelse.
--
-- Lissom 28. august: null anmeldelser paa Google. For et lokalt verksted er
-- det den storste enkeltfaktoren i «keramikkurs i naerheten» — kartet svarer
-- for de organiske treffene gjor det.
--
-- Meldingen gaar én gang per kursdato, noen timer etter at den er holdt, til
-- dem som faktisk betalte og motte opp.
--
-- ── Kanalen ───────────────────────────────────────────────────────────
--
-- Malen staar som «sms». SMS er ikke satt opp ennaa, og da gjor
-- Varsel::mal() akkurat det den skal: sender e-post i stedet, uten at noe
-- maa endres. Den dagen SMS skrus paa, gaar den samme meldingen som SMS —
-- som er kanalen med hoyest svarprosent — uten at noen roerer denne fila.
--
-- ── Hvorfor ingenting sendes ennaa ────────────────────────────────────
--
-- Malen er klar til bruk, men jobben som sender den staar av. Én bryter,
-- ikke to: anmeldelse_paa er 0, og anmeldelse_lenke er tom.
--
-- Lenken staar ikke i koden. Den er Lissom sin, og limes inn under
-- Markedsforing → E-post og SMS. Uten den har meldingen ingenting aa peke
-- paa, og «legg igjen noen ord» uten sted er bare stoy — derfor sender
-- jobben ikke for begge deler er paa plass.
--
-- Og uansett: aldri lenger tilbake enn tre dogn. Skrur du den paa i
-- november, gaar det ingen melding til dem som var her i august.

ALTER TABLE course_sessions
    ADD COLUMN IF NOT EXISTS anmeldelse_sendt_at DATETIME NULL
    AFTER paaminnelse_sendt_at;

INSERT INTO notification_templates (navn, kanal, emne, tekst, aktiv, gruppe) VALUES (
    'anmeldelse',
    'sms',
    'Takk for sist på {kurs}',
    'Hei {navn}! Takk for at du var hos oss på {kurs}. Hadde du en fin stund, betyr det mye for oss om du legger igjen noen ord: {lenke}\n\nHilsen Monica, Lissom Keramikk',
    1,
    'kurs'
) ON DUPLICATE KEY UPDATE navn = navn;

-- Innstillingene. Tomme med vilje — lenken er Lissom sin, ikke min.
INSERT INTO innstillinger (nokkel, verdi) VALUES
    ('anmeldelse_paa',    '0'),
    ('anmeldelse_lenke',  ''),
    ('anmeldelse_timer',  '3')
ON DUPLICATE KEY UPDATE nokkel = nokkel;
