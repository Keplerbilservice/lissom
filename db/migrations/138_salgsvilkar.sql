-- «Vilkår og angrerett» blir «Salgsvilkår».
--
-- Eieren, 3. september: «kan du fjerne vilkår og angrerett i footer, lag en
-- som heter Salgsvilkår, og bruk denne teksten. slett den gamle».
--
-- Overskrifta og ingressen på vilkårssida står i «content_blocks» under
-- nøklene «Vilkår/0/...». Malen i lissom-2108.html har standardverdiene, men
-- innh() leser basen først:
--
--     const lagret = (this.state.innholdLagret || {})[nokkel];
--     if (lagret !== undefined && lagret !== null) return lagret;
--
-- Ligger det en rad der, vinner den. Uten denne oppdateringa ville sida
-- fortsatt hett «Vilkår og angrerett» etter at malen ble endret — og ingen
-- ville skjønt hvorfor.
--
-- ── Hvorfor bare de gamle verdiene ────────────────────────────────────
--
-- Radene slettes bare når de er ordrett den gamle standardteksten. Har
-- verkstedet skrevet noe eget i feltet, står det. Da er det et valg noen har
-- tatt, og det skal ikke overkjøres av en oppdatering.
--
-- Slettes raden, faller feltet tilbake på malen — som nå sier «Salgsvilkår».

DELETE FROM content_blocks
 WHERE nokkel = 'Vilkår/0/Overskrift'
   AND verdi  = 'Vilkår og angrerett';

DELETE FROM content_blocks
 WHERE nokkel = 'Vilkår/0/Brødtekst'
   AND verdi  = 'Salgsvilkår for kurs, events, medlemskap og butikk hos Lissom Keramikk & Håndverk AS. Alle priser vises inkl. mva der mva gjelder.';

-- Datoen står i den samme blokka. Teksten er ny fra i dag, så «Sist
-- oppdatert» skal si det — men bare hvis den ikke er rettet for hånd.
DELETE FROM content_blocks
 WHERE nokkel = 'Vilkår/0/Sist oppdatert'
   AND verdi IN ('Sist oppdatert 3. september 2026',
                 'Sist oppdatert 2. september 2026',
                 'Sist oppdatert 25. august 2026');
