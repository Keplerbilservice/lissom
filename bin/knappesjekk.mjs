/**
 * Leter etter knapper som ikke gjor noe.
 *
 *   node bin/knappesjekk.mjs
 *
 * En knapp i malen peker paa en verdi: onClick="{{ lagre }}". Finnes ikke
 * «lagre» blant verdiene renderVals gir, blir bindingen staaende tom — og
 * knappen ser helt vanlig ut, men skjer ingenting naar man trykker.
 *
 * Skriptet finner alle bindingene i markupen og sjekker at det finnes en
 * verdi med det navnet. Punktnavn (k.velg) hoerer til en sc-for og sjekkes
 * paa siste ledd.
 */

import fs from 'node:fs';

const sti = new URL('../lissom-2108.html', import.meta.url).pathname;
const s = fs.readFileSync(sti, 'utf8');

// Malen staar i <x-dc>, og logikken i dc-skriptet etter den.
const skriptStart = s.indexOf('<script type="text/x-dc"');
const malStart = s.indexOf('<x-dc>');
const mal = s.slice(malStart, skriptStart);
const js = s.slice(skriptStart);

const bindinger = new Map();
for (const m of mal.matchAll(/on-?[Cc]lick="\{\{\s*([\w.]+)\s*\}\}"/g)) {
  const n = m[1];
  bindinger.set(n, (bindinger.get(n) || 0) + 1);
}
for (const m of mal.matchAll(/on-(?:change|book|navigate|input)="\{\{\s*([\w.]+)\s*\}\}"/g)) {
  const n = m[1];
  bindinger.set(n, (bindinger.get(n) || 0) + 1);
}

// Navn som faktisk settes et sted i skriptet.
const satt = new Set();
for (const m of js.matchAll(/^\s*([A-Za-z_]\w*)\s*:/gm)) satt.add(m[1]);
for (const m of js.matchAll(/\b([A-Za-z_]\w*)\s*:\s*(?:\(|function|this\.|\w)/g)) satt.add(m[1]);
// Object.assign(h, { velg: ... }) og lignende dekkes av mønsteret over.

const savn = [];
for (const [navn, antall] of bindinger) {
  const siste = navn.includes('.') ? navn.split('.').pop() : navn;
  if (!satt.has(siste)) savn.push({ navn, antall });
}

console.log(`${bindinger.size} bindinger i malen, ${bindinger.size - savn.length} har en verdi.`);
if (savn.length) {
  console.log('\nBindinger uten verdi:');
  savn.sort((a, b) => b.antall - a.antall).forEach(x => console.log(`  ${x.navn}  (${x.antall} sted${x.antall === 1 ? '' : 'er'})`));
  process.exit(1);
}
console.log('Ingen knapper uten funksjon.');
