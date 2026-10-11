-- Dugnad uten godkjenning, per medlem (eieren 11. oktober 2026: «kan du skru
-- av at Monica må godkjenne dugnaden til Eirin?», valgt «Bare for Eirin»).
--
-- Står bryteren på, blir medlemmets dugnadsforespørsel godkjent med en gang
-- (api/dugnad.php), og hun kan stemple inn direkte. Tida etter utstempling
-- godkjennes som før. Bryteren står på medlemskortet i ny og gammel admin.
--
-- Bare tillegg; tåler å kjøres to ganger. Ingen data endres: alle står på av.

ALTER TABLE members
  ADD COLUMN IF NOT EXISTS dugnad_uten_godkjenning TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Dugnadsforespørselen godkjennes med en gang (migrasjon 272). Tida godkjennes som før.';
