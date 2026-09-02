-- Delt betaling over disk, og to slag gavekort.
--
-- Eieren: «hva i huleste gjor jeg naar noen skal betale paa stedet og jeg maa
-- registrere salget manuelt, og de har et gavekort, men ikke paa hele
-- beloepet? jeg maa kunne taste inn gavekort, kontant og vipps, de maa kunne
-- dele opp.»
--
-- Kassa kunne ta imot ett beloep med én maate: Kontant eller Vipps. Gavekort
-- fantes ikke der i det hele tatt, enda kolonnene som trengs har ligget der
-- siden migrasjon 040 og virket paa nettsida hele tiden. Betalte noen 300
-- kontant og resten i Vipps, matte det slaas inn som to salg — og da stemte
-- verken ordrenummeret, kvitteringen eller lageret.

-- ---------------------------------------------------------------------------
-- Flere betalinger paa det samme salget
-- ---------------------------------------------------------------------------
--
-- «orders.payment_id» finnes fra for, men den peker én vei og holder bare én
-- rad: den betalingen som gjorde opp ordren. Et delt oppgjor er flere
-- betalinger paa den samme ordren, og det krever det motsatte.
--
-- Dette er ikke et nytt monster. Migrasjon 084 la «payments.booking_id» inn av
-- noeyaktig samme grunn, og skrev det ned: «flere betalinger kan hore til den
-- samme paameldingen (et delbeloep, resten senere)». Her er den samme
-- koblingen for et kassesalg.
--
-- Begge beholdes, og de sier hver sin ting:
--   orders.payment_id   = betalingen som staar som salgets hovedrad (uendret)
--   payments.order_id   = alle delene salget ble gjort opp med
ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS order_id BIGINT UNSIGNED NULL
  COMMENT 'Salget betalingen gjelder. Flere rader kan peke paa det samme.'
  AFTER member_id;

CREATE INDEX IF NOT EXISTS ix_payments_order ON payments (order_id);

-- Betalingene som alt finnes, kobles til ordren sin. Uten dette ville
-- historikken paa et gammelt salg staatt tom: raden finnes, men ingen visste
-- hvilken ordre den hoerte til andre veien. Koblingen leses ut av
-- «orders.payment_id», som har pekt riktig hele tiden.
UPDATE payments p
   JOIN orders o ON o.payment_id = p.id
    SET p.order_id = o.id
  WHERE p.order_id IS NULL;

-- ---------------------------------------------------------------------------
-- Gavekort: kjopt eller gitt
-- ---------------------------------------------------------------------------
--
-- Eieren: «jeg vil ha to typer gavekort, et som er ting vi gir ut som ikke
-- skal skatteberegnes og et som faktisk er kjopt av oss.»
--
-- Regnskapsmessig er de ikke det samme, og det er hele poenget:
--
--   kjopt  Noen har betalt for kortet. Pengene er inne, men tjenesten er ikke
--          levert. Salget er gjeld — «regnskap_konto_gavekort» — og blir
--          inntekt forst den dagen kortet loeses inn.
--
--   gitt   Verkstedet ga det bort. Ingen penger kom inn, og det finnes ingen
--          gjeld aa foere. Ved utstedelse skjer det ingenting i regnskapet.
--          Naar kortet loeses inn, er tjenesten levert og skal inntektsfoeres
--          — men motposten er en kostnad, ikke gjeld som trekkes ned.
--          Foert mot gjeldskontoen ville den gaatt i minus av kort ingen har
--          betalt for.
--
-- Kortet selv er likt: samme nummererte kode, samme saldo, samme uttakslogg.
-- Det er bare motkontoen som skiller dem.
ALTER TABLE gift_cards
  ADD COLUMN IF NOT EXISTS opprinnelse ENUM('kjopt','gitt') NOT NULL DEFAULT 'kjopt'
  COMMENT 'kjopt = noen betalte for det (gjeld). gitt = verkstedet ga det bort (kostnad).'
  AFTER status;

-- Hvem som utstedte det over disk. NULL paa alt som ble kjopt paa nettsida,
-- der ingen trykker paa noe.
ALTER TABLE gift_cards
  ADD COLUMN IF NOT EXISTS utstedt_av BIGINT UNSIGNED NULL
  COMMENT 'Admin som utstedte kortet i verkstedet. NULL = kjopt paa nett.'
  AFTER opprinnelse;

-- Regnskapet leter etter kortene som ble gitt bort naar det skal finne
-- motkontoen. Uten indeksen leses hele tabellen for hver dag i oppgjoret.
CREATE INDEX IF NOT EXISTS ix_giftcards_opprinnelse ON gift_cards (opprinnelse);
