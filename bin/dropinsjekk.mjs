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
 * ── Hva som maales ────────────────────────────────────────────────────
 *
 * To ting, fordi ordet alene ikke holder:
 *
 *   ORDET   «Drop-in» skal ikke staa noe sted paa noen skjerm.
 *           Unntaket er de tre skjermene som viser BETALTE bookinger fra
 *           den tida drop-in gikk. En kunde som har betalt kr. 490,- skal
 *           fortsatt se kjopet sitt, og linja skal fortsatt staa i
 *           regnskapet. De er merket «historikk» under, og skriptet sier
 *           fra om hva det fant der uten aa felle det. Skal historikken
 *           ogsaa bort, er det en egen beslutning — se docs/DROP-IN.md
 *           punkt 6 — og da skal merket fjernes herfra.
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

import { createRequire } from 'module';

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
const SKJERMER = [
  // ── Nettsiden ──────────────────────────────────────────────────────
  { sti: '/',              hvorfor: 'forsida — «Tatt kurs, eller med et medlem? Book drop-in» sto her' },
  { sti: '/kurs',          hvorfor: 'kurslista' },
  { sti: '/events',        hvorfor: 'events' },
  { sti: '/medlemskap',    hvorfor: 'medlemskapssida — inngangen til drop-in laa her til slutt' },
  { sti: '/kalender',      hvorfor: 'den offentlige kalenderen — 54 linjer i uke 36 mot ni kurs' },
  { sti: '/sporsmal-og-svar', hvorfor: 'to av tolv spoersmaal handlet om drop-in' },
  { sti: '/booking',       hvorfor: 'bookingen — godkjenningssteget sto her' },
  { sti: '/min-side',      hvorfor: 'Min side — «Timene er brukt opp → Drop-in kr. 490,-»',
    historikk: 'kundens egne kjop og plasser' },
  { sti: '/om-oss',        hvorfor: 'om oss' },
  { sti: '/nyttig-info',   hvorfor: 'nyttig info' },

  // ── Admin ──────────────────────────────────────────────────────────
  { sti: '/admin',         hvorfor: 'Oversikt — «Planlagte kurs» sto paa 165, der 116 var drop-in',
    historikk: 'salg per kurs',
    tall: [{ merke: 'Planlagte kurs', tak: 90, hvorfor: 'kursdatoer, ikke ni drop-in-plasser om dagen' }] },
  { sti: '/admin/kurs/alle', hvorfor: 'Kurs og deltakere — 139 rader foer det foerste kurset' },
  { sti: '/admin/kurs',    hvorfor: 'kursoppsettet — «Drop-in» sto i Type-nedtrekket' },
  { sti: '/admin/kalender', hvorfor: 'adminkalenderen — ni bleke rader per dag i hver kolonne' },
  { sti: '/admin/pameldte', hvorfor: 'paameldte', historikk: 'raden for en deltaker som har betalt' },
  { sti: '/admin/ny-registrering', hvorfor: 'ny registrering — «Drop-in» var ett av fire valg' },
  { sti: '/admin/okonomi', hvorfor: 'oekonomi — hadde egen konto og mva-kode for drop-in' },
  { sti: '/admin/innhold', hvorfor: 'Nettsiden → Innhold — hadde en egen Drop-in-seksjon' },
  { sti: '/admin/seo',     hvorfor: 'SEO — /drop-in var en av sidene' },
  { sti: '/admin/medlemskap', hvorfor: 'medlemskap' },
  { sti: '/admin/ressurser', hvorfor: 'ressurser' },
  { sti: '/admin/beskjeder', hvorfor: 'beskjeder' },
  { sti: '/admin/nyttig',  hvorfor: 'nyttig' },
  { sti: '/admin/varsler', hvorfor: 'varsler' },
];

/** Adresser som ikke skal finnes lenger. */
const BORTE = ['/drop-in', '/admin/drop-in'];

const b = await chromium.launch({
  args: ['--host-resolver-rules=MAP test.lissom.no 127.0.0.1', '--no-proxy-server'],
});
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

console.log('Drop-in-sjekk paa ' + ADRESSE + ' — ' + (SKJERMER.length + BORTE.length) + ' adresser\n');

const p = await kontekst.newPage();
p.setDefaultTimeout(20000);

/** Henter teksten paa en skjerm. Sida bygges om etter lasting; maaler vi for
 *  tidlig, er den tom og alt ser riktig ut. */
const tekstPaa = async (sti) => {
  await p.goto(ADRESSE + sti, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(3200);
  return p.locator('body').innerText();
};

for (const s of SKJERMER) {
  let tekst = '';
  try {
    tekst = await tekstPaa(s.sti);
  } catch (e) {
    si(false, s.sti + ' — kom ikke fram: ' + String(e).split('\n')[0].slice(0, 70));
    continue;
  }

  const traff = (tekst.match(/drop[\s-]?in/gi) || []).length;
  if (s.historikk) {
    // Her kan ordet staa, men bare som navnet paa noe som alt er betalt.
    console.log('  ~     ' + s.sti + ' — ' + (traff
      ? traff + ' treff, ventet: ' + s.historikk
      : 'ingen treff (' + s.historikk + ' finnes ikke i basen naa)'));
    tekst.split('\n').filter(l => /drop[\s-]?in/i.test(l)).slice(0, 3)
      .forEach(l => console.log('          ' + l.trim().slice(0, 80)));
  } else {
    si(traff === 0, s.sti + ' er fri for drop-in' + (traff ? ' (' + traff + ' treff)' : '') + ' — ' + s.hvorfor);
    if (traff) {
      tekst.split('\n').filter(l => /drop[\s-]?in/i.test(l)).slice(0, 5)
        .forEach(l => console.log('          ' + l.trim().slice(0, 80)));
    }
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

const medHistorikk = SKJERMER.filter(s => s.historikk).length;
console.log('\n' + (feil === 0
  ? 'Drop-in er borte (' + sjekker + ' sjekker).'
  : feil + ' av ' + sjekker + ' sjekker er feil.'));
console.log('Linjene med ~ er de ' + medHistorikk + ' skjermene der betalte kjop fra '
  + 'den tida drop-in gikk\nfortsatt skal kunne leses. Se docs/DROP-IN.md punkt 6.');
process.exit(feil === 0 ? 0 : 1);
