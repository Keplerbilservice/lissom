/**
 * Min side-vakt — kjøres før hver publisering (tests/alle.sh).
 *
 * Eieren, 30. september 2026: det nye admin bygges på /admin2 ved siden av
 * det gamle, og «Min side» for medlemmer og for kursdeltakere skal ikke
 * røres. Denne fila klikker seg gjennom hele Min side for et medlem og for
 * en kursdeltaker, på telefon (390 px med berøring) og på PC, og sammenligner
 * svarene fra API-ene Min side leser med en lagret fasit
 * (tests/godkjent/minside-fasit/). Endrer noe seg, stopper publiseringen.
 *
 *   bash tests/nettleser/kjor.sh minside.mjs
 *   E2E_FASIT_SKRIV=1 bash tests/nettleser/kjor.sh minside.mjs   → ny fasit
 *
 * Se tests/nettleser/kjor.sh for oppsettet (server, falsk Vipps, testdata).
 */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const HER = path.dirname(fileURLToPath(import.meta.url));
const ROT = path.resolve(HER, '..', '..');
const FASIT = path.join(ROT, 'tests', 'godkjent', 'minside-fasit');
const krev = createRequire(import.meta.url);
let chromium;
for (const fra of [ROT, path.resolve(ROT, '..', 'lissom')]) {
  try { ({ chromium } = krev(krev.resolve('playwright', { paths: [fra] }))); break; } catch { /* prov neste */ }
}
if (!chromium) { console.error('Fant ikke playwright. npm install playwright'); process.exit(1); }

const S = JSON.parse(process.env.E2E_SEED || '{}');
const ADR = process.env.E2E_ADRESSE || 'http://lokal.lissom.no:8140';
const VERT = new URL(ADR).hostname;

// ── Hjelpere (samme som i flyter.mjs) ─────────────────────────────────
let ok = 0;
const feil = [];
const sjekk = (hva, stemmer, detalj = '') => {
  if (stemmer) { ok++; console.log(`  OK    ${hva}`); }
  else { feil.push(hva + (detalj ? ' — ' + detalj : '')); console.log(`  FEIL  ${hva}${detalj ? ' — ' + detalj : ''}`); }
};
const db = (sql, p = {}) => JSON.parse(execFileSync('php', [path.join(HER, 'db.php'), sql, JSON.stringify(p)], { cwd: ROT, encoding: 'utf8' }) || '[]');
const verdi = (sql, p = {}) => { const r = db(sql, p)[0]; return r ? Object.values(r)[0] : null; };
const php = (kode) => JSON.parse(execFileSync('php', [path.join(HER, 'db.php'), '--php', kode], { cwd: ROT, encoding: 'utf8' }) || 'null');
const bryter = (nokkel, paa) => db("INSERT INTO content_blocks (nokkel, verdi) VALUES (:k, :v) ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)", { k: 'Vis/' + nokkel, v: paa ? 'ja' : 'nei' });

const nettleser = await chromium.launch({ args: [`--host-resolver-rules=MAP ${VERT} 127.0.0.1`] });
const skriptfeil = [];
const brukere = {};
async function side(hvem, bredde = 1358, hoyde = 900) {
  const mobil = bredde < 600;
  const k = await nettleser.newContext({ viewport: { width: bredde, height: hoyde }, isMobile: mobil, hasTouch: mobil });
  const token = (brukere[hvem] || S[hvem] || {}).token;
  if (token) await k.addCookies([{ name: 'lissom_sesjon', value: token, domain: VERT, path: '/', httpOnly: true }]);
  await k.addInitScript(() => { try { localStorage.setItem('lissom-samtykke', 'nei'); } catch (e) {} });
  const p = await k.newPage();
  p.on('pageerror', e => skriptfeil.push(`${p.url()} — ${String(e.message).split('\n')[0]}`));
  p.on('dialog', d => d.accept());
  await p.route('https://falsk.vipps/**', r => r.fulfill({ status: 200, body: 'falsk vipps' }));
  return p;
}
// Min side spør serveren jevnlig (hvem er inne, chat), saa «networkidle»
// kommer aldri. Vent paa lasting og gi skjermen tid.
const gaa = async (p, sti, ms = 3500) => { await p.goto(ADR + sti, { waitUntil: 'load', timeout: 45000 }); await p.waitForTimeout(ms); };
const api = (p, sti, kropp) => p.evaluate(async ([sti, kropp]) => {
  const r = await fetch(sti, kropp ? { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(kropp) } : { credentials: 'same-origin', cache: 'no-store' });
  let d; try { d = await r.json(); } catch { d = null; }
  return { status: r.status, d };
}, [sti, kropp]);
const dump = async (p, navn) => { if (!process.env.E2E_DUMP) return; fs.writeFileSync(path.join(process.env.E2E_DUMP, navn + '.txt'), await p.evaluate(() => document.body.innerText + '\n=== KNAPPER ===\n' + [...document.querySelectorAll('button,[role=button]')].map(b => (b.offsetParent ? 'V ' : 'H ') + b.innerText.trim().slice(0, 60)).join('\n'))); await p.screenshot({ path: path.join(process.env.E2E_DUMP, navn + '.png'), fullPage: true }).catch(() => {}); };
const synlig = (p, tekst, exact = false) => p.getByText(tekst, { exact }).filter({ visible: true }).first().isVisible().catch(() => false);
const trykk = async (loc) => {
  if (!(await loc.isVisible().catch(() => false))) return false;
  await loc.scrollIntoViewIfNeeded({ timeout: 3000 }).catch(() => {});
  await loc.click({ timeout: 5000 }).catch(() => loc.tap({ timeout: 5000 })).catch(() => loc.click({ force: true, timeout: 5000 })).catch(() => {});
  return true;
};
const lukkVinduer = async (p) => {
  if (await p.locator('[data-tp-vindu]').count() === 0) return;
  await p.locator('[data-tp-knapp="Ikke nå"]').click().catch(() => {});
  await p.waitForTimeout(300);
  await p.locator('[data-tp-knapp="Hopp over"]').click().catch(() => {});
  await p.waitForTimeout(300);
};

async function flyt(navn, fn) {
  if (process.env.E2E_BARE && !navn.startsWith(process.env.E2E_BARE)) return;
  console.log(`\n── ${navn} ──`);
  try { await fn(); } catch (e) { sjekk(`${navn} kjorte ferdig`, false, String(e.message).split('\n')[0]); }
}

