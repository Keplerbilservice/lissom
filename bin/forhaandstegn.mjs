/**
 * Tegner toppen av forsida ferdig, én gang, her — ikke i hver nettleser.
 *
 * ── Hvorfor ───────────────────────────────────────────────────────────
 *
 * Nettsida er én fil som dc-runtime bygger om til React naar den er lastet.
 * Fram til det er ferdig staar <x-dc> med «display:none», og den besokende
 * ser ingenting.
 *
 * PageSpeed 28. august, mobil:
 *
 *   Tid til foerste byte              130 ms
 *   Forsinkelse for gjengivelse     2 450 ms   ← her
 *   Storste innholdsrike opptegning  4 800 ms
 *
 * Elementet som maales er <p class="lx-hero-p"> — teksten i toppen. Den
 * ligger i fila fra foerste byte, men som «{{ heroTekst }}», og blir ikke
 * til lesbar tekst for hele oppstarten er ferdig.
 *
 * ── Hvordan ───────────────────────────────────────────────────────────
 *
 * Toppen av forsida — menylinja og heroen, alt som er over skjermkanten —
 * tegnes ferdig her under byggingen og legges ved som forside-topp.html.
 * side.php limer den inn rett etter <body> paa «/». Nettleseren tegner den
 * med det samme, uten aa vente paa noe.
 *
 * Naar dc-runtime er ferdig og den ekte toppen staar i DOM-en, fjernes
 * kopien. Den er tegnet fra den samme malen, med de samme stilene, saa det
 * som staar for og etter er det samme — og bin/toppsjekk.mjs maaler at det
 * faktisk er det: hvert synlige element over folden, med posisjon og
 * storrelse, for og etter byttet.
 *
 * ── Hvorfor ikke bare kopiere det nettleseren tegnet ───────────────────
 *
 * Fordi Chrome ikke skriver stilene tilbake slik de sto. To ting ryker:
 *
 *   «font: var(--type-body)»  blir til 15 tomme deklarasjoner. En kortform
 *   med var() lar seg ikke skrive ut, saa den blir splittet — og «font-
 *   style: ;» er ugyldig og kastes ved innlesing. 33 slike i heroen alene.
 *
 *   «-webkit-mask-image»  faller bort. Hjertet i toppen er et maskert bilde,
 *   og uten prefikset ville eldre Safari vist en firkant.
 *
 * Derfor hentes stilen tilbake fra malen: dc-runtime setter «data-dc-tpl»
 * paa hvert element den tegner, og det tallet peker rett paa noden i malen.
 * Elementene uten «data-dc-tpl» er de komponentene tegner selv — knappene
 * og menylinja — og deres stiler er bygget som vanlige strenger og kommer
 * ut hele.
 *
 * ── Teksten ───────────────────────────────────────────────────────────
 *
 * Eieren redigerer toppteksten under Nettsiden → Innhold, og den ligger i
 * content_blocks. Det som staar her er verdien slik den var da fila ble
 * bygget. Hvert felt merkes med «data-innh» og noekkelen sin, og side.php
 * bytter innholdet mot det som staar i basen naar det finnes. Endrer eieren
 * teksten, endres ogsaa forhaandstegningen — uten ny bygging.
 *
 * ── Tre bredder ───────────────────────────────────────────────────────
 *
 * Menylinja er en React-komponent som tegner ULIKT DOM etter bredden — ikke
 * bare ulik CSS. I ds-bundle.js:
 *
 *     const compact = evw < 1400;
 *     const tight   = evw < 1150;
 *
 * Ett oyeblikksbilde tatt paa mobil ble derfor feil paa skjerm: 46 elementer
 * mot 54, og logoen sto to piksler for hoyt. Derfor tegnes toppen én gang i
 * hver av de tre bredene, og en mediesporring viser den som passer. Den som
 * ikke passer staar med «display:none», den som passer med «display:contents»
 * — den gir ingen egen boks, saa barna ligger noyaktig som om innpakningen
 * ikke fantes.
 *
 * Grensene er de samme tallene som i ds-bundle.js. Komponenten deler paa
 * body sin «zoom», men den er 1 her; blir den noe annet en dag, er det dette
 * som forst gaar i staa, og bin/toppsjekk.mjs sier fra.
 *
 * ── Kjoring ───────────────────────────────────────────────────────────
 *
 *   php -S 127.0.0.1:8124 ekte-ruter.php &
 *   node bin/forhaandstegn.mjs
 *
 * Krever playwright. Gaar noe galt, skrives ingen fil, og side.php sender
 * sida ut uten forhaandstegning — akkurat som for.
 */

