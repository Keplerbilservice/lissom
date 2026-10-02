-- Innholdet i et maanedstrekk lagres paa betalingen foer kallet til Vipps.
-- Et nytt forsoek bruker samme Idempotency-Key, og Vipps avviser samme
-- noekkel med annet innhold («idempotency-conflict»). Foer ble forfallet
-- regnet ut paa nytt neste natt, og et trekk som kanskje gikk, ble staaende
-- uten id. Trygg aa kjoere to ganger.
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS trekk_foresporsel TEXT NULL DEFAULT NULL
        COMMENT 'Noeyaktig innhold sendt til Vipps for trekket (JSON). Gjenbrukes ved nytt forsoek.';