// ── Testpersonene ─────────────────────────────────────────────────────
//
// Faste navn, saa fasiten kan sammenlignes fra kjoring til kjoring. Alt har
// e-post paa @e2e.lissom.test og ryddes av seed.php og til slutt her.
const FLATE = ['internbutikk', 'medlemssalg', 'handleliste', 'medlemsforslag', 'dugnad', 'medlemfrys', 'verving', 'gaven', 'skisser', 'skissermedlemmer', 'skisserdeltakere', 'tilleggbarn', 'internkurs'];
const lagPerson = (nokkel, navn, { status = 'aktiv', plan = null, minutter = 0, bindingMnd = null, betalerIkke = false, frys = false, timepakke = 0, plasser = true } = {}) => {
  const p = php(`
    $plan = ${plan ? `'${plan}'` : 'null'};
    $pl = $plan ? Medlemskap::plan($plan) : null;
    $forventetSiste = null;
    $retteTil = null;
    $id = DB::settInn('members', ['navn' => '${navn}', 'epost' => 'minside-${nokkel}-' . '${S.tag}' . '@e2e.lissom.test',
      'telefon' => '+4791${String(Object.keys(brukere).length).padStart(6, '0')}', 'rolle' => 'medlem', 'status' => '${status}',
      'medlemskap_type' => $plan, 'start_dato' => gmdate('Y-m-01'), 'betaler_ikke' => ${betalerIkke ? 1 : 0},
      'slutt_dato' => ($pl && (int) $pl['engangs'] === 1) ? Medlemskap::proveSlutt() : null]);
    if ($plan) {
      $b = ${bindingMnd === null ? 'null' : bindingMnd};
      DB::settInn('subscriptions', ['member_id' => $id, 'plan' => $plan, 'pris_ore' => (int) $pl['pris_ore'], 'status' => 'aktiv',
        'binding_til' => $b ? gmdate('Y-m-d', strtotime('+' . $b . ' months')) : null]);
    }
    require dirname(__DIR__) . '/betalt-fixture.php';
    test_betalt_medlem($id);
    if (${minutter} > 0) {
      $m = strtotime(Stempling::manedStart() . ' UTC') + 120;
      $oktId = DB::settInn('check_ins', ['member_id' => $id, 'inn_tid' => gmdate('Y-m-d H:i:s', $m), 'ut_tid' => gmdate('Y-m-d H:i:s', $m + ${minutter} * 60), 'minutter' => ${minutter}]);
      // Samme kjente testokt er gammel de fleste dager, men nylig ved
      // maanedsskiftet. Regelen er ett doegn etter norsk stengetid kl. 23.
      $oslo = new DateTimeZone('Europe/Oslo');
      $inn = (new DateTimeImmutable('@' . $m))->setTimezone($oslo);
      $ut = (new DateTimeImmutable('@' . ($m + ${minutter} * 60)))->setTimezone($oslo);
      $retteTil = $inn->setTime(23, 0)->modify('+24 hours')->getTimestamp();
      $forventetSiste = ['id' => $oktId, 'auto' => false, 'apen' => false,
        'dag' => Booking::norskDatoKort(gmdate('Y-m-d H:i:s', $m)),
        'inn' => $inn->format('H:i'), 'ut' => $ut->format('H:i')];
    }
    if (${timepakke} > 0) {
      DB::settInn('timepakker', ['member_id' => $id, 'timer' => ${timepakke}, 'pris_ore' => 80000, 'status' => 'betalt', 'betalt_at' => gmdate('Y-m-d H:i:s')]);
    }
    if (${frys ? 1 : 0}) {
      // Godkjent frys som dekker i dag (eieren, 2. oktober 2026): medlemmet
      // er fryst, med en betalt periode. Foer laa det en soknad fram i tid her,
      // og det frosne medlemmet sto som «Aktivt» med «Stemple inn».
      DB::settInn('medlem_frys', ['member_id' => $id, 'fra_dato' => gmdate('Y-m-d', time() - 86400 * 5), 'til_dato' => gmdate('Y-m-d', time() + 86400 * 25), 'status' => 'godkjent', 'status_for' => 'aktiv', 'begrunnelse' => 'Reise']);
    }
    if (${plasser ? 1 : 0}) {
      DB::settInn('bookings', ['course_id' => ${S.kurs}, 'course_session_id' => ${S.okter.a}, 'member_id' => $id, 'antall' => 1, 'belop_ore' => 280000, 'status' => 'betalt']);
      DB::settInn('bookings', ['course_id' => ${S.kurs}, 'course_session_id' => ${S.okter.for}, 'member_id' => $id, 'antall' => 1, 'belop_ore' => 280000, 'status' => 'betalt']);
    }
    $t = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['token_hash' => hash('sha256', $t), 'member_id' => $id, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 7200)]);
    return ['id' => $id, 'token' => $t, 'forventetSiste' => $forventetSiste, 'retteTil' => $retteTil];`);
  brukere[nokkel] = p;
  return p;
};
const plan = (hva, rekke = 'sortering') => verdi(`SELECT navn FROM membership_plans WHERE aktiv = 1 AND ${hva} ORDER BY ${rekke} LIMIT 1`);
const PROVE = plan('engangs = 1');
const MINI = plan('engangs = 0 AND krever_fast_trekk = 0 AND timer IS NOT NULL', 'timer');
const MINITIMER = Number(verdi('SELECT timer FROM membership_plans WHERE navn = :n', { n: MINI }));
const BASIS = verdi("SELECT navn FROM membership_plans WHERE aktiv = 1 AND engangs = 0 AND krever_fast_trekk = 0 AND timer IS NOT NULL AND navn <> :n ORDER BY timer DESC LIMIT 1", { n: MINI });
const AAR = plan('binding_mnd >= 12');

const FOER = Object.fromEntries(db("SELECT nokkel, verdi FROM content_blocks WHERE nokkel LIKE 'Vis/%'").map(r => [r.nokkel, r.verdi]));
FLATE.forEach(k => bryter(k, true));
bryter('glemtstempling', false);

lagPerson('prove', 'Minside Prøve', { status: 'prove', plan: PROVE, minutter: 120 });
lagPerson('mini', 'Minside Mini', { plan: MINI, minutter: 180, bindingMnd: 2 });
lagPerson('basis', 'Minside Basis', { plan: BASIS, bindingMnd: 2 });
lagPerson('aar', 'Minside Aar', { plan: AAR, minutter: 60, bindingMnd: 12 });
lagPerson('pakke', 'Minside Pakke', { plan: MINI, minutter: MINITIMER * 60 + 30, timepakke: 6 });
lagPerson('over', 'Minside Over', { plan: MINI, minutter: MINITIMER * 60 + 90 });
lagPerson('frosset', 'Minside Frosset', { status: 'pause', plan: MINI, frys: true });
lagPerson('betalerikke', 'Minside Betalerikke', { plan: MINI, betalerIkke: true });
lagPerson('deltaker', 'Minside Deltaker', { status: 'ingen' });

