#!/usr/bin/env node
/**
 * Karusellen paa forsida: staar dataene foer skriptet som leser dem?
 *
 * Eieren, 20. september 2026: «er det slik at referanse karusellen har
 * stoppet aa rullere paa forsiden?» Den hadde det, og den hadde gjort det
 * stille — ingen feilmelding, ingenting i konsollen.
 *
 * nett.js leser «window.lissomRot» med det samme det kjorer:
 *
 *     var rot = window.lissomRot || [];
 *     felt('rot', rot.length, 12000, ...);
 *
 * og felt() gir opp paa «antall < 2». Nett::dokument() la sidas eget skript
 * ETTER det felles, saa lista var tom naar den ble lest. Feltet sto stille,
 * og prikkene fikk aldri en klikk-haandterer.
 *
 * Det er en rekkefoelge to filer maa vaere enige om, og ingenting i koden
 * sier fra naar de ikke er det. Derfor denne.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const rot = join(dirname(fileURLToPath(import.meta.url)), '..');
const les = (f) => readFileSync(join(rot, f), 'utf8');

let feil = 0;
const sjekk = (navn, ok, detalj = '') => {
  console.log((ok ? '  OK    ' : '  AVVIK ') + navn + (ok || !detalj ? '' : '  — ' + detalj));
  if (!ok) feil++;
};

const nett = les('app/nett/nett.php');
const js = les('nett.js');
const forside = les('app/nett/sider/forside.php');

// ── Rekkefoelgen i dokumentet ────────────────────────────────────────
//
// Linja som setter <script> sammen. Begge delene skal staa der, og sidas
// egne data skal komme forst.
const linje = (nett.match(/^.*window\.lissomMaal.*$/m) || [''])[0];
sjekk('linja som setter skriptet sammen finnes', linje !== '');

const iSide = linje.indexOf("$side['skript']");
const iFelles = linje.indexOf('self::skript()');
sjekk('sidas eget skript staar i linja', iSide !== -1);
sjekk('det felles skriptet staar i linja', iFelles !== -1);
sjekk('sidas data staar FOER det felles skriptet',
      iSide !== -1 && iFelles !== -1 && iSide < iFelles,
      'nett.js leser window.lissomRot med det samme, og gir opp naar lista er tom');

// ── At det faktisk er denne avhengigheten ────────────────────────────
//
// Blir noe av dette skrevet om, skal sjekken si fra framfor aa passe paa
// en regel som ikke gjelder lenger.
sjekk('forsida sender fortsatt window.lissomRot',
      forside.includes("'skript' => 'window.lissomRot = '"));
sjekk('nett.js leser den ved oppstart',
      js.includes('var rot = window.lissomRot || [];'));
sjekk('… og bruker lengden til aa bestemme om feltet skal rullere',
      /felt\('rot', rot\.length, 12000/.test(js));
sjekk('… og gir opp paa under to kort',
      js.includes('if (!el || antall < 2) return;'));

// ── Produktkarusellen ────────────────────────────────────────────────
//
// Den teller DOM-elementer i stedet for en variabel, og var derfor uberoert
// av feilen. Staar den regelen fast, er den ogsaa trygg mot at det skjer
// igjen.
sjekk('produktfeltet teller elementer, ikke en variabel',
      js.includes("d.querySelectorAll('[data-nett-rot=\"but\"] > [data-but]')"));

console.log('');
if (feil) {
  console.log(feil + (feil === 1 ? ' avvik' : ' avvik') + ' — karusellen paa forsida vil staa stille.');
  process.exit(1);
}
console.log('Karusellen paa forsida faar dataene sine for den leser dem.');
