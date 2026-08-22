-- Varselmalene, ordrett fra designet (admin → Beskjeder → E-post- og SMS-maler).
-- Disse redigeres fra admin etterpå; her legges bare utgangsteksten inn.

INSERT INTO notification_templates (navn, kanal, emne, tekst) VALUES
('ordrebekreftelse', 'epost', 'Takk for bestillingen hos Lissom!',
 'Hei {navn}! Vi har mottatt bestillingen din ({ordre}). Du finner kvitteringen under Min side. Velkommen til verkstedet!'),

('kurspaaminnelse', 'epost_sms', 'I morgen: {kurs} kl. {tid}',
 'Hei {navn}! Vi gleder oss til å se deg i morgen. Du får låne forkle av oss, men regn med å bli litt skitten. Adresse: Nordre Løkkevei 15, Teie.'),

('avbestilling', 'epost', 'Avbestillingen din er registrert',
 'Hei {navn}. Vi har avbestilt {kurs}. Eventuell refusjon ({belop}) er på vei til Vipps innen 3 virkedager.'),

('medlemskap_fornyet', 'epost', 'Medlemskapet ditt er fornyet',
 'Hei {navn}! {abonnement} er fornyet for en ny måned, og timene dine er fylt opp. God skapelyst!'),

('betaling_feilet', 'epost_sms', 'Vi fikk ikke trukket betalingen',
 'Hei {navn}. Månedstrekket for {abonnement} feilet. Åpne Vipps og godkjenn betalingen innen 5 dager for å beholde tilgangen.'),

('venteliste_ledig', 'sms', NULL,
 'Hei {navn}! Det ble ledig plass på {kurs} {dato}. Først til mølla — book her: {lenke}'),

('ferdig_brent', 'sms', NULL,
 'Hei {navn}! Keramikken din fra {kurs} er ferdig og klar til henting. Vi holder av den i to uker.');
