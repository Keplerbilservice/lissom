/**
 * Leter etter felter som ikke kan skrives i.
 *
 *   node bin/skjemasjekk.mjs
 *
 * Et felt i malen har to bindinger: value="{{ x }}" og onChange="{{ settX }}".
 * Mangler den siste, kan man klikke i feltet, men ikke endre noe — tastene
 * gjor ingenting, og det ser ut som skjermen har hengt seg.
 */

import fs from 'node:fs';

const s = fs.readFileSync(new URL('../lissom-2108.html', import.meta.url).pathname, 'utf8');
const mal = s.slice(s.indexOf('<x-dc>'), s.indexOf('<script type="text/x-dc"'));

const savn = [];
let felter = 0, lesbare = 0;

for (const m of mal.matchAll(/<(input|textarea|select)\b([^>]*)>/g)) {
  const [hele, tag, attr] = m;
  const type = (attr.match(/type="(\w+)"/) || [])[1] || 'text';
  if (type === 'hidden' || type === 'submit') continue;
  felter++;

  const harVerdi  = /(?:value|checked)="\{\{/.test(attr);
  const harEndring = /on-?[Cc]hange="\{\{|on-?[Ii]nput="\{\{/.test(attr);

  if (!harVerdi && !harEndring) continue;   // helt ustyrt felt, f.eks. filvelger
  if (harVerdi && !harEndring) {
    savn.push({ tag, utdrag: hele.slice(0, 110) });
  } else {
    lesbare++;
  }
}

console.log(`${felter} felter i malen, ${lesbare} kan bade leses og endres.`);
if (savn.length) {
  console.log('\nFelter med verdi, men uten onChange (kan ikke skrives i):');
  savn.forEach(x => console.log('  ' + x.utdrag));
  process.exit(1);
}
console.log('Ingen laaste felter.');
