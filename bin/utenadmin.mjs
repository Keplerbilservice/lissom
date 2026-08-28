/**
 * Lager utgaven av nettsida som ikke inneholder adminpanelet.
 *
 * ── Hvorfor ───────────────────────────────────────────────────────────
 *
 * Hver besokende laster ned hele adminpanelet: 30 skjermer, 604 kB markup
 * de aldri faar se. De er skjult bak en «sc-if», men skjult betyr lastet
 * ned, lest og saa ikke vist — ikke «ikke sendt».
 *
 * Maalt 28. august: fila er 2080 kB, hvorav 668 kB er adminskjermenes
 * markup. Komprimert utgjor det rundt en fjerdedel av det en besokende
 * laster ned, og en god del av de 758 millisekundene nettleseren bruker paa
 * aa lese HTML-en.
 *
 * ── Hvordan ───────────────────────────────────────────────────────────
 *
 * Adminskjermene ligger hver for seg i en «sc-if» paa toppnivaa, med to
 * mellomrom innrykk:
 *
 *     \n  <sc-if value="{{ erAdminOversikt }}" …>
 *       … skjermen …
 *     \n  </sc-if>
 *
 * En «sc-if» inne i skjermen har dypere innrykk, saa den forste
 * «\n  </sc-if>» etter aapningen er alltid den som lukker skjermen. Det er
 * hele regelen, og bin/adminsjekk.mjs kontrollerer at den holder: at det
 * blir noyaktig 30 blokker, at ingen offentlig skjerm forsvinner, og at
 * ingen av de delte rutene — sokeruta, salgsruta, bunnteksten — ryker med.
 *
 * Klipppingen gjores her, én gang, framfor i side.php ved hver forespoersel:
 * to millisekunder per besokende er ikke mye, men det er heller ikke noe man
 * skal betale for det samme svaret hver gang.
 *
 * Merk: det er BARE markupen som fjernes. Skriptet er felles, og en
 * innlogget admin faar hele fila.
 */

import fs from 'fs';
import path from 'path';

const ROT = path.resolve(import.meta.dirname, '..');
const KILDE = path.join(ROT, 'lissom-2108.html');
const MAAL = path.join(ROT, 'lissom-2108-uten-admin.html');

export function utenAdmin(kilde) {
  const blokker = [];
  let ut = kilde;
  // Bakfra, saa posisjonene foran ikke flytter seg underveis.
  const aapninger = [...kilde.matchAll(/\n {2}<sc-if value="\{\{ (erAdmin[A-Za-z0-9_]*) \}\}"/g)];
  for (const m of aapninger.reverse()) {
    const slutt = ut.indexOf('\n  </sc-if>', m.index);
    if (slutt < 0) {
      throw new Error('Fant ikke slutten paa ' + m[1] + ' — sjekk innrykket i malen');
    }
    const til = slutt + '\n  </sc-if>'.length;
    blokker.push({ navn: m[1], bytes: Buffer.byteLength(ut.slice(m.index, til)) });
    ut = ut.slice(0, m.index) + '\n  <!-- ' + m[1] + ': sendes bare til innlogget admin -->'
       + ut.slice(til);
  }
  return { html: ut, blokker: blokker.reverse() };
}

if (import.meta.filename === process.argv[1]) {
  const kilde = fs.readFileSync(KILDE, 'utf8');
  const { html, blokker } = utenAdmin(kilde);
  fs.writeFileSync(MAAL, html);
  const kb = (n) => Math.round(n / 1024);
  console.log(blokker.length + ' adminskjermer klippet bort.');
  console.log('  full utgave      ' + kb(Buffer.byteLength(kilde)) + ' kB');
  console.log('  uten admin       ' + kb(Buffer.byteLength(html)) + ' kB'
    + '   (' + kb(Buffer.byteLength(kilde) - Buffer.byteLength(html)) + ' kB mindre)');
}
