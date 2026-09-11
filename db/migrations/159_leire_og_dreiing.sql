-- To kort til under Verkstedet: «Leire» og «Dreiing».
--
-- Eieren, 11. september 2026 (GO paa forslaget): den nye mappa hadde tolv
-- dokumenter som ikke laa i repoet — Haandbok i elting av leire, tre guider
-- (gjenvinning, slikker, toerking) og aatte teknikkark (tre om elting, fem
-- om dreiing). Spurt om hvor de skulle ligge: «To nye kort: Leire og
-- Dreiing». Samme bryter som de andre kortene, av til han slaar den paa.
--
-- Kortene kommer etter de seks fra migrasjon 154. Dokumentene ligger i
-- importpakka (db/dokumenter/haandboker/leire og /dreiing) og legges inn av
-- «⚙ Kjør oppdateringer» — det er dette som faar knappen til aa dukke opp.
-- Ingen struktur endres.

INSERT IGNORE INTO verksted_kategorier (slug, navn, sortering) VALUES
    ('leire',   'Leire',   7),
    ('dreiing', 'Dreiing', 8);
