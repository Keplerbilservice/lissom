-- Fokuspunktet til Date Night-bildet på kurskortene.
--
-- Eieren, 12. oktober 2026: Date Night-bildet på events-kortet ble klippet
-- feil. Kortbildene (16:10) bruker nå fokuspunktet fra bilde_fokus, som
-- butikken og kursoversikten alt gjør. «50% 15%» flytter utsnittet opp, så
-- ansiktene og dreieskiva vises.
--
-- Tåler at tabellen mangler (samme oppsett som migrasjon 051), og å
-- kjøres to ganger.

CREATE TABLE IF NOT EXISTS bilde_fokus (
    fil        VARCHAR(191) NOT NULL PRIMARY KEY,
    fokus      VARCHAR(24)  NOT NULL DEFAULT '50% 50%',
    endret_av  INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO bilde_fokus (fil, fokus) VALUES ('uploads_foto-datenight.jpg', '50% 15%')
ON DUPLICATE KEY UPDATE fokus = VALUES(fokus);
