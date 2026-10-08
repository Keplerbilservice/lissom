-- Kampanjer til medlemmer (eieren, 8. oktober 2026: «bygg det»).
--
-- En kampanje vises enten paa lissom.no (banneret paa forsiden, som foer)
-- eller paa Min side for medlemmer. Hvor, velges naar kampanjen lages.
-- Den valgte medlemskampanjen speiles til content_blocks under
-- «Medlemskampanje/» (som «Kampanje/» for forsiden), og vises naar
-- bryteren Vis/medlemskampanje staar paa «ja». Bryteren er av til den slaas
-- paa i admin-ny › Kampanjer eller Min side for medlemmer.
--
-- Bare en ny kolonne. Alle kampanjene som finnes, er «nett». Kan kjoeres
-- flere ganger.
ALTER TABLE kampanjer
  ADD COLUMN IF NOT EXISTS publikum VARCHAR(16) NOT NULL DEFAULT 'nett';
