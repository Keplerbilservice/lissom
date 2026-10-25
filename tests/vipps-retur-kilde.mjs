// Retur fra Vipps til kurssida/gavekortsida, én kilde per kjøp, og kilden
// fra landingsadressen (25. oktober 2026).
//
//   php -S 127.0.0.1:8150 -t . tests/nettleser/ruter.php   (i en annen terminal)
//   node tests/vipps-retur-kilde.mjs
//
// Ingenting går til Google eller Meta: gtag og fbq er opptakere, og alle kall
// til googletagmanager.com, google-analytics.com og facebook.net stoppes.
// Måle-ID-ene er falske og legges inn i svaret fra /api/innhold.php.
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const { chromium } = createRequire(import.meta.url)('playwright');
const PORT = process.env.E2E_PORT_KILDE || '8150';
const ROT = `http://lokal.lissom.no:${PORT}`;
const GA = 'G-TEST12345';
const META = '1234567890123456';

const browser = await chromium.launch({ args: ['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1'] });
let feil = 0;
const sjekk = (navn, fn) => { try { fn(); console.log('  OK    ' + navn); } catch (e) { feil++; console.log('  FEIL  ' + navn + ' — ' + e.message); } };

async function side(samtykke, viewport) {
  const c = await browser.newContext({ viewport });
  await c.route(/googletagmanager\.com|google-analytics\.com|facebook\.net|facebook\.com/, r => r.abort());
  await c.route('**/api/innhold.php*', async r => {
    const svar = await r.fetch({ url: r.request().url().replace('lokal.lissom.no', '127.0.0.1') });
    const j = await svar.json();
    j.innhold = Object.assign({}, j.innhold, { 'Marked/GA-id': GA, 'Marked/Meta-piksel': META, 'Marked/GTM-id': '' });
    await r.fulfill({ response: svar, json: j });
  });
  // Serversidene: samme falske ID-er i window.lissomMaal.
  await c.route(/\/kurs\/[a-z0-9-]+\?utm_/, async r => {
    const svar = await r.fetch({ url: r.request().url().replace('lokal.lissom.no', '127.0.0.1') });
    const html = (await svar.text()).replace(/window\.lissomMaal = [^;]*;/, `window.lissomMaal = {"ga":"${GA}","meta":"${META}"};`);
    await r.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: html });
  });
  // gtag og fbq lages av sida selv, som i drift (etter «ja»). Skriptene
  // stoppes over, så kallene blir liggende i køene: dataLayer og fbq.queue.
  await c.addInitScript(([sam]) => {
    try { if (sam) localStorage.setItem('lissom-analyse', sam); } catch (e) {}
  }, [samtykke]);
  return { c, p: await c.newPage() };
}

const GA_KJOP = () => (window.dataLayer || []).map(a => Array.from(a)).filter(a => a[0] === 'event' && /^(purchase|booking_fullfort|gavekort_kjopt)$/.test(a[1]));
const META_KJOP = () => ((window.fbq && window.fbq.queue) || []).map(a => Array.from(a)).filter(a => a[0] === 'track' && a[1] === 'Purchase');
const kjop = async (p) => p.evaluate(([g, m]) => ({
  ga: eval(g)().map(a => [a[1], a[2].transaction_id]),
  meta: eval(m)().map(a => [a[1], (a[3] || {}).eventID]),
  adresse: location.pathname + location.search + location.hash,
  tekst: document.body.innerText,
}), [GA_KJOP.toString(), META_KJOP.toString()]);

