-- Skisser: tavler der verkstedet, medlemmer og kursdeltakere tegner med
-- fingeren eller pennen, legger inn bilder og skriver notater.
--
-- godkjent av eieren: 2026-09-30
--
-- Eieren, 30. september 2026: «jeg vil ha et skisseprogram, dette skal være
-- tilgjengelig i admin, men admin må også kunne velge å vise det, da må
-- bryter ligge på synlighet … man kan også laste opp bilder her, tegne med
-- fingeren og ta notater». Medlemmer og deltakere kan tegne selv; egne
-- tavler ser bare de selv og admin. Admin kan dele sine tavler.
--
-- Den første modulen (eieren samme dag: «moduler nå»). Tabellene brukes bare
-- av app/lib/skisser.php og de to API-ene. Se den fila for reglene.
--
--   skisser           én tavle: hvem som eier den, og hvem den er delt med
--   skisse_sider      innholdet, én rad per side, som JSON fra tegneflaten
--   skisse_versjoner  de siste versjonene av hver side, for «gå tilbake»
--   skisse_bilder     bildene som er lagt på en tavle, så tilgangen kan
--                     sjekkes på hver forespørsel

CREATE TABLE IF NOT EXISTS skisser (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    eier_id        BIGINT UNSIGNED NULL COMMENT 'members.id — admin eller medlemmet som lagde den',
    tittel         VARCHAR(120) NOT NULL DEFAULT 'Ny tavle',
    delt_medlemmer TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Bare admins tavler kan deles',
    delt_deltakere TINYINT(1) NOT NULL DEFAULT 0,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_eier (eier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS skisse_sider (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    skisse_id  BIGINT UNSIGNED NOT NULL,
    nr         SMALLINT UNSIGNED NOT NULL,
    data       MEDIUMTEXT NOT NULL COMMENT 'JSON fra tegneflaten (Fabric toJSON)',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_side (skisse_id, nr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS skisse_versjoner (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    side_id    BIGINT UNSIGNED NOT NULL,
    data       MEDIUMTEXT NOT NULL,
    lagret_av  BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_side (side_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS skisse_bilder (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    skisse_id     BIGINT UNSIGNED NOT NULL,
    fil           VARCHAR(64) NOT NULL COMMENT 'Filnavnet Bilder::taImot() ga',
    lastet_opp_av BIGINT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_skisse (skisse_id),
    UNIQUE KEY uniq_fil (fil)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bryterne i ⊙ Synlighet → Min side. AV fra start: eieren slår dem på når
-- han har sett funksjonen. Selve modulen (Vis/skisser) står på uten rad.
INSERT INTO content_blocks (nokkel, verdi)
SELECT 'Vis/skissermedlemmer', 'nei' FROM (SELECT 1) AS d
 WHERE NOT EXISTS (SELECT 1 FROM content_blocks WHERE nokkel = 'Vis/skissermedlemmer');

INSERT INTO content_blocks (nokkel, verdi)
SELECT 'Vis/skisserdeltakere', 'nei' FROM (SELECT 1) AS d
 WHERE NOT EXISTS (SELECT 1 FROM content_blocks WHERE nokkel = 'Vis/skisserdeltakere');
