-- Svar paa en henvendelse.
--
-- Foresporslene kunne bare merkes som besvart. Selve svaret skulle skrives i
-- e-postklienten, utenfor systemet. I admin kunne man derfor sende en ny
-- melding til alle medlemmer, eller huke av at man hadde svart — men ikke
-- faktisk svare den som spurte. Og den som spurte saa aldri svaret der de
-- skrev.
--
-- Én rad per svar. En henvendelse kan faa flere: et oppfoelgingsspoersmaal
-- hoerer til samme samtale, ikke til en ny.
--
-- «sendt» sier om varselet gikk ut. Gikk det ikke, staar svaret likevel her,
-- og det er bedre enn at det forsvinner: da kan det sendes paa nytt naar
-- e-postoppsettet er i orden.

CREATE TABLE IF NOT EXISTS foresporsel_svar (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    enquiry_id    BIGINT UNSIGNED NOT NULL,
    member_id     BIGINT UNSIGNED NULL COMMENT 'Den i verkstedet som svarte',
    tekst         TEXT NOT NULL,
    sendt_epost   TINYINT(1) NOT NULL DEFAULT 0,
    sendt_sms     TINYINT(1) NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_svar_foresporsel (enquiry_id, id),
    CONSTRAINT fk_svar_foresporsel FOREIGN KEY (enquiry_id)
        REFERENCES enquiries (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
