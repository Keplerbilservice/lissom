/**
 * Kurs. Flyttes i neste trinn; til da går hver rad til samme sted i det
 * gamle admin.
 */
import { el, rad } from './kjerne.js';
import { tegnKalender } from './kalender.js';

export default {
  id: 'kurs',
  navn: 'Kurs',
  ikon: '◷',
  adresse: 'kurs',
  async tegn(main) {
    main.replaceChildren(
      el('div', { class: 'hode' }, el('div', {}, el('div', { class: 'eb', tekst: 'Kurs' }), el('h1', { tekst: 'Kurs' }))),
      el('section', { class: 'k' },
        el('div', { class: 'rader' },
          rad('Kalender', null, '/admin/kalender'),
          rad('Kurs og datoer', null, '/admin/kurs'),
          rad('Påmeldte', null, '/admin/pameldte'),
          rad('Venteliste', null, '/admin/venteliste'),
          rad('Klar til henting', null, '/admin/ferdigbrent'),
          rad('Årskalender (hele året)', null, '/admin/arskalender'),
          rad('Kursholdere', null, '/admin/kursholdere'))));
    await tegnKalender(main);
  },
};
