-- Scan-Form som leverandør i handlelista.
--
-- Eieren, 15. september 2026: «jeg vil også kunne legge til Scan-Form
-- info@scan-form.no». Adressen er hans egen, ikke gjettet. Finnes raden
-- fra før (lagt til for hånd i admin), vekkes den og adressen fylles inn
-- bare om den står tom.
INSERT INTO leverandorer (navn, epost, bestillingsmaate, aktiv)
VALUES ('Scan-Form', 'info@scan-form.no', 'epost', 1)
ON DUPLICATE KEY UPDATE
    aktiv = 1,
    epost = IF(epost = '', VALUES(epost), epost);
