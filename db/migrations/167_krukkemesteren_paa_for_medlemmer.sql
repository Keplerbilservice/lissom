-- «Spør o store krukkemester» paa for medlemmene.
--
-- Eieren, 11. september 2026: «Skru på for medlemmer og publiser». Bryteren
-- «Vises for medlemmer» under Verkstedet er innstillingen
-- verksted_faq_medlem; den settes til 1 her, saa kortet paa Min side og
-- feltet under Nyttig info vises fra ⚙ Kjør oppdateringer. Bryteren i admin
-- virker som foer etterpaa.

INSERT INTO innstillinger (nokkel, verdi) VALUES ('verksted_faq_medlem', '1')
ON DUPLICATE KEY UPDATE verdi = '1';
