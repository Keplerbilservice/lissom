/**
 * Passer paa at ingen skjerm blir bredere enn telefonen.
 *
 * ── Hvorfor ───────────────────────────────────────────────────────────
 *
 * 31. august sto eieren paa Oversikt paa iPhone og maatte dra sida
 * sidelengs for aa lese den. De to panelene «Ikke betalt» og statistikken
 * hadde faatt «grid-column: span 2» da de ble flyttet inn i kortrutenettet.
 * Paa telefonen har rutenettet bare én spalte, og «span 2» lager da en
 * spalte til: panelene ble 413 piksler paa en skjerm som er 390.
 *
 * Ingen av de 752 sjekkene i tests/backend.php tok den, og ingen av dem
 * kunne ha tatt den. De leser kildekoden som tekst, og «grid-column: span
 * 2» er en helt korrekt streng. Feilen oppstaar foerst naar nettleseren
 * regner ut hvor mange spalter det er plass til.
 *
 * Det som tar den, er aa aapne den ekte sida i telefonbredde og maale.
 * Det er alt dette skriptet gjor.
 *
 * ── Hva som maales ────────────────────────────────────────────────────
 *
 * For hver adresse i Component.STIER: finnes det et element som naar
 * lenger til hoyre enn skjermkanten?
 *
 * Det foerste jeg proevde var «scrollWidth paa <html> <= innerWidth». Den
 * gikk grønt paa den ekte feilen: sida ruller nemlig ikke sidelengs — de
 * 413 pikslene blir klippet av kanten, og dokumentet melder seg som 390.
 * Det er akkurat det eieren saa: teksten stod bare ikke der.
 *
 * Saa hvert element maales for seg. Naar noe stikker ut, skrives hva det
 * er, hvor bredt det er, hvor langt ut det gaar og hvilke spalter
 * forelderen har — det peker som regel rett paa synderen.
 *
 * Adressene leses ut av lissom-2108.html selv, fra STIER-tabellen. Legges
 * en ny skjerm inn der, er den med her fra samme oyeblikk — lista kan ikke
 * bli gammel.
 *
 * ── Hva som IKKE maales ───────────────────────────────────────────────
 *
 * Ting som ligger bak et klikk: skjemaer som aapner seg, dialoger,
 * kalenderens dags- og maanedsvisning. Skriptet ser hver skjerm slik den
 * staar naar den er lastet. Det daekker det som pleier aa ryke — rutenett
 * og brede rader — men ikke alt.
 *
 * Noen ting skal kunne rulle sidelengs inni sin egen ramme: kalenderens
 * rutenett («.lx-kalbred») og brede tabeller. De hopper vi over — alt som
 * ligger inne i noe med «overflow-x: auto» eller «scroll» er der med vilje.
 * Det er ramma som ruller, ikke sida.
 *
 * ── Bruk ──────────────────────────────────────────────────────────────
 *
 *   node bin/breddesjekk.mjs             # 390 px, alle skjermer
 *   node bin/breddesjekk.mjs 360         # en annen bredde
 *   node bin/breddesjekk.mjs 390 admin   # bare adressene som inneholder «admin»
 *
 * Krever playwright, og at den lokale tjeneren og databasen er i gang:
 *
 *   php -S 127.0.0.1:8124 ekte-ruter.php
 *   mariadbd --user=root
 *
 * Derfor kan den ikke ligge i GitHub-jobben, som bare sjekker PHP-syntaks.
 * Den kjores for haand for en push, som de andre sjekkene i bin/.
 */

import fs from 'fs';
import path from 'path';
import { createRequire } from 'module';

const krev = createRequire(import.meta.url);
const { chromium } = krev('/opt/node22/lib/node_modules/playwright/index.js');

const ROT = path.resolve(import.meta.dirname, '..');
const ADRESSE = (process.env.LISSOM_URL || 'http://test.lissom.no:8124').replace(/\/+$/, '');
const BRUKER = process.env.LISSOM_BRUKER || 'test';
const PASSORD = process.env.LISSOM_PASSORD || 'Testpassord1!';

const BREDDE = Number(process.argv[2]) || 390;
const FILTER = process.argv[3] || '';

let feil = 0;
const si = (ok, t) => { if (!ok) feil++; console.log((ok ? '  OK  ' : '  FEIL') + '  ' + t); };

/**
 * Adressene, lest ut av STIER-tabellen i sida selv.
 *
 * Tabellen er fasiten paa hvilke skjermer som finnes. Aa skrive lista av
 * her ville gitt to steder aa vedlikeholde, og det ene ville blitt glemt.
 */
function stier(kilde) {
  const fra = kilde.indexOf('static get STIER() {');
  if (fra < 0) return [];
  const til = kilde.indexOf('\n  }', fra);
  const blokk = kilde.slice(fra, til);
  const ut = [];
  for (const m of blokk.matchAll(/\{\s*sti:\s*'([^']+)'/g)) {
    if (!ut.includes(m[1])) ut.push(m[1]);
  }
  return ut;
}

