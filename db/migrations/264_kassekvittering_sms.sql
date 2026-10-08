-- Kort SMS-kvittering fra kassa på iPaden (eieren, 8. oktober 2026, godkjent
-- tekst). «Kvittering på SMS» på ferdig-skjermen sender denne til kunden som
-- har mobil, når SMS er satt opp. Den fulle kvitteringen går fortsatt på
-- e-post. Teksten kan endres, og malen slås av, under Tekstmaler
-- («Kvittering fra kassa (SMS)»).
-- godkjent av eieren: 2026-10-08
--
-- Rører ingen andre maler.

INSERT INTO notification_templates (navn, kanal, emne, tekst, aktiv, gruppe) VALUES
('kassekvittering_sms', 'sms', NULL,
 'Takk for handelen hos Lissom! Betalt {belop} med {maate} {dato}. Hilsen oss i Lissom', 1, 'ordre')
ON DUPLICATE KEY UPDATE navn = navn;
