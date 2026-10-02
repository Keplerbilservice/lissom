-- L-2 og L-3 (pengeflyt-revisjonen, eieren 2. oktober 2026). Ingen rader
-- endres, ingenting fjernes. Trygg aa kjoere to ganger.
--
-- 1) En timepakke som er refundert i sin helhet, skal ikke lenger gi timer.
--    Booking::refunderBetaling() setter den «refundert»; Timepakke teller bare
--    «betalt». Uten denne verdien faller koden tilbake til «avbrutt».
ALTER TABLE timepakker
    MODIFY status ENUM('venter','betalt','avbrutt','refundert') NOT NULL DEFAULT 'venter';

-- 2) Gavekortuttaket knyttes til betalingen det ble trukket for. Sporet
--    (ref_type/ref_id) peker paa bookingen eller ordren; to betalinger paa
--    samme booking — en annullert og en ny — kunne da ikke skilles, og et
--    uttak som var gitt tilbake, sperret det neste trekket. Eldre rader faar
--    NULL og finnes paa sporet som foer.
ALTER TABLE gift_card_uses
    ADD COLUMN IF NOT EXISTS payment_id BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Betalingen uttaket ble trukket for (migrasjon 245)';
ALTER TABLE gift_card_uses
    ADD INDEX IF NOT EXISTS ix_giftcarduses_payment (payment_id);
