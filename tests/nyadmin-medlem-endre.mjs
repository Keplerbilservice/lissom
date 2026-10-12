// Ny admin › Medlemmer (eieren 12.10.2026): «når jeg klikker på medlemmer, må jeg også kunne endre personopplysninger,
// endre abonnement». Valgt: ett skjema bak «Endre». Sjekker at lista viser «Aktiv» + betaling, at skjemaet er fylt
// med det som står (datoene tømmes ellers), og at telefon og medlemskap lagres uten at «Medlem fra» endres.
import assert from 'node:assert/strict'; import {createRequire} from 'node:module'; import {execFileSync} from 'node:child_process';
const {chromium} = createRequire(import.meta.url)('playwright');
const fixture = (m, s) => JSON.parse(execFileSync('php', ['tests/nettleser/henting-fixture.php', m, JSON.stringify(s || {})], {encoding: 'utf8'}) || '{}');
const admin = fixture('seed'), medlem = fixture('seed'); fixture('member', medlem);
const BASE = 'http://lokal.lissom.no:' + (process.env.E2E_PORT || 8140);
const browser = await chromium.launch({args: ['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
try {
  const c = await browser.newContext({viewport: {width: 1280, height: 900}});
  await c.addCookies([{name: 'lissom_sesjon', value: admin.token, domain: 'lokal.lissom.no', path: '/'}]);
  const p = await c.newPage(), feil = [];
  p.on('pageerror', e => feil.push(e.message));
  const liste = () => p.evaluate(async () => (await (await fetch('/api/admin/medlemmer.php')).json()));
  let d = await liste();
  const for_ = d.medlemmer.find(m => m.id === medlem.admin);
  assert.ok(for_, 'medlemmet står i lista');
  const nyPlan = d.planer.find(x => x.navn !== for_.medlemskap && !x.engangs);
  await p.goto(`${BASE}/ny-admin#medlemmer?person=${medlem.admin}`, {waitUntil: 'networkidle'});
  const ark = p.getByRole('dialog');
  await ark.getByRole('button', {name: 'Endre', exact: true}).click();
  assert.equal(await p.locator('#mlm-e-fra').inputValue(), String(for_.startDato).slice(0, 10), '«Medlem fra» er fylt ut');
  assert.equal(await p.locator('#mlm-e-navn').inputValue(), for_.navn);
  await p.locator('#mlm-e-telefon').fill('90012345');
  if (nyPlan) await p.locator('#mlm-e-plan').selectOption(nyPlan.navn);
  await p.getByRole('button', {name: 'Lagre', exact: true}).click();
  await p.waitForTimeout(1200);
  d = await liste();
  const etter = d.medlemmer.find(m => m.id === medlem.admin);
  assert.match(String(etter.telefon), /90012345/);
  assert.equal(String(etter.startDato).slice(0, 10), String(for_.startDato).slice(0, 10), '«Medlem fra» står som før');
  if (nyPlan) assert.equal(etter.medlemskap, nyPlan.navn);
  const merker = await p.locator(`#mlm-liste [data-id="${medlem.admin}"] .merker .merke`).allTextContents();
  assert.equal(merker[0], 'Aktiv', JSON.stringify(merker));
  assert.deepEqual(feil, []);
  console.log(`Endre: telefon${nyPlan ? ' og medlemskap (' + nyPlan.navn + ')' : ''} lagret, «Medlem fra» urørt; merker ${JSON.stringify(merker)}.`);
} finally { await browser.close(); fixture('cleanup', medlem); fixture('cleanup', admin); }
