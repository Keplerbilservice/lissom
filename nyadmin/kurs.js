/**
 * Kurs. Flyttes i neste trinn; til da går hver rad til samme sted i det
 * gamle admin.
 */
import { el, rad } from './kjerne.js';

export default {
  id: 'kurs',
  navn: 'Kurs',
  ikon: '◷',
  adresse: 'kurs',
  async tegn(main) {
    main.replaceChildren(
      el('div', { class: 'hode' }, el('div', {}, el('div', { class: 'eb', tekst: 'Kurs' }), el('h1', { tekst: 'Kurs' }))),
      el('section', { class: 'k' },
        el('p', { class: 'dempet', style: 'margin:0', tekst: 'Denne delen flyttes snart. Til da åpner radene det samme i det gamle admin.' }),
        el('div', { class: 'g', style: 'gap:6px' },
          rad('Kalender', null, '/admin/kalender'),
          rad('Kurs og datoer', null, '/admin/kurs'),
          rad('Påmeldte', null, '/admin/pameldte'),
          rad('Venteliste', null, '/admin/venteliste'),
          rad('Klar til henting', null, '/admin/ferdigbrent'),
          rad('Årskalender', null, '/admin/arskalender'),
          rad('Kursholdere', null, '/admin/kursholdere'))));
  },
};
