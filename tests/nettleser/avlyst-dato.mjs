/**
 * Avlyst dato på Min side, i en ekte Chromium (eieren, GO 9. oktober 2026).
 *
 * Telefon (390 px med berøring) og PC: plassen står som «Avlyst» med eierens
 * tekst og «Velg ny dato» / «Få pengene tilbake» (ingen «Avbestill»), og
 * «Neste kurs» øverst hopper over avlyste datoer. På telefon hele veien:
 * velg dato → «Bytt til denne datoen» (plassen flyttet, 500 kr, betalt, og nå
 * er den «Neste kurs»), og «Få pengene tilbake» → «Du får 500 kr tilbake» →
 * refundert i (falsk) Vipps.
 *
 *   bash tests/nettleser/kjor.sh avlyst-dato.mjs
 */
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const HER = path.dirname(fileURLToPath(import.meta.url));
const ROT = path.resolve(HER, '..', '..');
const krev = createRequire(import.meta.url);
let chromium;
for (const fra of [ROT, path.resolve(ROT, '..', 'lissom')]) {
  try { ({ chromium } = krev(krev.resolve('playwright', { paths: [fra] }))); break; } catch { /* prov neste */ }
}
if (!chromium) { console.error('Fant ikke playwright. npm install playwright'); process.exit(1); }
const ADR = process.env.E2E_ADRESSE || 'http://lokal.lissom.no:8140';
const VERT = new URL(ADR).hostname;

const php = (kode) => JSON.parse(execFileSync('php', [path.join(HER, 'db.php'), '--php', kode], { cwd: ROT, encoding: 'utf8' }) || 'null');
const db = (sql) => JSON.parse(execFileSync('php', [path.join(HER, 'db.php'), sql, '{}'], { cwd: ROT, encoding: 'utf8' }) || '[]');
// Venter paa det riktige svaret, ikke paa faste sekunder: trykket og svaret fra
// serveren (POST til gitt api-fil) tas sammen, deretter leses basen om til
// betingelsen holder (maks 20 s). php -S tar en foresporsel om gangen, saa
// svartiden varierer sterkt paa trege CI-maskiner.
const medSvar = async (p, apiFil, trykk) => {
  const svar = p.waitForResponse(r => r.url().includes(apiFil) && r.request().method() === 'POST', { timeout: 30000 });
  await trykk();
  const r = await svar; try { console.log('  DEBUG ' + apiFil + ' ' + r.status() + ' ' + (await r.text()).slice(0, 400)); } catch (e) { console.log('  DEBUG les feilet ' + e.message); }
  return r;
};
const vent = async (les, ferdig, ms = 20000) => {
  const slutt = Date.now() + ms; let v = les();
  while (!ferdig(v) && Date.now() < slutt) { await new Promise(r => setTimeout(r, 400)); v = les(); }
  return v;
};
let ok = 0; const feil = [];
const sjekk = (n, v, m = '') => { if (v) { ok++; console.log('  OK    ' + n); } else { feil.push(n); console.log('  FEIL  ' + n + (m ? '  — ' + m : '')); } };

