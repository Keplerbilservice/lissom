// Ny admin › I dag › Må gjøres (eieren 12.10.2026): «de som har hentet keramikken sin, uten at jeg har sendt eller før jeg har
// sendt påminnelse, må kunne merkes utført … rett i påminnelsen, på samme sted som send». Valgt «Velg hvem»: «Merk hentet»
// ved siden av «Send «Klar til henting»» åpner deltakerne med avkrysning. De som er merket, telles ikke; har alle hentet, er saken borte.
import assert from 'node:assert/strict'; import {createRequire} from 'node:module'; import {execFileSync} from 'node:child_process';
const {chromium} = createRequire(import.meta.url)('playwright');
const fixture = (m, s) => JSON.parse(execFileSync('php', ['tests/nettleser/henting-fixture.php', m, JSON.stringify(s || {})], {encoding: 'utf8'}) || '{}');
const s = fixture('seed'); const {to} = fixture('hentetid', s);
const BASE = 'http://lokal.lissom.no:' + (process.env.E2E_PORT || 8140);
const browser = await chromium.launch({args: ['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
try {
  const c = await browser.newContext({viewport: {width: 1280, height: 900}});
  await c.addCookies([{name: 'lissom_sesjon', value: s.token, domain: 'lokal.lissom.no', path: '/'}]);
  const p = await c.newPage(), feil = [];
  p.on('pageerror', e => feil.push(e.message));
  await p.goto(`${BASE}/ny-admin#idag`, {waitUntil: 'networkidle'});
  const sak = () => p.locator('#arbeid .rad', {hasText: 'Klar til henting: ' + s.tag});
  await sak().waitFor();
  assert.match(await sak().innerText(), /2 deltakere/);
  const deltakere = () => p.evaluate(async id => (await (await fetch('/api/admin/ferdigbrent.php?oktId=' + id)).json()).deltakere, s.session);

  // Én har hentet: krysses av, lagres, og saken står igjen med én deltaker.
  await sak().getByRole('button', {name: 'Merk hentet', exact: true}).click();
  const ark = p.getByRole('dialog', {name: 'Merk hentet'});
  await ark.getByRole('checkbox', {name: s.tag + ' To'}).check();
  await ark.getByRole('button', {name: 'Lagre', exact: true}).click();
  await ark.waitFor({state: 'hidden'});
  let d = await deltakere();
  assert.equal(d.find(x => x.id === to).hentet, true, 'den andre er merket hentet');
  assert.equal(d.find(x => x.id === s.booking).hentet, false, 'den første er ikke rørt');
  await p.waitForTimeout(800);
  assert.match(await sak().innerText(), /1 deltakere/);

  // Den andre også: saken er borte.
  await sak().getByRole('button', {name: 'Merk hentet', exact: true}).click();
  assert.equal(await ark.getByRole('checkbox', {name: s.tag + ' To'}).isChecked(), true, 'den som er merket, står avkrysset');
  await ark.getByRole('checkbox', {name: s.tag, exact: true}).check();
  await ark.getByRole('button', {name: 'Lagre', exact: true}).click();
  await ark.waitFor({state: 'hidden'});
  await p.waitForTimeout(1200);
  assert.equal(await sak().count(), 0, 'saken er borte når alle har hentet');
  d = await deltakere();
  assert.ok(d.every(x => x.hentet), 'begge er hentet');
  assert.deepEqual(feil, []);
  console.log('Merk hentet: én av to merket (saken står med 1), så begge (saken er borte).');
} finally { await browser.close(); fixture('cleanup', s); }
