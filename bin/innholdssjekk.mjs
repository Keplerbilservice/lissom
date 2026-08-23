/**
 * Sjekker at hvert felt i innholdsredigeringen faktisk styrer noe.
 *
 *   node bin/innholdssjekk.mjs
 *
 * Feltene under Nettsiden → Innhold lagres i content_blocks med noekkelen
 * «Side/blokk/Felt». Nettsiden leser dem gjennom innh('Side/blokk/Felt') —
 * eller, for noen lister, gjennom et oppslag som settes sammen av
 * blokknavnet. Fantes ikke oppslaget, kunne eieren endre teksten, faa
 * «Lagret», og se at ingenting skjedde ute. Av 143 felter var 6 koblet.
 *
 * Skriptet leser modellen og markupen fra lissom-2108.html og melder fra om
 * felter ingen leser. Kjor det etter endringer i SIDEINNHOLD.
 */

import fs from 'node:fs';

const sti = new URL('../lissom-2108.html', import.meta.url).pathname;
const s = fs.readFileSync(sti, 'utf8');

const a = s.indexOf('const SIDEINNHOLD = {');
const b = s.indexOf('\n};\n', a) + 4;
const SIDEINNHOLD = eval(s.slice(a, b).replace('const SIDEINNHOLD =', '(').replace(/;\s*$/, ')'));

// Noeklene som slaas opp rett fram.
const direkte = new Set([...s.matchAll(/this\.innh\('([^']+)'\)/g)].map(m => m[1]));

// Blokkene som slaas opp via findIndex paa blokknavnet. Der er hele blokka
// koblet saa lenge oppslaget finnes.
const viaNavn = new Set([...s.matchAll(/findIndex\(s => s\.navn === '([^']+)'\)/g)].map(m => m[1]));

let felter = 0, koblet = 0;
const savn = [];

for (const [side, blokker] of Object.entries(SIDEINNHOLD)) {
  blokker.forEach((bl, i) => {
    // En blokk uten felter er en peker: den sier hvor noe redigeres, og
    // skal ikke telles som et felt som mangler kobling.
    if (!bl.felter || bl.felter.length === 0) return;
    const heleBlokka = viaNavn.has(bl.navn);
    bl.felter.forEach(f => {
      felter++;
      const n = `${side}/${i}/${f.l}`;
      if (direkte.has(n) || heleBlokka) koblet++;
      else savn.push(`${n}  (${bl.navn})`);
    });
  });
}

console.log(`${koblet} av ${felter} felter er koblet til nettsiden.`);
if (savn.length) {
  console.log('\nFelter ingen leser:');
  savn.forEach(n => console.log('  ' + n));
  process.exit(1);
}
console.log('Ingen felter uten virkning.');
