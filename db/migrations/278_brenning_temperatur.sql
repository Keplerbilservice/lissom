-- Glasurbrann kan settes til en av verkstedets to temperaturer.
ALTER TABLE brenninger
  ADD COLUMN temperatur SMALLINT UNSIGNED NULL
  AFTER slag;
