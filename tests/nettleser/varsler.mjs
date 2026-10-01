/** Real cron -> queue -> emailed certificate -> browser, using synthetic data. */
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { createRequire } from 'node:module';
import fs from 'node:fs';
import { startSmtpReceiver } from '../falsk-smtp.mjs';
const { chromium } = createRequire(import.meta.url)('playwright');
const fixture = (mode, data = {}) => JSON.parse(execFileSync('php', ['tests/nettleser/varsler-fixture.php', mode, JSON.stringify(data)], { encoding: 'utf8' }));
const checks = [];
const check = (name, ok) => { checks.push({ name, ok }); console.log(`${ok ? 'OK' : 'FEIL'} ${name}`); };
const cron = job => execFileSync('php', ['bin/cron.php', job], { encoding: 'utf8', timeout: 90000 });
const s = fixture('seed'); let browser, receiver;
try {
  cron('paaminnelser'); cron('anmeldelser');
  let d = fixture('inspect', s);
  const messages = name => d.notifications.filter(n => n.mottaker === `${s.tag}-${name}@e2e.lissom.test`);
  check('gammel betalt påmelding får én påminnelse dagen før', messages('reminder').length === 1 && messages('reminder')[0].mal === 'kurspaaminnelse');
  check('ny påmelding får ikke overflødig påminnelse', messages('recent').length === 0);
  check('ubetalt påmelding får ikke påminnelse', messages('unpaid').length === 0);
  check('fremtidig kurs får ikke for tidlig påminnelse', messages('future').length === 0);
  check('påminnelsen bruker riktig kurs og fornavn', /Nattkontroll/i.test(messages('reminder')[0]?.tekst || '') && /dreiekurs/i.test(messages('reminder')[0]?.tekst || ''));
  check('påminnelsestempel lagres', !!d.reminderStamp);
  check('gjennomført betalt kurs får oppfølging', messages('certificate').length === 1 && messages('certificate')[0].mal === 'anmeldelse');
  check('ubetalt gjennomført kurs får ikke kursbevismail', messages('unpaidpast').length === 0);
  check('kurs eldre enn tre døgn får ikke sen anmeldelsespåminnelse', messages('old').filter(n => n.mal === 'anmeldelse').length === 0);
  check('oppfølgingsstempel lagres', !!d.reviewStamp);
  const html = messages('certificate')[0]?.html || '';
  check('e-post inneholder trykkbart personlig kursbevis', /href="[^"]*api\/kursbevis\.php/.test(html) && html.includes('Kursbevis'));
  check('anmeldelsesknappen går til innstilt adresse', html.includes('https://example.test/anmeldelse'));
  check('trukket kursbevis utelates fra oppfølgingsmail', messages('revoked').length === 1 && !messages('revoked')[0].html.includes('api/kursbevis.php'));
  check('ingen uerstattede felt i kursbevismail', !/\{(?:fornavn|kursbevisblokk|lenke)\}/.test(html));
  const before = d.notifications.length;
  cron('paaminnelser'); cron('anmeldelser'); d = fixture('inspect', s);
  check('gjentatt cron gir ikke doble påminnelser eller bevismailer', d.notifications.length === before);
  receiver = await startSmtpReceiver();
  const delivered = await new Promise((resolve, reject) => {
    const child = spawn('php', ['tests/nettleser/varsler-fixture.php', 'deliver', JSON.stringify({ ...s, smtpPort: receiver.port })], { stdio: ['ignore', 'pipe', 'pipe'] });
    let out = ''; child.stdout.on('data', b => out += b); child.stderr.pipe(process.stderr);
    const timeout = setTimeout(() => child.kill(), 45000);
    child.on('error', e => { clearTimeout(timeout); reject(e); });
    child.on('exit', code => { clearTimeout(timeout); if (code !== 0) reject(new Error(`delivery exit ${code}`)); else { try { resolve(JSON.parse(out)); } catch (e) { reject(e); } } });
  });
  check('cron-meldingene leveres faktisk til lokal SMTP', delivered.delivery?.[0] === before && delivered.delivery?.[1] === 0 && receiver.messages.length === before);
  check('SMTP har bare syntetiske mottakere fra denne prøven', receiver.messages.every(m => m.recipient.startsWith(s.tag) && m.recipient.endsWith('@e2e.lissom.test')));
  const received = receiver.messages.find(m => m.recipient === `${s.tag}-certificate@e2e.lissom.test`)?.raw || '';
  const link = received.match(/href="([^"]*api\/kursbevis\.php[^\"]*)"/)?.[1]?.replace(/&amp;/g, '&');
  check('personlig kursbevislenke finnes i mottatt SMTP-melding', !!link);
  assert.ok(link, 'certificate link required for HTTP checks');
  const addr = process.env.E2E_ADRESSE || 'http://lokal.lissom.no:8140';
  const u = new URL(link); const local = addr + u.pathname + u.search;
  browser = await chromium.launch({ args: ['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1'] });
  for (const width of [390, 1280]) {
    const context = await browser.newContext({ viewport: { width, height: 900 } });
    const page = await context.newPage(); const errors = []; page.on('pageerror', e => errors.push(e.message));
    const response = await page.goto(local); const text = await page.locator('body').innerText();
    check(`${width}px: e-postlenken åpner bevis uten innlogging`, response.status() === 200);
    check(`${width}px: riktig deltaker på beviset`, text.includes('Nattkontroll certificate'));
    check(`${width}px: riktig kurs på beviset`, text.includes('Nattkontroll Dreiekurs'));
    check(`${width}px: ingen skriptfeil i beviset`, errors.length === 0);
    const wrong = new URL(local); wrong.searchParams.set('k', '0'.repeat(32));
    check(`${width}px: feil personlig kode avvises`, (await page.goto(wrong.href)).status() === 404);
    const other = new URL(local); other.searchParams.set('booking', String(s.bookings.revoked));
    check(`${width}px: koden kan ikke åpne en annen deltakers bevis`, (await page.goto(other.href)).status() === 404);
    await context.close();
  }
  fixture('revoke', s);
  const context = await browser.newContext();
  const revokedPage = await context.newPage();
  check('allerede utsendt lenke sperres ved tilbakekalling', (await revokedPage.goto(local)).status() === 404);
  await context.close();
} finally {
  if (browser) await browser.close();
  if (receiver) await receiver.close();
  fixture('cleanup', s);
  if (process.env.LISSOM_CRON_REPORT) fs.writeFileSync(process.env.LISSOM_CRON_REPORT, JSON.stringify({ checks }, null, 2));
}
assert.ok(checks.every(c => c.ok), 'all cron/certificate checks must pass');
console.log(`${checks.length} cron-/kursbeviskontroller bestod.`);
