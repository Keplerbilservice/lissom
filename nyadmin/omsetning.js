/**
 * Omsetningen, vist likt i «I dag» og i Penger.
 *
 * Eieren, 30. september 2026: omsetningen er UTEN mva («kontoen total maa
 * vaere alt uten mva, det er dette som er omsetning»). Hver konto viser
 * beloepet uten mva, og kontoene med mva faar mva-en paa egen linje under,
 * som informasjon. Det innbetalte med mva staar som en liten linje, ikke som
 * omsetning. Tallene regnes paa serveren (Omsetning::mvaFor / sumUtenMva).
 */
import { el, kr } from './kjerne.js';

/**
 * @param {Array} linjer   linjerIdag / linjerMnd fra oversikt.php
 * @param {number} eksOre  omsetningen uten mva
 * @param {number} bruttoOre det innbetalte med mva
 */
export function omsetningKort(tittel, linjer, eksOre, bruttoOre, ekstra) {
  const harMva = (linjer || []).some(l => l.mvaSats > 0);
  const rader = [];
  for (const l of linjer || []) {
    rader.push(el('div', { class: 'rad tom', 'data-linje': l.nokkel },
      el('span', { tekst: l.navn }), el('b', { tekst: kr(l.mvaSats > 0 ? l.eksOre : l.ore) })));
    if (l.mvaSats > 0) {
      rader.push(el('div', { class: 'rad tom mva', style: 'min-height:40px' },
        el('span', { class: 'dempet', tekst: 'Mva ' + l.mvaSats + ' %' }), el('span', { class: 'dempet', tekst: kr(l.mvaOre) })));
    }
  }
  if (!rader.length) rader.push(el('p', { class: 'tomt', tekst: 'Ingen betalinger ennå.' }));
  return el('section', { class: 'k', 'data-kort': 'omsetning' },
    el('div', { class: 'eb', tekst: tittel }),
    el('div', { class: 'stor', 'data-sum': String(eksOre || 0), tekst: kr(eksOre) }),
    el('div', { class: 'dempet', tekst: 'Omsetning (uten mva)' }),
    harMva ? el('div', { class: 'dempet', style: 'font-size:14px', 'data-innbetalt': String(bruttoOre || 0), tekst: 'Innbetalt inkl. mva ' + kr(bruttoOre) }) : null,
    ekstra || null,
    el('div', { class: 'g', style: 'gap:6px' }, rader));
}
