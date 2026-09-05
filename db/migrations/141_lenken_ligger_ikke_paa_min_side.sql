-- Brevet lovet en lenke som ikke finnes.
--
-- Eieren, 5. september: «sjekker du at man faktisk finner betalingslinken på
-- min side som du påstår i eposten». Svaret var nei.
--
-- Maalt i nettleseren: logget inn som en som har bestilt Aarsmedlemskap og
-- ikke godkjent i Vipps enda. Hele det hun ser paa Min side er «Bli medlem i
-- verkstedet» og innmeldingsskjemaet paa nytt. Ingen Vipps-lenke, ingen
-- beskjed om at en avtale venter paa henne.
--
-- Grunnen staar i api/medlemskap.php: «min»-objektet har fjorten felt, og
-- «vipps_url» er ikke ett av dem. Adressen lagres i subscriptions, men
-- sendes aldri ut — den brukes bare internt, til aa gjenbruke et paabegynt
-- forsoek og til purringen cron sender.
--
-- Eieren valgte: setningen ut av brevet. Lenken staar fortsatt i selve
-- e-posten, og purringen «Medlemskapet ditt venter paa deg» sender den paa
-- nytt dag 1 og dag 3 — den veien virker.
--
-- Bare den ene setningen tas. «Tar det bare et minutt» er sant og blir
-- staaende, og det samme gjor «du kan si opp avtalen selv fra Min side»
-- lenger nede — oppsigelsen ligger faktisk der.
--
-- REPLACE framfor en ny fulltekst: da overlever alt eieren selv maatte ha
-- rettet i malen under Beskjeder → E-post- og SMS-maler.
UPDATE notification_templates
   SET tekst = REPLACE(
       tekst,
       'Tar det bare et minutt. Har du lukket siden, finner du den samme lenken\npå Min side.',
       'Tar det bare et minutt.'
   )
 WHERE navn = 'innmelding_fast_trekk';
