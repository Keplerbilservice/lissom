-- Publiseringen av en artikkel.
--
-- Bestilt 26. august, punkt 10. «Publiser» har vaert en bryter mellom to
-- tilstander: kladd eller publisert. Det bestillingen ber om er fire, og at
-- det staar naar noe gikk ut og hvem som sendte det.
--
--   kladd         skrives paa
--   planlagt      skal ut paa et tidspunkt som er bestemt
--   publisert     ligger ute
--   avpublisert   har ligget ute, er tatt ned igjen
--
-- «Avpublisert» og «kladd» ser like ut for en besokende — begge er borte fra
-- nettsida. Forskjellen er for verkstedet: en kladd har aldri vaert ute, en
-- avpublisert har. Da vet man om det finnes lenker der ute som naa er dode.
--
-- Alt som staar som «publisert» i dag blir staaende som publisert.

ALTER TABLE articles
  MODIFY status ENUM('kladd','planlagt','publisert','avpublisert')
      NOT NULL DEFAULT 'kladd',
  ADD COLUMN IF NOT EXISTS publisert_at DATETIME NULL
      COMMENT 'Naar den sist gikk ut',
  ADD COLUMN IF NOT EXISTS publisert_av BIGINT UNSIGNED NULL
      COMMENT 'Hvem som trykte publiser',
  ADD COLUMN IF NOT EXISTS planlagt_til DATETIME NULL
      COMMENT 'UTC. Naar en planlagt artikkel skal ut.';

-- De som alt ligger ute har ingen publiseringstid. updated_at er det
-- naermeste vi har, og bedre enn et tomt felt: da staar det i det minste
-- naar den sist ble roert.
UPDATE articles
   SET publisert_at = updated_at
 WHERE status = 'publisert' AND publisert_at IS NULL;

-- En planlagt artikkel skal finnes uten aa lete gjennom hele tabellen.
CREATE INDEX IF NOT EXISTS idx_planlagt ON articles (status, planlagt_til);
