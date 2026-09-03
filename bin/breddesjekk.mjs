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
 * ── To maal til, lagt til 4. september ────────────────────────────────
 *
 * Eieren fant to feil paa én kveld som denne vakta gikk gronn paa.
 *
 * «Kutter tall»: dagsoppgjoret i Kassa viste «kr 99» der det skulle staa
 * «kr 990». De tre spaltene ble 351 piksler i et rutenett paa 306, og
 * kortet — som har «overflow: hidden» for de runde hjornene — klippet
 * siste siffer. Sida var 390 piksler hele tida. Derfor maales det naa
 * ogsaa mot INNSIDA av det naermeste kortet som skjuler det som stikker
 * ut, ikke bare mot skjermkanten.
 *
 * «Feil deling av tall»: «kr. 5 470,-» brakk i to paa telefonen, fordi
 * tusenskillet var et vanlig mellomrom. Derfor leses teksten slik den
 * staar paa skjermen, og et mykt mellomrom mellom sifrene i et beloep
 * meldes uansett hvor i koden det kom fra.
 *
 * ── Hva som IKKE maales ───────────────────────────────────────────────
 *
 * Ting som ligger bak et klikk: skjemaer som aapner seg, dialoger,
 * kalenderens dags- og maanedsvisning. Skriptet ser hver skjerm slik den
 * staar naar den er lastet. Det daekker det som pleier aa ryke — rutenett
 * og brede rader — men ikke alt.
 *
 * Med ett unntak: EKSTRA-lista lenger nede. Fanene i Kassa har ingen egen
 * adresse, og det var nettopp i én av dem tallet ble klippet. De aapnes
 * med et trykk for de maales. Lista kan vokse; hver rad koster tre
 * sekunder.
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

/**
 * Innhold som blir klippet av et kort det ligger inni.
 *
 * Maalet over spor «stikker noe utenfor SIDA». Det er ikke det samme som
 * «blir noe borte». 4. september sto eieren med dagsoppgjoret i Kassa paa
 * telefonen og saa «kr 99» der det skulle staa «kr 990»: de tre spaltene
 * Kontant, Vipps og Totalt ble 351 piksler i et rutenett paa 306, og kortet
 * — som har «overflow: hidden» for aa holde de runde hjornene — klippet
 * siste siffer. Sida var 390 piksler bred hele tida. Maalet over var gronn.
 *
 * Her er kanten kortet, ikke skjermen: naar noe naar lenger til hoyre enn
 * innsida av den naermeste forelderen som skjuler det som stikker ut, blir
 * det borte for den som ser paa.
 *
 * Hva som IKKE telles:
 *
 *   ruller med vilje    ligger det inne i noe med «overflow-x: auto» eller
 *                       «scroll», er det ramma som ruller — ikke noe som
 *                       forsvinner. Samme regel som over.
 *   avkortet med vilje  «text-overflow: ellipsis» ER en avkorting noen har
 *                       bedt om, og den viser tre prikker saa leseren vet.
 *   pynt                «aria-hidden» og «pointer-events: none», som over.
 *   skrivefelt          et input ruller sitt eget innhold; det er ikke borte.
 */
