/**
 * Penger. I dette trinnet: omsetningen denne måneden (uten mva, med mva på
 * egen linje) fra samme kilde som «I dag» og Oversikt, og veiene videre til
 * det som ikke er flyttet ennå. Resten av skjermen kommer i neste trinn.
 */
import { el, hent, rad, lasting, feilboks } from './kjerne.js';
import { omsetningKort } from './omsetning.js';

export default {
  id: 'penger',
  navn: 'Penger',
  ikon: 'kr',
  adresse: 'penger',
  async tegn(main) {
    main.replaceChildren(lasting());
    const ov = await hent('/api/admin/oversikt.php');
    if (ov.status !== 200) { main.replaceChildren(feilboks('Fikk ikke hentet tallene.', () => this.tegn(main))); return; }
    const o = ov.d.omsetning || {};
    const eks = typeof o.manedEksOre === 'number' ? o.manedEksOre : o.manedOre;
    main.replaceChildren(
      el('div', { class: 'hode' }, el('div', {}, el('div', { class: 'eb', tekst: 'Penger' }), el('h1', { tekst: 'Penger' }))),
      el('div', { class: 'g to' },
        omsetningKort('Denne måneden', o.linjerMnd || [], eks, o.manedOre),
        el('section', { class: 'k' }, el('h2', { tekst: 'Gå til' }),
          el('div', { class: 'g', style: 'gap:6px' },
            rad('Ta betalt (Kasse)', null, '/admin/uttak'),
            rad('Økonomi og dagsoppgjør', null, '/admin/okonomi'),
            rad('Nettbutikk og ordre', null, '/admin/butikk')))));
  },
};
