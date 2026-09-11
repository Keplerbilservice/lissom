-- Medlemsinvitasjonen etter kurset, og avmelding fra tilbuds-e-post.
--
-- Eieren, 11. september 2026: «jeg lastet opp en epost i download, som heter
-- epost, denne vil jeg skal sette opp så den går til alle kursdeltakere 3-4
-- dager etter at de har vært på kurs, forutsetter at de har betalt. legg til
-- teksten, Hei, det var så hyggelig å ha deg på kurs, så vi håper du vil
-- fortsette som medlem» — og: «men denne vil jeg aktivere nå».
--
-- E-posten er markedsføring til tidligere kunder (markedsføringsloven § 15):
-- lovlig, men bare med en avmelding som virker. Den hadde en lenke til
-- /avmelding som ikke fantes. Derfor tabellen epost_avmelding: én rad per
-- e-postadresse med en personlig kode; lenken i e-posten setter «reservert»,
-- og jobben (og senere tilbud) hopper over dem. Aktive medlemmer hoppes
-- ogsaa over — de er alt medlemmer.
--
-- Prisen paa «Prøv Lissom» staar ikke i teksten (ingen faste priser): den
-- hentes fra membership_plans naar e-posten sendes.
--
-- Bryteren og malen staar PAA fra start: eieren ville aktivere naa. Jobben
-- gaar i den samme cron-linja som «anmeldelser» (hver time), saa ingen ny
-- linje trengs i cPanel.

ALTER TABLE course_sessions
    ADD COLUMN IF NOT EXISTS fortsett_sendt_at DATETIME NULL
        COMMENT 'Naar medlemsinvitasjonen gikk til deltakerne paa denne datoen.'
        AFTER anmeldelse_sendt_at;

CREATE TABLE IF NOT EXISTS epost_avmelding (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    epost        VARCHAR(191)    NOT NULL,
    kode         CHAR(32)        NOT NULL COMMENT 'Den personlige koden i avmeldingslenka.',
    reservert    TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = vil ikke ha tilbud paa e-post.',
    reservert_at DATETIME        NULL,
    opprettet    DATETIME        NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    UNIQUE KEY epost (epost),
    UNIQUE KEY kode (kode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO notification_templates (navn, kanal, emne, tekst, aktiv, gruppe) VALUES (
    'fortsett',
    'epost',
    'Vil du fortsette med leire?',
    'Hei {navn}, det var så hyggelig å ha deg på kurs, så vi håper du vil fortsette som medlem.\n\nTusen takk for at du valgte å gå kurs hos oss! Det du laget der, var bare begynnelsen. Som tidligere kursdeltaker kan du bli medlem i verkstedet på Teie – og fortsette der kurset slapp.\n\n- Fullt utstyrt verksted med dreieskiver\n- Leire, glasurer og brenning på ett sted\n- Et fellesskap som deler tips og skaperglede\n- Kom og gå når det passer deg\n\n{visste}Se medlemskapene: https://lissom.no/medlemskap\n\nEller stikk innom, så viser vi deg rundt i verkstedet. Vi håper vi ses igjen!\n\nDu får denne e-posten fordi du har deltatt på kurs hos oss. Meld deg av: {avmelding}',
    1,
    'nyhetsbrev'
) ON DUPLICATE KEY UPDATE navn = navn;

INSERT INTO innstillinger (nokkel, verdi) VALUES
    ('fortsett_paa',   '1'),
    ('fortsett_dager', '3')
ON DUPLICATE KEY UPDATE nokkel = nokkel;