const klippet = () => {
  const KLIPPER = new Set(['hidden', 'clip']);
  const ut = [];
  document.querySelectorAll('*').forEach(e => {
    const r = e.getBoundingClientRect();
    if (r.width < 8 || r.height < 8) return;
    if (['INPUT', 'TEXTAREA', 'SELECT', 'CANVAS', 'SVG', 'IMG'].includes(e.tagName)) return;

    const st = getComputedStyle(e);
    if (st.visibility === 'hidden' || st.pointerEvents === 'none') return;
    if (e.getAttribute('aria-hidden') === 'true') return;

    // Den naermeste forelderen som bestemmer skjebnen sidelengs.
    let ramme = null;
    for (let f = e.parentElement; f; f = f.parentElement) {
      const o = getComputedStyle(f).overflowX;
      if (o === 'auto' || o === 'scroll') return;
      if (KLIPPER.has(o)) { ramme = f; break; }
    }
    if (ramme === null) return;

    const rst = getComputedStyle(ramme);
    if (rst.textOverflow === 'ellipsis') return;

    // Innsida av ramma: rammas venstre kant, pluss kantlinja, pluss det den
    // faktisk har plass til. «clientWidth» er nettopp den bredden.
    const rr = ramme.getBoundingClientRect();
    const innsida = rr.left + parseFloat(rst.borderLeftWidth || '0') + ramme.clientWidth;
    const over = Math.round(r.right - innsida);
    if (over <= 2) return;

    // Er forelderen like bred, er det den som er synderen. Da hjelper det
    // ikke aa liste opp hvert barn under den ogsaa.
    const f = e.parentElement;
    if (f && f !== ramme && Math.round(f.getBoundingClientRect().right) >= Math.round(r.right)) return;

    ut.push({
      navn: e.tagName.toLowerCase()
        + (e.id ? '#' + e.id : '')
        + (e.className && typeof e.className === 'string' && e.className.trim()
            ? '.' + e.className.trim().split(/\s+/)[0] : ''),
      tekst: (e.innerText || '').trim().slice(0, 30).replace(/\s+/g, ' '),
      over,
      ramme: ramme.tagName.toLowerCase()
        + (ramme.className && typeof ramme.className === 'string' && ramme.className.trim()
            ? '.' + ramme.className.trim().split(/\s+/)[0] : ''),
      spalter: getComputedStyle(e.parentElement || ramme).gridTemplateColumns,
    });
  });
  return ut.sort((a, b2) => b2.over - a.over).slice(0, 4);
};

/**
 * Beloep som kan brekke midt i tallet.
 *
 * «kr. 5 470,-» skal staa samlet. Skilles tusenene med et vanlig
 * mellomrom, har nettleseren lov til aa brekke der — og paa en telefon
 * blir det «kr. 5» paa én linje og «470,- utestaaende» paa neste.
 *
 * Eieren, 4. september, med bilde av det paa Oversikt: «feil deling av tall
 * her ogsaa» — og da han maatte peke paa det andre gang: «maa jeg si dette
 * flere ganger?».
 *
 * Nei. Regelen er at begge mellomrommene i et beloep er harde (U+00A0),
 * slik Booking::kroner() gjor det paa serveren. Denne maalingen leser
 * teksten slik den faktisk staar paa skjermen, og finner et vanlig
 * mellomrom mellom sifrene uansett hvor i koden det kom fra.
 *
 * Bare tall med «kr» foran telles. Et aarstall eller et antall som noen har
 * skrevet inn i en tekst, er ikke vaart aa rette.
 */
const myktTall = () => {
  const MYKT = /kr[.\s\u00a0]{0,3}\d{1,3}\u0020\d{3}/;
  const ut = [];
  document.querySelectorAll('*').forEach(e => {
    if (e.children.length) return;
    const t = (e.innerText || '').trim();
    if (!t || !MYKT.test(t)) return;
    if (e.getBoundingClientRect().height === 0) return;
    ut.push({
      navn: e.tagName.toLowerCase()
        + (e.className && typeof e.className === 'string' && e.className.trim()
            ? '.' + e.className.trim().split(/\s+/)[0] : ''),
      tekst: t.slice(0, 46).replace(/\s+/g, ' '),
    });
  });
  // Samme tekst kan staa flere steder; det er den ene feilen som skal rettes.
  const sett = new Set();
  return ut.filter(e => { if (sett.has(e.tekst)) return false; sett.add(e.tekst); return true; })
           .slice(0, 4);
};

/**
 * Skjermer som ligger bak et trykk.
 *
 * Adressene i STIER daekker sida slik den staar naar den er lastet. Fanene
 * i Kassa har ingen egen adresse — og det var nettopp i én av dem tallet
 * ble klippet. Her staar de faa som er verdt aa maale, med knappen som
 * aapner dem. Lista kan vokse; hver rad koster tre sekunder.
 */
const EKSTRA = [
  { sti: '/admin/uttak', klikk: ['Betalinger'] },
  { sti: '/admin/uttak', klikk: ['Varer i butikken'] },
  { sti: '/admin/uttak', klikk: ['Internbutikk'] },
];

