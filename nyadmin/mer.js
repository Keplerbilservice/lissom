/**
 * Mer: det som ikke brukes hver dag, i tre grupper (plan A v3). Flyttes i
 * neste trinn; til da går hver rad til samme sted i det gamle admin.
 */
import { el, rad } from './kjerne.js';

const GRUPPER = [
  ['Nettside og SoMe', [['Markedsføring og Innboks', '/admin/markedsforing'], ['SEO', '/admin/seo'], ['GEO', '/admin/geo'], ['Nyttig info', '/admin/nyttig']]],
  ['Innhold og maler', [['Maler', '/admin/maler'], ['Innhold', '/admin/innhold'], ['Referanser', '/admin/referanser'], ['Skisser', '/skisser.html']]],
  ['System', [['Varsler', '/admin/varsler'], ['Feilmeldinger', '/admin/feilmeldinger'], ['Brukere', '/admin/brukere'], ['Det gamle admin', '/admin']]],
];

export default {
  id: 'mer',
  navn: 'Mer',
  ikon: '≡',
  adresse: 'mer',
  async tegn(main) {
    main.replaceChildren(
      el('div', { class: 'hode' }, el('div', {}, el('div', { class: 'eb', tekst: 'Mer' }), el('h1', { tekst: 'Mer' }))),
      el('div', { class: 'g to' }, GRUPPER.map(([t, rader]) => el('section', { class: 'k' },
        el('h2', { tekst: t }),
        el('div', { class: 'g', style: 'gap:6px' }, rader.map(([n, a]) => rad(n, null, a)))))));
  },
};
