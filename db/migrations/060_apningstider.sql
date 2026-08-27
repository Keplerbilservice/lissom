-- Aapningstider som overstyrer det kursene sier.
--
-- Footeren har tre faste linjer skrevet inn i koden: «Verkstedet — etter
-- avtale», «Kurs og events — se datoer under kurs», «Medlemmer — dognaapent».
-- De kan ikke redigeres noe sted, og de sier ikke naar det faktisk er noen
-- her.
--
-- Naar det gaar et kurs, er verkstedet aapent for dem som gaar paa det. Det
-- kan regnes av kursdatoene, og det er bestilt. Men det maa kunne
-- overstyres: en helligdag, en ferieuke, en dag verkstedet er stengt selv om
-- det staar et kurs i kalenderen.
--
-- En rad her gaar foran alt som regnes ut for den dagen.

CREATE TABLE IF NOT EXISTS apningstider (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dato       DATE NOT NULL,
    -- Stengt gaar foran alt annet. Da betyr fra og til ingenting.
    stengt     TINYINT(1) NOT NULL DEFAULT 0,
    fra        TIME NULL,
    til        TIME NULL,
    merknad    VARCHAR(191) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_apning_dato (dato)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
