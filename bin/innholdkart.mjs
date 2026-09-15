/**
 * Standardtekstene fra innholdsredigeringen, som JSON for serveren.
 *
 * Nettsida har SIDEINNHOLD i lissom-2108.html: hvert felt eieren kan
 * redigere under Nettsiden → Innhold, med teksten som gjelder naar han ikke
 * har endret noe. innh() i nettsida slaar opp «Side/N/Felt» — foerst i
 * content_blocks, saa her.
 *
 * Serversidene (app/nett/) tegner de samme tekstene, og skal slaa opp paa
 * samme maate. Da maa de ha samme standardverdier. Denne fila leser dem ut av
 * nettsida — som bin/seokart.mjs gjoer med adressene — og skriver
 * innhold-standard.json. Kjoeres naar SIDEINNHOLD er endret:
 *
 *   node bin/innholdkart.mjs
 *
 * Fila er generert. Endre teksten i lissom-2108.html og kjoer paa nytt.
 */
import fs from 'fs';
import path from 'path';

const ROT = path.resolve(import.meta.dirname, '..');
const kilde = fs.readFileSync(path.join(ROT, 'lissom-2108.html'), 'utf8');

function balansert(tekst, fra) {
  let d = 0, iStreng = null;
  for (let i = fra; i < tekst.length; i++) {
    const c = tekst[i];
    if (iStreng) {
      if (c === '\\') { i++; continue; }
      if (c === iStreng) iStreng = null;
      continue;
    }
    if (c === "'" || c === '"' || c === '`') { iStreng = c; continue; }
    if (c === '{' || c === '[') d++;
    else if (c === '}' || c === ']') { d--; if (d === 0) return tekst.slice(fra, i + 1); }
  }
  throw new Error('Fant ikke slutten paa blokka fra ' + fra);
}

function konst(navn) {
  const i = kilde.indexOf('const ' + navn + ' = ');
  if (i < 0) throw new Error('Fant ikke «const ' + navn + '» i lissom-2108.html');
  const start = kilde.slice(i).search(/[[{]/) + i;
  return eval('(' + balansert(kilde, start) + ')');
}

const SIDEINNHOLD = konst('SIDEINNHOLD');
const ut = {};
let felter = 0;
Object.keys(SIDEINNHOLD).forEach(side => {
  SIDEINNHOLD[side].forEach((blokk, i) => {
    (blokk.felter || []).forEach(f => {
      ut[side + '/' + i + '/' + f.l] = f.v;
      felter++;
    });
  });
});

fs.writeFileSync(path.join(ROT, 'innhold-standard.json'), JSON.stringify(ut, null, 1) + '\n');
console.log(felter + ' felter skrevet til innhold-standard.json');

// Salgsvilkaarene: «const RAA = [ … ];» i renderVals (skjermen «Vilkår»).
// Serversida /vilkar tegner dem fra vilkar.json — samme tekst, ett sted.
const iRaa = kilde.indexOf('const RAA = [');
if (iRaa < 0) throw new Error('Fant ikke «const RAA = [» i lissom-2108.html');
const RAA = eval('(' + balansert(kilde, kilde.indexOf('[', iRaa)) + ')');
const avsn = x => typeof x === 'string'
  ? { harH: false, h: '', t: x, harPunkter: false, punkter: [] }
  : { harH: !!x.h, h: x.h || '', t: x.t || '', harPunkter: !!(x.punkter || []).length, punkter: (x.punkter || []).map(t => ({ t })) };
const vilkar = RAA.map((rad, n) => ({ nr: String(n + 1) + ')', h: rad[0], avsnitt: rad[1].map(avsn), ank: rad[2] || ('vilkar-' + (n + 1)), bolk: rad[3] || '', harBolk: !!rad[3] }));
fs.writeFileSync(path.join(ROT, 'vilkar.json'), JSON.stringify(vilkar, null, 1) + '\n');
console.log(vilkar.length + ' punkter skrevet til vilkar.json');
