-- Godkjenningslenka lever i ti minutter. Vår egen skal leve i fjorten dager.
--
-- Eieren, 6. september: «men det fungerer ikke, linken virker ikke, så noe er
-- feil, du må gjøre dette på en annen måte».
--
-- Vipps sin dokumentasjon: «By default, a user has a total of 10 minutes to
-- accept a payment. If the user doesn't complete the payment within this time
-- window, the payment request will expire. The EXPIRED state is a final
-- state.» Avtalen står PENDING til hun godkjenner — ellers blir den EXPIRED.
--
-- Alt verkstedet har sendt til Eirin har dermed vært dødt før hun rakk å
-- trykke: lenka ble kopiert fra admin, sendt på Messenger, og åpnet minutter
-- senere. Purringen cron sender dag 1 og dag 3 sendte den SAMME adressen, som
-- da var over et døgn gammel.
--
-- Nå sender vi ikke Vipps-adressen. Vi sender vår egen — lissom.no/godkjenn/…
-- — og lager Vipps-avtalen først i det hun trykker. Da er den sekunder
-- gammel hver gang, uansett hvor lenge meldingen har ligget.
--
-- Nøkkelen er en engangsnøkkel per medlem og plan. Den utløper etter fjorten
-- dager, og settes som brukt når avtalen er godkjent.
CREATE TABLE IF NOT EXISTS avtale_lenker (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  token      CHAR(32)    NOT NULL,
  member_id  INT         NOT NULL,
  plan       VARCHAR(80) NOT NULL,
  opprettet  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  utloper    DATETIME    NOT NULL,
  brukt_at   DATETIME    NULL DEFAULT NULL,
  -- Purringa hoerte for til subscriptions-raden. Den raden finnes ikke lenger
  -- for hun har trykt, saa telleverket foelger noekkelen i stedet.
  paaminnet_at     DATETIME NULL DEFAULT NULL,
  paaminnet_antall TINYINT  NOT NULL DEFAULT 0,
  UNIQUE KEY token (token),
  KEY medlem (member_id, utloper)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
