-- Lissom — grunnskjema
-- MySQL 8 / MariaDB 10.5+. Kjøres med: php bin/migrate.php
--
-- Konvensjoner:
--   * Alle beløp lagres i ØRE som heltall. Aldri desimaltall på penger.
--   * Alle tidspunkt er UTC. Visning i norsk tid skjer i frontend.
--   * Tabeller som er omfattet av bokføringsloven (payments, orders, bookings,
--     gift_cards) slettes aldri — medlemmet anonymiseres i stedet.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Medlemmer og innlogging
-- ---------------------------------------------------------------------------

CREATE TABLE members (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vipps_sub             VARCHAR(191) NULL,
  navn                  VARCHAR(191) NOT NULL DEFAULT '',
  epost                 VARCHAR(191) NULL,
  telefon               VARCHAR(32)  NULL,
  rolle                 ENUM('medlem','admin') NOT NULL DEFAULT 'medlem',
  medlemskap_type       VARCHAR(64)  NULL,
  status                ENUM('ingen','prove','aktiv','oppsagt','pause') NOT NULL DEFAULT 'ingen',
  start_dato            DATE NULL,
  slutt_dato            DATE NULL,
  recurring_agreement_id VARCHAR(191) NULL,
  timer_per_mnd         INT NULL COMMENT 'NULL = fri tilgang',
  notat                 TEXT NULL COMMENT 'Internt, kun synlig i admin',
  anonymisert_at        DATETIME NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_members_vipps_sub (vipps_sub),
  UNIQUE KEY uq_members_agreement (recurring_agreement_id),
  KEY ix_members_epost (epost),
  KEY ix_members_telefon (telefon),
  KEY ix_members_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sesjoner. Vi lagrer SHA-256 av tokenet, aldri tokenet selv: lekker databasen,
-- kan ingen logge inn med innholdet.
CREATE TABLE sessions (
  token_hash  CHAR(64) NOT NULL,
  member_id   BIGINT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  siste_bruk  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATETIME NOT NULL,
  ip          VARBINARY(16) NULL,
  user_agent  VARCHAR(255) NULL,
  PRIMARY KEY (token_hash),
  KEY ix_sessions_member (member_id),
  KEY ix_sessions_expires (expires_at),
  CONSTRAINT fk_sessions_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kortlevd state for OIDC-innlogging (CSRF-vern i Vipps Login-flyten).
CREATE TABLE login_states (
  state       CHAR(64) NOT NULL,
  retur_url   VARCHAR(255) NOT NULL DEFAULT '/',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATETIME NOT NULL,
  PRIMARY KEY (state),
  KEY ix_login_states_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Kurs, events og drop-in
-- ---------------------------------------------------------------------------

CREATE TABLE courses (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug              VARCHAR(191) NOT NULL,
  tittel            VARCHAR(191) NOT NULL,
  type              ENUM('kurs','event','dropin') NOT NULL DEFAULT 'kurs',
  tema              VARCHAR(64) NULL COMMENT 'Filter i frontend: Dreiing, Plateteknikk, Events ...',
  beskrivelse       MEDIUMTEXT NULL,
  bekreftelse_tekst MEDIUMTEXT NULL COMMENT 'Legges inn i kvitteringsmailen',
  bilde             VARCHAR(255) NULL,
  pris_ore          INT UNSIGNED NOT NULL,
  mva_prosent       TINYINT UNSIGNED NOT NULL DEFAULT 25,
  kapasitet         SMALLINT UNSIGNED NOT NULL DEFAULT 8,
  sms_paaminnelse   TINYINT(1) NOT NULL DEFAULT 1,
  status            ENUM('kladd','publisert','avlyst') NOT NULL DEFAULT 'kladd',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_courses_slug (slug),
  KEY ix_courses_status_type (status, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- En økt er én dato. Et kveldskurs har én, et kurs over fire uker har fire.
CREATE TABLE course_sessions (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id         BIGINT UNSIGNED NOT NULL,
  start_tid         DATETIME NOT NULL,
  slutt_tid         DATETIME NULL,
  kapasitet         SMALLINT UNSIGNED NULL COMMENT 'NULL = arv fra kurset',
  status            ENUM('planlagt','fullt','avlyst','gjennomfort') NOT NULL DEFAULT 'planlagt',
  paaminnelse_sendt_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_sessions_course (course_id),
  KEY ix_sessions_start (start_tid),
  CONSTRAINT fk_sessions_course FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Betalinger
--
-- Opprettes FØR brukeren sendes til Vipps, slik at webhooken alltid har en rad
-- å slå opp i. vipps_reference er vår egen referanse (LIS-...), ikke Vipps sin.
-- ---------------------------------------------------------------------------

CREATE TABLE payments (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vipps_reference   VARCHAR(64) NOT NULL,
  type              ENUM('epayment','recurring_charge') NOT NULL DEFAULT 'epayment',
  formal            ENUM('booking','dropin','gavekort','ordre','medlemskap') NOT NULL,
  member_id         BIGINT UNSIGNED NULL,
  belop_ore         INT UNSIGNED NOT NULL,
  valuta            CHAR(3) NOT NULL DEFAULT 'NOK',
  status            ENUM('opprettet','venter','autorisert','betalt','avbrutt','feilet','refundert','delvis_refundert')
                    NOT NULL DEFAULT 'opprettet',
  refundert_ore     INT UNSIGNED NOT NULL DEFAULT 0,
  idempotency_key   CHAR(36) NOT NULL,
  vipps_psp_ref     VARCHAR(191) NULL,
  siste_payload     JSON NULL COMMENT 'Siste rå webhook/statussvar fra Vipps',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_reference (vipps_reference),
  UNIQUE KEY uq_payments_idempotency (idempotency_key),
  KEY ix_payments_status (status),
  KEY ix_payments_member (member_id),
  KEY ix_payments_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vipps kan sende samme webhook flere ganger. Denne tabellen gjør at vi
-- behandler hver hendelse nøyaktig én gang.
CREATE TABLE vipps_webhook_events (
  event_id      VARCHAR(191) NOT NULL,
  type          VARCHAR(128) NOT NULL,
  referanse     VARCHAR(64) NULL,
  payload       JSON NOT NULL,
  mottatt_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  behandlet_at  DATETIME NULL,
  feilmelding   TEXT NULL,
  PRIMARY KEY (event_id),
  KEY ix_webhook_behandlet (behandlet_at),
  KEY ix_webhook_referanse (referanse)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Bookinger og venteliste
-- ---------------------------------------------------------------------------

CREATE TABLE bookings (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id         BIGINT UNSIGNED NOT NULL,
  course_session_id BIGINT UNSIGNED NULL COMMENT 'NULL for kurs som bookes samlet',
  member_id         BIGINT UNSIGNED NULL,
  gjest_navn        VARCHAR(191) NOT NULL DEFAULT '',
  gjest_epost       VARCHAR(191) NULL,
  gjest_telefon     VARCHAR(32) NULL,
  antall            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  belop_ore         INT UNSIGNED NOT NULL,
  status            ENUM('reservert','betalt','avbestilt','refundert','ikke_mott') NOT NULL DEFAULT 'reservert',
  payment_id        BIGINT UNSIGNED NULL,
  folge_medlem      VARCHAR(191) NULL COMMENT 'Drop-in: navnet på medlemmet gjesten kommer sammen med',
  reservert_til     DATETIME NULL COMMENT 'Ubetalte reservasjoner frigis etter dette',
  avbestilt_at      DATETIME NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_bookings_course (course_id),
  KEY ix_bookings_session (course_session_id),
  KEY ix_bookings_member (member_id),
  KEY ix_bookings_status (status),
  KEY ix_bookings_reservert_til (reservert_til),
  CONSTRAINT fk_bookings_course FOREIGN KEY (course_id) REFERENCES courses (id),
  CONSTRAINT fk_bookings_payment FOREIGN KEY (payment_id) REFERENCES payments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE waitlist (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id         BIGINT UNSIGNED NOT NULL,
  course_session_id BIGINT UNSIGNED NULL,
  navn              VARCHAR(191) NOT NULL,
  epost             VARCHAR(191) NULL,
  telefon           VARCHAR(32) NULL,
  posisjon          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status            ENUM('venter','varslet','booket','utlopt','fjernet') NOT NULL DEFAULT 'venter',
  varslet_at        DATETIME NULL,
  frist_at          DATETIME NULL COMMENT 'Hvor lenge plassen holdes av etter varsling',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_waitlist_course_status (course_id, status, posisjon),
  CONSTRAINT fk_waitlist_course FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Gavekort
-- ---------------------------------------------------------------------------

CREATE TABLE gift_cards (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kode              VARCHAR(32) NOT NULL,
  opprinnelig_ore   INT UNSIGNED NOT NULL,
  saldo_ore         INT UNSIGNED NOT NULL,
  gyldig_til        DATE NOT NULL COMMENT 'Tre år fra kjøp',
  kjoper_navn       VARCHAR(191) NULL,
  kjoper_epost      VARCHAR(191) NULL,
  mottaker_epost    VARCHAR(191) NULL,
  hilsen            TEXT NULL,
  payment_id        BIGINT UNSIGNED NULL,
  status            ENUM('ubetalt','aktivt','brukt','utlopt','annullert') NOT NULL DEFAULT 'ubetalt',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_giftcards_kode (kode),
  KEY ix_giftcards_status (status),
  CONSTRAINT fk_giftcards_payment FOREIGN KEY (payment_id) REFERENCES payments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hvert uttak logges, slik at saldoen alltid kan regnes ut på nytt fra bunnen.
CREATE TABLE gift_card_uses (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  gift_card_id  BIGINT UNSIGNED NOT NULL,
  belop_ore     INT UNSIGNED NOT NULL,
  ref_type      ENUM('booking','ordre','medlemskap') NOT NULL,
  ref_id        BIGINT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_giftcarduses_card (gift_card_id),
  CONSTRAINT fk_giftcarduses_card FOREIGN KEY (gift_card_id) REFERENCES gift_cards (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Butikk
-- ---------------------------------------------------------------------------

CREATE TABLE products (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tittel        VARCHAR(191) NOT NULL,
  beskrivelse   TEXT NULL,
  bilde         VARCHAR(255) NULL,
  kategori      VARCHAR(64) NULL,
  pris_ore      INT UNSIGNED NOT NULL,
  mva_prosent   TINYINT UNSIGNED NOT NULL DEFAULT 25,
  lager         INT NULL COMMENT 'NULL = ikke lagerstyrt',
  kun_medlemmer TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Internbutikken på Min side',
  status        ENUM('kladd','publisert','utsolgt') NOT NULL DEFAULT 'kladd',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_products_status (status, kun_medlemmer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ordrenr           VARCHAR(32) NOT NULL,
  member_id         BIGINT UNSIGNED NULL,
  kunde_navn        VARCHAR(191) NOT NULL DEFAULT '',
  kunde_epost       VARCHAR(191) NULL,
  kunde_telefon     VARCHAR(32) NULL,
  sum_ore           INT UNSIGNED NOT NULL,
  status            ENUM('ny','betalt','klar','hentet','kansellert','refundert') NOT NULL DEFAULT 'ny',
  payment_id        BIGINT UNSIGNED NULL,
  hentemelding_sendt_at DATETIME NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_ordrenr (ordrenr),
  KEY ix_orders_status (status),
  KEY ix_orders_member (member_id),
  CONSTRAINT fk_orders_payment FOREIGN KEY (payment_id) REFERENCES payments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_lines (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id    BIGINT UNSIGNED NOT NULL,
  product_id  BIGINT UNSIGNED NULL,
  tittel      VARCHAR(191) NOT NULL COMMENT 'Kopieres inn — produktet kan endre navn senere',
  antall      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  pris_ore    INT UNSIGNED NOT NULL COMMENT 'Stykkpris på kjøpstidspunktet',
  PRIMARY KEY (id),
  KEY ix_orderlines_order (order_id),
  CONSTRAINT fk_orderlines_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- «Laget av medlemmer». Betaling skjer P2P til selgers eget Vippsnummer —
-- Lissom formidler bare, og rører aldri pengene.
CREATE TABLE member_sales (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id     BIGINT UNSIGNED NOT NULL,
  tittel        VARCHAR(191) NOT NULL,
  beskrivelse   TEXT NULL,
  bilde         VARCHAR(255) NULL,
  pris_ore      INT UNSIGNED NOT NULL,
  vippsnummer   VARCHAR(16) NOT NULL,
  status        ENUM('til_godkjenning','publisert','avvist','solgt') NOT NULL DEFAULT 'til_godkjenning',
  avvist_grunn  VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_membersales_status (status),
  CONSTRAINT fk_membersales_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Verkstedbruk
-- ---------------------------------------------------------------------------

CREATE TABLE checkins (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id   BIGINT UNSIGNED NOT NULL,
  inn_tid     DATETIME NOT NULL,
  ut_tid      DATETIME NULL,
  minutter    INT UNSIGNED NULL COMMENT 'Settes ved utstempling',
  kilde       ENUM('minside','admin','automatisk') NOT NULL DEFAULT 'minside',
  PRIMARY KEY (id),
  KEY ix_checkins_member_inn (member_id, inn_tid),
  CONSTRAINT fk_checkins_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Oppsummering per måned. Fylles av cron, slik at Min side slipper å regne
-- gjennom alle innstemplinger ved hvert sidevisning.
CREATE TABLE hour_usage (
  member_id       BIGINT UNSIGNED NOT NULL,
  periode         CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  brukte_minutter INT UNSIGNED NOT NULL DEFAULT 0,
  maks_minutter   INT UNSIGNED NULL COMMENT 'NULL = fri tilgang',
  oppdatert_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (member_id, periode),
  CONSTRAINT fk_hourusage_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Innhold, varsling og sporing
-- ---------------------------------------------------------------------------

-- Tekstene admin-panelet redigerer. Samme nøkkelstruktur som `innhold` i
-- designfilen, f.eks. 'forside/heroTittel'.
CREATE TABLE content_blocks (
  nokkel        VARCHAR(191) NOT NULL,
  verdi         MEDIUMTEXT NULL,
  endret_av     BIGINT UNSIGNED NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (nokkel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_templates (
  navn          VARCHAR(64) NOT NULL,
  kanal         ENUM('epost','sms','epost_sms') NOT NULL,
  emne          VARCHAR(191) NULL,
  tekst         MEDIUMTEXT NOT NULL,
  aktiv         TINYINT(1) NOT NULL DEFAULT 1,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (navn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kø og logg i én tabell. Alt som skal sendes legges her først, og cron tømmer
-- køen. Da overlever varsler at webhotellet er tregt eller SMTP svarer sent,
-- og du kan alltid svare på «fikk jeg kvittering?».
CREATE TABLE notifications (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mal           VARCHAR(64) NULL,
  kanal         ENUM('epost','sms') NOT NULL,
  mottaker      VARCHAR(191) NOT NULL,
  emne          VARCHAR(191) NULL,
  tekst         MEDIUMTEXT NOT NULL,
  status        ENUM('ko','sendt','feilet') NOT NULL DEFAULT 'ko',
  forsok        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  feilmelding   VARCHAR(255) NULL,
  ref_type      VARCHAR(32) NULL,
  ref_id        BIGINT UNSIGNED NULL,
  send_etter    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sendt_at      DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_notifications_ko (status, send_etter),
  KEY ix_notifications_ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hvem gjorde hva i admin. Uunnværlig den dagen noe er endret og ingen husker.
CREATE TABLE audit_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id     BIGINT UNSIGNED NULL,
  handling      VARCHAR(64) NOT NULL,
  objekt_type   VARCHAR(32) NULL,
  objekt_id     BIGINT UNSIGNED NULL,
  detaljer      JSON NULL,
  ip            VARBINARY(16) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_audit_member (member_id),
  KEY ix_audit_objekt (objekt_type, objekt_id),
  KEY ix_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enkel ratebegrensning uten Redis: én rad per nøkkel per tidsvindu.
CREATE TABLE rate_limits (
  nokkel      VARCHAR(160) NOT NULL,
  vindu_start DATETIME NOT NULL,
  antall      INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (nokkel, vindu_start),
  KEY ix_ratelimits_vindu (vindu_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
