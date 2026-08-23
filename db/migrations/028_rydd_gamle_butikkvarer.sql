-- Varene som laa i butikken for de ekte kom inn.
--
-- «Kopp, sandglasur», «Kopp med hank», «Bolle, stor», «Bolle, liten» og
-- «Fat, 28 cm» var eksempeldata med innkjopte bilder. De sto side om side med
-- verkstedets egne arbeider, og en kunde kunne ikke se forskjell.
--
-- Varer som er solgt, kan ikke slettes — da mister gamle kvitteringer linjene
-- sine. De settes til kladd i stedet, akkurat som naar en vare fjernes fra
-- admin. Resten slettes.

UPDATE products
   SET status = 'kladd'
 WHERE kun_medlemmer = 0
   AND tittel IN ('Kopp, sandglasur', 'Kopp med hank', 'Bolle, stor', 'Bolle, liten', 'Fat, 28 cm')
   AND EXISTS (SELECT 1 FROM order_lines ol WHERE ol.product_id = products.id);

DELETE FROM products
 WHERE kun_medlemmer = 0
   AND tittel IN ('Kopp, sandglasur', 'Kopp med hank', 'Bolle, stor', 'Bolle, liten', 'Fat, 28 cm')
   AND NOT EXISTS (SELECT 1 FROM order_lines ol WHERE ol.product_id = products.id);
