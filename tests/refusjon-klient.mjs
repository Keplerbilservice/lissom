import fs from 'node:fs';
import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';

const html = fs.readFileSync(new URL('../lissom-2108.html', import.meta.url), 'utf8');
const start = html.indexOf('  refunderKall(referanse, kroner) {');
const end = html.indexOf('\n  }', start) + '\n  }'.length;
assert(start >= 0 && end > start);
const method = eval('({' + html.slice(start, end) + '})').refunderKall;
const idStart = html.indexOf('  nyRefusjonsId() {');
const idEnd = html.indexOf('\n  }', idStart) + '\n  }'.length;
const nyRefusjonsId = eval('({' + html.slice(idStart, idEnd) + '})').nyRefusjonsId;
const stored = new Map();
globalThis.sessionStorage = { getItem: k => stored.get(k), setItem: (k,v) => stored.set(k,v), removeItem: k => stored.delete(k) };
const calls = [];
let lost = true;
globalThis.fetch = async (_url, opts) => {
  calls.push(JSON.parse(opts.body));
  if (lost) { lost = false; throw new Error('Svar tapt etter server-commit'); }
  return { ok: true, json: async () => ({ refundert: 'kr. 20,-', gjenstaarOre: 8000, gjenstaar: 'kr. 80,-' }) };
};
const app = () => ({ erPublisert: () => true, setState: () => {}, hentAdmin: () => {}, refunderKall: method, nyRefusjonsId });
assert.equal(await app().refunderKall('ref-1', 20), false);
assert.equal(await app().refunderKall('ref-1', 20), true);
assert.equal(calls[0].operasjonId, calls[1].operasjonId, 'reload/retry skal beholde samme ID');
assert.equal(await app().refunderKall('ref-1', 20), true);
assert.notEqual(calls[1].operasjonId, calls[2].operasjonId, 'ny bekreftet intensjon skal ha ny ID');
console.log('OK: klient beholder operasjonId etter tapt svar, og lager ny etter bekreftet resultat.');

const rcStart = html.indexOf('  betalTilbakeTrekk(trekkId, igjenOre, igjenTekst, dato, navn) {');
const rcEnd = html.indexOf('\n  }', rcStart) + '\n  }'.length;
const recurring = eval('({' + html.slice(rcStart, rcEnd) + '})').betalTilbakeTrekk;
const rcCalls = [];
let rcLost = true;
const rcApp = () => ({ state: { personMedlemId: 123 },
  nyRefusjonsId,
  setState(s) { Object.assign(this.state,s); }, apnePerson() {},
  medlemKall(d) { rcCalls.push(d); const ok = !rcLost; rcLost = false; return Promise.resolve(ok); },
  betalTilbakeTrekk: recurring });
async function tilbake() {
  const a = rcApp(); a.betalTilbakeTrekk(42, 2000, '20 kr', 'dato', 'Test');
  a.state.detalj.gjor(); await new Promise(resolve => setImmediate(resolve));
}
await tilbake(); await tilbake();
assert.equal(rcCalls[0].operasjonId,rcCalls[1].operasjonId);
await tilbake(); assert.notEqual(rcCalls[1].operasjonId,rcCalls[2].operasjonId);
console.log('OK: avtaletrekk beholder operasjonId etter manglende svar og bytter etter bekreftet resultat.');

Object.defineProperty(globalThis, 'crypto', { configurable: true,
  value: { getRandomValues: a => webcrypto.getRandomValues(a) } });
assert.match(nyRefusjonsId(), /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
lost = true;
const fallbackStart = calls.length;
await app().refunderKall('fallback', 20); await app().refunderKall('fallback', 20);
assert.equal(calls[fallbackStart].operasjonId, calls[fallbackStart+1].operasjonId);
console.log('OK: uten randomUUID brukes getRandomValues UUIDv4 og ID bevares ved reload.');
globalThis.sessionStorage = { getItem() { throw new Error('Storage unavailable'); } };
lost = true;
const samePage = app();
const storageStart = calls.length;
await samePage.refunderKall('storage',20); await samePage.refunderKall('storage',20);
assert.equal(calls[storageStart].operasjonId,calls[storageStart+1].operasjonId);
console.log('OK: uten storage og randomUUID beholder samme side operasjonId etter tapt svar.');
