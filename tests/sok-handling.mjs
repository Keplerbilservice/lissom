import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
// Handlinger i toppsøket i ny admin (eieren 02.11.2026, «ja, det ser bra ut» på visningen).
// Henter HANDLING og handlingTreff ordrett fra ny-admin/felles.js og prøver matchingen. Ingen server, ingen sending.
//   node tests/sok-handling.mjs
const kode = readFileSync(new URL('../ny-admin/felles.js', import.meta.url), 'utf8');
const m = kode.match(/\/\* sok-handling:start \*\/([\s\S]*?)\/\* sok-handling:slutt \*\//);
assert.ok(m, 'fant ikke sok-handling-blokken i felles.js');
const handlingTreff = new Function(m[1] + '\nreturn handlingTreff;')();
const navn = q => handlingTreff(q).map(a => a.navn);
let ok = 0;
const sjekk = (tekst, fn) => { fn(); ok++; console.log('  OK    ' + tekst); };

sjekk('Monicas setning gir Lag nytt kurs og Nytt kurs eller serie', () =>
  assert.deepEqual(navn('hvordan setter jeg opp et helt nytt kurs fra bunnen av'), ['＋ Lag nytt kurs', '＋ Nytt kurs eller serie']));
sjekk('«fra bunnen» alene gir Lag nytt kurs', () => assert.deepEqual(navn('starte fra bunnen'), ['＋ Lag nytt kurs']));
sjekk('«sette opp kurs» gir bare Nytt kurs eller serie', () => assert.deepEqual(navn('sette opp kurs'), ['＋ Nytt kurs eller serie']));
sjekk('«ta betalt» gir Ta betalt', () => assert.deepEqual(navn('ta betalt'), ['Ta betalt']));
sjekk('«Vipps» (store bokstaver) gir Ta betalt', () => assert.deepEqual(navn('Vipps'), ['Ta betalt']));
sjekk('«leire» gir Uttak leire', () => assert.deepEqual(navn('leire'), ['Uttak leire']));
sjekk('«tegne» gir Skisseverktøy', () => assert.deepEqual(navn('tegne'), ['Skisseverktøy']));
sjekk('«forespørsel» gir Forespørsler', () => assert.deepEqual(navn('forespørsel'), ['Forespørsler']));
sjekk('kursnavn som «Kveldskurs» gir ingen handling', () => assert.deepEqual(navn('Kveldskurs'), []));
sjekk('«kurs» alene gir ingen handling', () => assert.deepEqual(navn('kurs'), []));
sjekk('ordet må begynne med nøkkelordet («omtegn» gir ikke Skisseverktøy)', () => assert.deepEqual(navn('omtegn'), []));
sjekk('undertekstene står ordrett', () => {
  for (const t of ['Et helt nytt kurs som ikke finnes fra før', 'Nye datoer for et kurs som finnes', 'Åpner kassa',
    'Ta ut leire fra internbutikken', 'Tegn en skisse', 'Alle forespørsler, nyeste øverst']) assert.ok(kode.includes(`'${t}'`), t);
});
sjekk('søkefeltet sier «… vare eller handling», og treffene telles', () => {
  assert.ok(kode.includes('aria-label="Søk etter kurs, deltaker, medlem, vare eller handling"'));
  assert.ok(kode.includes("tell('ny_sok_handling_' + id)"));
});
console.log(`${ok} av 13 sok-handling-kontroller bestått`);
