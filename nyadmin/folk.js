/**
 * Folk: medlemmer, kursdeltakere og kunder. Flyttes i neste trinn; til da
 * går hver rad til samme sted i det gamle admin.
 */
import { el, rad } from './kjerne.js';

export default {
  id: 'folk',
  navn: 'Folk',
  ikon: '☺',
  adresse: 'folk',
  async tegn(main) {
    main.replaceChildren(
      el('div', { class: 'hode' }, el('div', {}, el('div', { class: 'eb', tekst: 'Folk' }), el('h1', { tekst: 'Folk' }))),
      el('section', { class: 'k' },
        el('p', { class: 'dempet', style: 'margin:0', tekst: 'Denne delen flyttes snart. Til da åpner radene det samme i det gamle admin.' }),
        el('div', { class: 'rader' },
          rad('Medlemmer', null, '/admin/medlemmer/alle'),
          rad('Kursdeltakere', null, '/admin/deltakere/alle'),
          rad('Medlemskap', null, '/admin/medlemskap'),
          rad('Forespørsler', null, '/admin/ubesvarte'),
          rad('Dugnad', null, '/admin/ubesvarte?apne=dugnad'),
          rad('Medlemssøknader', null, '/admin/medlemssoknader'),
          rad('Registrer medlem', null, '/admin/ny-registrering'),
          rad('Til godkjenning', null, '/admin/godkjenning'),
          rad('Beskjeder', null, '/admin/beskjeder'))));
  },
};
