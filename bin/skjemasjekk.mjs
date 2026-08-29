/**
 * Leter etter felter man ikke kan skrive i, og felter som ikke er koblet til
 * noe i det hele tatt.
 *
 *   node bin/skjemasjekk.mjs
 *
 * Et felt i malen har to bindinger: value="{{ x }}" og onChange="{{ settX }}".
 * Mangler den siste, kan man klikke i feltet, men ikke endre noe.
 *
 * Mangler BEGGE, er feltet bare tegnet. Det ser ferdig ut, tar imot det man
 * skriver, og kaster det. Det er verre enn et felt som ikke virker, for
 * ingenting sier fra. Fram til 24. august hoppet dette skriptet over slike
 * felter — kommentaren sa «helt ustyrt felt, f.eks. filvelger». Bak den
 * setningen sto feltet «Gavekort eller rabattkode» i kassa i to maaneder,
 * uten binding og uten noe endepunkt bak seg.
 *
 * Skriptet saa ogsaa bare paa raa <input>, <textarea> og <select>. Skjemaene
 * er for det meste bygget av <x-import ... .Input>, og de var usynlige for
 * det. De telles med naa.
 *
 * Filvelgere staar i UNNTAK: <input type="file"> styres av onChange alene,
 * en value gir ikke mening, og de er kontrollert for haand.
 */

import fs from 'node:fs';

const s = fs.readFileSync(new URL('../lissom-2108.html', import.meta.url).pathname, 'utf8');
const mal = s.slice(s.indexOf('<x-dc>'), s.indexOf('<script type="text/x-dc"'));

/** Felter som med vilje staar uten binding, med grunnen. */
const UNNTAK = [
  { treff: /type="file"/, grunn: 'filvelger — styres av onChange alene' },
  {
    // Et laast felt SKAL ikke ha onChange. Det staar der for aa kunne leses
    // og kopieres — kalenderadressen til mobilen, for eksempel — og et
    // «settX» ville vaert en binding til noe som aldri skjer.
    treff: /readOnly="\{\{ true \}\}"/,
    grunn: 'laast felt — staar der for aa leses og kopieres, ikke skrives i',
  },
  {
    treff: /Jeg godtar vilkårene for medlemskap/,
    grunn: 'staar paa skjermen «Vipps-flyt», som ingenting lenker til. Eneste '
         + 'vei inn er aa skrive /betaling manuelt. Skjermen viser hvordan '
         + 'maanedstrekk skal se ut naar Vipps Recurring blir godkjent — det '
         + 'er ikke godkjent enda, og hele flyten er en tegning. Roeres ikke '
         + 'for eieren har tatt stilling til om skjermen skal staa eller gaa.',
  },
];

const utenEndring = [];
const utenAlt = [];
let felter = 0, hele = 0;

const sjekk = (kode, navn) => {
  const type = (kode.match(/type="(\w+)"/) || [])[1] || 'text';
  if (type === 'hidden' || type === 'submit') return;
  felter++;

  const harVerdi   = /(?:value|checked|selected)="\{\{/.test(kode);
  const harEndring = /on-?[Cc]hange="\{\{|on-?[Ii]nput="\{\{|on-?[Cc]lick="\{\{/.test(kode);

  if (harVerdi && harEndring) { hele++; return; }
  if (UNNTAK.some(u => u.treff.test(kode))) { hele++; return; }
  (harVerdi || harEndring ? utenEndring : utenAlt)
    .push({ navn, utdrag: kode.replace(/\s+/g, ' ').slice(0, 130) });
};

for (const m of mal.matchAll(/<(input|textarea|select)\b[^>]*>/g)) {
  sjekk(m[0], m[1]);
}
// Skjemaene er stort sett satt sammen av komponenter, ikke raa tagger.
for (const m of mal.matchAll(/<x-import[^>]*\.(Input|Textarea|Select|Switch|Checkbox|Radio)"[^>]*>/g)) {
  sjekk(m[0], m[1]);
}

console.log(`${felter} felter i malen, ${hele} er koblet opp.`);

if (utenAlt.length) {
  console.log(`\n${utenAlt.length} felt uten binding i det hele tatt — tar imot det som skrives og kaster det:`);
  utenAlt.forEach(x => console.log('  ' + x.utdrag));
}
if (utenEndring.length) {
  console.log(`\n${utenEndring.length} felt med verdi, men uten onChange — kan ikke skrives i:`);
  utenEndring.forEach(x => console.log('  ' + x.utdrag));
}
if (utenAlt.length || utenEndring.length) process.exit(1);
console.log('Ingen felter uten funksjon.');
