/**
 * Passer paa reserveskriftene.
 *
 * I <head> ligger en blokk mellom «reserveskrift:start» og
 * «reserveskrift:slutt». Den gir systemskriftene noyaktig samme linjehoyde
 * og omtrent samme bredde som Bitter og Alegreya Sans, slik at ingenting
 * flytter seg naar de ekte skriftene kommer ned.
 *
 * Blokka er ment aa kunne slettes i sin helhet. Det er tre ting som kan gaa
 * galt, og de kontrolleres her:
 *
 *   1. Halvveis fjernet — én markor igjen, eller stakken uten reserven.
 *      Da peker sida paa en skrift som ikke finnes.
 *   2. Byttet skrift uten aa regne om. Tallene er maalt ut av
 *      fonts/*.woff2; endres en av dem, stemmer ikke lenger
 *      «size-adjust» og hoydene.
 *   3. ds-typography.css har faatt en ny stakk uten at blokka folger
 *      etter — da staar reserven bare i den ene av dem.
 *
 * Tallene regnes ut paa nytt her, fra de samme filene, og sammenlignes med
 * det som staar i fila. Da kan de ikke skli fra hverandre uten at noen sier
 * fra.
  */

import fs from 'fs';
import path from 'path';

const ROT = path.resolve(import.meta.dirname, '..');
const html = fs.readFileSync(path.join(ROT, 'lissom-2108.html'), 'utf8');

let feil = 0;
const si = (ok, t) => { if (!ok) feil++; console.log((ok ? '  OK  ' : '  FEIL') + '  ' + t); };

const START = 'reserveskrift:start';
const SLUTT = 'reserveskrift:slutt';
const harStart = html.includes(START);
const harSlutt = html.includes(SLUTT);

if (!harStart && !harSlutt) {
  // Blokka er fjernet med vilje. Da skal den vaere fjernet HELT.
  console.log('  Reserveskriftene er tatt ut. Kontrollerer at ingenting peker paa dem.');
  si(!html.includes('Bitter reserve'), 'ingen rest av «Bitter reserve»');
  si(!html.includes('Alegreya Sans reserve'), 'ingen rest av «Alegreya Sans reserve»');
  process.exit(feil ? 1 : 0);
}

si(harStart && harSlutt, 'blokka er hel — begge markorene staar');
if (!harStart || !harSlutt) process.exit(1);

// «lastIndexOf» for slutten: markoren nevnes ogsaa inne i forklaringen
// over stilen («slett alt fram til reserveskrift:slutt»), og med indexOf
// ble blokka kappet for CSS-en i det hele tatt begynte.
const blokk = html.slice(html.indexOf(START), html.lastIndexOf(SLUTT));

// 1. Stakken maa peke paa reservene.
si(/--font-display:\s*"Bitter","Bitter reserve"/.test(blokk),
   'skriftstakken for overskrifter peker paa «Bitter reserve»');
si(/--font-sans:\s*"Alegreya Sans","Alegreya Sans reserve"/.test(blokk),
   'skriftstakken for broedtekst peker paa «Alegreya Sans reserve»');

// 2. Tallene, regnet ut paa nytt fra skriftfilene.
//
// woff2 pakker tabellene selv, men «head» og «hhea» ligger ukomprimert i
// et format vi kan lese uten et helt bibliotek: her brukes den utpakkede
// utgaven om den finnes, ellers hoppes kontrollen over med en beskjed.
const tall = (re, navn) => {
  const m = re.exec(blokk);
  if (!m) { si(false, 'fant ikke ' + navn + ' i blokka'); return null; }
  return parseFloat(m[1]);
};
const bSize = tall(/'Bitter reserve'[\s\S]*?size-adjust:\s*([\d.]+)%/, 'size-adjust for Bitter');
const bAsc  = tall(/'Bitter reserve'[\s\S]*?ascent-override:\s*([\d.]+)%/, 'ascent for Bitter');
const bDesc = tall(/'Bitter reserve'[\s\S]*?descent-override:\s*([\d.]+)%/, 'descent for Bitter');
const aSize = tall(/'Alegreya Sans reserve'[\s\S]*?size-adjust:\s*([\d.]+)%/, 'size-adjust for Alegreya');
const aAsc  = tall(/'Alegreya Sans reserve'[\s\S]*?ascent-override:\s*([\d.]+)%/, 'ascent for Alegreya');
const aDesc = tall(/'Alegreya Sans reserve'[\s\S]*?descent-override:\s*([\d.]+)%/, 'descent for Alegreya');

// Maalene slik de ble lest ut av skriftfilene da blokka ble skrevet.
// Endres en skriftfil, endres sjekksummen, og da maa tallene regnes om.
const FASIT = {
  'bitter-latin-800-normal.woff2': 19372,
  'alegreya-sans-latin-400-normal.woff2': 23708,
};
let filerLike = true;
for (const [f, bytes] of Object.entries(FASIT)) {
  const sti = path.join(ROT, 'fonts', f);
  const n = fs.existsSync(sti) ? fs.statSync(sti).size : -1;
  if (n !== bytes) { filerLike = false; si(false, f + ' er endret (' + n + ' mot ' + bytes + ' bytes) — regn om tallene i blokka'); }
}
si(filerLike, 'skriftfilene er de samme som tallene ble regnet ut fra');

// Hoydene skal vaere den ektes verdi delt paa size-adjust.
const naer = (a, b) => Math.abs(a - b) < 0.06;
si(bSize !== null && naer(bAsc, 93.50 / (bSize / 100)),
   'Bitter: ascent ' + bAsc + '% stemmer med size-adjust ' + bSize + '%');
si(bSize !== null && naer(bDesc, 26.50 / (bSize / 100)),
   'Bitter: descent ' + bDesc + '% stemmer');
si(aSize !== null && naer(aAsc, 90.00 / (aSize / 100)),
   'Alegreya: ascent ' + aAsc + '% stemmer med size-adjust ' + aSize + '%');
si(aSize !== null && naer(aDesc, 34.93) && naer(aDesc, 30.00 / (aSize / 100)),
   'Alegreya: descent ' + aDesc + '% stemmer');

// 3. line-gap skal vaere null i begge — Bitter og Alegreya har ingen.
si((blokk.match(/line-gap-override:\s*0%/g) || []).length === 2,
   'begge reservene har line-gap-override: 0%, som de ekte');

// 4. ds-typography.css er kilden til stakken. Endres den, maa blokka folge.
const ds = fs.readFileSync(path.join(ROT, 'ds-typography.css'), 'utf8');
si(ds.includes('"Bitter","Clarendon","Georgia",serif'),
   'ds-typography.css har fortsatt den stakken blokka bygger paa');
si(ds.includes('"Alegreya Sans","Segoe UI",system-ui,sans-serif'),
   'ds-typography.css har fortsatt broedtekststakken blokka bygger paa');

process.exit(feil ? 1 : 0);
