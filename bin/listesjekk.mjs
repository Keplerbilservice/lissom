#!/usr/bin/env node
/**
 * Peker hver <sc-for> og <sc-if> paa noe som finnes?
 *
 * «popFaq» ble slettet sammen med prislista 27. august. Sporsmaalene paa
 * Paint on Pots forsvant fra sida, og ingen vakt sa fra: en sc-for over en
 * liste som ikke finnes tegner bare ingenting. Knappesjekken teller bindinger
 * som HAR en verdi, saa en binding som er borte forsvinner ogsaa ut av
 * tellinga — den ser ikke hullet.
 *
 * Denne leser malen, finner hver liste og hvert vilkaar, og sier fra om
 * navnet ikke settes noe sted i renderVals().
 */
import { readFileSync } from 'node:fs';

const s = readFileSync(new URL('../lissom-2108.html', import.meta.url), 'utf8');

// Navn som settes som props: «navn:» i et objektuttrykk, eller via en spread
// som gir et objekt med noekler. Vi tar det enkle: alt som staar som
// «noekkel:» et sted i fila, pluss «noekkel =».
// To props kan staa paa samme linje — «mkArtBildeVelg: a.velg, mkArtBildeHar:
// a.har» — saa vi kan ikke lete fra linjestart. Vi tar alt som staar som
// «noekkel:» hvor som helst. Det godtar litt for mye (en css-verdi i en
// streng kan se slik ut), men denne vakten skal fange det som MANGLER; aa
// godta for mye gjor den bare mildere, ikke feil.
const satt = new Set([...s.matchAll(/\b([A-Za-z_$][\w$]*)\s*:/g)].map(m => m[1]));

// Navn som kommer fra en sc-for: «as="k"» gir k, og k.noe er da lovlig.
const loopnavn = new Set([...s.matchAll(/\sas="([A-Za-z_$][\w$]*)"/g)].map(m => m[1]));

const mangler = [];
const sjekk = (uttrykk, hvor, linje) => {
  const rot = uttrykk.trim().split('.')[0];
  if (!rot || loopnavn.has(rot) || satt.has(rot)) return;
  mangler.push(`${hvor} «${uttrykk.trim()}»  — linje ${linje}`);
};

const linjeFor = (i) => s.slice(0, i).split('\n').length;

for (const m of s.matchAll(/<sc-for[^>]*\slist="\{\{\s*([^}]+?)\s*\}\}"/g)) {
  sjekk(m[1], 'sc-for list', linjeFor(m.index));
}
for (const m of s.matchAll(/<sc-if[^>]*\svalue="\{\{\s*([^}]+?)\s*\}\}"/g)) {
  sjekk(m[1], 'sc-if value', linjeFor(m.index));
}

if (mangler.length) {
  console.error('Peker paa noe som ikke settes:');
  mangler.forEach(n => console.error('  ' + n));
  process.exit(1);
}
console.log('Alle sc-for og sc-if peker paa noe som settes.');
