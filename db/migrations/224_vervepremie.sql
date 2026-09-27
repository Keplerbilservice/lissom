-- Vervepremie: verv en venn til aarsmedlemskap og faa timer i verkstedet.
--
-- Eieren, 27. september 2026: «admin vil legge ut paa min side for
-- medlemmer, vervepremie, lages som kampanje banner, verv en venn til
-- aarsmedlemskap og faa 5 timer til bruk i verkstedet, jeg maa kunne endre
-- antall timer de faar». Valgt: personlig vervelenke, premien kommer som en
-- timegave (medlemsgaver) naar vennens betaling er gjennomfort. Se
-- app/lib/verving.php.
--
-- Bryteren staar AV til eieren slaar den paa i Markedsfoering › Vervepremie.

INSERT IGNORE INTO content_blocks (nokkel, verdi) VALUES ('Vis/verving', 'nei');
INSERT IGNORE INTO innstillinger (nokkel, verdi) VALUES ('verving_timer', '5');

-- Den personlige koden i lenka. Lages foerste gang medlemmet ser banneret.
ALTER TABLE members
  ADD COLUMN IF NOT EXISTS verve_kode VARCHAR(16) NULL,
  ADD UNIQUE KEY IF NOT EXISTS uq_members_verve_kode (verve_kode);

-- Koden foelger innmeldingen gjennom turen innom Vipps.
ALTER TABLE medlemsordrer
  ADD COLUMN IF NOT EXISTS verve_kode VARCHAR(16) NULL;

-- Én rad per venn som ble vervet. UNIQUE paa vennen er sperren: webhook og
-- retur kan komme begge to, men premien gis bare én gang.
CREATE TABLE IF NOT EXISTS vervinger (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    verver_id        BIGINT UNSIGNED NOT NULL,
    venn_id          BIGINT UNSIGNED NOT NULL,
    subscription_id  BIGINT UNSIGNED NULL,
    plan             VARCHAR(64)     NOT NULL DEFAULT '',
    timer            INT             NOT NULL,
    gave_id          BIGINT UNSIGNED NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vervinger_venn (venn_id),
    KEY ix_vervinger_verver (verver_id),
    CONSTRAINT fk_vervinger_verver FOREIGN KEY (verver_id)
        REFERENCES members (id) ON DELETE CASCADE,
    CONSTRAINT fk_vervinger_venn FOREIGN KEY (venn_id)
        REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
