-- Den tomme avtale-ID-en sperret alle engangsbetalinger etter den forste.
--
-- «subscriptions.vipps_agreement_id» har UNIQUE KEY uq_subs_agreement.
-- Medlemskap::startEngangs() satte den til tom streng i stedet for NULL. To
-- rader med '' er to like verdier, og den andre ble avvist:
--
--   SQLSTATE[23000]: Integrity constraint violation: 1062
--   Duplicate entry '' for key 'uq_subs_agreement'
--
-- Eieren fikk den 2. september da han skulle betale for medlemskapet sitt.
--
-- En engangsbetaling har ingen avtale i Vipps, og skal staa uten. NULL
-- teller ikke som en verdi i en unik noekkel, saa flere rader kan staa slik.
--
-- Koden setter NULL fra naa. Denne filen rydder raden som alt staar med ''
-- og sperrer for alle andre. Det kan bare vaere én — det er nettopp det den
-- unike noekkelen har sorget for.
UPDATE subscriptions
   SET vipps_agreement_id = NULL
 WHERE vipps_agreement_id = '';
