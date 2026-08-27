-- Paint on Pots: plassen er gratis, gjenstanden betales i verkstedet.
--
-- Kurset sto til 690 kroner. Det var prisen med gjenstanden inkludert, fra
-- den gang alle betalte det samme uansett hva de valgte aa male. Etter
-- migrasjon 074 er de to skilt: plassen bookes, og gjenstanden slaas inn i
-- kassa som en butikkvare.
--
-- Sto prisen igjen paa 690, ville kunden betalt 690 for plassen og
-- gjenstanden i tillegg. Lissom bestemte 27. august at plassen skal vaere
-- gratis: du booker et bord, og betaler bare det du tar med deg hjem.
--
-- Bookingen taaler dette fra for — et belop under Vipps sitt minstebelop
-- markeres som betalt uten aa sende noen til Vipps. Kunden faar bekreftelse
-- som vanlig.
--
-- Bare kursene der gjenstanden betales i verkstedet. Alt annet staar.

UPDATE courses
   SET pris_ore = 0
 WHERE gjenstand_i_kassa = 1
   AND pris_ore > 0;
