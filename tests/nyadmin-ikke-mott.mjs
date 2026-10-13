// «Møtte ikke opp» rett fra kurset i kalenderen (eieren 13.10.2026): kalender → kurset → trykk på deltakeren.
// Knappen setter ikke_mott (samme status som «Ikke møtt» i Start dagens kurs), og «Møtte likevel» setter den
// tilbake til statusen før: betalt når plassen er ført som betalt (her Kontant), ellers reservert.
// PC og mobil. Kjøres via tests/nettleser/kjor.sh admin-ny.
import assert from 'node:assert/strict'; import {createRequire} from 'node:module'; import {execFileSync} from 'node:child_process';
const {chromium} = createRequire(import.meta.url)('playwright');
const fixture = (m, s) => JSON.parse(execFileSync('php', ['tests/nettleser/henting-fixture.php', m, JSON.stringify(s || {})], {encoding: 'utf8'}) || '{}');
const php = kode => execFileSync('php', ['-r', `require 'tests/nettleser/testdatabase.php';krev_testdatabase(getcwd());require 'app/bootstrap.php';${kode}`], {encoding: 'utf8'});
const BASE = 'http://lokal.lissom.no:' + (process.env.E2E_PORT || 8140);
const s = fixture('seed');
php(`DB::oppdater('bookings',['betalt_maate'=>'Kontant'],['id'=>${s.booking}]);`);
const status = () => php(`echo DB::verdi('SELECT status FROM bookings WHERE id=:i',['i'=>${s.booking}]);`).trim();
const browser = await chromium.launch({args: ['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
try {
  for (const [navn, vp, mobil] of [['PC', {width: 1280, height: 900}, false], ['mobil', {width: 390, height: 844}, true]]) {
    const c = await browser.newContext({viewport: vp, hasTouch: mobil, isMobile: mobil});
    await c.addCookies([{name: 'lissom_sesjon', value: s.token, domain: 'lokal.lissom.no', path: '/'}]);
    const p = await c.newPage(), feil = [];
    p.on('pageerror', e => feil.push(e.message));
    const aapne = async () => {
      await p.goto(`${BASE}/admin#kurs?okt=${s.session}&fra=kalender`, {waitUntil: 'networkidle'});
      await p.locator(`[data-k="deltaker"][data-booking="${s.booking}"]`).click();
      return p.locator('dialog[open]');
    };
    let ark = await aapne();
    await ark.getByRole('button', {name: 'Møtte ikke opp', exact: true}).click();
    await ark.waitFor({state: 'hidden'});
    await p.waitForTimeout(500);
    assert.equal(status(), 'ikke_mott', `${navn}: Møtte ikke opp lagres som ikke_mott`);
    ark = await aapne();
    assert.equal(await ark.getByRole('button', {name: 'Møtte ikke opp', exact: true}).count(), 0, `${navn}: knappen er byttet ut`);
    await ark.getByRole('button', {name: 'Møtte likevel', exact: true}).click();
    await ark.waitFor({state: 'hidden'});
    await p.waitForTimeout(500);
    assert.equal(status(), 'betalt', `${navn}: Møtte likevel setter den tilbake til betalt`);
    assert.equal(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, `${navn}: ingen sideveis rulling`);
    assert.deepEqual(feil, [], `${navn}: ingen skriptfeil`);
    await c.close();
    console.log(`  OK    ${navn}: Møtte ikke opp og Møtte likevel fra kurset`);
  }
} finally {
  await browser.close();
  fixture('cleanup', s);
}
