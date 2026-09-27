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
// Skjermbildene Gemini ser paa etterpaa — se nederst.
const tilGemini = [];
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

    // ── Karusellene ruller ──────────────────────────────────────────────
    //
    // Eieren, 27. september 2026: «i den sjekken som gjores hver time, saa
    // sjekker dere ogsaa om karusellene fungerer som det skal?» Det gjorde
    // vi ikke. Referansekarusellen paa forsida sto stille i dager 20.
    // september uten at noen vakt sa fra. Her tas et avtrykk av hver
    // karusell, det ventes lenger enn den bruker paa ett bytte, og avtrykket
    // maa ha endret seg. Bare paa PC: musa staar i hjornet, ikke over.
    // Karuseller med bare ett element ruller ikke, og telles ikke.
    if (s.navn === 'PC') {
      const avtrykk = () => side.evaluate(() => {
        const alle = [...document.querySelectorAll('[data-nett-rot], [data-nett-karusell], [data-vakt-karusell]')];
        return alle.map((el, i) => {
          const type = el.getAttribute('data-nett-rot') || (el.hasAttribute('data-nett-karusell') ? 'karusell' : 'galleri');
          const barn = [...el.querySelectorAll('*')];
          const antall = type === 'rot' ? (window.lissomRot || []).length
            : type === 'but' ? el.querySelectorAll(':scope > [data-but]').length
            : type === 'karusell' ? [...el.children].filter(b => b.hasAttribute('data-nett-bilde') || (b.style && b.style.backgroundImage)).length
            : Number(el.getAttribute('data-vakt-karusell')) || el.children.length;
          const synlig = el.getClientRects().length > 0;
          const spor = el.innerText + '|' + barn.map(b => getComputedStyle(b).opacity + (b.hidden ? 'h' : '') + b.style.transform).join(',')
            + '|' + el.style.transform + '|' + el.scrollLeft;
          return { nr: i, type, antall, synlig, spor };
        });
      }).catch(() => []);
      const for_ = await avtrykk();
      const aktuelle = for_.filter(k => k.synlig && k.antall > 1);
      if (aktuelle.length) {
        await side.mouse.move(0, 0);
        // Referansekarusellen bytter hvert 10. sekund, de andre oftere.
        await side.waitForTimeout(12500);
        const etter = await avtrykk();
        for (const k of aktuelle) {
          const e = etter.find(x => x.nr === k.nr);
          sjekk(`${navn}: karusellen «${k.type}» ruller`, !!e && e.spor !== k.spor, 'sto stille i 12 sekunder');
        }
      }
    }

    if (status !== 200 || skriptfeil.length || maal.tekst <= 200 || maal.bredde > maal.skjerm + 1) {
      const fil = `${s.navn}${sti.replace(/\//g, '_') || '_forside'}.png`;
      await side.screenshot({ path: path.join(SKJERMBILDER, fil), fullPage: false }).catch(() => {});
    }
    const bilde = await side.screenshot({ type: 'jpeg', quality: 45 }).catch(() => null);
    if (bilde) tilGemini.push({ navn, bilde });
    await side.close();
  }
  await kontekst.close();
}

// ── Eierens vedtak paa den ekte sida ───────────────────────────────────
//
// Eieren, 27. september 2026: «sjekkene vet hva de skal se etter». Det som
// er godkjent og synlig for kunden, staar i tests/godkjent/vedtak.json under
// «live»: tekst som skal staa (eller ikke staa), og elementer som skal
// finnes. Vakta paa PC-en henter fila fra main sammen med denne.
{
  let reg = null;
  try { reg = JSON.parse(fs.readFileSync(path.join(ROT, 'tests/godkjent/vedtak.json'), 'utf8')); }
  catch { console.log('\n── Vedtak: fant ikke tests/godkjent/vedtak.json — hoppet over'); }
  const liveVedtak = reg ? reg.vedtak.filter(v => (v.live || []).length) : [];
  if (liveVedtak.length) {
    console.log(`\n── Eierens vedtak (${liveVedtak.length}) ──`);
    const kontekst = await nettleser.newContext({ viewport: { width: 1440, height: 900 } });
    for (const v of liveVedtak) {
      for (const l of v.live) {
        const side = await kontekst.newPage();
        let html = '';
        try {
          await side.goto(ADRESSE + l.sti, { waitUntil: 'networkidle', timeout: 45000 });
          await side.waitForTimeout(800);
          html = await side.content();
        } catch (e) { /* html blir tom, og sjekkene under slaar ut */ }
        for (const s of l.finnes || []) sjekk(`Vedtak ${v.id}: ${l.sti} viser «${s}»`, html.includes(s), v.hva);
        for (const s of l.ikkeFinnes || []) sjekk(`Vedtak ${v.id}: ${l.sti} viser ikke «${s}»`, html !== '' && !html.includes(s), v.hva);
        for (const sel of l.selektorer || []) {
          const n = await side.locator(sel).count().catch(() => 0);
          sjekk(`Vedtak ${v.id}: ${l.sti} har ${sel}`, n > 0, v.hva);
        }
        await side.close();
      }
    }
    await kontekst.close();
  }
}
await nettleser.close();