const alle = stier(fs.readFileSync(path.join(ROT, 'lissom-2108.html'), 'utf8'));
const liste = FILTER ? alle.filter(s => s.includes(FILTER)) : alle;

if (liste.length === 0) {
  console.log('  FEIL  fant ingen adresser i STIER' + (FILTER ? ' som passer «' + FILTER + '»' : ''));
  process.exit(1);
}

console.log('Breddesjekk paa ' + BREDDE + ' px — ' + liste.length + ' skjermer paa ' + ADRESSE + '\n');

const b = await chromium.launch({
  args: ['--host-resolver-rules=MAP test.lissom.no 127.0.0.1', '--no-proxy-server'],
});
const kontekst = await b.newContext({
  viewport: { width: BREDDE, height: 900 },
  deviceScaleFactor: 2,
  isMobile: BREDDE < 700,
  hasTouch: BREDDE < 700,
});

// Innlogget som admin. Uten det sender side.php den lette utgaven, og
// ingen av de 34 adminskjermene finnes i det hele tatt.
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

/** Elementene som naar lenger til hoyre enn skjermkanten, bredest forst. */
const utenfor = () => {
  const vindu = window.innerWidth;
  const ut = [];
  document.querySelectorAll('*').forEach(e => {
    const r = e.getBoundingClientRect();
    if (r.right <= vindu + 1 || r.width < 40 || r.height < 12) return;

    // Ligger det inne i noe som skal rulle sidelengs, er det med vilje.
    // Kalenderens rutenett er det tydeligste: der ruller ramma, ikke sida.
    for (let f = e.parentElement; f; f = f.parentElement) {
      const o = getComputedStyle(f).overflowX;
      if (o === 'auto' || o === 'scroll') return;
    }

    // Pynt som blor ut av kanten med vilje. Vannmerket paa innloggingssida
    // ligger paa «right: -140px» og er 620 piksler bredt — det skal stikke
    // ut, og det er derfor det baerer «aria-hidden» og «pointer-events:
    // none». Begge deler er noe noen har skrevet med hensikt; en feil som
    // denne har ingen av dem.
    const st = getComputedStyle(e);
    if (e.getAttribute('aria-hidden') === 'true' || st.pointerEvents === 'none') return;

    // Er forelderen like bred, er det den som er synderen. Da hjelper det
    // ikke aa liste opp hvert barn under den ogsaa.
    const f = e.parentElement;
    if (f && Math.round(f.getBoundingClientRect().right) >= Math.round(r.right)) return;

    ut.push({
      navn: e.tagName.toLowerCase()
        + (e.id ? '#' + e.id : '')
        + (e.className && typeof e.className === 'string' && e.className.trim()
            ? '.' + e.className.trim().split(/\s+/)[0] : ''),
      tekst: (e.innerText || '').trim().slice(0, 30).replace(/\s+/g, ' '),
      bredde: Math.round(r.width),
      hoyre: Math.round(r.right),
      spalter: e.parentElement ? getComputedStyle(e.parentElement).gridTemplateColumns : 'none',
    });
  });
  return ut.sort((a, b2) => b2.bredde - a.bredde).slice(0, 4);
};

const p = await kontekst.newPage();
p.setDefaultTimeout(20000);

for (const sti of liste) {
  let maal;
  try {
    await p.goto(ADRESSE + sti, { waitUntil: 'domcontentloaded' });
    // Sida bygges om til React etter lasting. Uten en pause her maaler vi
    // malen for den er tegnet, og alt ser riktig ut.
    await p.waitForTimeout(3200);
    maal = await p.evaluate(utenfor);
  } catch (e) {
    si(false, sti + ' — kom ikke fram: ' + String(e).split('\n')[0].slice(0, 80));
    continue;
  }
  const verst = maal.length ? Math.max(...maal.map(e => e.hoyre)) - BREDDE : 0;
  si(maal.length === 0,
     sti + (maal.length === 0 ? '' : ' — ' + maal.length + ' element'
       + (maal.length === 1 ? '' : 'er') + ' utenfor kanten, verst ' + verst + ' px'));
  for (const e of maal) {
    console.log('          ' + e.bredde + ' px, naar til ' + e.hoyre + ': ' + e.navn
      + (e.tekst ? '  «' + e.tekst + '»' : '')
      + (e.spalter && e.spalter !== 'none' ? '  [spalter: ' + e.spalter + ']' : ''));
  }
}

await b.close();

console.log('\n' + (feil === 0
  ? 'Alle ' + liste.length + ' skjermene holder seg innenfor ' + BREDDE + ' px.'
  : feil + ' av ' + liste.length + ' skjermer er for brede.'));
process.exit(feil === 0 ? 0 : 1);
