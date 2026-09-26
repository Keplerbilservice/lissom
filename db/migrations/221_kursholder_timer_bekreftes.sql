-- Kursholderen paa Min side: stempling, forslag fra kursets lengde, bekreftelse,
-- og lønn eller timer. Eieren, 26. september 2026 (GO paa skissen).
--
-- - kursholdere.betaling: «lonn» (timelisten under Økonomi) eller «timer»
--   (bekreftet tid legges til verkstedtimene, som dugnad).
-- - kursholder_timer faar session_id (hvilken kursdato), status (forslag /
--   bekreftet), kilde (manuell / lengde / stemplet) og inn/ut-tid. Radene som
--   finnes fra foer er ført for haand av verkstedet: bekreftet og manuell.

ALTER TABLE kursholdere
    ADD COLUMN IF NOT EXISTS betaling ENUM('lonn','timer') NOT NULL DEFAULT 'lonn'
        COMMENT 'Lønn (timelisten) eller timer (verkstedtimene)';

ALTER TABLE kursholder_timer
    ADD COLUMN IF NOT EXISTS session_id BIGINT UNSIGNED NULL COMMENT 'Kursdatoen timene gjelder',
    ADD COLUMN IF NOT EXISTS status ENUM('forslag','bekreftet') NOT NULL DEFAULT 'bekreftet',
    ADD COLUMN IF NOT EXISTS kilde ENUM('manuell','lengde','stemplet') NOT NULL DEFAULT 'manuell',
    ADD COLUMN IF NOT EXISTS inn_tid DATETIME NULL COMMENT 'Stemplet inn (UTC)',
    ADD COLUMN IF NOT EXISTS ut_tid DATETIME NULL COMMENT 'Stemplet ut (UTC)';

ALTER TABLE kursholder_timer
    ADD UNIQUE KEY IF NOT EXISTS ux_kursholder_okt (kursholder_id, session_id);
