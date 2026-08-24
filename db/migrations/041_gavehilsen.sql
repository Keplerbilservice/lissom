-- Gaveinnpakning og hilsen paa en butikkordre.
--
-- Kassa hadde en avkrysning «Send som gave — vi pakker inn og legger ved en
-- hilsen» og et felt for hilsenen. Ingen av delene naadde serveren: haken sto
-- bare i nettleseren, og feltet var ikke koblet til noe. En kunde kunne huke
-- av, skrive «Gratulerer med dagen, mamma», betale — og verkstedet fikk aldri
-- vite noe om det.

ALTER TABLE orders
  ADD COLUMN gave TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'Skal pakkes inn som gave'
      AFTER betalt_maate,
  ADD COLUMN gave_hilsen VARCHAR(300) NULL
      COMMENT 'Hilsenen som legges ved'
      AFTER gave;
