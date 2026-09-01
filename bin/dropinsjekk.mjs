/**
 * Passer paa at drop-in er borte.
 *
 * ── Hvorfor ───────────────────────────────────────────────────────────
 *
 * Drop-in er tatt ned. Eieren, 31. august:
 *
 *   «nå vil jeg at du fjerner det som har med drop in, i admin, min side,
 *    og nettsiden globalt i alle steder, alle kalendere, og du skal
 *    faktisk sjekke at det er borte»
 *
 * Den siste setningen er grunnen til at denne fila finnes. Foer dette laa
 * drop-in over hele huset — hver dag fra aatte om morgenen til ti om kvelden
 * i plasser paa halvannen time, over hundre datoer i basen mot noen faa
 * titalls kursdatoer — og overalt der de to blandet seg, druknet kursene:
 *
 *   Den offentlige kalenderen   54 drop-in-linjer i uke 36, mot ni kurs
 *   Kurs og deltakere           139 rader drop-in foer det foerste kurset
 *   «Planlagte kurs» paa Oversikt   165, der 116 var drop-in
 *   Adminkalenderen             ni bleke rader per dag i hver kolonne
 *
 * Eieren maatte si fra om hvert enkelt sted, én om gangen, over flere dager:
 * «drop inn kurs ligger i oversikten, hallo rydd opp og globalt» — «hvorfor
 * har jeg drop inn under planlagte kurs?» — «jeg er fittelei av aa si ting
 * hundre ganger». Hver gang rettet jeg stedet han pekte paa, og hver gang
 * sto det igjen fire andre.
 *
 * Grunnen til at det kunne skje er at ingen proeve kunne se det. Reglene
 * ligger i JavaScript som kjoerer i nettleseren; i fila er de bare tekst.
 * Dette skriptet aapner skjermene og ser etter.
 *
 * Se docs/DROP-IN.md for hva drop-in var, og hvordan det skrus paa igjen.
 * Skal det tilbake, skal denne fila slettes i samme slengen — ikke
 * kommenteres bort, ikke settes til aa godta det den naa avviser.
 *
 * ── Hvilke skjermer ───────────────────────────────────────────────────
 *
 * Alle sammen. Adressene leses ut av STIER-tabellen i lissom-2108.html, slik
 * breddesjekken gjor det — tabellen er fasiten paa hvilke skjermer som
 * finnes.
 *
 * Foerste utgave hadde en haandplukket liste paa 26 adresser. Eieren, 1.
 * september, med et skjermbilde av «Drop-in i verkstedet» i dra-lista paa
 * kalenderen: «Andre? Kan du ikke bare soke det opp da? Og faktisk sjekke».
 * Han hadde rett: en liste jeg skriver selv, dekker det jeg kom paa. Det er
 * den samme feilen som gjorde at han maatte melde fra om drop-in fem ganger.
 * Naa gaar den gjennom alt.
 *
 * ── Paa to bredder ────────────────────────────────────────────────────
 *
 * 390 px og 1400 px. innerText leser bare det som faktisk vises, og
 * skjermene tegner ulikt paa telefon og skjerm: fargeforklaringen i
 * adminkalenderen, sidemenyene, mobilpanelene. En vakt som bare kjorer paa
 * én bredde ser bare halve sida. Eieren jobber fra telefonen.
 *
 * ── Hva som maales ────────────────────────────────────────────────────
 *
 * To ting, fordi ordet alene ikke holder:
 *
 *   ORDET   «Drop-in» skal ikke staa noe sted paa noen skjerm. Ikke ett.
 *
 *           En kort periode hadde tre av skjermene unntak: Min side,
 *           Oversikt og Paameldte viste fortsatt ordet naar det fantes en
 *           betalt booking fra den tida drop-in gikk. Eieren, 1. september:
 *           «Historikken på drop inn skal også bort». Migrasjon 111 slettet
 *           de bookingene og betalingene, og unntaket er borte med dem.
 *   TALLENE Tellingene skjermen viser. «Planlagte kurs» sto paa 165 uten at
 *           ordet «Drop-in» sto noe sted paa Oversikt — skaden var tallet.
 *           Foerste utgave av dette skriptet ga groent paa akkurat den
 *           skjermen eieren spurte om, med filteret slaatt av. Derfor leses
 *           tallene ogsaa.
 *
 * ── Bruk ──────────────────────────────────────────────────────────────
 *
 *   node bin/dropinsjekk.mjs
 *
 * Krever playwright, og at den lokale tjeneren og databasen er i gang:
 *
 *   php -S 127.0.0.1:8124 ekte-ruter.php
 *   mariadbd --user=root
 *
 * Og at «DELETE FROM rate_limits» er kjort i lissom_test, ellers slipper
 * ikke innlogginga gjennom.
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { createRequire } from 'module';

const ROT = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const krev = createRequire(import.meta.url);
const { chromium } = krev('/opt/node22/lib/node_modules/playwright/index.js');

const ADRESSE = (process.env.LISSOM_URL || 'http://test.lissom.no:8124').replace(/\/+$/, '');
const BRUKER  = process.env.LISSOM_BRUKER  || 'test';
const PASSORD = process.env.LISSOM_PASSORD || 'Testpassord1!';

let feil = 0, sjekker = 0;
const si = (ok, t) => { sjekker++; if (!ok) feil++; console.log((ok ? '  OK  ' : '  FEIL') + '  ' + t); };

/**
 * Skjermene.
 *
 * «tall» er tellinger paa skjermen som ogsaa maa staa riktig: merket, og
 * hvor hoyt tallet under det faar vaere. Kursdatoene framover er noen faa
 * titalls; drop-in alene var 126. Et tak paa 90 skiller de to fra hverandre
 * uten aa ryke hver gang det settes opp et kurs til.
 */
