/**
 * /booking?plan=<navn> åpnet direkte — fra en annonse, en delt lenke eller
 * en ny innlasting.
 *
 *   bash tests/nettleser/kjor.sh booking-plan-lenke.mjs
 *   (eller: E2E_ADRESSE=http://lokal.lissom.no:<port> node tests/nettleser/booking-plan-lenke.mjs)
 *
 * Eieren, 2. oktober 2026: /booking?plan=Prøv%20Lissom og ?plan=Årsmedlemskap
 * viste «Nybegynner dreiekurs» og «så betaler du se pris» når lenken ble
 * åpnet direkte. Inne på siden ble det riktig.
 *
 * Krever en lokal server (php -S med side.php som ruter) og en testbase med
 * planene i membership_plans. Vertsnavnet må slutte på lissom.no, ellers
 * henter appen ingenting (erPublisert()) — derfor --host-resolver-rules.
 *
 * Sjekker for hver aktive plan, på 390 og 1280 px:
 *   - direkte lenke åpner planens kort på bookingskjermen (navn og pris)
 *   - ny innlasting gjør det samme
 *   - aldri «Nybegynner dreiekurs» eller «se pris»
 * og at en ukjent plan gir lista over medlemskapene, ikke et kurs. Ingen
 * knapper trykkes: testen stopper før Vipps.
 */
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const { chromium } = createRequire(import.meta.url)('playwright');
const BASE = (process.env.E2E_ADRESSE || 'http://lokal.lissom.no:8140').replace(/\/$/, '');
const vert = new URL(BASE).hostname;

const nettleser = await chromium.launch({ args: [`--host-resolver-rules=MAP ${vert} 127.0.0.1`] });
let ok = 0;
const sjekk = (navn, fn) => { fn(); ok++; console.log('  OK    ' + navn); };

const tittel = (p) => p.locator('[data-screen-label="Booking"] h1').first();

async function sjekkKort(p, plan, hva, bredde) {
  await tittel(p).waitFor({ timeout: 30000 });
  await p.waitForFunction((n) => {
    const h = document.querySelector('[data-screen-label="Booking"] h1');
    return h && h.textContent.trim() === n;
  }, plan.navn, { timeout: 30000 });
  const tekst = await p.locator('[data-screen-label="Booking"]').innerText();
  const h1 = (await tittel(p).innerText()).trim();
  sjekk(`${bredde} px ${hva} «${plan.navn}»: tittel er planen`, () => assert.equal(h1, plan.navn));
  sjekk(`${bredde} px ${hva} «${plan.navn}»: pris ${plan.pris} står`, () => assert.ok(tekst.includes(plan.pris), 'mangler pris'));
  sjekk(`${bredde} px ${hva} «${plan.navn}»: ikke «se pris»`, () => assert.ok(!tekst.includes('se pris')));
  sjekk(`${bredde} px ${hva} «${plan.navn}»: ikke «Nybegynner dreiekurs»`, () => assert.ok(!tekst.includes('Nybegynner dreiekurs')));
  sjekk(`${bredde} px ${hva} «${plan.navn}»: adressen er /booking`, () => assert.equal(new URL(p.url()).pathname, '/booking'));
}

try {
  for (const bredde of [390, 1280]) {
    const kontekst = await nettleser.newContext({
      viewport: { width: bredde, height: 900 },
      ...(bredde === 390 ? { isMobile: true, hasTouch: true } : {}),
    });
    const p = await kontekst.newPage();
    const feil = [];
    p.on('pageerror', (e) => feil.push(e.message));

    // Gjennom nettleseren: vertsnavnet finnes bare der (host-resolver-rules).
    const svar = await p.goto(BASE + '/api/medlemskap.php');
    const planer = (await svar.json()).planer || [];
    assert.ok(planer.length >= 2, 'testbasen har for få planer');

    for (const plan of planer) {
      // Slik annonsen skriver den: %20 for mellomrom, æøå prosentkodet.
      await p.goto(BASE + '/booking?plan=' + encodeURIComponent(plan.navn));
      await sjekkKort(p, plan, 'direkte lenke', bredde);
      await p.reload();
      await sjekkKort(p, plan, 'ny innlasting', bredde);
    }

    // «+» for mellomrom og andre store/små bokstaver skal også treffe.
    const medMellomrom = planer.find((x) => x.navn.includes(' ')) || planer[0];
    await p.goto(BASE + '/booking?plan=' + encodeURIComponent(medMellomrom.navn.toUpperCase()).replace(/%20/g, '+'));
    await sjekkKort(p, medMellomrom, 'pluss og store bokstaver', bredde);

    // Ukjent plan: lista over medlemskapene, aldri et kurs.
    await p.goto(BASE + '/booking?plan=' + encodeURIComponent('Finnes ikke'));
    await p.locator('[data-screen-label="Medlemskap"]').first().waitFor({ timeout: 30000 });
    sjekk(`${bredde} px ukjent plan: medlemskapslista vises`, () => assert.ok(true));
    sjekk(`${bredde} px ukjent plan: adressen er /medlemskap`, () => assert.equal(new URL(p.url()).pathname + new URL(p.url()).search, '/medlemskap'));
    const booking = await p.locator('[data-screen-label="Booking"]').count();
    sjekk(`${bredde} px ukjent plan: ingen bookingskjerm`, () => assert.equal(booking, 0));

    sjekk(`${bredde} px: ingen skriptfeil`, () => assert.deepEqual(feil, []));
    await kontekst.close();
  }
} finally {
  await nettleser.close();
}
console.log(`\n${ok} sjekker bestått`);
