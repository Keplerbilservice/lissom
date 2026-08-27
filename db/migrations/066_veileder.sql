-- Kursveilederen: sporsmalene og svarene som data.
--
-- Bestilt 26. august. Kartleggingen ligger i docs/KURSVEILEDER.md.
--
-- Det viktigste funnet der: veilederen lagres ingen steder. Sporsmalene er
-- skrevet inn i koden, og svarene ligger i this.state — nettleserens minne.
-- Redigeringen i admin ser ut til aa virke, men ingenting overlever en
-- oppfriskning av sida.
--
-- content_blocks og innstillinger er noekkel -> tekst, én verdi per noekkel.
-- De kan ikke holde en liste med rekkefolge, type og aktiv-flagg. Aa presse
-- sporsmalene inn som JSON i én tekstverdi ville gjort det umulig aa flytte
-- ett sporsmal, deaktivere ett, eller sporre «hvilke svar peker paa dette
-- kurset» uten aa lese og tolke hele klumpen.

CREATE TABLE IF NOT EXISTS veileder_sporsmal (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    -- Et fast navn paa de tre som fantes fra for. Anbefalingen har
    -- saerregler for antallet og for «hvem er dere to», og de reglene maa
    -- kunne finne sporsmalet uten aa gjette paa en id eller en tekst noen
    -- kan ha skrevet om.
    nokkel      VARCHAR(40) NULL,
    tekst       VARCHAR(255) NOT NULL,
    hjelpetekst VARCHAR(255) NULL,
    type        ENUM('envalg','flervalg','janei','tall','fritekst','avhuking')
                NOT NULL DEFAULT 'envalg',
    sortering   SMALLINT NOT NULL DEFAULT 0,
    aktiv       TINYINT(1) NOT NULL DEFAULT 1,
    -- Betinget sporsmal. «Hvem er dere to?» vises bare naar svaret paa
    -- antallet er noeyaktig 2. Uten dette ville det blitt stilt til alle,
    -- og veilederen fatt ett sporsmal mer enn den har i dag.
    vis_nar_id    BIGINT UNSIGNED NULL,
    vis_nar_verdi VARCHAR(80) NULL,
    -- Bare for typen «tall»: telleren gaar fra og til, og siste steg kan ha
    -- en egen tekst («Flere enn 12»).
    min_verdi   SMALLINT NULL,
    maks_verdi  SMALLINT NULL,
    maks_tekst  VARCHAR(60) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_nokkel (nokkel),
    KEY idx_rekke (sortering, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS veileder_svar (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sporsmal_id BIGINT UNSIGNED NOT NULL,
    tekst       VARCHAR(255) NOT NULL,
    sortering   SMALLINT NOT NULL DEFAULT 0,
    aktiv       TINYINT(1) NOT NULL DEFAULT 1,
    -- Hva svaret betyr. Samme ordliste som merkene paa kurset (migrasjon
    -- 065), saa anbefalingen kan sammenligne de to.
    passer_nivaa VARCHAR(60) NULL,
    passer_hvem  VARCHAR(80) NULL,
    metode       VARCHAR(20) NULL,
    varighet     VARCHAR(20) NULL,
    -- Peker rett paa ett kurs eller én side, slik det virker i dag.
    -- «SIDE:paintonpots» gaar til en side, alt annet er et kursnavn.
    mal          VARCHAR(80) NULL,
    begrunnelse  VARCHAR(255) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sporsmal (sporsmal_id, sortering, id),
    CONSTRAINT fk_svar_sporsmal FOREIGN KEY (sporsmal_id)
        REFERENCES veileder_sporsmal (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- De tre som finnes i dag, ord for ord.
--
-- INSERT IGNORE paa noekkelen: kjores migrasjonen om igjen, blir det som
-- staar der staaende. Ingen sletting av det verkstedet har skrevet.
INSERT IGNORE INTO veileder_sporsmal
    (nokkel, tekst, hjelpetekst, type, sortering, aktiv, min_verdi, maks_verdi, maks_tekst)
VALUES
    ('antall', 'Hvor mange er dere?', NULL, 'tall', 10, 1, 1, 12, 'Flere enn 12'),
    ('hvem',   'Hvem er dere to?',    NULL, 'envalg', 20, 1, NULL, NULL, NULL),
    ('hva',    'Hva vil du helst?',   NULL, 'envalg', 30, 1, NULL, NULL, NULL);

-- «Hvem er dere to?» vises bare naar antallet er noeyaktig 2.
UPDATE veileder_sporsmal v
   JOIN veileder_sporsmal a ON a.nokkel = 'antall'
    SET v.vis_nar_id = a.id, v.vis_nar_verdi = '2'
  WHERE v.nokkel = 'hvem' AND v.vis_nar_id IS NULL;

-- Svarene paa «Hvem er dere to?».
INSERT INTO veileder_svar (sporsmal_id, tekst, sortering, passer_hvem)
SELECT s.id, x.tekst, x.sortering, x.hvem
  FROM veileder_sporsmal s
  JOIN (SELECT 'Venner' AS tekst, 10 AS sortering, 'venner' AS hvem
        UNION ALL SELECT 'Kjæreste eller date', 20, 'par'
        UNION ALL SELECT 'Familie', 30, 'familie') x
 WHERE s.nokkel = 'hvem'
   AND NOT EXISTS (SELECT 1 FROM veileder_svar v WHERE v.sporsmal_id = s.id);

-- Svarene paa «Hva vil du helst?» — de tre som staar i kvRegler() i dag.
INSERT INTO veileder_svar (sporsmal_id, tekst, sortering, mal, metode, passer_nivaa)
SELECT s.id, x.tekst, x.sortering, x.mal, x.metode, x.nivaa
  FROM veileder_sporsmal s
  JOIN (SELECT 'Lære å dreie' AS tekst, 10 AS sortering,
               'Nybegynner dreiekurs' AS mal, 'dreiing' AS metode, 'nybegynner' AS nivaa
        UNION ALL SELECT 'Lage boller og fat', 20, 'Store fat kurs', 'handbygging', 'nybegynner'
        UNION ALL SELECT 'Male ferdig keramikk', 30, 'SIDE:paintonpots', 'maling', 'nybegynner') x
 WHERE s.nokkel = 'hva'
   AND NOT EXISTS (SELECT 1 FROM veileder_svar v WHERE v.sporsmal_id = s.id);

-- Tekstene som sto i kvRegler() i dag. De er skrevet av verkstedet og skal
-- ikke bli borte fordi lista flyttet seg.
UPDATE veileder_svar v
  JOIN veileder_sporsmal s ON s.id = v.sporsmal_id
   SET v.begrunnelse = CASE v.tekst
         WHEN 'Lære å dreie'        THEN 'To økter over to dager der du lærer å sentrere, dreie og trimme.'
         WHEN 'Lage boller og fat'  THEN 'Formiddagskurs i plateteknikk der du bygger store fat over form.'
         WHEN 'Male ferdig keramikk' THEN 'Mal ferdig keramikk — vi glaserer og brenner. Passer også for barn.'
         ELSE v.begrunnelse END
 WHERE s.nokkel = 'hva' AND v.begrunnelse IS NULL;

-- «Kjæreste eller date» ga Date Night, som en saerregel i koden. Naa staar
-- den som data, paa svaret, der den kan endres.
UPDATE veileder_svar v
  JOIN veileder_sporsmal s ON s.id = v.sporsmal_id
   SET v.mal = 'Date Night',
       v.begrunnelse = COALESCE(v.begrunnelse, 'En kveld ved dreieskiva for to, med noe godt i glasset.')
 WHERE s.nokkel = 'hvem' AND v.tekst = 'Kjæreste eller date' AND v.mal IS NULL;
