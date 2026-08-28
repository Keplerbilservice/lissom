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
 * ── Og skriptet ───────────────────────────────────────────────────────
 *
 * Nettsida er 1534 kB. Av dem er 388 kB markup og 1071 kB det ene
 * skriptet nederst — logikken for hele nettstedet, med kommentarene sine.
 *
 * Maalt 28. august med firedoblet mobil-CPU brukte nettleseren 1013 ms bare
 * paa aa lese fila og bygge DOM-en, foer dc-runtime hadde begynt. Den tida
 * folger filstoerrelsen, og 439 kB av skriptet er kommentarer og innrykk.
 *
 * Derfor kjores skriptet gjennom terser her — uten aa dope om navn og uten
 * aa skrive om kode, bare mellomrom og kommentarer bort. Programmet er det
 * samme, tegn for tegn i det som betyr noe. Kilden i lissom-2108.html staar
 * urort, og det er fortsatt den man leser og redigerer.
 *
 *   1071 kB → 633 kB
 *
 * «mangle: false» er ikke en forsiktighetsregel, den er paakrevd:
 * dc-runtime leser metodenavn ut av teksten, og et omdopt navn ville brutt
 * bindingene. Det er samme grunn som i bin/minifiser.mjs.
 *
 * Markupen roeres ikke. Et mellomrom mellom «{{ heroTittel }}» og «<em>»
 * er et mellomrom paa skjermen, og dc-runtime skiller paa tomme tekstnoder
 * med og uten mellomrom (se walkText i support.js). De 27 kB HTML-
 * kommentarer er ikke verdt den risikoen.
 *
 * Merk: det er bare den lette utgaven som komprimeres. En innlogget admin
 * faar hele fila, med kommentarer og alt — den er kilden.
 *
 * Krever terser:  npm install terser  (samme som bin/minifiser.mjs)
 */

import fs from 'fs';
import path from 'path';
import { minify } from 'terser';

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

/**
 * Kommentarer og innrykk ut av det store skriptet nederst.
 *
 * Skriptet staar som «<script type="text/x-dc" data-dc-script …>» etter
 * </x-dc>. Nettleseren kjorer det ikke selv — dc-runtime henter teksten og
 * evaluerer den — men den maa likevel lese hele strengen inn i DOM-en.
 *
 * Finner vi det ikke, gaar fila ut som den er. En side som er treg er bedre
 * enn en side som mangler logikken sin.
 */
export async function utenKommentarer(html) {
  const j = html.lastIndexOf('</x-dc>');
  if (j < 0) return { html, for: 0, etter: 0 };
  const m = /<script[^>]*data-dc-script[^>]*>/.exec(html.slice(j));
  const slutt = html.lastIndexOf('</script>');
  if (!m || slutt < 0) return { html, for: 0, etter: 0 };
  const fra = j + m.index + m[0].length;
  if (slutt <= fra) return { html, for: 0, etter: 0 };

  const js = html.slice(fra, slutt);
  const r = await minify(js, {
    // Ingen omskriving og ingen omdoping. Bare mellomrom og kommentarer.
    compress: false, mangle: false, format: { comments: false }, ecma: 2020,
  });
  const ny = r.code;
  // «</script>» inne i en streng ville avsluttet taggen og delt fila i to.
  // Kilden deler den derfor opp som '<scr' + 'ipt>'; skulle en minifiser
  // en dag sette den sammen igjen, stopper vi her framfor aa sende ut en
  // odelagt fil.
  if (ny.includes('</script')) {
    throw new Error('Det komprimerte skriptet inneholder </script — sendes ikke ut');
  }
  return {
    html: html.slice(0, fra) + ny + html.slice(slutt),
    for: Buffer.byteLength(js),
    etter: Buffer.byteLength(ny),
  };
}

/** Hele byggingen: adminskjermene bort, saa kommentarene ut av skriptet. */
export async function lettUtgave(kilde) {
  const { html, blokker } = utenAdmin(kilde);
  const k = await utenKommentarer(html);
  return { html: k.html, blokker, skript: k };
}

if (import.meta.filename === process.argv[1]) {
  const kilde = fs.readFileSync(KILDE, 'utf8');
  const { html, blokker, skript } = await lettUtgave(kilde);
  fs.writeFileSync(MAAL, html);
  const kb = (n) => Math.round(n / 1024);
  console.log(blokker.length + ' adminskjermer klippet bort.');
  console.log('  skriptet         ' + kb(skript.for) + ' kB → ' + kb(skript.etter) + ' kB');
  console.log('  full utgave      ' + kb(Buffer.byteLength(kilde)) + ' kB');
  console.log('  uten admin       ' + kb(Buffer.byteLength(html)) + ' kB'
    + '   (' + kb(Buffer.byteLength(kilde) - Buffer.byteLength(html)) + ' kB mindre)');
}
