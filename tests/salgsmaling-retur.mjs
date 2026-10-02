// Salgsmåling når kunden kommer tilbake fra Vipps til forsida (nett.js).
// Forsida ble serverside 21. september 2026, og da talte ingen kjøpet i
// nettleseren: «/#betaling=ok&kjop=…» ble aldri lest. Gjennomgang og GO
// fra eieren 2. oktober 2026. Kjør: node tests/salgsmaling-retur.mjs
import fs from 'node:fs';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const rot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const kode = fs.readFileSync(path.join(rot, 'nett.js'), 'utf8');

function lager(start = {}) {
  const m = new Map(Object.entries(start));
  return {
    getItem: (k) => (m.has(k) ? m.get(k) : null),
    setItem: (k, v) => m.set(k, String(v)),
    removeItem: (k) => m.delete(k),
    _m: m,
  };
}

function kjor({ hash, samtykke = 'ja', kjoper = null, talt = '' }) {
  const element = () => null;
  const document = {
    referrer: 'https://api.vipps.no/',
    head: { appendChild() {} },
    body: { appendChild() {} },
    querySelector: element,
    querySelectorAll: () => [],
    getElementById: element,
    createElement: () => ({ setAttribute() {}, style: {}, appendChild() {} }),
    addEventListener() {},
    documentElement: { style: {}, classList: { add() {}, remove() {} } },
  };
  const location = { hash, pathname: '/', search: '', href: 'https://lissom.no/' + hash };
  const byttet = [];
  const fbq = [];
  const window = {
    document, location,
    history: { replaceState: (a, b, url) => { byttet.push(url); location.hash = ''; } },
    localStorage: lager({ 'lissom-analyse': samtykke, ...(talt ? { 'lissom-kjop': talt } : {}) }),
    sessionStorage: lager(kjoper ? { lissom_kjoper: JSON.stringify(kjoper) } : {}),
    lissomMaal: { ga: 'G-GMJSTL5KP2', meta: '1094401586278954' },
    addEventListener() {},
    navigator: {},
    matchMedia: () => ({ matches: false, addEventListener() {} }),
    setTimeout: () => 0,
    setInterval: () => 0,
    clearInterval() {},
    clearTimeout() {},
    requestIdleCallback: () => 0,
  };
  // Meta-pikselen «finnes» alt, så kallene kan leses.
  window.fbq = (...a) => fbq.push(a);
  window.window = window;
  const ctx = vm.createContext({ ...window, window, setInterval: () => 0, setTimeout: () => 0, clearTimeout() {}, clearInterval() {}, console, JSON, Math, Date, Number, String, RegExp, Object, Array, encodeURIComponent });
  try { vm.runInContext(kode, ctx, { filename: 'nett.js' }); } catch (e) { console.log("nett.js stoppet:", e.message); }
  const dl = (ctx.window.dataLayer || []).map((a) => Array.from(a));
  return { dl, fbq, byttet, ls: window.localStorage, ss: window.sessionStorage };
}

let feil = 0;
const sjekk = (navn, ok) => { console.log((ok ? 'OK   ' : 'FEIL ') + navn); if (!ok) feil++; };

// 1. Kjøp av kurs, med samtykke og kjøper lagt igjen før Vipps.
const a = kjor({ hash: '#betaling=ok&kjop=123&belop=500.00&slag=booking', kjoper: { epost: 'Ola@Eksempel.no', telefon: '912 34 567' } });
const kjop = a.dl.find((x) => x[0] === 'event' && x[1] === 'purchase');
sjekk('purchase sendes til GA4 ved retur fra Vipps', !!kjop);
sjekk('transaction_id er L123 og beløpet 500', kjop && kjop[2].transaction_id === 'L123' && kjop[2].value === 500 && kjop[2].currency === 'NOK');
sjekk('booking_fullfort sendes ved siden av', a.dl.some((x) => x[0] === 'event' && x[1] === 'booking_fullfort' && x[2].transaction_id === 'L123'));
const ud = a.dl.find((x) => x[0] === 'set' && x[1] === 'user_data');
sjekk('user_data med e-post (små bokstaver) og telefon +47', ud && ud[2].email === 'ola@eksempel.no' && ud[2].phone_number === '+4791234567');
const fbKjop = a.fbq.find((x) => x[0] === 'track' && x[1] === 'Purchase');
sjekk('Meta Purchase med eventID L123 (slås sammen med serveren)', fbKjop && fbKjop[3] && fbKjop[3].eventID === 'L123' && fbKjop[2].value === 500);
sjekk('Kjøpet merkes som talt (lissom-kjop)', (a.ls.getItem('lissom-kjop') || '').split(',').includes('123'));
sjekk('Kjøperen fjernes fra sessionStorage', a.ss.getItem('lissom_kjoper') === null);
sjekk('Beløpet strykes fra adressen', a.byttet.length === 1 && a.byttet[0] === '/');

// 2. Samme kjøp en gang til (tilbakeknapp/ny fane): telles ikke igjen.
const b = kjor({ hash: '#betaling=ok&kjop=123&belop=500.00&slag=booking', talt: '123' });
sjekk('Allerede talt kjøp sendes ikke på nytt', !b.dl.some((x) => x[1] === 'purchase') && !b.fbq.some((x) => x[1] === 'Purchase'));

// 3. Uten samtykke: ingenting sendes.
const c = kjor({ hash: '#betaling=ok&kjop=124&belop=300.00&slag=gavekort', samtykke: 'nei' });
sjekk('Uten samtykke sendes ingen kjøp', !c.dl.some((x) => x[1] === 'purchase') && !c.fbq.some((x) => x[1] === 'Purchase'));

// 4. Avbrutt betaling: ikke et kjøp.
const e = kjor({ hash: '#betaling=avbrutt' });
sjekk('Avbrutt betaling telles ikke', !e.dl.some((x) => x[1] === 'purchase'));

// 5. Gavekort får eget Ads-navn.
const f = kjor({ hash: '#betaling=ok&kjop=125&belop=1000.00&slag=gavekort' });
sjekk('Gavekort sender gavekort_kjopt', f.dl.some((x) => x[0] === 'event' && x[1] === 'gavekort_kjopt'));

console.log(feil ? `\n${feil} feil` : '\nAlle sjekker grønne');
process.exit(feil ? 1 : 0);
