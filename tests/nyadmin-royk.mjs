// Røyktest av den nye adminen (/ny-admin, eieren 08.10.2026), etter den grundige sjekken 08.10.2026.
// Alle sidene på PC, iPad, mobil (berøring) og en PC med smalt vindu / 200 % zoom: ingen skriptfeil, ingen
// 4xx/5xx fra serveren, ingen sideveis rulling. Mobilsperren gjelder bare berøring + smal skjerm, topplinja står
// på én rad på iPad, og «Send beskjed»-arket har ingenting forhåndsvalgt, låst hovedknapp og «Vil du forkaste?» på
// nettleserens Tilbake.
import assert from 'node:assert/strict'; import {createRequire} from 'node:module'; import {execFileSync} from 'node:child_process';
const {chromium} = createRequire(import.meta.url)('playwright');
const fixture = (m, s) => JSON.parse(execFileSync('php', ['tests/nettleser/henting-fixture.php', m, JSON.stringify(s || {})], {encoding: 'utf8'}) || '{}');
const s = fixture('seed');
const BASE = 'http://lokal.lissom.no:' + (process.env.E2E_PORT || 8140);
const SIDER = ['idag', 'kalender', 'kurs', `kurs?okt=${s.session}&fra=kurs`, 'medlemmer', 'varer', 'mer', 'semedlem', 'innstillinger'];
// Min side i forhåndsvisningen (Medlemssiden) spør etter stempling og frys for adminbrukeren. Det er kundesiden
// og eksisterende oppførsel (ikke en del av ny-admin) — meldt til eieren, ikke rettet her.
const KJENT = [/\/api\/stempling\.php/, /\/api\/medlem-frys\.php/];
const browser = await chromium.launch({args: ['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
const feil = [];
try {
  for (const [navn, vp, beroring] of [['PC', {width: 1280, height: 900}, false], ['iPad', {width: 1024, height: 768}, true],
    ['mobil', {width: 390, height: 844}, true], ['PC-zoom', {width: 640, height: 800}, false]]) {
    const c = await browser.newContext({viewport: vp, hasTouch: beroring, isMobile: navn === 'mobil'});
    await c.addCookies([{name: 'lissom_sesjon', value: s.token, domain: 'lokal.lissom.no', path: '/'}]);
    const p = await c.newPage(), her = [];
    p.on('pageerror', e => her.push('JS: ' + e.message));
    p.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) her.push('konsoll: ' + m.text()); });
    p.on('response', r => { const u = r.url(); if (u.startsWith(BASE) && r.status() >= 400 && !KJENT.some(k => k.test(u))) her.push(`HTTP ${r.status()} ${u.replace(BASE, '')}`); });
    for (const id of SIDER) {
      const for_ = her.length;
      await p.goto(`${BASE}/ny-admin#${id}`, {waitUntil: 'networkidle'});
      await p.waitForTimeout(600);
      const m = await p.evaluate(() => ({rull: document.documentElement.scrollWidth <= innerWidth + 1, tekst: document.querySelector('#arbeid')?.innerText || '',
        topp: document.querySelector('#topp')?.getBoundingClientRect().height || 0, mobil: document.documentElement.classList.contains('na-mobil')}));
      const sperret = /Brukes på iPad eller PC/.test(m.tekst);
      if (!m.rull) her.push(`${id}: sideveis rulling`);
      if (/Noe gikk galt|ikke bygd ennå/.test(m.tekst)) her.push(`${id}: ${m.tekst.slice(0, 120)}`);
      const forventSperre = navn === 'mobil' && ['medlemmer', 'varer', 'mer', 'semedlem', 'innstillinger'].includes(id);
      if (sperret !== forventSperre) her.push(`${id}: «Brukes på iPad eller PC» ${sperret ? 'vises' : 'mangler'} (${navn})`);
      if (navn === 'iPad' && m.topp > 90) her.push(`${id}: topplinja brekker på iPad (${Math.round(m.topp)} px)`);
      if (m.mobil !== (navn === 'mobil')) her.push(`${id}: mobilvisningen er ${m.mobil ? 'på' : 'av'} på ${navn}`);
      console.log(`${navn} #${id}: ${her.length > for_ ? 'FEIL' : 'ok'}`);
    }
    // «Send beskjed» fra kurssiden: ingenting valgt, låst knapp, Tilbake spør før noe forkastes.
    if (navn === 'PC') {
      await p.goto(`${BASE}/ny-admin#kurs?okt=${s.session}&fra=kurs`, {waitUntil: 'networkidle'});
      await p.getByRole('button', {name: /Send beskjed til/}).click();
      const ark = p.locator('dialog#ark');
      await ark.getByText(/mottakere/).waitFor();
      assert.equal(await ark.locator('[data-k="beskjedKanal"][aria-pressed="true"]').count(), 0, 'ingen kanal er valgt på forhånd');
      assert.equal(await ark.locator('#nk-bok').isDisabled(), true, 'Send er låst til kanal og tekst er valgt');
      await ark.getByRole('button', {name: 'E-post', exact: true}).click();
      await ark.locator('#nk-beskjed').fill('Hei, dette er en test.');
      assert.equal(await ark.locator('#nk-bok').isDisabled(), false, 'Send er åpen når kanal og tekst er valgt');
      assert.equal(await p.evaluate(() => getComputedStyle(document.documentElement).overflow), 'hidden', 'bakgrunnen ruller ikke bak arket');
      await p.goBack();
      await ark.getByText('Vil du forkaste det du har skrevet?').waitFor();
      await ark.getByRole('button', {name: 'Forkast'}).click();
      await p.waitForTimeout(300);
      assert.equal(await p.evaluate(() => document.querySelector('dialog#ark').open), false, 'arket er lukket');
      assert.ok(p.url().includes('#kurs?okt='), 'står fortsatt på kurssiden');
      console.log('PC: «Send beskjed» uten forhåndsvalg, låst knapp, Tilbake spør «Vil du forkaste?».');
      // Rediger påmelding (kontrolløren 09.10.2026): rabatt og beløp kan ikke begge gjelde. Rabatten låser beløpet og
      // viser det serveren vil regne ut (pris × antall − rabatt); et skrevet beløp låser rabatten. Ingenting lagres her.
      await p.goto(`${BASE}/ny-admin#kurs?okt=${s.session}&fra=kurs`, {waitUntil: 'networkidle'});
      await p.locator(`[data-k="deltaker"][data-booking="${s.booking}"]`).first().click();
      await ark.locator('[data-k="dRediger"]').click();
      await ark.locator('#d-rabatt').fill('10');
      assert.equal(await ark.locator('#d-belop').isDisabled(), true, 'rabatt: beløpet er låst');
      assert.equal(await ark.locator('#d-belop').inputValue(), '90', 'rabatt: beløpet regnes som på serveren (100 kr − 10 %)');
      assert.match(await ark.locator('#d-hint').innerText(), /Nytt beløp/, 'rabatt: det nye beløpet vises før lagring');
      await ark.locator('#d-rabatt').fill('');
      assert.equal(await ark.locator('#d-belop').isDisabled(), false, 'rabatten tilbake: beløpet er åpent igjen');
      assert.equal(await ark.locator('#d-belop').inputValue(), '100', 'rabatten tilbake: beløpet er som før');
      await ark.locator('#d-belop').fill('80');
      assert.equal(await ark.locator('#d-rabatt').isDisabled(), true, 'beløp: rabatten er låst');
      assert.match(await ark.locator('#d-hint').innerText(), /Beløpet du skrev gjelder/, 'beløp: det skrevne gjelder');
      await ark.locator('[data-k="lukk"]').first().click();
      console.log('PC: Rediger påmelding låser rabatt eller beløp og viser beløpet før lagring.');
    }
    feil.push(...her.map(f => `${navn}: ${f}`));
    await c.close();
  }
  assert.deepEqual([...new Set(feil)], [], 'ingen feil på noen side');
  console.log('Ny admin: alle sidene på PC, iPad, mobil og smal PC uten skriptfeil og uten 4xx/5xx.');
} finally { await browser.close(); fixture('cleanup', s); }
