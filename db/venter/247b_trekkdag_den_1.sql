-- 247b: trekkdag den 1. for avtaler som alt loeper.
--
-- VENTER PAA EIERENS JA. Ligger i db/venter/, ikke i db/migrations/, saa
-- «Kjoer oppdateringer» tar den ikke. Naar eieren sier ja: flytt fila til
-- db/migrations/247b_trekkdag_den_1.sql (247 er kolonnen forste_trekk_sjekket).
--
-- Eieren, 2. oktober 2026: alle faste trekk den 1. Nye avtaler faar det i
-- koden (Medlemskap::TREKK_DAG). Denne gjelder dem som alt loeper med en
-- annen trekkdag.
--
-- Alternativ 1 (anbefalt): bare trekkdagen settes til 1. neste_trekk staar.
-- Trekket som alt er planlagt (og varslet) gaar som foer; trekket etter
-- havner den 1. Eksempel: trekkdag 15, neste trekk 15. oktober (gjelder
-- oktober) -> 1. november (gjelder november). Ingen maaned trekkes to ganger:
-- noekkelen er avtale + maaned, og hver maaned har fortsatt ett trekk.
-- Ingen trekkes tidligere enn varslet.
--
-- Trygg aa kjoere flere ganger.

UPDATE subscriptions
   SET trekk_dag = 1
 WHERE status = 'aktiv'
   AND COALESCE(vipps_agreement_id, '') <> ''
   AND (trekk_dag IS NULL OR trekk_dag <> 1);
