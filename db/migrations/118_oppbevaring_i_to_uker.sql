-- Ferdig keramikk oppbevares i to uker, ikke tre.
--
-- SMS-en sa tre uker. Spoersmaal og svar paa nettsiden sa to. En kunde som
-- leste SMS-en trodde hen hadde en uke ekstra — og verkstedet sto med
-- keramikk de trodde de var ferdige med.
--
-- Eieren, 1. september: «to uker».
--
-- Merk at dette er noe annet enn brennetida. Den er to til fire uker, og
-- staar i Kursmal::HENTING. De to har vaert blandet sammen for; her gjelder
-- bare hvor lenge vi tar vare paa det etter at kunden har fatt beskjed.
--
-- Tallet staar ogsaa i api/admin/ferdigbrent.php (UKER_OPPBEVARING). Denne
-- filen retter teksten kunden faktisk faar.

UPDATE notification_templates
   SET tekst = REPLACE(tekst, 'Vi oppbevarer den hos oss i tre uker.',
                              'Vi oppbevarer den hos oss i to uker.')
 WHERE navn = 'ferdig_brent'
   AND tekst LIKE '%oppbevarer den hos oss i tre uker%';

-- Og om noen har skrevet den om for haand, med et annet ordvalg:
UPDATE notification_templates
   SET tekst = REPLACE(tekst, 'i tre uker', 'i to uker')
 WHERE navn = 'ferdig_brent'
   AND tekst LIKE '%i tre uker%';
