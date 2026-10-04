-- Nettbutikken og Internt: to brytere per vare, felles lager.
--
-- Eieren, 4. oktober 2026 (GO): «enkelt legge ut produkter jeg skal selge, og
-- enkelt legge ut til intern bruk ... så vil det være de samme varene som blir
-- tilgjengelig og de må jeg ha lagerstyring på, min og maks og varsling».
--
-- Foer dette var en vare ENTEN i nettbutikken (kun_medlemmer = 0) ELLER
-- internt (kun_medlemmer = 1). Naa:
--
--   kun_medlemmer   betyr «Internt»: varen er i internbutikken for medlemmene.
--                   Navnet staar, saa alt som leser det (gamle admin, kassa,
--                   handlelista) virker som foer.
--   i_nettbutikk    ny: 1 = i nettbutikken, 0 = ikke. NULL = som foer: i
--                   nettbutikken naar varen ikke er internt.
--
-- En vare kan ha begge. Lageret (products.lager) er det samme uansett hvor
-- varen selges. Alle eksisterende varer faar NULL, saa kundesiden ser lik ut
-- etter oppdateringen; bryteren settes foerst naar varen lagres i Butikk.
-- Se Lager::iNettbutikkSql(). Trygg aa kjoere to ganger.

ALTER TABLE products
    ADD COLUMN IF NOT EXISTS i_nettbutikk TINYINT(1) NULL DEFAULT NULL
        COMMENT 'I nettbutikken: 1/0. NULL = som foer (naar kun_medlemmer = 0). kun_medlemmer = Internt.';