import fs from 'fs';
import path from 'path';
import { createRequire } from 'module';

const krev = createRequire(import.meta.url);
const { chromium } = krev('/opt/node22/lib/node_modules/playwright/index.js');

const ROT = path.resolve(import.meta.dirname, '..');
const MAAL = path.join(ROT, 'forside-topp.html');
const ADRESSE = process.env.LISSOM_URL || 'http://127.0.0.1:8124/';

/**
 * Hvilken innholdsnoekkel hvert felt i toppen kommer fra.
 *
 * Leses ut av nettsida selv, fra linjene «heroTittel: this.innh('Forside/0/
 * Overskrift')» i renderVals. Da staar koblingen ett sted, ikke to.
 */
function innholdsnokler(kilde) {
  const kart = {};
  for (const m of kilde.matchAll(/(\w+):\s*this\.innh\('([^']+)'\)/g)) {
    kart[m[1]] = m[2];
  }
  return kart;
}

const kilde = fs.readFileSync(path.join(ROT, 'lissom-2108.html'), 'utf8');
const nokler = innholdsnokler(kilde);

// Samme tall som «compact» og «tight» i ds-bundle.js.
const BREDDER = [
  { navn: 'tight',   bredde: 412,  fra: null, til: 1149 },
  { navn: 'compact', bredde: 1200, fra: 1150, til: 1399 },
  { navn: 'full',    bredde: 1440, fra: 1400, til: null },
];

const b = await chromium.launch();

async function tegn(bredde, nokler) {
const p = await b.newPage({ viewport: { width: bredde, height: 900 } });
await p.goto(ADRESSE, { waitUntil: 'domcontentloaded' });
await p.waitForSelector('[data-screen-label="Forside"] section.lx-hero', { timeout: 20000 });
await p.waitForTimeout(1500);

const svar = await p.evaluate((nokler) => {
  const rot = document.querySelector('[data-screen-label="Forside"]');
  const header = rot.querySelector('header');
  const hero = rot.querySelector('section.lx-hero');
  if (!header || !hero) return { feil: 'fant ikke menylinja eller heroen' };

  // Malen, slik dc-runtime selv leverer den, med «data-dc-tpl» paa hver node.
  const mal = window.__dcAnnotatedTemplate(window.__dcRootName());
  if (!mal) return { feil: 'fikk ikke malen fra dc-runtime' };
  const md = new DOMParser().parseFromString('<div>' + mal + '</div>', 'text/html');

  const advarsler = [];

  /** Navnene i «{{ }}» i denne nodens egne tekstbarn, i rekkefolge. */
  const bindinger = (node) => {
    const ut = [];
    for (const barn of node.childNodes) {
      if (barn.nodeType !== 3) continue;
      for (const m of (barn.nodeValue || '').matchAll(/\{\{([\s\S]+?)\}\}/g)) {
        ut.push(m[1].trim());
      }
    }
    return ut;
  };

  const reparer = (el) => {
    for (const e of [el, ...el.querySelectorAll('*')]) {
      const nr = e.getAttribute && e.getAttribute('data-dc-tpl');
      if (nr == null) continue;
      const fra = md.querySelector('[data-dc-tpl="' + CSS.escape(nr) + '"]');
      e.removeAttribute('data-dc-tpl');
      if (!fra) { advarsler.push('ingen mal-node for ' + nr); continue; }

      // Stilen slik den sto i malen — med var()-kortformer og -webkit-.
      //
      // Bare naar det er det samme elementet. Et <x-import> i malen blir til
      // <div class="sc-host-x" style="display:contents"> paa skjermen, og
      // den stilen er komponentens eget maskineri — skrev vi malens over
      // den, ble wrapperen en vanlig blokk og knappene fikk hver sin linje.
      if (fra.tagName === e.tagName) {
        const stil = fra.getAttribute('style');
        if (stil != null) e.setAttribute('style', stil);
        else e.removeAttribute('style');
      }

      // Feltene eieren kan redigere, merket med noekkelen sin.
      //
      // Vanligvis ligger de rett under noden. Men et <x-import> — knappene i
      // toppen — tegnes av en komponent, og teksten havner et stykke ned:
      // <div class=sc-host-x><button><span class=sc-interp>. Finner vi dem
      // ikke rett under, leter vi nedover, men bare i det som hoerer til
      // denne noden: alt under en ny «data-dc-tpl» er en annen node sitt.
      const navn = bindinger(fra);
      let spenn = [...e.children].filter(c => c.classList.contains('sc-interp'));
      if (spenn.length !== navn.length) {
        spenn = [...e.querySelectorAll('.sc-interp')].filter(
          c => c.closest('[data-dc-tpl]') === null || c.closest('[data-dc-tpl]') === e);
      }
      if (spenn.length !== navn.length) {
        if (spenn.length) advarsler.push('felt uten treff i ' + e.tagName + ' (' + spenn.length + ' mot ' + navn.length + ')');
        continue;
      }
      spenn.forEach((s, i) => {
        const n = nokler[navn[i]];
        if (n) s.setAttribute('data-innh', n);
      });
    }
  };

  const kopi = document.createElement('div');
  kopi.appendChild(header.cloneNode(true));
  kopi.appendChild(hero.cloneNode(true));
  reparer(kopi.firstChild);
  reparer(kopi.lastChild);

  // Det komponentene tegner selv har ingen node i malen aa hente stilen fra,
  // og der finnes den samme feilen: menylinja hadde «gap: 0 var(--space-…)»,
  // som Chrome skrev ut som «row-gap: 0px; column-gap: ;». Da faller
  // avstanden mellom logoen og menyvalgene bort.
  //
  // For dem hentes den utregnede verdien fra elementet slik det staar paa
  // skjermen. Kopien har noyaktig samme rekkefolge som originalen, saa
  // elementene passer én til én.
  const ekte = [header, ...header.querySelectorAll('*'), hero, ...hero.querySelectorAll('*')];
  const klone = [kopi.firstChild, ...kopi.firstChild.querySelectorAll('*'),
                 kopi.lastChild, ...kopi.lastChild.querySelectorAll('*')];
  if (ekte.length !== klone.length) return { feil: 'kopien har ikke samme form som originalen' };
  klone.forEach((k, i) => {
    const stil = k.getAttribute && k.getAttribute('style');
    if (!stil || !/[a-zA-Z-]+:\s*;/.test(stil)) return;
    const beregnet = getComputedStyle(ekte[i]);
    k.setAttribute('style', stil.replace(/([a-zA-Z-]+):\s*;/g, (hel, felt) => {
      const v = beregnet.getPropertyValue(felt);
      if (!v) { advarsler.push('tom stil uten verdi: ' + felt); return ''; }
      return felt + ': ' + v + ';';
    }));
  });

  return { html: kopi.innerHTML, advarsler,
           felt: (kopi.innerHTML.match(/data-innh=/g) || []).length };
}, nokler);

  await p.close();
  return svar;
}

