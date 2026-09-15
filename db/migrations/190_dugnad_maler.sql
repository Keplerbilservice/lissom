-- Malene for dugnad (migrasjon 189). Alle e-poster gaar gjennom malene, saa
-- verkstedet kan endre ordene under Verkstedet → Tekstmaler.
--
-- To til verkstedet (intern), fire til medlemmet.

INSERT INTO notification_templates (navn, kanal, emne, tekst, gruppe) VALUES
('intern_dugnad_sporsmal', 'epost', 'Dugnad: {navn} vil bidra',
 '{navn} spør om å jobbe dugnad i verkstedet:\n\n«{tekst}»\n\nGodkjenn eller avslå under Admin → Ubesvarte → Dugnad:\nhttps://lissom.no/admin/ubesvarte', 'system'),
('intern_dugnad_ferdig', 'epost', 'Dugnad: {navn} er ferdig — {varighet}',
 '{navn} har stemplet ut fra dugnad.\n\n«{tekst}»\nTid: {varighet} (forslag: {forslag} timer)\n\nSe over jobben og godkjenn tida under Admin → Ubesvarte → Dugnad:\nhttps://lissom.no/admin/ubesvarte', 'system'),
('dugnad_godkjent', 'epost', 'Dugnaden er godkjent',
 'Hei {fornavn}!\n\nDu kan jobbe dugnad i verkstedet:\n«{tekst}»\n{svar}\nStemple inn som dugnad på Min side når du begynner, og ut når du er ferdig. Tida legges til timene dine når verkstedet har godkjent jobben.\n\nhttps://lissom.no/min-side', 'system'),
('dugnad_avslatt', 'epost', 'Dugnad — ikke denne gangen',
 'Hei {fornavn}!\n\nVerkstedet kan ikke ta imot dugnaden «{tekst}» nå.\n{svar}\nDu kan gjerne spørre igjen senere.\n\nhttps://lissom.no/min-side', 'system'),
('dugnad_tid_godkjent', 'epost', 'Takk for dugnaden — {timer} timer lagt til',
 'Hei {fornavn}!\n\nTakk for innsatsen med «{tekst}».\nVerkstedet har godkjent {timer} timer, og de er lagt til timene dine.\n{svar}\nDu ser det under timene på Min side:\nhttps://lissom.no/min-side', 'system'),
('dugnad_tid_avvist', 'epost', 'Dugnaden ble ikke godkjent',
 'Hei {fornavn}!\n\nVerkstedet godkjente ikke tida for «{tekst}», så den er ikke lagt til.\n{svar}\nTa gjerne kontakt om du lurer på noe.\n\nhttps://lissom.no/min-side', 'system')
ON DUPLICATE KEY UPDATE navn = navn;