// ── 0. Fasit (først, før flytene endrer noe): svarene Min side leser ─────────────────────────────────
//
// For hver av de åtte hentes svarene fra API-ene Min side bruker, og det
// som endrer seg fra dag til dag (id-er, datoer, klokkeslett, tokens,
// lenker med nøkler) byttes ut med plassholdere. Resten skal være likt fra
// kjøring til kjøring. Blir et svar annerledes, er Min side endret.
const FASIT_API = ['/api/meg.php', '/api/medlemskap.php', '/api/stempling.php', '/api/handleliste.php', '/api/skisser.php?sjekk=1',
  '/api/mine-plasser.php', '/api/mine-kjop.php', '/api/butikk.php', '/api/medlem-frys.php', '/api/gave.php',
  '/api/medlemsforslag.php', '/api/mine-bilder.php', '/api/mine-dokumenter.php', '/api/medlemssalg.php'];
const MND = 'januar|februar|mars|april|mai|juni|juli|august|september|oktober|november|desember';
const DAG = 'mandag|tirsdag|onsdag|torsdag|fredag|lørdag|søndag';
const normTekst = (s) => String(s)
  .replace(/\r\n/g, '\n')
  .replace(new RegExp(S.tag, 'g'), '<tag>')
  .replace(/([?&][A-Za-z_]+=)\d+/g, '$1<id>')
  .replace(/(\/)\d{2,}(?=[/?.#]|$)/g, '$1<id>')
  .replace(/\b[0-9a-f]{24,}\b/gi, '<nøkkel>')
  .replace(/\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?/g, '<dato>')
  .replace(new RegExp(`\\b(${DAG})\\b`, 'gi'), '<dag>')
  .replace(new RegExp(`\\b\\d{1,2}\\.\\s*(${MND})(\\s+\\d{4})?`, 'gi'), '<dato>')
  .replace(new RegExp(`\\b(${MND})\\b(\\s+\\d{4})?`, 'gi'), '<måned>')
  .replace(/\b\d{1,2}[:.]\d{2}\b/g, '<kl>')
  .replace(/\b(19|20)\d{2}\b/g, '<år>')
  .replace(/\+?47\d{8}\b/g, '<telefon>')
  .replace(/Uke \d+/g, 'Uke <n>');
const IDNOKLER = /^(id|.*Id|.*_id|token|ref|referanse|reference|vippsRef|kode|verveKode|lenke|url|href|bilde|sjekksum|versjon|sist.*|.*Tid|.*_at|opprettet|dato|.*Dato)$/;
const norm = (v, nokkel = '') => {
  if (Array.isArray(v)) return v.map(x => norm(x, nokkel));
  if (v && typeof v === 'object') return Object.fromEntries(Object.keys(v).sort().map(k => [k, norm(v[k], k)]));
  if (IDNOKLER.test(nokkel) && v !== null && v !== '' && v !== false) return `<${typeof v}>`;
  if (typeof v === 'string') return normTekst(v);
  return v;
};
// Noe av det Min side leser er felles for alle, og endres av andre tester og
// av verkstedet: varene i butikken, leverandørene, vervepremien, hvem som er
// inne. Der sammenlignes bare formen (feltene og typene), ikke innholdet.
const form = (v) => Array.isArray(v) ? '<liste>' : (v && typeof v === 'object') ? Object.fromEntries(Object.keys(v).sort().map(k => [k, form(v[k])])) : (v === null ? null : `<${typeof v}>`);
const FELLES = {
  '/api/butikk.php': [''], '/api/mine-dokumenter.php': [''], '/api/medlemssalg.php': [''],
  '/api/handleliste.php': ['leverandorer', 'varer', 'frakt', 'fraktOppsett', 'soner'], '/api/meg.php': ['verving', 'internInfo'],
  '/api/stempling.php': ['inne', 'ressurser'],
};
const felles = (a, d) => {
  const stier = FELLES[a];
  if (!stier || !d || typeof d !== 'object') return d;
  // Hele svaret er felles (varene i butikken): bare at det svarer.
  if (stier.includes('')) return '<felles>';
  const k = { ...d };
  for (const n of stier) if (n in k) k[n] = form(k[n]);
  return k;
};
await flyt('Fasit: svarene Min side leser er uendret', async () => {
  const skriv = process.env.E2E_FASIT_SKRIV === '1';
  if (skriv) fs.mkdirSync(FASIT, { recursive: true });
  for (const hvem of ['prove', 'mini', 'basis', 'aar', 'pakke', 'over', 'frosset', 'betalerikke']) {
    const p = await side(hvem, 390, 844);
    await gaa(p, '/min-side', 1200);
    const svar = {};
    let faktiskSiste = null;
    for (const a of FASIT_API) {
      const r = await api(p, a);
      if (a === '/api/stempling.php') faktiskSiste = r.d?.siste ?? null;
      svar[a] = { status: r.status, svar: norm(felles(a, r.d)) };
    }
    await p.context().close();
    const fil = path.join(FASIT, `${hvem}.json`);
    const ny = JSON.stringify(svar, null, 2) + '\n';
    if (skriv) { fs.writeFileSync(fil, ny); sjekk(`fasit skrevet for ${hvem}`, true); continue; }
    if (!fs.existsSync(fil)) { sjekk(`fasit finnes for ${hvem}`, false, fil); continue; }
    const gammel = JSON.parse(fs.readFileSync(fil, 'utf8'));
    // Behold den lagrede fasiten. Bare den datostyrte retten til aa rette
    // akkurat den seedede okta har en annen forventning ved maanedsskiftet.
    // Objektet kommer fra fixturedata, ikke fra svaret vi kontrollerer.
    const siste = brukere[hvem].retteTil !== null && Date.now() / 1000 <= brukere[hvem].retteTil
      ? brukere[hvem].forventetSiste : null;
    const sortertSiste = (v) => JSON.stringify(v === null ? null : Object.fromEntries(Object.entries(v).sort()));
    sjekk(`${hvem}: siste okt har riktig id, klokkeslett og rettingsvindu`,
      sortertSiste(faktiskSiste) === sortertSiste(siste));
    gammel['/api/stempling.php'].svar.siste = norm(siste);
    const ulike = FASIT_API.filter(a => JSON.stringify(gammel[a]) !== JSON.stringify(svar[a]));
    sjekk(`${hvem}: alle ${FASIT_API.length} svar er som i fasiten`, ulike.length === 0,
      ulike.map(a => { const g = JSON.stringify(gammel[a]) || '', n = JSON.stringify(svar[a]) || ''; let i = 0; while (i < g.length && g[i] === n[i]) i++; return `${a}: …${g.slice(Math.max(0, i - 60), i + 80)} ≠ …${n.slice(Math.max(0, i - 60), i + 80)}`; }).join(' | '));
  }
});

// ── 1. Hjem, medlem ───────────────────────────────────────────────────
for (const [bredde, hoyde, hva] of [[390, 844, 'mobil'], [1358, 900, 'PC']]) {
  await flyt(`Medlem, hjem (${hva})`, async () => {
    const p = await side('mini', bredde, hoyde);
    await gaa(p, '/min-side');
    await lukkVinduer(p);
    await dump(p, 'hjem-' + hva);
    sjekk(`${hva}: «Hei, Minside»`, await synlig(p, 'Hei, Minside'));
    sjekk(`${hva}: stempling og timer`, await synlig(p, /Stemple inn og timene dine/i));
    const igjen = MINITIMER - 3;
    const kropp = await p.evaluate(() => document.body.innerText);
    sjekk(`${hva}: timene igjen står riktig (${igjen} av ${MINITIMER})`, new RegExp(`(^|\\n)\\s*${igjen}\\s*\\n\\s*av ${MINITIMER} timer`).test(kropp));
    const st = (await api(p, '/api/stempling.php')).d || {};
    sjekk(`${hva}: … og stemmer med serveren`, Number(st?.timer?.igjen) === igjen, JSON.stringify(st?.timer || {}));
    sjekk(`${hva}: planen vises`, await synlig(p, MINI, true));
    sjekk(`${hva}: «Se medlemskapet»`, await synlig(p, 'Se medlemskapet'));
    sjekk(`${hva}: Mine påmeldinger og Kursbevis`, await synlig(p, 'Mine påmeldinger') && await synlig(p, 'Kursbevis'));
    sjekk(`${hva}: Internbutikk`, await synlig(p, 'Internbutikk', true));
    sjekk(`${hva}: Chat`, await p.locator('#minside-chat').isVisible());
    sjekk(`${hva}: Handleliste`, await synlig(p, 'Handleliste', true));
    sjekk(`${hva}: Selg mine produkter`, await synlig(p, 'Selg mine produkter'));
    sjekk(`${hva}: Del på Instagram og i galleriet`, await synlig(p, 'Del på Instagram og i galleriet'));
    sjekk(`${hva}: Dugnad`, await synlig(p, 'Send forespørsel'));
    sjekk(`${hva}: Vervepremie`, await synlig(p, 'Kopier personlig lenke'));
    sjekk(`${hva}: Skisser`, await synlig(p, 'Skisser', true));
    const bred = await p.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
    sjekk(`${hva}: ingen sidelengs rulling`, !bred);
    await p.context().close();
  });
}

// ── 1b. Fryst medlem: «Fryst til <dato>», ingen «Stemple inn» ───────────
//
// Eieren, 2. oktober 2026: det frosne medlemmet med betalt periode sto som
// «AKTIVT» med «Stemple inn» øverst, og serveren slapp det inn.
// Dørkode og wifi settes bare i denne flyten (og fjernes etterpå, om de ikke
// fantes fra før): meg.php-fasiten over leser formen på internInfo.
const DORKODE = 'E2E-8264#';
const WIFI = 'E2E-wifi-passord';
for (const [bredde, hoyde, hva] of [[390, 844, 'mobil'], [1280, 900, 'PC']]) {
  const privatFoer = db("SELECT nokkel FROM content_blocks WHERE nokkel IN ('Privat/dorkode', 'Privat/wifi')").map(r => r.nokkel);
  const privatOrig = Object.fromEntries(db("SELECT nokkel, verdi FROM content_blocks WHERE nokkel IN ('Privat/dorkode', 'Privat/wifi')").map(r => [r.nokkel, r.verdi]));
  for (const [k, v] of [['Privat/dorkode', DORKODE], ['Privat/wifi', WIFI]]) {
    db('INSERT INTO content_blocks (nokkel, verdi) VALUES (:k, :v) ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)', { k, v });
  }
  await flyt(`Fryst medlem, hjem (${hva})`, async () => {
    const p = await side('frosset', bredde, hoyde);
    await gaa(p, '/min-side');
    await lukkVinduer(p);
    await dump(p, 'fryst-' + hva);
    const til = String(verdi("SELECT til_dato FROM medlem_frys WHERE member_id = :m AND status = 'godkjent'", { m: brukere.frosset.id }));
    const tekst = php(`return Booking::norskDatoKort('${til}');`);
    const merke = p.locator('.ms-o-stempel span').filter({ hasText: /^Fryst til / }).filter({ visible: true }).first();
    sjekk(`${hva}: «Fryst til ${tekst}» øverst`, await merke.isVisible().catch(() => false)
      && (await merke.innerText()).trim().toLowerCase() === ('Fryst til ' + tekst).toLowerCase());
    sjekk(`${hva}: ikke «Aktivt»`, !(await p.locator('.ms-o-stempel span').filter({ hasText: /^Aktivt$/i }).filter({ visible: true }).count()));
    sjekk(`${hva}: «Stemple inn» er skjult`, !(await p.getByRole('button', { name: 'Stemple inn' }).filter({ visible: true }).count()));
    const r = await api(p, '/api/stempling.php', { handling: 'inn' });
    sjekk(`${hva}: serveren avviser innstempling (403)`, r.status === 403 && r.d?.fryst === true, JSON.stringify(r));
    sjekk(`${hva}: ingen økt lagret`, Number(verdi('SELECT COUNT(*) FROM check_ins WHERE member_id = :m', { m: brukere.frosset.id })) === 0);
    const bred = await p.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
    sjekk(`${hva}: ingen sidelengs rulling`, !bred);
    // Eieren, 2. oktober 2026: heller ingen dørkode, wifi, medlemstid eller
    // Bordplass/Dreieskive mens frysen gjelder.
    const meg = await api(p, '/api/meg.php');
    sjekk(`${hva}: ingen dørkode eller wifi i meg.php`, JSON.stringify(meg.d?.internInfo) === '{}', JSON.stringify(meg.d?.internInfo));
    const kropp = await p.evaluate(() => document.body.innerText);
    sjekk(`${hva}: ingen «Dørkode» i toppen`, !(await p.locator('.ms-tl-pille').filter({ hasText: 'Dørkode' }).filter({ visible: true }).count()));
    sjekk(`${hva}: dørkoden og wifi står ikke på siden`, !kropp.includes(DORKODE) && !kropp.includes(WIFI));
    sjekk(`${hva}: ingen «Kun for medlemmer» med «Meld meg på»`, !(await p.locator('#minside-internkurs').filter({ visible: true }).count()));
    sjekk(`${hva}: ingen Bordplass/Dreieskive`, !(await p.getByRole('button', { name: /Bordplass|Dreieskive/ }).filter({ visible: true }).count()));
    await gaa(p, '/stemple', 2500);
    await dump(p, 'fryst-stemple-' + hva);
    sjekk(`${hva}: /stemple har ingen «Stemple inn»`, !(await p.getByRole('button', { name: 'Stemple inn' }).filter({ visible: true }).count()));
    await p.context().close();
    // Kontroll: et vanlig medlem ser alt dette på samme skjerm, saa sjekkene
    // over maaler noe.
    const q = await side('mini', bredde, hoyde);
    await gaa(q, '/min-side');
    await lukkVinduer(q);
    const megQ = await api(q, '/api/meg.php');
    sjekk(`${hva}: kontroll — vanlig medlem får dørkoden`, megQ.d?.internInfo?.dorkode === DORKODE);
    sjekk(`${hva}: kontroll — vanlig medlem ser «Dørkode ${DORKODE}» i toppen`, await q.locator('.ms-tl-pille').filter({ hasText: DORKODE }).filter({ visible: true }).count() > 0);
    sjekk(`${hva}: kontroll — vanlig medlem ser medlemstid og Bordplass`, await q.locator('#minside-internkurs').filter({ visible: true }).count() > 0
      && await q.getByRole('button', { name: /Bordplass/ }).filter({ visible: true }).count() > 0);
    await gaa(q, '/stemple', 2500);
    sjekk(`${hva}: kontroll — vanlig medlem har «Stemple inn» på /stemple`, await q.getByRole('button', { name: 'Stemple inn' }).filter({ visible: true }).count() > 0);
    await q.context().close();
  });
  for (const k of ['Privat/dorkode', 'Privat/wifi']) {
    if (privatFoer.includes(k)) db('UPDATE content_blocks SET verdi = :v WHERE nokkel = :k', { k, v: privatOrig[k] });
    else db('DELETE FROM content_blocks WHERE nokkel = :k', { k });
  }
}

// ── 2. Stemple inn, stemple ut og «Feil tid» ─────────────────────────
await flyt('Stempling inn og ut, «Feil tid — si fra»', async () => {
  const p = await side('basis', 390, 844);
  await gaa(p, '/min-side');
  await lukkVinduer(p);
  sjekk('«Stemple inn» står der', await trykk(p.getByRole('button', { name: 'Stemple inn' }).filter({ visible: true }).first()));
  await p.waitForTimeout(700);
  await dump(p, 'stemple-inn');
  sjekk('… «Stemple inn nå?» spør først', await synlig(p, 'Stemple inn nå?'));
  await trykk(p.getByText('Ja, stemple inn', { exact: false }).filter({ visible: true }).first());
  await p.waitForTimeout(1500);
  sjekk('… innstemplet i basen', Number(verdi('SELECT COUNT(*) FROM check_ins WHERE member_id = :m AND ut_tid IS NULL', { m: brukere.basis.id })) === 1);
  await gaa(p, '/min-side', 2500);
  sjekk('«Stemple ut» står der', await trykk(p.getByRole('button', { name: 'Stemple ut' }).filter({ visible: true }).first()));
  await p.waitForTimeout(700);
  await dump(p, 'stemple-ut');
  sjekk('… og «Feil tid — si fra» i ruta ved utstempling', await synlig(p, 'Feil tid — si fra'));
  await trykk(p.getByRole('button', { name: 'Bekreft', exact: true }).filter({ visible: true }).first());
  await p.waitForTimeout(1500);
  await dump(p, 'etter-ut');
  sjekk('… utstemplet i basen', Number(verdi('SELECT COUNT(*) FROM check_ins WHERE member_id = :m AND ut_tid IS NULL', { m: brukere.basis.id })) === 0);
  await p.context().close();
});

// ── 3. Timer brukt opp: vinduene ──────────────────────────────────────
await flyt('Timer brukt opp: vindu 2 og vindu 3', async () => {
  let p = await side('over', 390, 844);
  await gaa(p, '/min-side');
  sjekk('vanlig medlem, brukt opp: vindu 2', await p.locator('[data-tp-vindu="2"]').isVisible().catch(() => false));
  await p.context().close();
  const ekstra = lagPerson('proveTom', 'Minside Provetom', { status: 'prove', plan: PROVE, minutter: 12 * 60 });
  p = await side('proveTom', 390, 844);
  await gaa(p, '/min-side');
  const v3 = p.locator('[data-tp-vindu="3"]');
  sjekk('Prøv Lissom, brukt opp: vindu 3 med «Velg medlemskap»', await v3.isVisible().catch(() => false) && await v3.locator('[data-tp-knapp="Velg medlemskap"]').isVisible().catch(() => false));
  sjekk('… og ingen «Kjøp timepakke»', await v3.locator('[data-tp-knapp="Kjøp timepakke"]').count() === 0);
  await p.context().close();
  p = await side('pakke', 390, 844);
  await gaa(p, '/min-side');
  const st = (await api(p, '/api/stempling.php')).d || {};
  sjekk('med betalt timepakke: pakketimene er med (5,5 igjen)', Number(st?.timer?.igjen) === 5.5, JSON.stringify(st?.timer || {}));
  sjekk('… og ingen vindu 2', await p.locator('[data-tp-vindu="2"]').count() === 0);
  await p.context().close();
  void ekstra;
});

// ── 4. Medlemskap-fanen: Forny, Bytt, frys, kursbevis ─────────────────
for (const [bredde, hoyde, hva] of [[390, 844, 'mobil'], [1358, 900, 'PC']]) {
  await flyt(`Medlemskap-fanen (${hva})`, async () => {
    const p = await side('mini', bredde, hoyde);
    await gaa(p, '/min-side/medlemskap');
    await lukkVinduer(p);
    await dump(p, 'medlemskap-' + hva);
    sjekk(`${hva}: «Medlemskapet ditt» med planen`, await synlig(p, /Medlemskapet ditt/i) && await synlig(p, MINI, true));
    sjekk(`${hva}: Forny og Bytt abonnement`,
      await p.getByRole('button', { name: /^Forny$/i }).filter({ visible: true }).first().isVisible().catch(() => false)
      && await p.getByRole('button', { name: /^Bytt abonnement$/i }).filter({ visible: true }).first().isVisible().catch(() => false));
    sjekk(`${hva}: «Si opp» er borte i bindingstiden`, !(await p.getByRole('button', { name: /^Si opp$/i }).filter({ visible: true }).first().isVisible().catch(() => false)));
    sjekk(`${hva}: bindingstid vises`, await synlig(p, 'Bindingstid'));
    await trykk(p.getByRole('button', { name: /^Bytt abonnement$/i }).filter({ visible: true }).first());
    await p.waitForTimeout(900);
    sjekk(`${hva}: «Bytt abonnement» åpner velgeren med de andre planene`, await synlig(p, BASIS));
    await p.keyboard.press('Escape').catch(() => {});
    await gaa(p, '/min-side/medlemskap', 2500);
    sjekk(`${hva}: Frys av medlemskap`, await synlig(p, /Frys av medlemskap/i));
    sjekk(`${hva}: Kursbevis med «Åpne beviset»`, await p.getByRole('button', { name: /Åpne beviset/i }).filter({ visible: true }).first().isVisible().catch(() => false));
    await p.context().close();
  });
}
await flyt('Forny åpner Vipps med riktig plan', async () => {
  const p = await side('mini', 390, 844);
  await gaa(p, '/min-side/medlemskap');
  await lukkVinduer(p);
  const foer = Number(verdi('SELECT COUNT(*) FROM payments WHERE member_id = :m', { m: brukere.mini.id }));
  await trykk(p.getByRole('button', { name: /^Forny$/i }).filter({ visible: true }).first());
  await p.waitForTimeout(1200);
  await trykk(p.getByRole('button', { name: /Betal|Vipps|Forny/i }).filter({ visible: true }).last());
  await p.waitForTimeout(2500);
  const etter = db('SELECT belop_ore, status FROM payments WHERE member_id = :m ORDER BY id DESC LIMIT 1', { m: brukere.mini.id })[0] || {};
  const pris = Number(verdi('SELECT pris_ore FROM membership_plans WHERE navn = :n', { n: MINI }));
  sjekk('Forny lager en betaling for planen i Vipps', Number(verdi('SELECT COUNT(*) FROM payments WHERE member_id = :m', { m: brukere.mini.id })) === foer + 1 && Number(etter.belop_ore) === pris, JSON.stringify(etter));
  sjekk('… og det blir ingen ny avtale', Number(verdi("SELECT COUNT(*) FROM subscriptions WHERE member_id = :m AND status = 'aktiv'", { m: brukere.mini.id })) === 1);
  await p.context().close();
});
await flyt('Frys: søknaden lagres', async () => {
  const p = await side('basis', 390, 844);
  await gaa(p, '/min-side/medlemskap');
  await lukkVinduer(p);
  const dato = (d) => new Date(Date.now() + d * 864e5).toISOString().slice(0, 10);
  const felt = p.locator('input[type="date"]');
  await felt.nth(0).fill(dato(14)).catch(() => {});
  await felt.nth(1).fill(dato(40)).catch(() => {});
  await trykk(p.getByRole('button', { name: /Søk om frys/i }).filter({ visible: true }).first());
  await p.waitForTimeout(1500);
  sjekk('frys-søknaden står i basen', Number(verdi('SELECT COUNT(*) FROM medlem_frys WHERE member_id = :m', { m: brukere.basis.id })) === 1);
  const r = await api(p, '/api/medlem-frys.php');
  sjekk('… og Min side leser den', r.status === 200, JSON.stringify(r.d).slice(0, 160));
  await p.context().close();
});
await flyt('Kursbevis åpnes', async () => {
  const p = await side('mini', 1358, 900);
  await gaa(p, '/min-side/medlemskap');
  await lukkVinduer(p);
  const [ny] = await Promise.all([
    p.context().waitForEvent('page', { timeout: 8000 }).catch(() => null),
    trykk(p.getByRole('button', { name: /Åpne beviset/i }).filter({ visible: true }).first()),
  ]);
  const mal = ny || p;
  await mal.waitForLoadState('load').catch(() => {});
  const tekst = await mal.evaluate(() => document.body.innerText).catch(() => '');
  sjekk('beviset åpnes med kursnavnet', /E2E Dreiekurs/.test(tekst) && /Minside Mini/.test(tekst), (mal.url() + ' ' + tekst.slice(0, 120)).replace(/\s+/g, ' '));
  await p.context().close();
});

// ── 5. Deltaker: hjem, plasser, bilder, avbestilling ─────────────────
for (const [bredde, hoyde, hva] of [[390, 844, 'mobil'], [1358, 900, 'PC']]) {
  await flyt(`Kursdeltaker, hjem (${hva})`, async () => {
    const p = await side('deltaker', bredde, hoyde);
    await gaa(p, '/min-side');
    sjekk(`${hva}: «Hei, Minside»`, await synlig(p, 'Hei, Minside'));
    sjekk(`${hva}: Neste kurs`, await synlig(p, /Neste kurs/i));
    sjekk(`${hva}: Mine påmeldinger med kurset`, await synlig(p, 'Mine påmeldinger') && await synlig(p, 'E2E Dreiekurs'));
    sjekk(`${hva}: Kursbevis`, await synlig(p, /^Kursbevis/));
    sjekk(`${hva}: Bilder av det du laget`, await synlig(p, 'Bilder av det du laget') && await p.locator('#minside-pameldinger input[type="file"]').first().isVisible());
    sjekk(`${hva}: Bli medlem med planene`, await synlig(p, 'Bli medlem i verkstedet') && await synlig(p, PROVE));
    sjekk(`${hva}: Skisser (bryteren for deltakere er på)`, await synlig(p, 'Skisser', true));
    sjekk(`${hva}: ingen medlemsting (stempling, internbutikk, chat)`,
      !(await synlig(p, /Stemple inn og timene dine/i)) && !(await synlig(p, 'Internbutikk', true)) && !(await synlig(p, 'Chat', true)));
    const bred = await p.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
    sjekk(`${hva}: ingen sidelengs rulling`, !bred);
    await p.context().close();
  });
}
await flyt('Kursdeltaker: mine plasser, mine kjøp, bilder og avbestilling', async () => {
  const p = await side('deltaker', 390, 844);
  await gaa(p, '/min-side');
  const pl = await api(p, '/api/mine-plasser.php');
  sjekk('mine plasser svarer', pl.status === 200, JSON.stringify(pl.d).slice(0, 120));
  const kj = await api(p, '/api/mine-kjop.php');
  sjekk('mine kjøp svarer', kj.status === 200, JSON.stringify(kj.d).slice(0, 120));
  const bok = Number(verdi("SELECT id FROM bookings WHERE member_id = :m AND course_session_id = :o", { m: brukere.deltaker.id, o: S.okter.a }));
  const bi = await api(p, '/api/mine-bilder.php?bookingId=' + bok);
  sjekk('mine bilder svarer', bi.status === 200, JSON.stringify(bi.d).slice(0, 120));
  await trykk(p.locator('.ms-o-pamld2').first());
  await p.waitForTimeout(1200);
  sjekk('trykk på «Mine påmeldinger» viser «Avbestill»', await trykk(p.locator('#minside-pameldinger').getByText('Avbestill', { exact: true }).filter({ visible: true }).last()));
  await p.waitForTimeout(900);
  sjekk('… «Avbestille E2E Dreiekurs?» med vilkårene', await synlig(p, 'Avbestille E2E Dreiekurs?'));
  await trykk(p.getByText('Avbestill og få pengene tilbake').filter({ visible: true }).first());
  await p.waitForTimeout(2500);
  await dump(p, 'avbestill');
  const st = verdi('SELECT status FROM bookings WHERE id = :i', { i: bok });
  sjekk('… plassen er avbestilt i basen', /avbestilt|kansellert|refundert/.test(String(st)), String(st));
  await p.context().close();
});

// ── 6. Butikk-fanen: internbutikk, handleliste, leire for Prøv Lissom ─
for (const [hvem, leire, hva] of [['aar', true, 'vanlig medlem'], ['prove', false, 'Prøv Lissom']]) {
  await flyt(`Internbutikken (${hva})`, async () => {
    const p = await side(hvem, 390, 844);
    await gaa(p, '/min-side/butikk');
    await lukkVinduer(p);
    sjekk(`${hva}: Internbutikk og kjøpshistorikk`, await synlig(p, /Internbutikk/i) && await synlig(p, /Kjøpshistorikk/i));
    const leireVare = verdi("SELECT tittel FROM products WHERE leire = 1 AND status = 'publisert' ORDER BY id LIMIT 1");
    if (leireVare) sjekk(`${hva}: leire ${leire ? 'vises' : 'er skjult'}`, (await synlig(p, leireVare)) === leire);
    const b = await api(p, '/api/butikk.php');
    const harLeire = JSON.stringify(b.d || {}).includes(String(leireVare || '§§'));
    if (leireVare) sjekk(`${hva}: … også hos serveren`, harLeire === leire);
    sjekk(`${hva}: Handleliste`, await synlig(p, /^Handleliste$/i));
    await p.context().close();
  });
}
await flyt('Handleliste: legg til og se lista', async () => {
  const p = await side('aar', 390, 844);
  await gaa(p, '/min-side/butikk');
  await lukkVinduer(p);
  const r = await api(p, '/api/handleliste.php');
  sjekk('handlelista svarer', r.status === 200, JSON.stringify(r.d).slice(0, 160));
  sjekk('frakten beskrives på handlelista', await synlig(p, /frakt/i));
  await p.context().close();
});

// ── 7. Chat, medlemsforslag, vervepremie, gave, dugnad, selg ─────────
await flyt('Chat: melding sendes og vises', async () => {
  const p = await side('aar', 390, 844);
  await gaa(p, '/min-side/chat');
  await lukkVinduer(p);
  const tekst = `Hei fra vakta ${S.tag}`;
  const felt = p.locator('textarea, input[type="text"]').last();
  await felt.fill(tekst).catch(() => {});
  await trykk(p.getByRole('button', { name: /^Send$/i }).filter({ visible: true }).last());
  await p.waitForTimeout(1800);
  sjekk('chatmeldingen er lagret', Number(verdi('SELECT COUNT(*) FROM chat_meldinger WHERE member_id = :m AND tekst = :t', { m: brukere.aar.id, t: tekst })) === 1);
  sjekk('… og vises i chatten', await synlig(p, tekst));
  await p.context().close();
});
await flyt('Medlemsforslag, vervepremie og dugnad', async () => {
  const p = await side('aar', 390, 844);
  await gaa(p, '/min-side');
  await lukkVinduer(p);
  const mf = await api(p, '/api/medlemsforslag.php');
  sjekk('medlemsforslag svarer', mf.status === 200, JSON.stringify(mf.d).slice(0, 120));
  sjekk('vervepremien viser lenken', await synlig(p, 'Kopier personlig lenke'));
  const kode = verdi('SELECT verve_kode FROM members WHERE id = :i', { i: brukere.aar.id });
  sjekk('… medlemmet har en vervekode', !!kode, String(kode));
  sjekk('dugnad: «Send forespørsel» står der', await p.getByRole('button', { name: 'Send forespørsel' }).filter({ visible: true }).first().isVisible().catch(() => false));
  const du = await api(p, '/api/dugnad.php', { handling: 'sporr', tekst: 'Kan rydde glasurhylla' });
  sjekk('dugnad: serveren tar imot forespørselen', du.status === 200 && du.d && du.d.ok !== false, JSON.stringify(du.d).slice(0, 120));
  sjekk('dugnad-forespørselen er lagret', Number(verdi('SELECT COUNT(*) FROM dugnad WHERE member_id = :m', { m: brukere.aar.id })) >= 1);
  await p.context().close();
});
await flyt('Gave: løs inn', async () => {
  db("INSERT INTO medlemsgaver (member_id, type, timer, status, gyldig_til, gitt_av) VALUES (:m, 'timer', 2, 'aktiv', :g, :a)", { m: brukere.basis.id, g: new Date(Date.now() + 30 * 864e5).toISOString().slice(0, 10), a: S.admin.id });
  const p = await side('basis', 390, 844);
  await gaa(p, '/min-side');
  await lukkVinduer(p);
  sjekk('gaven vises («2 ekstra timer»)', await synlig(p, '2 ekstra timer'));
  await trykk(p.getByRole('button', { name: 'Løs inn gaven' }).filter({ visible: true }).first()) || await trykk(p.getByText('Løs inn gaven').first());
  await p.waitForTimeout(800);
  await trykk(p.getByRole('button', { name: 'Løs inn gaven' }).filter({ visible: true }).last());
  await p.waitForTimeout(1500);
  sjekk('… gaven er løst inn', Number(verdi('SELECT COUNT(*) FROM medlemsgave_bruk WHERE member_id = :m', { m: brukere.basis.id })) === 1);
  await p.context().close();
});
await flyt('Selg egne arbeider', async () => {
  const p = await side('aar', 390, 844);
  await gaa(p, '/min-side/selg');
  await lukkVinduer(p);
  sjekk('skjemaet for å selge vises', await synlig(p, 'Selg keramikk') && await p.getByRole('button', { name: /Send til godkjenning/i }).filter({ visible: true }).first().isVisible().catch(() => false));
  const r = await api(p, '/api/medlemssalg.php');
  sjekk('… og medlemssalg svarer', r.status === 200, JSON.stringify(r.d).slice(0, 120));
  await p.context().close();
});

// ── 8. Synlighet: av betyr borte (og serveren stopper) ───────────────
await flyt('Synlighet-bryterne slår av det de skal', async () => {
  const av = [
    ['internbutikk', 'Internbutikk'], ['handleliste', 'Handleliste'],
    ['medlemsforslag', 'Del på Instagram og i galleriet'], ['dugnad', 'Send forespørsel'], ['verving', 'Kopier personlig lenke'],
    ['skissermedlemmer', 'Skisser'],
  ];
  lagPerson('syn', 'Minside Synlighet', { plan: AAR, bindingMnd: 12 });
  av.forEach(([k]) => bryter(k, false));
  bryter('skisserdeltakere', false);
  const p = await side('syn', 390, 844);
  await gaa(p, '/min-side');
  await lukkVinduer(p);
  for (const [k, tekst] of av) sjekk(`av: ${k} er borte`, !(await synlig(p, tekst, true)));
  const sk = await api(p, '/api/skisser.php');
  sjekk('av: skisser for medlemmer gir 403 hos serveren', sk.status === 403, String(sk.status));
  av.forEach(([k]) => bryter(k, true));
  bryter('skisserdeltakere', true);
  await gaa(p, '/min-side');
  await lukkVinduer(p);
  for (const [k, tekst] of av) sjekk(`på: ${k} er tilbake`, await synlig(p, tekst, true));
  bryter('medlemssalg', false);
  await gaa(p, '/min-side/selg');
  sjekk('av: selg egne arbeider — skjemaet er borte', !(await p.getByRole('button', { name: /Send til godkjenning/i }).filter({ visible: true }).first().isVisible().catch(() => false)));
  bryter('medlemssalg', true);
  await p.context().close();
  bryter('medlemfrys', false);
  const q = await side('basis', 390, 844);
  await gaa(q, '/min-side/medlemskap');
  sjekk('av: frys av medlemskap er borte', !(await synlig(q, /Frys av medlemskap/i)));
  await q.context().close();
  bryter('medlemfrys', true);
});

// ── 9. Forhåndsvisningen i admin er den samme Min side ───────────────
const seksjoner = (p) => p.evaluate(() => {
  const ord = ['Internbutikk', 'Chat', 'Handleliste', 'Selg mine produkter', 'Skisser',
    'Del på Instagram og i galleriet', 'Dugnad', 'Vervepremie', 'Stemple inn og timene dine', 'I verkstedet nå', 'Ovnen',
    'Bli medlem i verkstedet', 'Medlemmenes salgsuke'];
  const t = document.body.innerText.toLowerCase();
  return ord.filter(o => t.includes(o.toLowerCase()));
});
await flyt('Forhåndsvisningen i admin = ekte Min side', async () => {
  for (const [rolle, rad, hvem] of [['medlem', 'Hva medlemmene ser', 'aar'], ['deltaker', 'Hva kursdeltakere ser', 'deltaker']]) {
    const a = await side('admin', 1358, 900);
    await a.goto(ADR + '/admin/oversikt', { waitUntil: 'load', timeout: 45000 });
    await a.waitForTimeout(3500);
    const knapp = a.getByText(rad, { exact: true }).first();
    sjekk(`admin: «${rad}» finnes`, await trykk(knapp));
    await a.waitForTimeout(3500);
    const fh = await seksjoner(a);
    const e = await side(hvem, 1358, 900);
    await gaa(e, '/min-side');
    await lukkVinduer(e);
    const ekte = await seksjoner(e);
    const mangler = ekte.filter(x => !fh.includes(x));
    const ekstra = fh.filter(x => !ekte.includes(x));
    sjekk(`${rolle}: forhåndsvisningen har de samme delene som ekte Min side`, mangler.length === 0 && ekstra.length === 0,
      `mangler i forhåndsvisning: ${mangler.join(', ') || '—'} · bare i forhåndsvisning: ${ekstra.join(', ') || '—'}`);
    await a.context().close(); await e.context().close();
  }
});

// ── Opprydding og oppsummering ────────────────────────────────────────
const ider = Object.values(brukere).map(b => Number(b.id)).filter(Boolean);
if (ider.length) {
  const liste = ider.join(',');
  for (const sql of [
    `DELETE FROM chat_meldinger WHERE member_id IN (${liste})`, `DELETE FROM medlem_frys WHERE member_id IN (${liste})`,
    `DELETE FROM dugnad WHERE member_id IN (${liste})`, `DELETE FROM handleliste_linjer WHERE member_id IN (${liste})`,
    `DELETE FROM timepakke_bruk WHERE member_id IN (${liste})`, `DELETE FROM timepakker WHERE member_id IN (${liste})`,
    `DELETE FROM timer_svar WHERE member_id IN (${liste})`, `DELETE FROM check_ins WHERE member_id IN (${liste})`,
  ]) { try { db(sql); } catch { /* tabellen kan mangle */ } }
}
for (const k of [...FLATE, 'glemtstempling']) {
  const n = 'Vis/' + k;
  if (n in FOER) db('UPDATE content_blocks SET verdi = :v WHERE nokkel = :k', { k: n, v: FOER[n] });
  else db('DELETE FROM content_blocks WHERE nokkel = :k', { k: n });
}
await nettleser.close();
const skript = [...new Set(skriptfeil)];
sjekk('ingen skriptfeil i nettleseren', skript.length === 0, skript.slice(0, 5).join(' | '));
console.log(`\n${ok} sjekker i orden, ${feil.length} feil.`);
if (feil.length) { console.log('\nFeil:'); feil.forEach(f => console.log('  ✗ ' + f)); process.exit(1); }
