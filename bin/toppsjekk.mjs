/**
 * Passer paa den ferdigtegnede toppen av forsida.
 *
 * forside-topp.html er menylinja og heroen, tegnet ferdig av
 * bin/forhaandstegn.mjs og limt inn av side.php. Den er det foerste en
 * besokende ser, og den skal se noyaktig ut som sida gjor naar den er
 * ferdig. Blir den feil, ser man det med én gang.
 *
 * Selve likheten maales i nettleseren — 42 til 54 synlige elementer per
 * bredde, med posisjon, storrelse, skrift og farge, for og etter byttet.
 * Det krever en tjener og kjores for haand. Her kontrolleres det som kan
 * leses ut av filene, og som er det som pleier aa ryke naar noe endres:
 *
 *   1. Fila finnes, og har de tre breddene med de grensene komponenten
 *      selv bruker.
 *   2. Ingen rester fra tegningen: «data-dc-tpl», tomme stildeklarasjoner.
 *   3. Feltene eieren kan redigere finnes, og noeklene er ekte.
 *   4. Sidas eget stilark staar i <head>, ikke i <helmet>. Staar det i
 *      kroppen, tegnes toppen uten det og hopper naar det kommer.
 *   5. Skriftene over skjermkanten er varslet.
 *   6. Byttet ser ikke etter den skjulte malen inne i <x-dc>.
 *   7. side.php og fila er enige om filnavnet.
 */

import fs from 'fs';
import path from 'path';

const ROT = path.resolve(import.meta.dirname, '..');
const les = (f) => fs.readFileSync(path.join(ROT, f), 'utf8');

let feil = 0;
const si = (ok, t) => { if (!ok) feil++; console.log((ok ? '  OK  ' : '  FEIL') + '  ' + t); };

const toppFil = path.join(ROT, 'forside-topp.html');
if (!fs.existsSync(toppFil)) {
  si(false, 'forside-topp.html finnes — kjor «node bin/forhaandstegn.mjs»');
  process.exit(1);
}
const topp = les('forside-topp.html');
const side = les('side.php');
const html = les('lissom-2108.html');
const ds   = les('ds-bundle.js');

// 1. De tre breddene, med komponentens egne grenser.
for (const n of ['tight', 'compact', 'full']) {
  si(topp.includes('data-topp="' + n + '"'), 'bredden «' + n + '» er tegnet');
}
// Tallene staar i ds-bundle.js som «evw < 1400» og «evw < 1150». Endres de
// der, maa mediesporringene her folge etter — ellers faar en skjerm paa
// 1300 px menylinja som hoerer til en annen bredde.
for (const [uttrykk, grense] of [[/evw < 1400/, 1400], [/evw < 1150/, 1150]]) {
  si(uttrykk.test(ds), 'ds-bundle.js har fortsatt grensa ' + grense);
  si(topp.includes(String(grense - 1) + 'px') || topp.includes(String(grense) + 'px'),
     'mediesporringen kjenner grensa ' + grense);
}

// 2. Rester fra tegningen.
si(!topp.includes('data-dc-tpl'), 'ingen «data-dc-tpl» igjen');
const tomme = topp.match(/[a-zA-Z-]+:\s*;/g) || [];
si(tomme.length === 0, 'ingen tomme stildeklarasjoner'
   + (tomme.length ? ' — fant ' + tomme.slice(0, 3).join(' ') : ''));
si(!topp.includes('</script'), 'ingen «</script» som ville delt fila i to');

// 3. Feltene eieren kan redigere.
const felt = [...topp.matchAll(/data-innh="([^"]*)"/g)].map(m => m[1]);
si(felt.length >= 3 * 10, felt.length + ' redigerbare felt (10 per bredde)');
const ukjente = [...new Set(felt)].filter(n => !html.includes("this.innh('" + n + "')"));
si(ukjente.length === 0, 'alle noeklene finnes i nettsida'
   + (ukjente.length ? ' — ukjent: ' + ukjente.join(', ') : ''));
// side.php bytter bare innholdet i spenn som ikke har tagger inni seg.
const spenn = (topp.match(/<span class="sc-interp" data-innh=/g) || []).length;
const enkle = (topp.match(/<span class="sc-interp" data-innh="[^"]*">[^<]*<\/span>/g) || []).length;
si(spenn === enkle, 'alle ' + spenn + ' feltene er ren tekst, slik side.php venter');

// 4. Sidas eget stilark, i hodet.
const hode = html.slice(0, html.indexOf('</head>'));
// Den ekte <helmet>-taggen ligger i kroppen. Ordet staar ogsaa i flere
// kommentarer i hodet — de handler om nettopp denne flyttingen — saa
// soket maa begynne etter </head>.
const etterHodet = html.indexOf('</head>');
const kropp = html.slice(html.indexOf('<helmet>', etterHodet),
                         html.indexOf('</helmet>', etterHodet));
si(hode.includes('.lx-hero-pad'), 'sidas eget stilark staar i <head>');
si(!kropp.includes('.lx-hero-pad'), 'stilarket ligger ikke igjen i <helmet>');

// 5. Skriftene over skjermkanten.
for (const f of ['bitter-latin-800-normal', 'alegreya-sans-latin-400-normal',
                 'bitter-latin-600-italic', 'alegreya-sans-latin-700-normal',
                 'bitter-latin-700-normal']) {
  si(hode.includes('preload" href="/fonts/' + f + '.woff2'), 'skrifta ' + f + ' er varslet');
}

// 6. Byttet.
si(html.includes("closest('x-dc')"),
   'byttet ser bort fra den skjulte malen inne i <x-dc>');
si(html.includes('lissom-topp'), 'skriptet som bytter ut kopien staar i nettsida');

// 7. side.php.
si(side.includes("/forside-topp.html'"), 'side.php henter forside-topp.html');
si(side.includes("$adresse === '/'"), 'side.php limer den bare inn paa forsida');

console.log('\n' + Math.round(Buffer.byteLength(topp) / 1024) + ' kB raa, '
  + Math.round(Buffer.byteLength(topp) / 1024 / 4.8) + ' kB komprimert (anslag).');
process.exit(feil ? 1 : 0);
