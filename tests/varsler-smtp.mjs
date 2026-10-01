/** Loopback-only SMTP integration receiver; never forwards any email. */
import { startSmtpReceiver } from './falsk-smtp.mjs';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawn } from 'node:child_process';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'lissom-smtp-'));
const receiver = await startSmtpReceiver();
const { messages, attempts } = receiver;
try {
  const resultPath = path.join(tmp, 'php.json');
  const child = spawn('php', ['tests/varsler-smtp.php'], { cwd: root, env: { ...process.env,
    LISSOM_SMTP_TEST_PORT: String(receiver.port), LISSOM_SMTP_TEST_RESULT: resultPath }, stdio: ['ignore', 'pipe', 'pipe'] });
  child.stdout.pipe(process.stdout); child.stderr.pipe(process.stderr);
  const timeout = setTimeout(() => child.kill(), 90000);
  const code = await new Promise((resolve, reject) => { child.on('error', reject); child.on('exit', resolve); });
  clearTimeout(timeout);
  assert.equal(code, 0, 'PHP queue checks');
  const checks = JSON.parse(fs.readFileSync(resultPath, 'utf8'));
  const check = (name, ok) => { checks.push({ name, ok }); console.log(`${ok ? 'OK' : 'FEIL'} ${name}`); };
  const templateCount = checks.filter(c => c.name.endsWith('e-post bygges og legges i kø')).length;
  check('SMTP-mottakeren fikk alle forventede meldinger', messages.length === 4 + templateCount);
  check('alle varselmaler nådde den lokale SMTP-mottakeren', messages.filter(m => m.recipient.startsWith('mal-')).length === templateCount && templateCount > 0);
  check('fremtidig melding nådde ikke SMTP', !attempts.some(m => m.recipient === 'future@example.test'));
  check('avvist mottaker nådde ikke DATA', !attempts.some(m => m.recipient === 'reject@example.test'));
  const success = messages.find(m => m.recipient === 'success@example.test')?.raw || '';
  check('kursbevislenken overføres i HTML', success.includes('https://example.test/kursbevis?k=syntetisk'));
  check('UTF-8 innhold overføres', success.includes('æøå'));
  check('ren tekst og HTML overføres sammen', success.includes('multipart/alternative') && success.includes('text/plain') && success.includes('text/html'));
  check('prikk først i linjen escapes', success.includes('..prikk'));
  check('Reply-To følger med', success.includes('Reply-To: reply@example.test'));
  check('Message-ID følger med', /Message-ID: <[^>]+>/.test(success));
  const retries = attempts.filter(m => m.recipient === 'retry@example.test');
  check('to SMTP-forsøk gir én levert retry', retries.length === 2 && messages.filter(m => m.recipient === 'retry@example.test').length === 1);
  check('retry beholder Message-ID', retries.length === 2 && retries[0].raw.match(/Message-ID: (.+)/)?.[1] === retries[1].raw.match(/Message-ID: (.+)/)?.[1]);
  if (process.env.LISSOM_SMTP_REPORT) fs.writeFileSync(process.env.LISSOM_SMTP_REPORT, JSON.stringify({ checks, templates: templateCount, delivered: messages.length, attempts: attempts.length }, null, 2));
  assert.ok(checks.every(c => c.ok), 'all SMTP checks must pass');
  console.log(`${checks.length} SMTP-kontroller bestod; ingen melding forlot localhost.`);
} finally {
  await receiver.close();
  assert.ok(path.isAbsolute(tmp) && tmp.startsWith(path.join(os.tmpdir(), 'lissom-smtp-')), 'owned temporary directory');
  fs.rmSync(tmp, { recursive: true, force: true });
}