const p = await kontekst.newPage();
p.setDefaultTimeout(20000);

/** Trykker paa en knapp med denne teksten — den ekte, ikke malen. */
const trykk = async (side, tekst) => await side.evaluate(t => {
  const el = Array.from(document.querySelectorAll('button'))
    .filter(x => (x.innerText || '').trim() === t)
    .find(x => x.getBoundingClientRect().height > 0);
  if (!el) return false;
  el.click();
  return true;
}, tekst);

/** Maaler én skjerm slik den staar naa, og skriver det som er galt. */
const maalNa = async (navn) => {
  const ute = await p.evaluate(utenfor);
  const kl = await p.evaluate(klippet);
  const mt = await p.evaluate(myktTall);
  const verst = ute.length ? Math.max(...ute.map(e => e.hoyre)) - BREDDE : 0;
  const deler = [];
  if (ute.length) {
    deler.push(ute.length + ' utenfor kanten, verst ' + verst + ' px');
  }
  if (kl.length) {
    deler.push(kl.length + ' klippet av et kort, verst ' + kl[0].over + ' px');
  }
  if (mt.length) {
    deler.push(mt.length + ' beloep som kan brekke midt i tallet');
  }
  si(deler.length === 0, navn + (deler.length ? ' — ' + deler.join(' · ') : ''));
  for (const e of ute) {
    console.log('          ' + e.bredde + ' px, naar til ' + e.hoyre + ': ' + e.navn
      + (e.tekst ? '  «' + e.tekst + '»' : '')
      + (e.spalter && e.spalter !== 'none' ? '  [spalter: ' + e.spalter + ']' : ''));
  }
  for (const e of kl) {
    console.log('          klippet ' + e.over + ' px av ' + e.ramme + ': ' + e.navn
      + (e.tekst ? '  «' + e.tekst + '»' : '')
      + (e.spalter && e.spalter !== 'none' ? '  [spalter: ' + e.spalter + ']' : ''));
  }
  for (const e of mt) {
    console.log('          mykt mellomrom i beloepet: ' + e.navn + '  «' + e.tekst + '»');
  }
};

for (const sti of liste) {
  try {
    await p.goto(ADRESSE + sti, { waitUntil: 'domcontentloaded' });
    // Sida bygges om til React etter lasting. Uten en pause her maaler vi
    // malen for den er tegnet, og alt ser riktig ut.
    await p.waitForTimeout(3200);
  } catch (e) {
    si(false, sti + ' — kom ikke fram: ' + String(e).split('\n')[0].slice(0, 80));
    continue;
  }
  await maalNa(sti);
}

// Skjermene bak et trykk. Bare de som er filtrert bort over hoppes.
for (const e of EKSTRA) {
  if (FILTER && !e.sti.includes(FILTER)) continue;
  const navn = e.sti + ' → ' + e.klikk.join(' → ');
  try {
    await p.goto(ADRESSE + e.sti, { waitUntil: 'domcontentloaded' });
    await p.waitForTimeout(3200);
    for (const t of e.klikk) {
      if (!(await trykk(p, t))) {
        si(false, navn + ' — fant ingen knapp «' + t + '»');
        throw new Error('hoppet');
      }
      await p.waitForTimeout(1500);
    }
  } catch (feilen) {
    if (String(feilen).includes('hoppet')) continue;
    si(false, navn + ' — kom ikke fram: ' + String(feilen).split('\n')[0].slice(0, 80));
    continue;
  }
  await maalNa(navn);
}

await b.close();

const antall = liste.length + EKSTRA.filter(e => !FILTER || e.sti.includes(FILTER)).length;
console.log('\n' + (feil === 0
  ? 'Alle ' + antall + ' skjermene holder seg innenfor ' + BREDDE
    + ' px, ingenting blir klippet av et kort, og ingen beloep kan brekke.'
  : feil + ' av ' + antall + ' skjermer har noe som ikke er synlig.'));
process.exit(feil === 0 ? 0 : 1);