// ── Gemini ser over sidene ─────────────────────────────────────────────
//
// Eieren, 27. september 2026: «husk at den faste timelige sjekken ogsaa skal
// utfores av Gemini, at dere samarbeider». Sjekkene over maaler det som kan
// maales. Gemini ser paa skjermbildene som en besokende: tekst som overlapper,
// bilder som mangler, tomme felt, feilmeldinger, noe som ser oedelagt ut.
// Noekkelen ligger i Dokumenter\Claude-noekler\gemini.txt, eller i
// GEMINI_API_KEY. Uten noekkel hoppes dette over.
const gNokkel = (() => {
  if (process.env.GEMINI_API_KEY) return process.env.GEMINI_API_KEY.trim();
  try {
    const r = fs.readFileSync(path.join(process.env.USERPROFILE || process.env.HOME || '',
      'Documents', 'Claude-nøkler', 'gemini.txt'), 'utf8').trim();
    return r.includes('=') ? r.slice(r.indexOf('=') + 1).trim() : r;
  } catch { return ''; }
})();
if (gNokkel && tilGemini.length && process.env.VAKT_GEMINI !== '0') {
  console.log(`\n── Gemini ser over ${tilGemini.length} skjermbilder ──`);
  const deler = [{ text:
    'Du er testeksperten for lissom.no, et norsk keramikkverksted. Under er skjermbilder av hver side, '
    + 'paa PC og paa mobil, rett etter at de lastet. Se etter det en besokende ville merket som feil: '
    + 'tekst som overlapper eller er kuttet, bilder som mangler eller er odelagte, tomme seksjoner, '
    + 'feilmeldinger, knapper eller menyer som ligger feil, sider som bare viser lasting. '
    + 'Hvert bilde viser bare den forste skjermhoyden, og samtykkebanneret for informasjonskapsler '
    + 'ligger nederst: det som kuttes i nederkant av bildet eller skjules av banneret er IKKE en feil. '
    + 'Ikke meld smak, designforslag eller ting som bare er uvanlige. Svar KUN med JSON: '
    + '{"avvik":[{"side":"<navnet>","hva":"<kort, norsk>"}]} — tom liste naar alt ser riktig ut.' }];
  for (const t of tilGemini) {
    deler.push({ text: `Side: ${t.navn}` });
    deler.push({ inline_data: { mime_type: 'image/jpeg', data: t.bilde.toString('base64') } });
  }
  try {
    const svar = await fetch('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent', {
      method: 'POST',
      headers: { 'x-goog-api-key': gNokkel, 'Content-Type': 'application/json' },
      body: JSON.stringify({ contents: [{ role: 'user', parts: deler }],
        generationConfig: { responseMimeType: 'application/json' } }),
    });
    const j = await svar.json();
    if (!svar.ok) {
      console.log(`  Gemini svarte ikke (status ${svar.status}) — hoppet over`);
    } else {
      const tekst = (j?.candidates?.[0]?.content?.parts || []).map(p => p.text || '').join('');
      const funn = (JSON.parse(tekst || '{}').avvik) || [];
      if (!funn.length) { ok++; console.log('  OK    Gemini fant ingenting som ser galt ut'); }
      for (const f of funn) sjekk(`Gemini: ${f.side}`, false, f.hva);
    }
  } catch (e) {
    // Gemini nede er ikke lissom.no nede. Det skrives ned, men teller ikke.
    console.log('  Gemini kunne ikke spores — hoppet over: ' + String(e.message).split('\n')[0]);
  }
}

console.log(`\n${ok} sjekker i orden, ${avvik.length} avvik.`);
if (avvik.length) {
  console.log('\nAvvik:');
  for (const a of avvik) console.log(`  ✗ ${a}`);
  process.exit(1);
}
