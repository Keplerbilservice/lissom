-- Gaver fra verkstedet til medlemmene.
--
-- Skjermen «Gi gave» i admin fylte ut et skjema, sa «Gaven er sendt til alle
-- medlemmer» og la den i nettleseren til den som trykket. Monica saa altsaa
-- sin egen gave paa sin egen Min side; ingen andre fikk noen ting. Kortet
-- «Ta med en venn» sto samtidig hos alle medlemmer uansett — det var fast
-- tekst, ikke en gave noen hadde gitt.
--
-- Én rad per gave. member_id NULL betyr «alle medlemmer»: det er én gave, gitt
-- til gruppa, ikke én rad per medlem. Da kan den ogsaa trekkes tilbake i ett
-- grep, og et nytt medlem som kommer til i loepet av maaneden ser den ogsaa.
--
-- Hva gaven er verdt, staar her som tall og tekst. Den utloeser ingen
-- betaling og ingen timer av seg selv — skjermen lover et kort paa Min side,
-- og det er det den gir. Skal et gavekort her bli et ekte gavekort som kan
-- brukes i kassa, er det en avgjoerelse verkstedet maa ta foerst.
--
-- «gyldig_til» er siste dag gaven vises. Skjermen sier «ut inneværende
-- maaned», og datoen regnes ut naar gaven lagres.

CREATE TABLE IF NOT EXISTS medlemsgaver (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id    BIGINT UNSIGNED NULL COMMENT 'NULL = alle medlemmer',
    type         ENUM('venn','timer','gavekort') NOT NULL DEFAULT 'venn',
    timer        INT NULL COMMENT 'Antall timer naar type = timer',
    belop_ore    INT NULL COMMENT 'Beloep naar type = gavekort',
    hilsen       TEXT NULL,
    gyldig_til   DATE NOT NULL,
    status       ENUM('aktiv','trukket') NOT NULL DEFAULT 'aktiv',
    gitt_av      BIGINT UNSIGNED NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_gave_medlem (member_id, status, gyldig_til),
    CONSTRAINT fk_gave_medlem FOREIGN KEY (member_id)
        REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hvem som har brukt en gave som gjelder alle. Uten denne ville «Ta med en
-- venn» kunne loeses inn saa mange ganger man orket, og verkstedet ville ikke
-- se hvem som hadde brukt sin.
CREATE TABLE IF NOT EXISTS medlemsgave_bruk (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    gave_id     BIGINT UNSIGNED NOT NULL,
    member_id   BIGINT UNSIGNED NOT NULL,
    beskjed     TEXT NULL COMMENT 'Det medlemmet skrev da de loeste den inn',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gave_medlem (gave_id, member_id),
    CONSTRAINT fk_bruk_gave FOREIGN KEY (gave_id)
        REFERENCES medlemsgaver (id) ON DELETE CASCADE,
    CONSTRAINT fk_bruk_medlem FOREIGN KEY (member_id)
        REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