/**
 * Hvorfor akkurat denne skjermen er verdt aa merke seg. Bare en note i
 * utskriften — lista over skjermer kommer fra STIER, ikke herfra, saa det aa
 * glemme en note gjor ingen skjerm usjekket.
 */
const HVORFOR = {
  '/':                 'forsida — «Tatt kurs, eller med et medlem? Book drop-in» sto her',
  '/kurs':             'kurslista',
  '/medlemskap':       'medlemskapssida — inngangen til drop-in laa her til slutt',
  '/kalender':         'den offentlige kalenderen — 54 linjer i uke 36 mot ni kurs',
  '/sporsmal-og-svar': 'to av tolv spoersmaal handlet om drop-in',
  '/booking':          'bookingen — godkjenningssteget sto her',
  '/min-side':         'Min side — knappen «Drop-in · kr. 490,-», og kundens egne kjop',
  '/admin':            'Oversikt — «Planlagte kurs» sto paa 165, der 116 var drop-in',
  '/admin/kurs/alle':  'Kurs og deltakere — 139 rader foer det foerste kurset',
  '/admin/kurs':       'kursoppsettet — «Drop-in» sto i Type-nedtrekket',
  '/admin/kalender':   'adminkalenderen — bleke rader per dag, og dra-lista «Alle kurs»',
  '/admin/pameldte':   'paameldte — raden for en deltaker som hadde betalt',
  '/admin/ny-registrering': 'ny registrering — «Drop-in» var ett av fire valg',
  '/admin/okonomi':    'oekonomi — hadde egen konto og mva-kode for drop-in',
  '/admin/innhold':    'Nettsiden → Innhold — hadde en egen Drop-in-seksjon',
  '/admin/seo':        'SEO — /drop-in var en av sidene',
  '/admin/ressurser':  'ressurser — merknaden paa dreieskivene nevnte drop-in',
};

/**
 * Tellinger paa skjermen som ogsaa maa staa riktig.
 *
 * Ordet alene holder ikke: «Planlagte kurs» sto paa 165 uten at ordet
 * «Drop-in» sto noe sted paa Oversikt — skaden var tallet. Kursdatoene
 * framover er noen faa titalls; drop-in alene var 126. Et tak paa 90 skiller
 * de to uten aa ryke hver gang det settes opp et kurs til.
 */
const TALL = {
  '/admin': [{ merke: 'Planlagte kurs', tak: 90, hvorfor: 'kursdatoer, ikke ni drop-in-plasser om dagen' }],
};

/** Adresser som ikke skal finnes lenger. */
const BORTE = ['/drop-in', '/admin/drop-in'];

/**
 * Adressene, lest ut av STIER-tabellen i sida selv — samme kilde som
 * breddesjekken bruker. Aa skrive lista av her ville gitt to steder aa
 * vedlikeholde, og det ene ville blitt glemt.
 */
function stier(kilde) {
  const fra = kilde.indexOf('static get STIER() {');
  if (fra < 0) return [];
  const blokk = kilde.slice(fra, kilde.indexOf('\n  }', fra));
  const ut = [];
  for (const m of blokk.matchAll(/\{\s*sti:\s*'([^']+)'/g)) {
    if (!ut.includes(m[1])) ut.push(m[1]);
  }
  return ut;
}

const SKJERMER = stier(fs.readFileSync(path.join(ROT, 'lissom-2108.html'), 'utf8'))
  .filter(sti => BORTE.indexOf(sti) === -1)
  .map(sti => ({ sti, hvorfor: HVORFOR[sti] || '', tall: TALL[sti] }));

