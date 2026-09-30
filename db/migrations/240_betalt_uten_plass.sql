-- godkjent av eieren: 2026-09-30
--
-- «Må refunderes: betalt etter at plassen var borte». Codex-gjennomgangen
-- 30. september 2026: en betaling som kom etter at plassen var sluppet og
-- solgt til en annen, vekket bookingen og ga bort plassen to ganger. Naa
-- blir bookingen staaende, betalingen staar som betalt, og verkstedet faar
-- denne beskjeden. Eieren valgte «Bare varsle, refunder for hånd» — ingen
-- automatisk refusjon. Se Booking::varsleBetaltUtenPlass().
--
-- En mal som de andre interne, med egen bryter under Varsler, saa teksten
-- kan endres i Tekst maler.

INSERT INTO notification_templates (navn, kanal, emne, tekst, gruppe) VALUES
('intern_betalt_uten_plass', 'epost', 'Må refunderes: betalt etter at plassen var borte',
 'Betalingen kom inn etter at plassen var sluppet og solgt til en annen. Kunden har ikke fått plass.\n\nKunde: {navn}\nKurs: {kurs}\nBeløp: {belop}\nReferanse: {referanse}\n\nRefunder under Kasse › Betalinger — søk på referansen.', 'system')
ON DUPLICATE KEY UPDATE navn = navn;

-- Samme oppsett som de andre interne e-postene (migrasjon 227): overskrift,
-- avsnitt og et faktakort.
UPDATE notification_templates SET
    overskrift = 'Må refunderes',
    avsnitt = '["Betalingen kom inn etter at plassen var sluppet og solgt til en annen. Kunden har ikke fått plass.","Refunder under Kasse › Betalinger — søk på referansen."]',
    kort = '[["Kunde","{navn}"],["Kurs","{kurs}"],["Beløp","{belop}"],["Referanse","{referanse}"]]',
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'intern_betalt_uten_plass' AND overskrift IS NULL;
