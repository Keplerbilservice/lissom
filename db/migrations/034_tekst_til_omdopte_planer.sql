-- Fyller tekst paa medlemskap som ikke fikk noen i 032.
--
-- Migrasjon 032 la inn teksten med UPDATE ... WHERE navn = '30 timer'. Hadde
-- verkstedet alt dopt om planen — til «Basis 30» — traff ingen av dem, og
-- planen ble staaende uten merkelapp, beskrivelse, punkter og bilde. Admin
-- skrev da «Ingen tekst er skrevet ennaa» paa akkurat det ene kortet, mens
-- de andre saa ferdige ut.
--
-- Denne gaar ikke etter navn. Den ser paa hva planen ER: er den engangs, er
-- den en proveperiode; har den et timetall, staar timetallet; har den
-- binding, sies det. Da virker den ogsaa for medlemskap verkstedet lager
-- selv senere.
--
-- Bare tomme felter fylles. Har noen skrevet noe, staar det.

UPDATE membership_plans SET
  merke = CASE WHEN engangs = 1 THEN 'Prøveperiode' ELSE 'Medlemskap' END
 WHERE merke IS NULL OR merke = '';

UPDATE membership_plans SET
  undertekst = CASE
    WHEN timer IS NULL THEN 'Ingen timebegrensning'
    WHEN engangs = 1   THEN CONCAT(timer, ' timer, brukes i løpet av 30 dager')
    ELSE CONCAT(timer, ' timer i måneden')
  END
 WHERE undertekst IS NULL OR undertekst = '';

UPDATE membership_plans SET
  beskrivelse = CASE
    WHEN engangs = 1     THEN 'En måned med verkstedtilgang, så du finner ut om dette er noe for deg.'
    WHEN timer IS NULL   THEN 'Ubegrenset verkstedtid for deg som jobber med keramikk fast.'
    WHEN binding_mnd > 0 THEN CONCAT('Få mer, betal mindre: ', timer, ' timer i måneden til lavere pris, med årsavtale.')
    ELSE CONCAT(timer, ' timer i måneden, brukt når det passer deg. Fornyes automatisk.')
  END
 WHERE beskrivelse IS NULL OR beskrivelse = '';

UPDATE membership_plans SET
  punkter = CONCAT_WS('\n',
    CASE WHEN timer IS NULL THEN 'Ingen timebegrensning'
         ELSE CONCAT(timer, ' verkstedtimer i måneden') END,
    CASE WHEN engangs = 1 THEN 'Leire og brenning kjøpes i tillegg'
         ELSE 'Egen hylle i verkstedet' END,
    CASE WHEN engangs = 1 THEN 'Kan kun benyttes én gang'
         ELSE 'Tilgang 24/7 med dørkode' END,
    CASE WHEN engangs = 1     THEN 'Går ikke automatisk over til abonnement'
         WHEN binding_mnd > 0 THEN CONCAT('Binding i ', binding_mnd, ' måneder')
         ELSE 'Selg egne arbeider gjennom lissom.no' END
  )
 WHERE punkter IS NULL OR punkter = '';

UPDATE membership_plans SET
  passer_for = CASE
    WHEN engangs = 1     THEN 'er nysgjerrig etter et kurs'
    WHEN timer IS NULL   THEN 'jobber med keramikk fast'
    WHEN binding_mnd > 0 THEN 'bruker verkstedet fast hele året'
    ELSE 'vil ha fast plass i uka'
  END
 WHERE passer_for IS NULL OR passer_for = '';

-- Bildet. Uten et bilde faller kortet tilbake paa et generelt fotografi, og
-- det ser ut som bildet er borte. Sorteringa avgjor hvilket av de fire
-- verkstedbildene planen faar, saa to planer ikke ender med samme.
UPDATE membership_plans SET
  bilde = CASE (sortering % 4)
    WHEN 1 THEN 'uploads_shutterstock_2829104351.jpg'
    WHEN 2 THEN 'uploads_shutterstock_2829103797.jpg'
    WHEN 3 THEN 'uploads_shutterstock_2830613711.jpg'
    ELSE        'uploads_shutterstock_2829104157.jpg'
  END
 WHERE bilde IS NULL OR bilde = '';

-- Ett fremhevet kort. Er ingen fremhevet, tar den rimeligste loepende
-- planen plassen — det er den som pleier aa staa i midten.
UPDATE membership_plans SET fremhevet = 1
 WHERE aktiv = 1 AND engangs = 0
   AND NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM membership_plans WHERE fremhevet = 1) t)
 ORDER BY pris_ore ASC LIMIT 1;
