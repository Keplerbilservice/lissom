-- Signaturen uten «Keramiker & daglig leder».
--
-- Eieren, 25. september 2026: «signaturen, ikke ha med daglig leder».
-- Linja tas bort fra signaturen i e-postene (innstillinger.epost_signatur),
-- slik den staar i basen — ogsaa om den er redigert for haand.

UPDATE innstillinger
   SET verdi = REGEXP_REPLACE(verdi,
         '<div[^>]*>\\s*Keramiker (&amp;|&) daglig leder\\s*</div>\\s*', '')
 WHERE nokkel = 'epost_signatur';
