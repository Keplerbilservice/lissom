-- Sporingen fra nettleseren paa betalingen, saa kjoepet kan maales fra
-- serveren naar pengene er i havn (app/lib/maaling.php). JSON: GA4 client_id
-- og session_id, Metas _fbp/_fbc, IP og user-agent. Tom naar kunden ikke
-- har samtykket til maaling — da finnes ingen cookies aa ta vare paa.
--
-- Eieren, 21. september 2026, om Google/Meta: «er jeg optimalisert, eller
-- kunne det vaert bedre». Google Ads hadde ingen nylige konverteringer og
-- Meta 0 kjoep, fordi kjoepet bare ble maalt i nettleseren paa returen fra
-- Vipps — som ofte aldri laster paa mobil.

ALTER TABLE payments ADD COLUMN IF NOT EXISTS sporing TEXT NULL AFTER siste_payload;