const deler = [];
const regler = [];
for (const v of BREDDER) {
  const svar = await tegn(v.bredde, nokler);
  if (svar.feil) {
    await b.close();
    console.error('Ingen forhaandstegning (' + v.navn + '): ' + svar.feil);
    process.exit(1);
  }
  if (svar.html.includes('</script')) {
    await b.close();
    console.error('Forhaandstegningen inneholder </script — skrives ikke.');
    process.exit(1);
  }
  for (const a of new Set(svar.advarsler)) console.log('  merk (' + v.navn + '): ' + a);
  console.log('  ' + v.navn.padEnd(8) + v.bredde + ' px: '
    + Math.round(Buffer.byteLength(svar.html) / 1024) + ' kB, ' + svar.felt + ' felt');
  deler.push('<div data-topp="' + v.navn + '">\n' + svar.html + '\n</div>');
  const vilkaar = [v.fra ? '(min-width:' + v.fra + 'px)' : '',
                   v.til ? '(max-width:' + v.til + 'px)' : ''].filter(Boolean).join(' and ');
  regler.push('@media ' + vilkaar + '{#lissom-topp>[data-topp="' + v.navn + '"]{display:contents}}');
}
await b.close();

// Kopien ligger over sida til den ekte toppen er der. «contain» stopper den
// fra aa gi sida hoyde, saa ingenting hopper naar den fjernes.
//
// Innpakningene har «display:none» som utgangspunkt, og den som passer
// bredden faar «display:contents» — ingen egen boks, saa barna ligger
// noyaktig som om innpakningen ikke fantes.
const ut = '<style>#lissom-topp>[data-topp]{display:none}'
  + regler.join('') + '</style>\n'
  + '<div id="lissom-topp" aria-hidden="true" style="position:absolute;'
  + 'top:0;left:0;width:100%;contain:layout;">\n' + deler.join('\n') + '\n</div>\n';

fs.writeFileSync(MAAL, ut);
console.log('forside-topp.html skrevet: ' + Math.round(Buffer.byteLength(ut) / 1024) + ' kB.');
