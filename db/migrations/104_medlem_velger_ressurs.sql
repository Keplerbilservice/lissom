-- Medlemmet sier hva det skal bruke.
--
-- Eieren, 30. august: «kunne det vøre løst om de booker inn og velger
-- dreieskive, eller verkstedplass» — medlemmene — «det skjer på min side».
--
-- Slik det var etter migrasjon 103: innstemplede medlemmer ble trukket fra de
-- åtte skivene, alltid. Et medlem som håndbygger ved bordet ble talt feil, og
-- systemet holdt av en skive ingen skulle bruke. Gjetningen var med vilje
-- konservativ, men den var en gjetning.
--
-- Nå velger medlemmet selv når det stempler inn, fra Min side. Da er tallet
-- eksakt, og ingen holder av noe de ikke bruker.
--
-- NULL står igjen som «vet ikke»: økter som alt var åpne da dette ble lagt
-- ut, og medlemmer som stempler inn fra en gammel fane. De teller mot
-- skivene som før — se Booking::inneNaa().

ALTER TABLE check_ins
  ADD COLUMN ressurs_id INT NULL
      COMMENT 'Hva medlemmet valgte ved innstempling. NULL = ikke oppgitt.';
