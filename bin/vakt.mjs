/**
 * Vakta paa den ekte nettsida.
 *
 *   node bin/vakt.mjs                   (mot https://lissom.no)
 *   LISSOM_URL=http://... node bin/vakt.mjs
 *
 * ── Hvorfor ───────────────────────────────────────────────────────────
 *
 * Eieren, 27. september 2026: funksjoner paa nettsida, i admin og paa Min
 * side sluttet aa virke etter endringer andre steder, og det ble oppdaget
 * av eieren — ikke av oss. Testene foer publisering fanger det meste, men
 * ikke det som bare skjer paa webhotellet: en fil som ikke ble lastet opp,
 * en migrasjon som ikke er kjort, et skript som feiler i en ekte nettleser.
 *
 * Vakta aapner sidene slik en besokende gjor, paa PC og paa telefon, og
 * feiler naar en side ikke svarer, et skript kaster feil, sida staar tom
 * eller blir bredere enn skjermen. Den kjores etter hver publisering og
 * hver time — se .github/workflows/vakt.yml.
 */

import fs from 'fs';
import path from 'path';

const ROT = path.resolve(import.meta.dirname, '..');
const ADRESSE = (process.env.LISSOM_URL || 'https://lissom.no').replace(/\/+$/, '');
const SKJERMBILDER = process.env.VAKT_BILDER || path.join(ROT, 'vakt-bilder');

const { chromium } = await import('playwright');

const stier = Object.keys(JSON.parse(fs.readFileSync(path.join(ROT, 'seo-kart.json'), 'utf8')).stier);

// De aapne endepunktene sidene henter data fra. Svarer ikke disse, staar
// kurs, butikk og kalender tomme selv om sida i seg selv laster.
const api = ['kurs.php', 'butikk.php', 'apningstider.php', 'innhold.php', 'nyheter.php', 'referanser.php'];

const skjermer = [
  { navn: 'PC', viewport: { width: 1440, height: 900 } },
  { navn: 'mobil', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true },
];

let ok = 0;
const avvik = [];
const sjekk = (hva, stemmer, detalj = '') => {
  if (stemmer) { ok++; console.log(`  OK    ${hva}`); }
  else { avvik.push(`${hva}${detalj ? ' — ' + detalj : ''}`); console.log(`  AVVIK ${hva}${detalj ? ' — ' + detalj : ''}`); }
};

console.log(`\n── API (${ADRESSE}) ──`);
for (const a of api) {
  let status = 0, json = false;
  try {
    const svar = await fetch(`${ADRESSE}/api/${a}`, { headers: { Origin: ADRESSE } });
    status = svar.status;
    json = (svar.headers.get('content-type') || '').includes('json') && !!(await svar.json());
  } catch (e) { /* status blir 0 */ }
  sjekk(`/api/${a} svarer med data`, status === 200 && json, `status ${status}`);
}

fs.mkdirSync(SKJERMBILDER, { recursive: true });
const nettleser = await chromium.launch();

for (const s of skjermer) {
  console.log(`\n── Sidene paa ${s.navn} ──`);
  const kontekst = await nettleser.newContext({ viewport: s.viewport, isMobile: !!s.isMobile, hasTouch: !!s.hasTouch });
  for (const sti of stier) {
    const side = await kontekst.newPage();
    const skriptfeil = [];
    side.on('pageerror', (e) => skriptfeil.push(String(e.message).split('\n')[0]));
    let status = 0;
    try {
      const svar = await side.goto(ADRESSE + sti, { waitUntil: 'networkidle', timeout: 45000 });
      status = svar ? svar.status() : 0;
      await side.waitForTimeout(800);
    } catch (e) {
      skriptfeil.push('lastet ikke: ' + String(e.message).split('\n')[0]);
    }
    const maal = await side.evaluate(() => ({
      tekst: (document.body?.innerText || '').trim().length,
      bredde: document.documentElement.scrollWidth,
      skjerm: window.innerWidth,
    })).catch(() => ({ tekst: 0, bredde: 0, skjerm: 0 }));

    const navn = `${sti} (${s.navn})`;
    sjekk(`${navn} svarer`, status === 200, `status ${status}`);
    sjekk(`${navn} har ingen skriptfeil`, skriptfeil.length === 0, skriptfeil.slice(0, 3).join(' | '));
    sjekk(`${navn} har innhold`, maal.tekst > 200, `${maal.tekst} tegn`);
    sjekk(`${navn} er ikke bredere enn skjermen`, maal.bredde <= maal.skjerm + 1, `${maal.bredde} px paa ${maal.skjerm} px`);

    if (status !== 200 || skriptfeil.length || maal.tekst <= 200 || maal.bredde > maal.skjerm + 1) {
      const fil = `${s.navn}${sti.replace(/\//g, '_') || '_forside'}.png`;
      await side.screenshot({ path: path.join(SKJERMBILDER, fil), fullPage: false }).catch(() => {});
    }
    await side.close();
  }
  await kontekst.close();
}
await nettleser.close();

console.log(`\n${ok} sjekker i orden, ${avvik.length} avvik.`);
if (avvik.length) {
  console.log('\nAvvik:');
  for (const a of avvik) console.log(`  ✗ ${a}`);
  process.exit(1);
}
