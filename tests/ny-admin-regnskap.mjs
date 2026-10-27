import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

const root = new URL('../', import.meta.url);
const read = path => readFileSync(new URL(path, root), 'utf8');
const shell = read('ny-admin/felles.js');
const identity = read('api/admin/arbeidsrom.php');

assert.match(identity, /krev_regnskap\(\)/);
assert.match(identity, /'erRegnskap'\s*=>\s*Sesjon::erRegnskap\(\)/);
assert.match(shell, /if \(!r\.erAdmin && !r\.erRegnskap\)/);
assert.match(shell, /if \(!meg\.erAdmin && !meg\.erRegnskap\)/);
assert.match(shell, /NA\.meg\?\.regnskap && !\['regnskap', 'betalinger', 'timeliste'\]\.includes\(ny\.id\)/);
assert.match(shell, /if \(!NA\.meg\.regnskap\) \{\s*toppPoll/);

const pages = new Map();
const calls = [];
const api = async path => {
  calls.push(path);
  if (path === 'okonomi.php') return {maaned: 'oktober', omsetningEks: '100 kr', mva: '25 kr', omsetning: '125 kr'};
  if (path.startsWith('dagsoppgjor.php?')) return {bilag: [{dato: '2026-10-27', sumOre: 12500, linjer: [], inn: [], balanse: true}], oppsett: {}, manglerKonto: []};
  if (path === 'betalinger.php') return {betalinger: [{medlem: 'Test', formal: 'Kurs', tidspunkt: '27. oktober', maate: 'Vipps', status: 'betalt', belop: '125 kr'}]};
  if (path.startsWith('timeliste.php?')) return {summer: [{navn: 'Test', timer: 2, sum: '500 kr', ubekreftet: 0}], rader: []};
  throw Error(`Uventet API: ${path}`);
};
const NA = {api, esc: v => String(v ?? ''), kr: v => `${v / 100} kr`, registrerSide: (id, def) => pages.set(id, def)};
runInNewContext(read('ny-admin/regnskap.js'), {window: {NA}, Date, URLSearchParams, encodeURIComponent});
assert.deepEqual([...pages.keys()], ['regnskap', 'betalinger', 'timeliste']);

const element = () => ({innerHTML: '', querySelector: () => ({})});
const accounting = element();
await pages.get('regnskap').tegn(accounting);
assert.match(accounting.innerHTML, /Regnskap og dagsoppgjør/);
assert.match(accounting.innerHTML, /2026-10-27/);
assert.match(accounting.innerHTML, /Last ned dagsoppgjør/);
assert.ok(calls.some(x => x.startsWith('dagsoppgjor.php?')));

const payments = element();
await pages.get('betalinger').tegn(payments);
assert.match(payments.innerHTML, /Registrerte innbetalinger/);
assert.equal(calls.at(-1), 'betalinger.php');

const hours = element();
await pages.get('timeliste').tegn(hours);
assert.match(hours.innerHTML, /Last ned timeliste/);
assert.ok(calls.at(-1).startsWith('timeliste.php?periode='));

for (const endpoint of ['betalinger', 'timeliste', 'manedsrapport', 'okonomi', 'dagsoppgjor', 'transaksjoner']) {
  assert.match(read(`api/admin/${endpoint}.php`), /krev_regnskap\(\)/);
}
console.log('Regnskapstilgang i ny admin: OK');