for (const viewport of [{ width: 390, height: 844 }, { width: 1280, height: 900 }]) {
  const b = viewport.width + ' px';

  // 1. Serveren har sendt til GA4 og Meta: nettleseren sender ikke på nytt.
  {
    const { c, p } = await side('ja', viewport);
    await p.goto(`${ROT}/kurs/dreiekurs?betaling=ok#kjop=901&belop=2800.00&slag=booking&server=ga.meta`);
    await p.getByText('Betalingen er gjennomført').first().waitFor({ timeout: 15000 });
    // Målingen er lastet (config til GA4, init til Meta) før vi ser etter.
    await p.waitForFunction(() => (window.dataLayer || []).some(a => a[0] === 'config')
      && ((window.fbq && window.fbq.queue) || []).some(a => a[0] === 'init'), null, { timeout: 15000 });
    await p.waitForTimeout(1000);
    const r = await kjop(p);
    sjekk(`${b}: kurssida viser utfallet`, () => assert.match(r.tekst, /Plassen din er registrert/));
    sjekk(`${b}: ingen purchase fra nettleseren når serveren sendte`, () => assert.deepEqual(r.ga, []));
    sjekk(`${b}: ingen Purchase til Meta fra nettleseren`, () => assert.deepEqual(r.meta, []));
    sjekk(`${b}: ?betaling= og tallene strøket fra adressen`, () => assert.equal(r.adresse, '/kurs/dreiekurs'));
    const talt = await p.evaluate(() => localStorage.getItem('lissom-kjop') || '');
    sjekk(`${b}: kjøpet merket som talt`, () => assert.match(talt, /(^|,)901(,|$)/));
    await c.close();
  }

  // 2. Serveren sendte ikke (ikke samtykket ved start): nettleseren sender.
  {
    const { c, p } = await side('ja', viewport);
    await p.goto(`${ROT}/kurs/dreiekurs?betaling=ok#kjop=902&belop=1490.00&slag=booking`);
    await p.getByText('Betalingen er gjennomført').first().waitFor({ timeout: 15000 });
    await p.waitForFunction(g => eval(g)().some(a => a[1] === 'purchase'), GA_KJOP.toString(), { timeout: 15000 });
    const r = await kjop(p);
    sjekk(`${b}: purchase + booking_fullfort fra nettleseren med L902`, () => assert.deepEqual(r.ga, [['purchase', 'L902'], ['booking_fullfort', 'L902']]));
    sjekk(`${b}: Purchase til Meta med eventID L902`, () => assert.deepEqual(r.meta, [['Purchase', 'L902']]));
    await c.close();
  }

  // 3. Bare GA4 fra serveren: Meta fra nettleseren, GA4 ikke.
  {
    const { c, p } = await side('ja', viewport);
    await p.goto(`${ROT}/gavekort?betaling=ok#kjop=903&belop=1590.00&slag=gavekort&server=ga`);
    await p.waitForFunction(m => eval(m)().length > 0, META_KJOP.toString(), { timeout: 15000 });
    const r = await kjop(p);
    sjekk(`${b}: gavekortsida — GA4 ikke fra nettleseren`, () => assert.deepEqual(r.ga, []));
    sjekk(`${b}: gavekortsida — Meta fra nettleseren, L903`, () => assert.deepEqual(r.meta, [['Purchase', 'L903']]));
    sjekk(`${b}: gavekortsida — adressen er /gavekort`, () => assert.equal(r.adresse, '/gavekort'));
    await c.close();
  }

  // 4. Serverens GA4 feilet (Meta gikk): nettleseren sender purchase som
  //    reserve, men aldri booking_fullfort (dedupliseres ikke i Ads).
  {
    const { c, p } = await side('ja', viewport);
    await p.goto(`${ROT}/kurs/dreiekurs?betaling=ok#kjop=904&belop=2800.00&slag=booking&server=meta&forsokt=ga.meta`);
    await p.waitForFunction(g => eval(g)().some(a => a[1] === 'purchase'), GA_KJOP.toString(), { timeout: 15000 });
    await p.waitForTimeout(1000);
    const r = await kjop(p);
    sjekk(`${b}: reserve — bare purchase L904, ingen booking_fullfort`, () => assert.deepEqual(r.ga, [['purchase', 'L904']]));
    sjekk(`${b}: reserve — ingen Meta fra nettleseren`, () => assert.deepEqual(r.meta, []));
    await c.close();
  }
}

// 5. Kilden fra landingsadressen: cookie bare etter «ja».
{
  const { c, p } = await side('ja', { width: 390, height: 844 });
  await p.goto(`${ROT}/kurs/dreiekurs?utm_source=facebook&utm_medium=paid_social&utm_campaign=host&fbclid=IwAR2F4-dbP0l7Mn1Iaw`);
  await p.waitForTimeout(1000);
  const k = (await c.cookies()).find(x => x.name === 'lissom_kilde');
  sjekk('med «ja»: lissom_kilde satt på landingssida', () => assert.ok(k));
  const v = k ? JSON.parse(decodeURIComponent(k.value)) : {};
  sjekk('lissom_kilde har utm og fbclid', () => assert.equal(v.utm_source + '|' + v.fbclid, 'facebook|IwAR2F4-dbP0l7Mn1Iaw'));
  await c.close();
}
{
  const { c, p } = await side('', { width: 390, height: 844 });
  await p.goto(`${ROT}/kurs/dreiekurs?utm_source=facebook&fbclid=IwAR2F4-dbP0l7Mn1Iaw`);
  await p.waitForTimeout(1000);
  const k = (await c.cookies()).find(x => x.name === 'lissom_kilde');
  sjekk('uten samtykke: ingen lissom_kilde', () => assert.equal(k, undefined));
  await c.close();
}

await browser.close();
console.log(feil === 0 ? '\n  Alt grønt.' : `\n  ${feil} FEIL`);
process.exit(feil === 0 ? 0 : 1);
