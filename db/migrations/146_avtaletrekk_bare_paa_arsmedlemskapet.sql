-- Avtaletrekk i Vipps hører til ett medlemskap, ikke flere.
--
-- Eieren, 6. september 2026: «årsmedlemskap er eneste veie avtaletrekk, dette
-- er eneste stedet avtaletrekk skal være i bruk».
--
-- Bakgrunnen: Eirin fikk en helt vanlig Vipps-betaling i stedet for en avtale
-- å godkjenne i appen — «hun fikk vipps, ingen godkjennelse i vipps, bare en
-- helt vanlig måte å betale med vipps». Det er «krever_fast_trekk» på planen
-- som avgjør hvilken av de to veiene som brukes, og feltet har aldri hatt en
-- eneste knapp i admin. Verkstedet kunne verken se hva som sto eller endre
-- det.
--
-- Her settes dataene slik regelen sier. Haken kommer samtidig inn i
-- planskjemaet, så det kan leses og styres derfra heretter.
UPDATE membership_plans
   SET krever_fast_trekk = CASE WHEN navn = 'Årsmedlemskap' THEN 1 ELSE 0 END;
