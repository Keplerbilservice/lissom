-- Varene i butikken, hentet fra designet.
--
-- Prisen her er fasiten. Nettleseren sender aldri belop ved kjop — den sender
-- hvilke varer og hvor mange, og serveren regner ut summen selv.

INSERT INTO products (tittel, beskrivelse, bilde, kategori, pris_ore, mva_prosent, kun_medlemmer, status) VALUES
('Kopp, sandglasur', 'Dreid stengods, ca. 3 dl. Tåler oppvaskmaskin.',
 'uploads_shutterstock_2830582037.jpg', 'Kopper', 39000, 25, 0, 'publisert'),

('Kopp med hank', 'Dreid stengods, ca. 4 dl. Hver kopp er litt ulik.',
 'uploads_shutterstock_2830582037.jpg', 'Kopper', 45000, 25, 0, 'publisert'),

('Bolle, stor', 'Dreid stengods, 22 cm. Fin til servering.',
 'uploads_shutterstock_2830582659.jpg', 'Boller', 59000, 25, 0, 'publisert'),

('Bolle, liten', 'Dreid stengods, 14 cm. Selges enkeltvis.',
 'uploads_shutterstock_2830582659.jpg', 'Boller', 34000, 25, 0, 'publisert'),

('Fat, 28 cm', 'Dekorglasur. Ikke matsikret, ment som pyntefat.',
 'uploads_shutterstock_2830582925.jpg', 'Fat', 79000, 25, 0, 'publisert');

-- Internbutikken: varer kun medlemmer kan kjope.
INSERT INTO products (tittel, beskrivelse, kategori, pris_ore, mva_prosent, kun_medlemmer, status) VALUES
('Leire, 10 kg', 'Stengodsleire. Hentes i verkstedet.', 'Materialer', 29000, 25, 1, 'publisert'),
('Ekstra glasurbrann', 'Én ekstra brenning utover det medlemskapet dekker.', 'Brenning', 22000, 25, 1, 'publisert');
