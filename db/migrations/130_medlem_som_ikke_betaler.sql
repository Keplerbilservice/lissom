-- Medlemmer som ikke skal betale.
--
-- Eieren, 2. september: «jeg vil ogsaa ha mulighet til aa opprette et medlem,
-- og tildele de et av mine medlemskap uten at de maa betale».
--
-- Det gikk fra for — «Nytt medlem» i admin oppretter medlemmet uten avtale og
-- uten betalingskrav. Det som manglet var aa kunne SI det. Naar medlemslista
-- naa skal vise hvem som ikke har betalt, ville et gratismedlem staatt roedt
-- for alltid, og druknet dem som faktisk skylder penger.
--
--   betaler_ikke        haken. 0 for alle som staar der fra for.
--   betaler_ikke_grunn  kort tekst, saa den som ser lista om et halvt aar
--                       skjonner hvorfor — «bytter mot dugnad»,
--                       «aeresmedlem». Valgfri.
--
-- Medlemskapet, timene og doerkoden er de samme som for alle andre. Dette
-- sier bare at det ikke skal komme penger.
ALTER TABLE members
  ADD COLUMN betaler_ikke       TINYINT(1)   NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN betaler_ikke_grunn VARCHAR(200) NULL              AFTER betaler_ikke;
