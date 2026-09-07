-- Innmeldingen blir en ordre paa serveren, ikke en tanke i nettleseren.
--
-- Eieren meldte seg inn paa Aarsmedlemskap 7. september 2026 og fikk «Proev
-- Lissom, kr 990, 10 timer, 30 dager». Grunnen sto i skjermkoden:
--
--     return p.some(x => x.navn === v) ? v : (p[0] ? p[0].navn : '');
--
-- Var valget borte, ble det FOERSTE medlemskapet i lista kjopt i stedet for
-- at noen stoppet. Foerste plan er «Proev Lissom», og den har ikke fast
-- trekk — derfor kom det ingen avtale aa godkjenne, bare en vanlig betaling.
-- Det er det samme som skjedde med Eirin.
--
-- Valget forsvant fordi innmeldingen krevde innlogging foerst: kunden ble
-- sendt til Vipps, og alt som laa i nettleserens minne var borte naar hun kom
-- tilbake. Paa mobil kan turen innom Vipps-appen gi en ny fane, der ogsaa
-- sessionStorage er tomt.
--
-- Naa lages raden her FOER noen forlater sida. Noekkelen foelger med i
-- adressen resten av veien, og planen leses herfra — aldri fra nettleseren.

CREATE TABLE IF NOT EXISTS medlemsordrer (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Noekkelen som staar i adressen: /meld-inn/<token>
    token           CHAR(32)        NOT NULL,
    -- Medlemskapet kunden trykket paa. Ingen standardverdi med vilje:
    -- mangler den, skal ingenting kunne selges.
    plan            VARCHAR(64)     NOT NULL,
    -- Prisen slik den sto da hun trykket. Endrer verkstedet prisen mens hun
    -- staar i Vipps, skal hun ha den hun saa.
    pris_ore        INT UNSIGNED    NOT NULL,
    betaling        ENUM('trekk','engang') NOT NULL DEFAULT 'trekk',
    -- Settes naar vi vet hvem hun er. Kan staa tom til hun kommer fra Vipps.
    medlem_id       BIGINT UNSIGNED     NULL,
    navn            VARCHAR(191)    NOT NULL DEFAULT '',
    epost           VARCHAR(191)        NULL,
    telefon         VARCHAR(32)         NULL,
    erfaring        TEXT                NULL,
    melding         TEXT                NULL,
    vilkaar         VARCHAR(32)         NULL,
    status          ENUM('ny','apnet','fullfort','avbrutt') NOT NULL DEFAULT 'ny',
    subscription_id BIGINT UNSIGNED     NULL,
    opprettet       DATETIME        NOT NULL DEFAULT current_timestamp(),
    utloper         DATETIME        NOT NULL,
    apnet_at        DATETIME            NULL,
    fullfort_at     DATETIME            NULL,
    PRIMARY KEY (id),
    UNIQUE KEY token (token),
    KEY medlem (medlem_id),
    KEY status_utloper (status, utloper)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
