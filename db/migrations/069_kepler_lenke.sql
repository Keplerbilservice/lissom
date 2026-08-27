-- Nettadressen til Kepler.
--
-- Referansen viser hva som ble laget og for hvem. Lenken lar den som blir
-- nysgjerrig gaa videre til kunden — og den er ogsaa en hoeflighet: vi viser
-- fram noen andres navn og merke, og da skal veien til dem staa aapen.
--
-- Egen migrasjon og ikke en retting i 068: den kan alt ha kjort.

UPDATE referansekunder
   SET lenke = 'https://www.kepler.no/'
 WHERE navn = 'Kepler'
   AND (lenke IS NULL OR lenke = '');
