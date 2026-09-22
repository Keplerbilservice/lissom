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
import * as acorn from 'acorn';

const ROT = path.resolve(import.meta.dirname, '..');
const KILDE = path.join(ROT, 'lissom-2108.html');
const MAAL = path.join(ROT, 'lissom-2108-uten-admin.html');

/**
 * Lesesidene som serveren tegner ferdig (app/nett/sider/). Malene deres
 * maa staa i lissom-2108.html — det er derfra serveren leser dem — men
 * kunden trenger dem ikke i appen: et trykk paa «Om oss» i menyen der er
 * en sidelasting (Component.SERVERSIDER i nettsida). Samme navn som i
 * app/nett/nett.php SIDER, bare som sc-if-verdien skjermen staar bak.
 */
export const LESESKJERMER = [
  // Forside, kurslista, nyheter og nyttig info er slettet fra kilden
  // (21. september 2026) — de finnes bare som serversider.
  'erOmOss', 'erSporsmal', 'erPersonvern', 'erVilkar',
  'erKursoversikt', 'erKalenderside', 'erPop',
  'erPlakatCone', 'erPlakatInfo', 'erPlakatTrivsel',
];

export function utenAdmin(kilde) {
  const blokker = [];
  let ut = kilde;
  // Bakfra, saa posisjonene foran ikke flytter seg underveis.
  const aapninger = [...kilde.matchAll(/\n {2}<sc-if value="\{\{ (er[A-Za-z0-9_]*) \}\}"/g)]
    .filter(m => m[1].startsWith('erAdmin') || LESESKJERMER.includes(m[1]));
  for (const m of aapninger.reverse()) {
    const slutt = ut.indexOf('\n  </sc-if>', m.index);
    if (slutt < 0) {
      throw new Error('Fant ikke slutten paa ' + m[1] + ' — sjekk innrykket i malen');
    }
    const til = slutt + '\n  </sc-if>'.length;
    blokker.push({ navn: m[1], bytes: Buffer.byteLength(ut.slice(m.index, til)) });
    ut = ut.slice(0, m.index) + '\n  <!-- ' + m[1] + (m[1].startsWith('erAdmin')
      ? ': sendes bare til innlogget admin -->'
      : ': tegnes av serveren, se app/nett/ -->')
       + ut.slice(til);
  }
  // Merket nettsida kjenner kundeutgaven paa (utenLeseskjermer()).
  if (!/\r?\n<body>\r?\n/.test(ut)) {
    throw new Error('Fant ikke <body> aa merke');
  }
  ut = ut.replace(/(\r?\n)<body>(\r?\n)/, '$1<body data-lett-utgave>$2');
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

/**
 * Verdiene for skjermer kunden ikke har, ut av skriptet.
 *
 * ── Hvorfor ───────────────────────────────────────────────────────────
 *
 * PageSpeed 22. september 2026, Book-sida paa mobil: fila var 1547 kB
 * (408 kB komprimert), og av skriptets 1006 kB var 917 kB det ene
 * objektet renderVals() bygger — verdiene for ALLE skjermene, ogsaa de 30
 * adminskjermene som alt er klippet ut av malen over. Kunden lastet ned
 * dem, nettleseren leste dem, og runtime regnet dem ut for hver tegning.
 * 677 kB av objektet (74 %) gav noekler ingen kundeskjerm bruker.
 *
 * ── Hvordan ───────────────────────────────────────────────────────────
 *
 * Objektet er «const vals = { … }» i renderVals(): en lang liste av
 * egenskaper og «...spredninger» (IIFE-er og this.metode()-kall) som hver
 * gir en haandfull noekler. For hver av dem finner vi noeklene den gir
 * (rekursivt gjennom return-objektene), og sjekker om noen av dem staar i
 * en «{{ … }}» i den malen som er igjen. Gir blokken bare noekler malen
 * aldri nevner, tas den ut.
 *
 * Det er strengt: en blokk beholdes hvis én noekkel brukes, og hvis
 * noeklene ikke lar seg lese ut sikkert (et kall vi ikke kan folge, en
 * beregnet noekkel). Etterpaa kontrolleres det at hver identifikator malen
 * bruker som fantes som noekkel foer, fortsatt finnes — ellers stoppes
 * byggingen. Kilden i lissom-2108.html roeres ikke; admin faar alt.
 */
export function utenAdminVals(html) {
  const j = html.lastIndexOf('</x-dc>');
  const m = /<script[^>]*data-dc-script[^>]*>/.exec(html.slice(j));
  const slutt = html.lastIndexOf('</script>');
  if (j < 0 || !m || slutt < 0) return { html, blokker: 0, for: 0, etter: 0 };
  const fra = j + m.index + m[0].length;
  const src = html.slice(fra, slutt);

  const ast = acorn.parse(src, { ecmaVersion: 2022, sourceType: 'script' });
  const cls = ast.body.find(n => n.type === 'ClassDeclaration');
  if (!cls) throw new Error('Fant ikke klassen i skriptet');
  const metoder = new Map(cls.body.body.map(x => [x.key.name || x.key.value, x]));
  const rv = metoder.get('renderVals');
  const decl = rv && rv.value.body.body.find(n => n.type === 'VariableDeclaration' && n.declarations[0].id.name === 'vals');
  const obj = decl && decl.declarations[0].init;
  if (!obj || obj.type !== 'ObjectExpression') throw new Error('Fant ikke «const vals = { … }» i renderVals');

  // Alle return-setninger i en funksjonskropp, uten aa gaa inn i indre funksjoner.
  const alleReturns = (block) => {
    const ut = [];
    (function gaa(n) {
      if (!n || typeof n.type !== 'string') return;
      if (n.type === 'ReturnStatement') { ut.push(n); return; }
      if (n.type === 'FunctionExpression' || n.type === 'ArrowFunctionExpression' || n.type === 'FunctionDeclaration') return;
      for (const k of Object.keys(n)) { const v = n[k]; if (Array.isArray(v)) v.forEach(gaa); else if (v && typeof v.type === 'string') gaa(v); }
    })(block);
    return ut;
  };
  // Noeklene et uttrykk gir. «?» foran betyr: kan ikke leses sikkert.
  const noekler = (node, dybde = 0) => {
    const ut = new Set();
    if (!node || dybde > 6) { ut.add('?dyp'); return ut; }
    if (node.type === 'ObjectExpression') {
      for (const p of node.properties) {
        if (p.type === 'Property') ut.add(p.computed ? '?beregnet' : (p.key.name || String(p.key.value)));
        else if (p.type === 'SpreadElement') for (const k of noekler(p.argument, dybde + 1)) ut.add(k);
        else ut.add('?' + p.type);
      }
    } else if (node.type === 'CallExpression') {
      const c = node.callee;
      if (c.type === 'ArrowFunctionExpression' || c.type === 'FunctionExpression') {
        const kropp = c.body.type === 'BlockStatement' ? c.body : { type: 'BlockStatement', body: [{ type: 'ReturnStatement', argument: c.body }] };
        for (const st of alleReturns(kropp)) for (const k of noekler(st.argument, dybde + 1)) ut.add(k);
      } else if (c.type === 'MemberExpression' && c.object.type === 'ThisExpression' && !c.computed && metoder.get(c.property.name)) {
        for (const st of alleReturns(metoder.get(c.property.name).value.body)) for (const k of noekler(st.argument, dybde + 1)) ut.add(k);
      } else ut.add('?kall');
    } else if (node.type === 'ConditionalExpression') {
      for (const k of noekler(node.consequent, dybde + 1)) ut.add(k);
      for (const k of noekler(node.alternate, dybde + 1)) ut.add(k);
    } else if (node.type === 'LogicalExpression') {
      for (const k of noekler(node.left, dybde + 1)) ut.add(k);
      for (const k of noekler(node.right, dybde + 1)) ut.add(k);
    } else ut.add('?' + node.type);
    return ut;
  };

  // Identifikatorene malen bruker.
  const mal = html.slice(html.indexOf('<body'), j);
  const brukt = new Set();
  for (const t of mal.matchAll(/\{\{\s*([^}]+?)\s*\}\}/g)) {
    for (const id of t[1].matchAll(/[A-Za-z_$][A-Za-z0-9_$]*/g)) brukt.add(id[0]);
  }

  const behold = [];
  const alleFoer = new Set();
  let fjernet = 0, fjernetBytes = 0;
  for (const p of obj.properties) {
    const ks = [...noekler(p.type === 'Property' ? { type: 'ObjectExpression', properties: [p] } : p.argument)];
    ks.filter(k => !k.startsWith('?')).forEach(k => alleFoer.add(k));
    const usikker = ks.some(k => k.startsWith('?')) || ks.length === 0;
    const brukes = ks.some(k => brukt.has(k));
    if (usikker || brukes) behold.push(p);
    else { fjernet++; fjernetBytes += p.end - p.start; }
  }

  // Objektet bygges opp igjen av det som beholdes, med kildeteksten urort.
  const nyObj = '{\n' + behold.map(p => src.slice(p.start, p.end)).join(',\n') + ',\n    }';
  const nySrc = src.slice(0, obj.start) + nyObj + src.slice(obj.end);

  // Kontroll 1: skriptet skal fortsatt kunne leses.
  const ast2 = acorn.parse(nySrc, { ecmaVersion: 2022, sourceType: 'script' });
  // Kontroll 2: alt malen bruker som var en noekkel foer, er en noekkel naa.
  const cls2 = ast2.body.find(n => n.type === 'ClassDeclaration');
  const metoder2 = new Map(cls2.body.body.map(x => [x.key.name || x.key.value, x]));
  const obj2 = metoder2.get('renderVals').value.body.body.find(n => n.type === 'VariableDeclaration' && n.declarations[0].id.name === 'vals').declarations[0].init;
  const alleEtter = new Set();
  for (const p of obj2.properties) [...noekler(p.type === 'Property' ? { type: 'ObjectExpression', properties: [p] } : p.argument)].filter(k => !k.startsWith('?')).forEach(k => alleEtter.add(k));
  const mangler = [...brukt].filter(id => alleFoer.has(id) && !alleEtter.has(id));
  if (mangler.length) throw new Error('Verdier malen bruker forsvant fra skriptet: ' + mangler.slice(0, 10).join(', '));

  return { html: html.slice(0, fra) + nySrc + html.slice(slutt), blokker: fjernet, for: Buffer.byteLength(src), etter: Buffer.byteLength(nySrc), bytes: fjernetBytes };
}

