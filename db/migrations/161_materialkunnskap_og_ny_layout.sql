-- «Materialkunnskap» i Leire, og ny utgave av 26 PDF-er.
--
-- Eieren, 11. september 2026, runde tre: mappa «Nye dokumenter 2» hadde
-- «Håndbok - Materialkunnskap» (ny; spurt om kort: «Leire») og en
-- handbok.css med layoutfiks for bilderutenettet som «skal overskrive den
-- gamle». De 26 PDF-ene som ble laget fra HTML i dag er derfor laget paa
-- nytt med den, under samme sti i manifestet. Importen bytter fila paa
-- dokumenter som alt er inne naar stoerrelsen er en annen — se
-- Dokumenter::importer().
--
-- Denne migrasjonen endrer ingen struktur. Den er her fordi «⚙ Kjør
-- oppdateringer» bare kjoerer naar det staar en migrasjon og venter, og det
-- er den knappen som starter importen.

ALTER TABLE verksted_dokumenter
    MODIFY COLUMN kilde VARCHAR(191) NULL
        COMMENT 'Stien i importpakka dokumentet kom fra. NULL = lastet opp for haand. Samme sti, ny stoerrelse = ny utgave av fila.';
