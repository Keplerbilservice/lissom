-- Oppsett eieren kan endre selv, uten aa redigere en fil paa serveren.
--
-- Alt hemmelig har til naa ligget i app/secrets.php, utenfor webroten. Det er
-- riktig for det som settes én gang av den som setter opp nettstedet — men
-- innloggingen til e-postkontoen og til SMS-leverandoeren er ikke det. Den maa
-- kunne byttes den dagen passordet endres, av den som eier kontoen, uten aa
-- gaa veien om FTP eller om meg. Slik det har vaert, har e-post og SMS staatt
-- uvirksomt fordi det ikke fantes noen vei til aa skru det paa.
--
-- secrets.php gjelder fortsatt foerst. Staar noekkelen der, er det den som
-- teller, og denne tabellen roerer den ikke. Det er altsaa ikke to steder aa
-- lete: det er ett sted med en reserve under.
--
-- Ingen offentlig endepunkt leser herfra. content_blocks var uaktuell — den
-- serveres til alle som ber om den.

CREATE TABLE IF NOT EXISTS innstillinger (
    nokkel      VARCHAR(64)  NOT NULL PRIMARY KEY,
    verdi       TEXT         NULL,
    endret_av   INT UNSIGNED NULL,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