/** Hele byggingen: adminskjermene bort, verdiene deres ut av skriptet, saa kommentarene ut. */
export async function lettUtgave(kilde) {
  const { html, blokker } = utenAdmin(kilde);
  const v = utenAdminVals(html);
  const k = await utenKommentarer(v.html);
  return { html: k.html, blokker, vals: v, skript: k };
}

if (import.meta.filename === process.argv[1]) {
  const kilde = fs.readFileSync(KILDE, 'utf8');
  const { html, blokker, vals, skript } = await lettUtgave(kilde);
  fs.writeFileSync(MAAL, html);
  const kb = (n) => Math.round(n / 1024);
  console.log(blokker.filter(b => b.navn.startsWith('erAdmin')).length + ' adminskjermer og '
    + blokker.filter(b => !b.navn.startsWith('erAdmin')).length + ' leseskjermer klippet bort.');
  console.log('  verdier          ' + vals.blokker + ' blokker kunden ikke bruker ut av renderVals (' + kb(vals.bytes) + ' kB)');
  console.log('  skriptet         ' + kb(vals.for) + ' kB → ' + kb(vals.etter) + ' kB → ' + kb(skript.etter) + ' kB');
  console.log('  full utgave      ' + kb(Buffer.byteLength(kilde)) + ' kB');
  console.log('  uten admin       ' + kb(Buffer.byteLength(html)) + ' kB'
    + '   (' + kb(Buffer.byteLength(kilde) - Buffer.byteLength(html)) + ' kB mindre)');
}
