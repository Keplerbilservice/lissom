/**
 * Passer paa at utgaven uten admin er riktig og i takt med nettsida.
 *
 * side.php sender den lette utgaven til alle som ikke er innlogget som
 * admin. Blir den feil, ser en besokende det med én gang — en side som
 * mangler bunntekst, eller et sokefelt som ikke aapner seg.
 *
 * Derfor kontrolleres tre ting:
 *
 *   1. Fila er i takt med lissom-2108.html.
 *   2. Alt som IKKE er admin staar igjen: hver offentlige skjerm, og de
 *      delte rutene som ligger midt mellom adminskjermene — sokeruta,
 *      salgsruta og bunnteksten. De er det farligste: de ligger inni
 *      omraadet, og en klipping som tar for mye tar dem forst.
 *   3. Ingen adminskjerm staar igjen.
 */

import fs from 'fs';
import path from 'path';
import { lettUtgave } from './utenadmin.mjs';

const ROT = path.resolve(import.meta.dirname, '..');
const kilde = fs.readFileSync(path.join(ROT, 'lissom-2108.html'), 'utf8');
const maalFil = path.join(ROT, 'lissom-2108-uten-admin.html');

let feil = 0;
const si = (ok, t) => { if (!ok) feil++; console.log((ok ? '  OK  ' : '  FEIL') + '  ' + t); };

const { html, blokker, skript } = await lettUtgave(kilde);

if (!fs.existsSync(maalFil)) {
  si(false, 'lissom-2108-uten-admin.html finnes — kjor «node bin/utenadmin.mjs»');
} else {
  const paaDisk = fs.readFileSync(maalFil, 'utf8');
  if (paaDisk !== html) fs.writeFileSync(maalFil, html);
  si(paaDisk === html, 'den lette utgaven er i takt med lissom-2108.html'
    + (paaDisk === html ? '' : ' — den er naa oppdatert, ta den med i commiten'));
}

const skjermer = (s) => [...s.matchAll(/data-screen-label="([^"]+)"/g)].map(m => m[1]);
const alle = skjermer(kilde);
const igjen = skjermer(html);
const offentlige = alle.filter(n => !n.startsWith('Admin'));

si(blokker.length === alle.filter(n => n.startsWith('Admin')).length,
   blokker.length + ' blokker klippet, ' + alle.filter(n => n.startsWith('Admin')).length + ' adminskjermer finnes');
si(igjen.length === offentlige.length,
   'alle ' + offentlige.length + ' offentlige skjermer staar igjen (fant ' + igjen.length + ')');

const mistet = offentlige.filter(n => igjen.indexOf(n) < 0);
si(mistet.length === 0, 'ingen offentlig skjerm er borte' + (mistet.length ? ' — mangler: ' + mistet.join(', ') : ''));
si(igjen.every(n => !n.startsWith('Admin')), 'ingen adminskjerm staar igjen');

// De delte rutene ligger MELLOM adminskjermene. Tar klippingen for mye, er
// det disse som ryker forst — og da mister nettsida bunnteksten sin.
for (const n of ['salgApen', 'sokApen', 'visFooter', 'piApen', 'mkBildevalgApen']) {
  si(html.includes('{{ ' + n + ' }}'), 'den delte blokka «' + n + '» staar igjen');
}

// Malen skal fortsatt vaere hel: like mange sc-if aapninger som lukkinger.
const tell = (s, re) => (s.match(re) || []).length;
si(tell(html, /<sc-if\b/g) === tell(html, /<\/sc-if>/g),
   'like mange <sc-if> som </sc-if> i den lette utgaven ('
   + tell(html, /<sc-if\b/g) + ')');
si(tell(html, /<sc-for\b/g) === tell(html, /<\/sc-for>/g),
   'like mange <sc-for> som </sc-for>');

// Markorene side.php trenger.
for (const m of ['<!-- seo:start -->', '<!-- seo:slutt -->']) {
  si(html.includes(m), 'markoren ' + m + ' staar i den lette utgaven');
}

// side.php maa kjenne navnet paa sesjonscookien uten aa laste backend —
// det er hele poenget med den billige veien. Da staar navnet to steder, og
// da skal noen si fra hvis de skiller lag.
const sesjon = fs.readFileSync(path.join(ROT, 'app/lib/session.php'), 'utf8');
const side = fs.readFileSync(path.join(ROT, 'side.php'), 'utf8');
const iSesjon = (sesjon.match(/const COOKIE = '([^']+)'/) || [])[1];
const iSide = (side.match(/const SIDE_COOKIE = '([^']+)'/) || [])[1];
si(!!iSesjon && iSesjon === iSide,
   'side.php og Sesjon::COOKIE er enige om cookienavnet ('
   + iSesjon + (iSesjon === iSide ? '' : ' mot ' + iSide) + ')');

// Skriptet skal vaere komprimert, ikke bare med. Glipper terser-steget,
// gaar fila ut 439 kB tyngre uten at noe annet sier fra.
si(skript.etter > 0 && skript.etter < skript.for * 0.8,
   'skriptet er komprimert ('
   + Math.round(skript.for / 1024) + ' kB → ' + Math.round(skript.etter / 1024) + ' kB)');

const kb = (n) => Math.round(Buffer.byteLength(n) / 1024);
console.log('\n' + kb(kilde) + ' kB → ' + kb(html) + ' kB ('
  + (kb(kilde) - kb(html)) + ' kB mindre for den som ikke er admin).');
process.exit(feil ? 1 : 0);
