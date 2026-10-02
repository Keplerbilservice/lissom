-- L-3 (pengeflyt-revisjonen, eieren 2. oktober 2026): en timepakke som er
-- refundert i sin helhet, skal ikke lenger gi timer. Booking::refunderBetaling()
-- setter den «refundert»; Timepakke teller bare «betalt». Uten denne verdien
-- faller koden tilbake til «avbrutt». Ingen rader endres. Trygg aa kjoere to
-- ganger.
ALTER TABLE timepakker
    MODIFY status ENUM('venter','betalt','avbrutt','refundert') NOT NULL DEFAULT 'venter';
