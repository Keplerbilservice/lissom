// /ny-admin › Varer (eieren 09.10.2026): fanene Internlager · Lissom kolleksjon · Medlemskolleksjon · Alle · Handleliste,
// søk innen fanen, rediger vare med minimum og maks, «Under minimum», handlelista (legg til, handlet, fjern),
// rediger og fjern i medlemskolleksjonen med «Er du sikker?». PC 1280 og iPad 1024 (berøring): ingen skriptfeil,
// ingen 4xx/5xx og ingen sideveis rulling.
//   E2E_PORT=8146 node tests/nyadmin-varer.mjs   (php -S 127.0.0.1:<port> -t . tests/nettleser/ruter.php)
import assert from 'node:assert/strict'; import {createRequire} from 'node:module'; import {execFileSync} from 'node:child_process';
const {chromium} = createRequire(import.meta.url)('playwright');
const fixture = (m, s) => JSON.parse(execFileSync('php', ['tests/nettleser/varer-fixture.php', m, JSON.stringify(s || {})], {encoding: 'utf8'}) || '{}');
const s = fixture('seed');
const BASE = 'http://lokal.lissom.no:' + (process.env.E2E_PORT || 8140);
const browser = await chromium.launch({args: ['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
const rader = p => p.locator('#var-liste').getByText(new RegExp(s.tag));
const rad = (p, navn) => p.locator('#var-liste .radknapp', {hasText: s.tag + ' ' + navn});
try {
  for (const [navn, vp, beroring] of [['iPad', {width: 1024, height: 1366}, true], ['PC', {width: 1280, height: 900}, false]]) {
    const c = await browser.newContext({viewport: vp, hasTouch: beroring});
    await c.addCookies([{name: 'lissom_sesjon', value: s.token, domain: 'lokal.lissom.no', path: '/'}]);
    const p = await c.newPage(), feil = [];
    p.on('pageerror', e => feil.push('JS: ' + e.message));
    p.on('response', r => { if (r.url().startsWith(BASE) && r.status() >= 400) feil.push(`HTTP ${r.status()} ${r.url().replace(BASE, '')}`); });
    p.on('dialog', d => d.accept());
    await p.goto(`${BASE}/ny-admin#varer`, {waitUntil: 'networkidle'});
    await p.getByRole('heading', {name: 'Varer og lager', exact: true}).waitFor();
    const faner = (await p.locator('#var-faner button').allTextContents()).map(t => t.replace(/ \(\d+\)$/, ''));
    assert.deepEqual(faner, ['Internlager', 'Lissom kolleksjon', 'Medlemskolleksjon', 'Alle', 'Handleliste'], navn + ': fanene');
    assert.equal(await p.locator('#var-faner [aria-pressed="true"]').innerText(), 'Alle', navn + ': Alle er valgt først');
    // Søk innen fanen (topplinja).
    await p.locator('#sok').fill(s.tag);
    const fane = async (n, med, uten) => {
      await p.locator('#var-faner button', {hasText: n}).click();
      for (const m of med) assert.equal(await rad(p, m).count(), 1, `${navn}: ${n} viser ${m}`);
      for (const u of uten) assert.equal(await rad(p, u).count(), 0, `${navn}: ${n} viser ikke ${u}`);
    };
    await fane('Internlager', ['Leire'], ['Kopp', 'Krus']);
    await fane('Lissom kolleksjon', ['Kopp'], ['Leire', 'Krus']);
    await fane('Medlemskolleksjon', ['Krus'], ['Leire', 'Kopp']);
    await fane('Alle', ['Leire', 'Kopp', 'Krus'], []);
    await p.locator('#sok').fill(s.tag + ' Kop');
    assert.equal(await rader(p).count(), 1, navn + ': søket filtrerer innen fanen');
    await p.locator('#sok').fill(s.tag);
    const sideveis = await p.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
    assert.equal(sideveis, false, navn + ': ingen sideveis rulling');

    if (navn === 'PC') {
      // Rediger: minimum 8 og maks 20 på Leire (6 på lager) → «Under minimum» og i handlelista med 14.
      await p.locator('#var-faner button', {hasText: 'Internlager'}).click();
      await rad(p, 'Leire').click();
      await p.getByLabel('Beskrivelse', {exact: true}).fill('Steingodsleire');
      await p.getByLabel('Minimum på lager', {exact: true}).fill('8');
      await p.getByLabel('Maks på lager', {exact: true}).fill('20');
      await p.getByRole('button', {name: 'Lagre', exact: true}).click();
      await p.locator('#ark').waitFor({state: 'hidden'});
      await rad(p, 'Leire').getByText('Under minimum', {exact: true}).waitFor();
      let x = fixture('inspect', s);
      assert.equal(x.intern.lager_min, 8); assert.equal(x.intern.lager_maks, 20);
      assert.equal(x.linjer.length, 1); assert.equal(x.linjer[0].antall, 14, 'fyll opp til maks: 20 − 6');
      // Handleliste: under minimum + på lista; Handlet legger 14 på lageret.
      await p.locator('#var-faner button', {hasText: 'Handleliste'}).click();
      await p.getByRole('heading', {name: 'Under minimum', exact: true}).waitFor();
      const linje = p.locator('#var-liste .rad', {hasText: s.tag + ' Leire'});
      assert.equal(await linje.filter({hasText: 'På handlelista'}).count(), 1, 'merket «På handlelista» under minimum');
      const paaLista = linje.filter({has: p.getByRole('button', {name: 'Handlet', exact: true})});
      assert.equal(await paaLista.filter({hasText: '14 stk'}).count(), 1, 'på handlelista med 14 stk');
      await paaLista.getByRole('button', {name: 'Handlet', exact: true}).click();
      await paaLista.waitFor({state: 'detached'});
      x = fixture('inspect', s);
      assert.equal(x.intern.lager, 20, 'lageret 6 + 14'); assert.equal(x.linjer[0].status, 'ferdig');
      // Legg til vare med eget antall, og fjern linja igjen.
      await p.getByRole('button', {name: '＋ Legg til vare'}).click();
      await p.locator('#hl-vare').selectOption(String(s.intern));
      await p.locator('#hl-antall').fill('5');
      await p.getByRole('button', {name: 'Legg til', exact: true}).click();
      const ny = p.locator('#var-liste .rad', {hasText: s.tag + ' Leire'}).filter({has: p.getByRole('button', {name: 'Handlet', exact: true})}).filter({hasText: '5 stk'});
      await ny.waitFor();
      await ny.getByRole('button', {name: /^Fjern/}).click();
      await ny.waitFor({state: 'detached'});
      x = fixture('inspect', s);
      assert.equal(x.linjer.filter(l => l.status === 'sendt').length, 0, 'linja er fjernet');
      // Medlemskolleksjonen: rediger pris, og fjern med «Er du sikker?».
      await p.locator('#var-faner button', {hasText: 'Medlemskolleksjon'}).click();
      await rad(p, 'Krus').click();
      await p.getByLabel('Pris', {exact: true}).fill('275');
      await p.getByRole('button', {name: 'Lagre', exact: true}).click();
      await p.locator('#ark').waitFor({state: 'hidden'});
      assert.equal(fixture('inspect', s).salg.pris_ore, 27500);
      await rad(p, 'Krus').click();
      await p.getByRole('button', {name: 'Fjern vare', exact: true}).click();
      await p.getByRole('heading', {name: 'Fjern vare?', exact: true}).waitFor();
      await p.locator('#ark [data-ja]').click();
      await rad(p, 'Krus').waitFor({state: 'detached'});
      assert.equal(fixture('inspect', s).salg, null, 'medlemsvaren er fjernet');
      // Fjern vare i Lissom kolleksjon (aldri solgt → slettes).
      await p.locator('#var-faner button', {hasText: 'Lissom kolleksjon'}).click();
      await rad(p, 'Kopp').click();
      await p.getByRole('button', {name: 'Fjern vare', exact: true}).click();
      await p.locator('#ark [data-ja]').click();
      await rad(p, 'Kopp').waitFor({state: 'detached'});
      assert.equal(fixture('inspect', s).nett, null, 'varen er fjernet');
    } else {
      // iPad: arket åpnes og har feltene, uten sideveis rulling.
      await p.locator('#var-faner button', {hasText: 'Internlager'}).click();
      await rad(p, 'Leire').tap();
      await p.getByLabel('Minimum på lager', {exact: true}).waitFor();
      assert.equal(await p.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, 'iPad: ingen sideveis rulling i arket');
      await p.getByRole('button', {name: 'Avbryt', exact: true}).tap();
      await p.locator('#var-faner button', {hasText: 'Handleliste'}).tap();
      await p.getByRole('heading', {name: 'Handlelista', exact: true}).waitFor();
    }
    assert.deepEqual(feil, [], navn + ': ingen feil');
    console.log(`${navn}: fanene, søket${navn === 'PC' ? ', min/maks, handlelista (handlet, legg til, fjern), medlemskolleksjonen og fjern vare' : ' og arket'} virker.`);
    await c.close();
  }
} finally {
  await browser.close();
  fixture('rydd', s);
}
