-- Verkstedets lager i handlelista.
--
-- Eieren, 4. oktober 2026 (GO): naar en vare naar min, skal den «legge seg i
-- den interne handlelisten som vi velger når skal sendes ut på epost» —
-- automatisk, med antallet som fyller opp til maks (min 5, maks 25: 20 stk).
--
-- Linjene i handlelista hoerte alltid til et medlem. Verkstedets egne linjer
-- har ingen: member_id = NULL. De staar i samlebestillingen under
-- leverandoeren som «Verkstedets lager», faar sin del av frakten, gaar med i
-- e-posten naar admin trykker «Bestill», og faar aldri et Vipps-krav. Se
-- Lager::tilHandlelista() og api/admin/handlelister.php.
--
-- Trygg aa kjoere to ganger.

ALTER TABLE handleliste_linjer
    MODIFY member_id BIGINT UNSIGNED NULL COMMENT 'NULL = verkstedets eget lager (Lager::tilHandlelista)';