if (SKJERMER.length === 0) {
  console.log('  FEIL  fant ingen adresser i STIER');
  process.exit(1);
}

const b = await chromium.launch({
  args: ['--host-resolver-rules=MAP test.lissom.no 127.0.0.1', '--no-proxy-server'],
});
const BREDDER = [
  { navn: 'mobil',   bredde: 390,  hoyde: 1400 },
  { navn: 'skjerm',  bredde: 1400, hoyde: 1100 },
];
const kontekst = await b.newContext({ viewport: { width: 1400, height: 1100 } });

// Innlogget som admin — uten det finnes ingen av adminskjermene.
const start = await kontekst.newPage();
await start.goto(ADRESSE + '/', { waitUntil: 'domcontentloaded' });
const paalogging = await start.evaluate(([bruker, passord]) =>
  fetch('/api/logg-inn.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ brukernavn: bruker, passord }),
  }).then(r => r.json()).catch(e => ({ feil: String(e) })), [BRUKER, PASSORD]);
await start.close();

if (!paalogging || paalogging.ok !== true) {
  console.log('  FEIL  fikk ikke logget inn som «' + BRUKER + '»: ' + JSON.stringify(paalogging));
  console.log('        Har du kjort «DELETE FROM rate_limits» i lissom_test?');
  await b.close();
  process.exit(1);
}

console.log('Drop-in-sjekk paa ' + ADRESSE + ' — ' + (SKJERMER.length + BORTE.length)
  + ' adresser x ' + BREDDER.length + ' bredder\n');

const p = await kontekst.newPage();
p.setDefaultTimeout(20000);

/** Henter teksten paa en skjerm. Sida bygges om etter lasting; maaler vi for
 *  tidlig, er den tom og alt ser riktig ut. */
const tekstPaa = async (sti) => {
  await p.goto(ADRESSE + sti, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(3200);
  return p.locator('body').innerText();
};

for (const bredde of BREDDER) {
await p.setViewportSize({ width: bredde.bredde, height: bredde.hoyde });
console.log('── ' + bredde.navn + ', ' + bredde.bredde + ' px ' + '─'.repeat(40));

for (const s of SKJERMER) {
  let tekst = '';
  try {
    tekst = await tekstPaa(s.sti);
  } catch (e) {
    si(false, s.sti + ' — kom ikke fram: ' + String(e).split('\n')[0].slice(0, 70));
    continue;
  }

  const traff = (tekst.match(/drop[\s-]?in/gi) || []).length;
  si(traff === 0, s.sti + ' er fri for drop-in' + (traff ? ' (' + traff + ' treff)' : '')
    + (s.hvorfor ? ' — ' + s.hvorfor : ''));
  if (traff) {
    tekst.split('\n').filter(l => /drop[\s-]?in/i.test(l)).slice(0, 5)
      .forEach(l => console.log('          ' + l.trim().slice(0, 80)));
  }

  // Tellingene. Kortet skriver tallet paa linja under merket.
  for (const t of (s.tall || [])) {
    const linjer = tekst.split('\n').map(l => l.trim());
    const i = linjer.findIndex(l => l === t.merke);
    const raa = i === -1 ? null : linjer.slice(i + 1, i + 4).find(l => /^\d+$/.test(l));
    if (raa == null) {
      si(false, s.sti + ' — fant ikke tallet under «' + t.merke + '»'
        + (i === -1 ? ' (merket sto ikke paa sida)' : ' (ingen tall paa de tre linjene under)'));
      continue;
    }
    const n = Number(raa);
    si(n <= t.tak, s.sti + ' teller ' + n + ' under «' + t.merke + '» (tak ' + t.tak + ') — ' + t.hvorfor
      + (n <= t.tak ? '' : '  ⟵ det er drop-in-plasser i tallet'));
  }
}

}

// Adressene skal ikke lenger fore noe sted. 404-skjermen er svaret; det som
// ikke er greit er at de fortsatt viser drop-in.
for (const sti of BORTE) {
  let tekst = '';
  try {
    tekst = await tekstPaa(sti);
  } catch (e) {
    si(false, sti + ' — kom ikke fram: ' + String(e).split('\n')[0].slice(0, 70));
    continue;
  }
  const traff = (tekst.match(/drop[\s-]?in/gi) || []).length;
  si(traff === 0, sti + ' finnes ikke lenger' + (traff ? ' — men viser fortsatt drop-in (' + traff + ' treff)' : ''));
}

await b.close();

console.log('\n' + (feil === 0
  ? 'Drop-in er borte (' + sjekker + ' sjekker).'
  : feil + ' av ' + sjekker + ' sjekker er feil.'));
process.exit(feil === 0 ? 0 : 1);
