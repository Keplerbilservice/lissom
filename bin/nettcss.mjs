/**
 * Stilene til serversidene — nett.css.
 *
 * Serversidene (app/nett/) skal se ut som appen. Appen har stilene sine i
 * hodet paa lissom-2108.html: designsystemets filer (ds-*.css, lagt inn av
 * bin/inline-css.php), reserveskriftene, bildereglene og den store
 * stilblokka med lx-klassene. Denne fila leser de samme blokkene ut av
 * nettsida og skriver dem til nett.css, saa det finnes én kilde: endres en
 * stil i lissom-2108.html, kjoeres denne, og serversidene foelger.
 *
 *   node bin/nettcss.mjs
 *
 * Til slutt legges nett-egen.css til — det som bare serversidene trenger
 * (menylinja uten React, rotasjonene, samtykkeboksen). Den er skrevet for
 * haand og ligger i repoet.
 *
 * nett.css er generert. Ikke rediger den.
 */
import fs from 'fs';
import path from 'path';

const ROT = path.resolve(import.meta.dirname, '..');
const kilde = fs.readFileSync(path.join(ROT, 'lissom-2108.html'), 'utf8');

// Bare hodet — foer <x-dc>, der appen begynner.
const hodeSlutt = kilde.search(/^<x-dc>/m);
if (hodeSlutt < 0) throw new Error('Fant ikke <x-dc> i lissom-2108.html');
const hode = kilde.slice(0, hodeSlutt);

const blokker = [];
const re = /<style>([\s\S]*?)<\/style>/g;
let m;
while ((m = re.exec(hode)) !== null) {
  const css = m[1].trim();
  // Appens egen: skjuler malen til den er bygd. Ikke for oss.
  if (css === 'x-dc{display:none!important}') continue;
  blokker.push(css);
}
if (blokker.length < 3) throw new Error('Fant bare ' + blokker.length + ' stilblokker i hodet — ser ikke ut som foer');

const egen = fs.readFileSync(path.join(ROT, 'nett-egen.css'), 'utf8').trim();
const ut = '/* Generert av bin/nettcss.mjs fra lissom-2108.html og nett-egen.css. Ikke rediger. */\n'
  + blokker.join('\n\n') + '\n\n/* nett-egen.css */\n' + egen + '\n';
fs.writeFileSync(path.join(ROT, 'nett.css'), ut);
console.log(blokker.length + ' stilblokker + nett-egen.css → nett.css, ' + Math.round(ut.length / 1024) + ' kB');
