#!/usr/bin/env node
/**
 * Kaller malen paa noe som ikke finnes?
 *
 * «Frys av medlemskap» ble bygget ferdig i skjermen og i API-et, men de fire
 * metodene som henter og sender laa aldri i fila. renderVals() kalte
 * this.hentFrys() paa Min side, og hele nettsiden stoppet med
 * «this.hentFrys is not a function» — for alle, i produksjon.
 *
 * Denne leser lissom-2108.html, finner alt som kalles med this.<navn>(), og
 * sier fra om noe av det ikke er definert.
 */
import { readFileSync } from 'node:fs';

const fil = new URL('../lissom-2108.html', import.meta.url);
const s = readFileSync(fil, 'utf8');

// Metodene i klassene staar med to mellomrom foran seg.
const definert = new Set([...s.matchAll(/^ {2}(?:static\s+)?(?:get\s+|set\s+)?([A-Za-z_$][\w$]*)\s*\(/gm)].map(m => m[1]));
// Felt satt paa instansen: this._resize = ... teller ogsaa som definert.
for (const m of s.matchAll(/this\.([A-Za-z_$][\w$]*)\s*=\s*(?:\(|function|async|this\.)/g)) definert.add(m[1]);
// Arvet fra rammeverket.
['setState', 'forceUpdate', 'render'].forEach(n => definert.add(n));

const kalt = new Set([...s.matchAll(/this\.([A-Za-z_$][\w$]*)\s*\(/g)].map(m => m[1]));
const mangler = [...kalt].filter(n => !definert.has(n)).sort();

if (mangler.length) {
  console.error('Kalles, men finnes ikke:');
  for (const n of mangler) {
    const linje = s.slice(0, s.indexOf('this.' + n + '(')).split('\n').length;
    console.error('  this.' + n + '()  — linje ' + linje);
  }
  process.exit(1);
}
console.log(kalt.size + ' metoder kalles, alle finnes.');
