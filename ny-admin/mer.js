/* Ny admin, del C: Mer (eierens GO 08.10.2026, prototypen lissom-enklere-admin-2).
   Det som brukes sjeldnere. Hvert kort åpner den eksisterende skjermen i admin-ny;
   ingenting lages på nytt her. */
(() => {
  'use strict';
  const NA = () => window.NyAdmin || window;
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const registrer = (id, opp) => { const f = NA().registrerSide; if (typeof f === 'function') f(id, opp); else document.addEventListener('DOMContentLoaded', () => registrer(id, opp), { once: true }); };

  // [tittel, undertekst, adresse]. Adresse null = finnes ikke på nett ennå.
  const KORT = [
    ['Rapporter', 'Omsetning, kurs og medlemmer', '/admin-ny#penger'],
    ['Markedsføring', 'Nyhetsbrev, SMS, kampanjer', '/admin-ny#marked'],
    ['Vedtak', 'Regler og priser', null],
    ['Eksport', 'Regnskap og lister', '/admin-ny#regnskap'],
    ['Gammel admin', 'Reserve', '/admin-ny'],
  ];

  function tegn(el) {
    el.innerHTML = `<div class="head"><div><div class="eyebrow">Brukes sjeldnere</div><h1>Mer</h1></div></div>
      <div class="mer">${KORT.map(([t, u, url]) => url
        ? `<button data-mer-gaa="${esc(url)}"><b>${esc(t)}</b><small>${esc(u)}</small></button>`
        : `<button disabled title="Vedtakslista ligger i koden (tests/godkjent/vedtak.json) og vises ikke på nett ennå"><b>${esc(t)}</b><small>Ikke på nett ennå</small></button>`).join('')}</div>`;
  }

  document.addEventListener('click', e => { const b = e.target.closest('[data-mer-gaa]'); if (b) location.href = b.dataset.merGaa; });

  registrer('mer', { tittel: 'Mer', ikon: '…', tegn, mobil: false });
})();
