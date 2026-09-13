-- Ovnkortet: raabrann satt, glasurbrann satt, ovnen toemt.
--
-- Eieren, 13. september 2026: «jeg vil utvide til, en pille råbrann satt
-- og en pille glassurbrann satt … jeg vil at statusen skal vises i samme
-- kortet som ovnen tømt». GO paa skissen.
--
-- Samme tabell som «Ovn er tømt» (migrasjon 171), med et slag paa hver rad:
-- «tomt», «raabrann» eller «glasurbrann». Det siste trykket er statusen.
-- Radene fra foer er toemminger, og faar «tomt» som standard.

ALTER TABLE ovn_tomt
    ADD COLUMN IF NOT EXISTS slag VARCHAR(24) NOT NULL DEFAULT 'tomt'
    COMMENT 'tomt, raabrann eller glasurbrann' AFTER member_id;
