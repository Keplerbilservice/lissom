/**
 * Lager seo-kart.json av nettsida.
 *
 * Tittel, beskrivelse, canonical og delingsbilde settes av JavaScript etter
 * at sida er lastet. Det virker for et menneske, men ikke for det som ikke
 * kjorer skript: Bing, AI-assistentene, og forhaandsvisningen naar noen deler
 * en lenke paa Instagram eller Facebook. De fikk forsidas tittel paa alle
 * adresser, uten canonical og uten strukturerte data.
 *
 * side.php setter dem i stedet inn i selve svaret, for det gaar ut. Men da
 * maa serveren vite hva som staar hvor — og det maa vaere det SAMME som
 * nettsida sier, ellers har vi to lister som blir uenige den dagen én av dem
 * endres.
 *
 * Derfor leses de rett ut av lissom-2108.html:
 *
 *   STIER          adressene og hvilken skjerm de peker paa
 *   SEO_FERDIG     tittel, meta, canonical og delingstekst per side
 *   seoIdForSide   hvilken SEO-oppforing en skjerm hoerer til
 *
 * Kjor denne naar noe av det er endret. bin/seosjekk.mjs sier fra hvis
 * kartet og nettsida har kommet i utakt.
 */

import fs from 'fs';
import path from 'path';

const ROT = path.resolve(import.meta.dirname, '..');
const kilde = fs.readFileSync(path.join(ROT, 'lissom-2108.html'), 'utf8');

/** Klipper ut et balansert {...} eller [...] som starter etter «etter». */
function balansert(tekst, fra) {
  let d = 0;
  for (let i = fra; i < tekst.length; i++) {
    const c = tekst[i];
    if (c === '{' || c === '[') d++;
    else if (c === '}' || c === ']') { d--; if (d === 0) return tekst.slice(fra, i + 1); }
  }
  throw new Error('Fant ikke slutten paa blokka fra ' + fra);
}

function statiskGetter(navn) {
  const i = kilde.indexOf('static get ' + navn + '()');
  if (i < 0) throw new Error('Fant ikke «static get ' + navn + '()» i lissom-2108.html');
  const r = kilde.indexOf('return', i);
  const start = kilde.slice(r).search(/[[{]/) + r;
  return eval('(' + balansert(kilde, start) + ')');
}

/** Kartet inne i seoIdForSide(): skjerm → SEO-oppforing. */
function sideTilSeo() {
  const i = kilde.indexOf('seoIdForSide(side) {');
  if (i < 0) throw new Error('Fant ikke seoIdForSide() i lissom-2108.html');
  const slutt = kilde.indexOf('}[side] || null;', i);
  if (slutt < 0) throw new Error('seoIdForSide() ser ikke ut som for — sjekk den for hand');
  const start = kilde.lastIndexOf('return {', slutt);
  return eval('(' + balansert(kilde, kilde.indexOf('{', start)) + ')');
}

const STIER = statiskGetter('STIER');
const SEO   = statiskGetter('SEO_FERDIG');
const KART  = sideTilSeo();

// Adresse → SEO-oppforing.
//
// Unntaket staar i seoIdForSide() og gjentas her, for det henger paa
// adressen og ikke paa skjermen: «/events» og «/kurs» er samme skjerm med
// hvert sitt filter.
const stier = {};
for (const r of STIER) {
  if (r.sti.includes(':')) continue;              // ruter med parameter tas av kurs-oppslaget
  let id = KART[r.side] || null;
  if (r.sti === '/events')  { id = 'events'; }
  if (r.side === 'booking') { id = null; }
  if (id && SEO[id]) { stier[r.sti] = id; }
}

const ut = {
  laget: new Date().toISOString().slice(0, 10),
  om: 'Laget av bin/seokart.mjs. Rediger lissom-2108.html, ikke denne.',
  stier,
  sider: SEO,
};

const maal = path.join(ROT, 'seo-kart.json');
fs.writeFileSync(maal, JSON.stringify(ut, null, 2) + '\n');
console.log(Object.keys(stier).length + ' adresser og ' + Object.keys(SEO).length
  + ' sider skrevet til seo-kart.json');
