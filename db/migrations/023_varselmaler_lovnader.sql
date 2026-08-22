-- To malene lovte kunden noe systemet ikke gjor.
--
-- «Plassen er din i 24 timer» — ventelisteplassen holdes ikke av noe sted.
-- Adminskjermen sier det rett ut: «forste som booker, far den». Kunden fikk
-- den motsatte beskjeden, og den som stolte pa SMS-en og kom tilbake dagen
-- etter, fant plassen tatt.
--
-- «Du finner kvitteringen vedlagt» — det finnes ingen vedleggsmekanisme i
-- utsendingen. Kunden leter etter et vedlegg som aldri var der.
--
-- Malene kan redigeres fra admin. Derfor rettes bare den teksten som fortsatt
-- staar urort — har eieren skrevet sin egen, skal den ikke overskrives.

UPDATE notification_templates
   SET tekst = 'Hei {navn}! Det ble ledig plass på {kurs} {dato}. Først til mølla — book her: {lenke}'
 WHERE navn = 'venteliste_ledig'
   AND tekst = 'Hei {navn}! Det ble ledig plass på {kurs} {dato}. Plassen er din i 24 timer: {lenke}';

UPDATE notification_templates
   SET tekst = 'Hei {navn}! Vi har mottatt bestillingen din ({ordre}). Du finner kvitteringen under Min side. Velkommen til verkstedet!'
 WHERE navn = 'ordrebekreftelse'
   AND tekst = 'Hei {navn}! Vi har mottatt bestillingen din ({ordre}). Du finner kvitteringen vedlagt. Velkommen til verkstedet!';