const seed = () => php(`
  $tag = bin2hex(random_bytes(3));
  $kid = DB::settInn('courses', ['slug' => 'avlnett-' . $tag, 'tittel' => 'Dreiekurs for nybegynnere', 'type' => 'kurs', 'pris_ore' => 50000, 'kapasitet' => 8, 'status' => 'publisert']);
  $om = static fn(int $t): string => gmdate('Y-m-d H:00:00', time() + $t * 3600);
  $avl = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => $om(30), 'status' => 'avlyst', 'avlyst_at' => gmdate('Y-m-d H:i:s')]);
  $ny1 = DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => $om(200), 'status' => 'planlagt']);
  DB::settInn('course_sessions', ['course_id' => $kid, 'start_tid' => $om(370), 'status' => 'planlagt']);
  $mid = DB::settInn('members', ['navn' => 'Kari Avlnett', 'epost' => 'avlnett-' . $tag . '@e2e.lissom.test', 'telefon' => '41234567', 'status' => 'ingen', 'rolle' => 'medlem']);
  $t = bin2hex(random_bytes(32));
  DB::settInn('sessions', ['member_id' => $mid, 'token_hash' => hash('sha256', $t), 'maate' => 'passord', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 7200)]);
  $bok = [];
  foreach ([1, 2] as $i) {
    $b = DB::settInn('bookings', ['member_id' => $mid, 'course_id' => $kid, 'course_session_id' => $avl, 'antall' => 1, 'belop_ore' => 50000, 'status' => 'reservert']);
    $p = DB::settInn('payments', ['member_id' => $mid, 'booking_id' => $b, 'vipps_reference' => 'AVLNETT-' . $tag . '-' . $i, 'type' => 'epayment',
      'formal' => 'booking', 'belop_ore' => 50000, 'status' => 'betalt', 'idempotency_key' => bin2hex(random_bytes(18))]);
    DB::oppdater('bookings', ['payment_id' => $p, 'status' => 'betalt'], ['id' => $b]);
    $bok[] = $b;
  }
  return ['token' => $t, 'kurs' => $kid, 'm' => $mid, 'b1' => $bok[0], 'b2' => $bok[1], 'ny1' => $ny1];
`);
const rydd = (S) => php(`
  $b = '${S.b1}, ${S.b2}';
  DB::kjor("DELETE FROM notifications WHERE ref_type = 'booking' AND ref_id IN ($b)");
  DB::kjor("DELETE FROM payment_refunds WHERE payment_id IN (SELECT id FROM payments WHERE booking_id IN ($b))");
  DB::kjor("UPDATE bookings SET payment_id = NULL WHERE id IN ($b)");
  DB::kjor("DELETE FROM payments WHERE booking_id IN ($b)");
  DB::kjor("DELETE FROM bookings WHERE course_id = ${S.kurs}");
  DB::kjor("DELETE FROM course_sessions WHERE course_id = ${S.kurs}");
  DB::kjor("DELETE FROM courses WHERE id = ${S.kurs}");
  DB::kjor("DELETE FROM sessions WHERE member_id = ${S.m}");
  DB::kjor("DELETE FROM audit_log WHERE member_id = ${S.m}");
  DB::kjor("DELETE FROM rate_limits WHERE nokkel LIKE 'avbestill%' OR nokkel LIKE 'avlyst-plass%'");
  DB::kjor("DELETE FROM members WHERE id = ${S.m}");
  return 1;
`);

