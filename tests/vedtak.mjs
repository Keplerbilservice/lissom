/**
 * Eierens vedtak — at det som er godkjent, fortsatt står der.
 *
 *   node tests/vedtak.mjs
 *
 * Eieren, 27. september 2026: «nå er det eposter og alle endringer vi gjør
 * lagres, så det plutselig ikke endrer seg, og sjekkene vet hva de skal se
 * etter, slik at de fanger opp evnt slikt».
 *
 * Registeret står i tests/godkjent/vedtak.json. Her kjøres «kode»-delen:
 * tekstbiter som må finnes, eller ikke finnes, i navngitte filer. «live»-
 * delen kjøres av bin/vakt.mjs mot lissom.no.
 *
 * I tillegg: en ny migrasjon som skriver over tekstene i e-postmalene eller
 * innholdsblokkene, må si at eieren har godkjent det. Ellers kunne en
 * oppdatering overskrevet det eieren selv har skrevet i Tekst maler.
 */

import fs from 'fs';
import path from 'path';

const ROT = path.resolve(import.meta.dirname, '..');
const reg = JSON.parse(fs.readFileSync(path.join(ROT, 'tests/godkjent/vedtak.json'), 'utf8'));

let ok = 0;
const brudd = [];
const lest = {};
const les = (fil) => {
  if (!(fil in lest)) {
    try { lest[fil] = fs.readFileSync(path.join(ROT, fil), 'utf8'); } catch { lest[fil] = null; }
  }
  return lest[fil];
};

for (const v of reg.vedtak) {
  const navn = `Vedtak ${v.id} (${v.dato}): ${v.hva}`;
  for (const k of v.kode || []) {
    const innhold = les(k.fil);
    if (innhold === null) { brudd.push(`${navn} — fila ${k.fil} finnes ikke`); continue; }
    for (const s of k.finnes || []) {
      if (innhold.includes(s)) ok++;
      else brudd.push(`${navn} — brutt i ${k.fil}: mangler «${s}»`);
    }
    for (const s of k.ikkeFinnes || []) {
      if (!innhold.includes(s)) ok++;
      else brudd.push(`${navn} — brutt i ${k.fil}: «${s}» står der igjen`);
    }
  }
}

// ── Vern av eierens tekster ─────────────────────────────────────────────
//
// Migrasjonene til og med 226 fantes da regelen kom. Nyere migrasjoner som
// endrer tekst i notification_templates eller content_blocks, må ha
// «-- godkjent av eieren: <dato>» i fila.
const SISTE_FOR_REGELEN = 226;
const mappe = path.join(ROT, 'db/migrations');
for (const fil of fs.readdirSync(mappe).filter(f => f.endsWith('.sql')).sort()) {
  const nr = parseInt(fil, 10);
  if (!(nr > SISTE_FOR_REGELEN)) continue;
  const sql = fs.readFileSync(path.join(mappe, fil), 'utf8');
  const utenKommentar = sql.replace(/--.*$/gm, '');
  const rorerTekst =
    /UPDATE\s+notification_templates\s+SET[\s\S]*?\b(tekst|emne)\s*=/i.test(utenKommentar)
    || /(UPDATE|INSERT\s+INTO|REPLACE\s+INTO)\s+content_blocks\b/i.test(utenKommentar);
  if (!rorerTekst) { ok++; continue; }
  if (/--\s*godkjent av eieren:\s*\S+/i.test(sql)) ok++;
  else brudd.push(`Migrasjon ${fil} endrer tekster i e-postmalene eller innholdet uten «-- godkjent av eieren: <dato>»`);
}

console.log(`${reg.vedtak.length} vedtak, ${ok} sjekker i orden, ${brudd.length} brudd.`);
if (brudd.length) {
  for (const b of brudd) console.log('  ✗ ' + b);
  process.exit(1);
}
