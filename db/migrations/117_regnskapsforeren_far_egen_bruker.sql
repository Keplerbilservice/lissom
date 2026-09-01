-- En egen rolle til regnskapsfoereren.
--
-- Eieren, 1. september: «jeg oensker aa lage en bruker log in til min
-- regnskapsoerer». Hun skal se OEkonomi og betalingene — ikke deltakerlister,
-- ikke medlemmer, ikke e-postadressene til folk som har vaert paa kurs.
--
-- Systemet hadde to roller: «medlem» og «admin». En admin ser alt. Uten en
-- tredje rolle var valget mellom aa gi henne hele verkstedet eller aa sende
-- filene manuelt hver maaned.
--
-- Rollen gir lesetilgang til regnskapet og betalingene. Den kan ikke refundere
-- penger, endre kurs, se deltakere eller sende noe. Det staar i koden, ikke
-- bare i skjermen: hvert endepunkt slipper henne inn eller ikke.

ALTER TABLE members
  MODIFY rolle ENUM('medlem', 'admin', 'regnskap') NOT NULL DEFAULT 'medlem';
