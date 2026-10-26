// Ny admin › I dag › Må gjøres (eieren 26.10.2026, «Bort når alle har fått»): Monica sendte hentebeskjeden én og én,
// og «Klar til henting» ble stående fordi datoen aldri ble publisert. Nå er saken borte når alle som ikke har hentet,
// har fått beskjed (sendt eller i kø) — uansett hvor den ble sendt fra. Datoen publiseres ikke av det.
import assert from 'node:assert/strict'; import {createRequire} from 'node:module'; import {execFileSync} from 'node:child_process';
const {chromium} = createRequire(import.meta.url)('playwright');
const fixture = (m, s) => JSON.parse(execFileSync('php', ['tests/nettleser/henting-fixture.php', m, JSON.stringify(s || {})], {encoding: 'utf8'}) || '{}');
const s = fixture('seed'); const {to} = fixture('hentetid', s); fixture('email', s);
const BASE = 'http://lokal.lissom.no:' + (process.env.E2E_PORT || 8140);
const browser = await chromium.launch({args: ['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
try {
  const c = await browser.newContext({viewport: {width: 1280, height: 900}});
  await c.addCookies([{name: 'lissom_sesjon', value: s.token, domain: 'lokal.lissom.no', path: '/'}]);
  const p = await c.newPage(), feil = [];
  p.on('pageerror', e => feil.push(e.message));
  const sak = () => p.locator('#arbeid .rad', {hasText: 'Klar til henting: ' + s.tag});
  const post = data => p.evaluate(async d => (await fetch('/api/admin/ferdigbrent.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(d)})).status, data);
  const idag = async () => { await p.goto(`${BASE}/ny-admin#idag`, {waitUntil: 'networkidle'}); await p.locator('#arbeid .laster').waitFor({state: 'detached'}); await p.waitForTimeout(800); };

  await idag();
  await sak().waitFor();
  assert.match(await sak().innerText(), /2 deltakere/);

  // Beskjed til én (samme kall som «Send hentebeskjed» i kursarket): den andre mangler, saken står.
  assert.equal(await post({handling: 'meld-en', bookingId: s.booking}), 200);
  await idag();
  assert.equal(await sak().count(), 1, 'saken står når én ikke har fått beskjed');

  // Den andre har hentet: alle som ikke har hentet, har fått beskjed — saken er borte.
  assert.equal(await post({handling: 'hentet', bookingId: to, hentet: 'ja'}), 200);
  await idag();
  assert.equal(await sak().count(), 0, 'saken er borte når alle har fått beskjed eller hentet');
  assert.equal(fixture('inspect', s).stamp, null, 'datoen er ikke publisert på ferdigbrent');

  // Angres «hentet», mangler den andre beskjed igjen: saken er tilbake.
  assert.equal(await post({handling: 'hentet', bookingId: to, hentet: 'nei'}), 200);
  await idag();
  assert.equal(await sak().count(), 1, 'saken kommer tilbake når noen mangler beskjed');
  assert.deepEqual(feil, []);
  console.log('Klar til henting: står med én uten beskjed, borte når alle har fått eller hentet, ikke publisert av det.');
} finally { await browser.close(); fixture('cleanup', s); }
