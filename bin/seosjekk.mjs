/**
 * Passer paa at seo-kart.json og nettsida sier det samme.
 *
 * side.php setter tittel og beskrivelse i hodet for svaret gaar ut, og henter
 * dem fra seo-kart.json. Kartet lages av nettsida, men det skjer bare naar
 * noen kjorer bin/seokart.mjs. Gjor de ikke det, staar det gamle titler i
 * hodet mens skjermen viser de nye — og ingen ser det, for JavaScript retter
 * det opp i nettleseren.
 *
 * Denne sammenligner de to, og sier fra for det skjer.
 */

import fs from 'fs';
import path from 'path';
import { execFileSync } from 'child_process';

const ROT = path.resolve(import.meta.dirname, '..');
const kartFil = path.join(ROT, 'seo-kart.json');

let feil = 0;
const si = (ok, tekst) => { if (!ok) feil++; console.log((ok ? '  OK  ' : '  FEIL') + '  ' + tekst); };

// Markorene side.php leter etter.
const html = fs.readFileSync(path.join(ROT, 'lissom-2108.html'), 'utf8');
si(html.includes('<!-- seo:start -->') && html.includes('<!-- seo:slutt -->'),
   'markorene seo:start og seo:slutt staar i lissom-2108.html');
si(html.indexOf('<!-- seo:start -->') < html.indexOf('<!-- seo:slutt -->'),
   'seo:start kommer for seo:slutt');
si((html.match(/<!-- seo:start -->/g) || []).length === 1
   && (html.match(/<!-- seo:slutt -->/g) || []).length === 1,
   'markorene staar bare én gang hver');

if (!fs.existsSync(kartFil)) {
  si(false, 'seo-kart.json finnes — kjor «node bin/seokart.mjs»');
} else {
  const fra = fs.readFileSync(kartFil, 'utf8');
  execFileSync(process.execPath, [path.join(ROT, 'bin/seokart.mjs')], { stdio: 'pipe' });
  const til = fs.readFileSync(kartFil, 'utf8');
  // «laget»-datoen endrer seg av seg selv og teller ikke.
  const uten = (s) => s.replace(/"laget": "[^"]*",?\n/, '');
  si(uten(fra) === uten(til),
     'seo-kart.json er i takt med lissom-2108.html'
     + (uten(fra) === uten(til) ? '' : ' — den er naa oppdatert, ta den med i commiten'));

  const kart = JSON.parse(til);
  const utenTittel = Object.entries(kart.stier)
    .filter(([, id]) => !((kart.sider[id] || {}).tittel || '').trim());
  si(utenTittel.length === 0,
     'alle adressene i kartet har en tittel'
     + (utenTittel.length ? ' — mangler: ' + utenTittel.map(([s]) => s).join(', ') : ''));

  const utenCanon = Object.entries(kart.stier)
    .filter(([, id]) => !((kart.sider[id] || {}).canonical || '').trim());
  si(utenCanon.length === 0,
     'alle adressene i kartet har en canonical'
     + (utenCanon.length ? ' — mangler: ' + utenCanon.map(([s]) => s).join(', ') : ''));

  // To sider som sier at de egentlig er den samme er en duplikat i soket.
  const sett = new Map();
  const doble = [];
  for (const [sti, id] of Object.entries(kart.stier)) {
    const c = (kart.sider[id] || {}).canonical || '';
    if (sett.has(c)) { doble.push(sett.get(c) + ' og ' + sti); }
    sett.set(c, sti);
  }
  si(doble.length === 0,
     'ingen to adresser deler canonical' + (doble.length ? ' — ' + doble.join('; ') : ''));

  console.log('\n' + Object.keys(kart.stier).length + ' adresser i kartet.');
}

process.exit(feil ? 1 : 0);
