-- «Forny» paa et medlemskap som gjores opp selv (Mini, Basis).
--
-- Eieren, 29. september 2026 (full sjekk): «Forny» ga «Du har alt et
-- medlemskap». Naa betaler knappen neste periode i Vipps paa avtalen som
-- alt loeper, og perioden gjelder fra der forrige betaling slutter — ikke
-- fra dagen det trykkes. Betaler hen tre dager foer forfall, mister hen
-- ikke de tre dagene.
--
-- gjelder_fra: dagen perioden betalingen dekker, starter. NULL = dagen
-- betalingen ble gjort, slik alle betalinger foer denne har regnet.

ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS gjelder_fra DATE NULL DEFAULT NULL;