const nettleser = await chromium.launch({ args: [`--host-resolver-rules=MAP ${VERT} 127.0.0.1`] });
const skriptfeil = [];
try {
  for (const [hva, bredde, mobil] of [['mobil 390', 390, true], ['PC 1280', 1280, false]]) {
    console.log(`\n── ${hva} ──`);
    const S = seed();
    console.log('  DEBUG S ' + JSON.stringify(S) + ' okter ' + JSON.stringify(db(`SELECT id, start_tid, status FROM course_sessions WHERE course_id = ${S.kurs}`)) + ' rl ' + JSON.stringify(db("SELECT * FROM rate_limits WHERE nokkel LIKE 'avbestill%' OR nokkel LIKE 'avlyst-plass%'")));
    try {
      const k = await nettleser.newContext({ viewport: { width: bredde, height: 860 }, isMobile: mobil, hasTouch: mobil });
      await k.addCookies([{ name: 'lissom_sesjon', value: S.token, domain: VERT, path: '/', httpOnly: true }]);
      await k.addInitScript(() => { try { localStorage.setItem('lissom-samtykke', 'nei'); } catch (e) {} });
      const p = await k.newPage();
      p.on('pageerror', e => skriptfeil.push(String(e.message).split('\n')[0]));
      await p.route('https://falsk.vipps/**', r => r.fulfill({ status: 200, body: 'falsk vipps' }));
      await p.goto(ADR + '/min-side', { waitUntil: 'load', timeout: 45000 });
      await p.waitForTimeout(4000);
      const kort = p.locator('[data-ms-modul="pameldinger"]');
      const tekst = await kort.innerText().catch(() => '');
      sjekk('kortet viser «Avlyst» og eierens tekst', /avlyst/i.test(tekst)
        && tekst.includes('Lissom har dessverre avlyst denne datoen. Velg en ny dato, eller få pengene tilbake.'), tekst.slice(0, 300));
      sjekk('… «Velg ny dato» og «Få pengene tilbake», ingen «Avbestill»',
        (await kort.getByText('Velg ny dato', { exact: true }).count()) === 2 && (await kort.getByText('Få pengene tilbake', { exact: true }).count()) === 2
        && (await kort.getByText('Avbestill', { exact: true }).count()) === 0);
      sjekk('«Neste kurs» hopper over avlyste datoer (vises ikke)', (await p.locator('.ms-neste:visible, .msny-neste:visible').count()) === 0);

      if (mobil) {
        await kort.getByText('Velg ny dato', { exact: true }).first().tap();
        await p.waitForTimeout(1500);
        const knapper = p.locator('[role="dialog"] button, dialog button');
        const dato = (await knapper.allInnerTexts()).find(t => /\d{2}:\d{2}/.test(t));
        sjekk('dialogen viser datoer å velge', !!dato);
        console.log('  DEBUG knapper ' + JSON.stringify(await knapper.allInnerTexts()));
        await knapper.filter({ hasText: dato }).first().tap();
        await p.waitForTimeout(800);
        await medSvar(p, '/api/avlyst-plass.php', () => p.getByText('Bytt til denne datoen', { exact: true }).tap());
        const b1 = (await vent(() => db(`SELECT course_session_id, belop_ore, status FROM bookings WHERE id = ${S.b1}`), r => Number(r[0]?.course_session_id) === S.ny1))[0];
        sjekk('plassen er flyttet, 500 kr, betalt', Number(b1.course_session_id) === S.ny1 && Number(b1.belop_ore) === 50000 && b1.status === 'betalt', JSON.stringify(b1));
        await p.locator('.ms-neste:visible, .msny-neste:visible').first().waitFor({ state: 'visible', timeout: 20000 }).catch(() => {});
        sjekk('… og nå er den nye datoen «Neste kurs»', (await p.locator('.ms-neste:visible, .msny-neste:visible').count()) >= 1);
        await kort.getByText('Få pengene tilbake', { exact: true }).first().tap();
        await p.waitForTimeout(1200);
        const d2 = await p.locator('body').innerText();
        sjekk('«Du får 500 kr tilbake» og «Ja, gi meg pengene tilbake»', /Du får 500[\s ]kr tilbake/.test(d2) && /ja, gi meg pengene tilbake/i.test(d2));
        await medSvar(p, '/api/avbestill.php', () => p.getByText('Ja, gi meg pengene tilbake', { exact: true }).tap());
        const b2 = (await vent(() => db(`SELECT b.status, p.refundert_ore FROM bookings b JOIN payments p ON p.id = b.payment_id WHERE b.id = ${S.b2}`), r => r[0]?.status === 'refundert' && Number(r[0]?.refundert_ore) === 50000))[0];
        sjekk('refundert 50000 i (falsk) Vipps, plassen refundert', b2.status === 'refundert' && Number(b2.refundert_ore) === 50000, JSON.stringify(b2));
      }
      await k.close();
    } finally { rydd(S); }
  }
  sjekk('ingen skriptfeil i nettleseren', skriptfeil.length === 0, skriptfeil.join(' | '));
} finally {
  await nettleser.close();
  console.log(`\n  ${ok} ok, ${feil.length} feil`);
}
process.exit(feil.length ? 1 : 0);
