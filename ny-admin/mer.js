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
    ['Vedlikehold', 'Databaseoppdateringer', 'vedlikehold'],
    ['Gammel admin', 'Reserve', '/admin-ny'],
  ];

  function tegn(el) {
    el.innerHTML = `<div class="head"><div><div class="eyebrow">Brukes sjeldnere</div><h1>Mer</h1></div></div>
      <div class="mer">${KORT.map(([t, u, url]) => url
        ? `<button data-mer-gaa="${esc(url)}"><b>${esc(t)}</b><small>${esc(u)}</small></button>`
        : `<button disabled title="Vedtakslista ligger i koden (tests/godkjent/vedtak.json) og vises ikke på nett ennå"><b>${esc(t)}</b><small>Ikke på nett ennå</small></button>`).join('')}</div>`;
  }

  /* Vedlikehold (eieren 09.10.2026): det som venter fra api/migrer.php, og «Kjør oppdateringer» (NA.kjorOppdateringer). */
  async function arkVedlikehold() {
    const N = NA();
    N.apneArk(N.arkHode('Vedlikehold') + '<p class="laster" data-vedlikehold>Henter …</p>');
    const d = await N.oppdateringer(true);
    /* Lukket eller byttet til et annet ark imens: ikke åpne det igjen. */
    if (!N.arkApen() || !document.querySelector('#ark-inn [data-vedlikehold]')) return;
    const m = d ? d.mangler : null;
    const inn = N.apneArk(`${N.arkHode('Vedlikehold')}
      <div class="kort-head"><h3>Databaseoppdateringer</h3></div>
      ${m === null ? '<p class="feil">Kunne ikke hente oppdateringene.</p>' : `<p>${m.length} oppdateringer venter.</p>${m.map(f => `<div class="rad"><div class="tekst"><b>${esc(f)}</b></div></div>`).join('')}`}
      <div class="ark-fot"><button class="knapp" type="button" data-lukk>Lukk</button>${m && m.length ? '<button class="knapp hoved" type="button" data-kjor>Kjør oppdateringer</button>' : ''}</div>`);
    const k = inn.querySelector('[data-kjor]');
    if (k) k.onclick = async () => { k.disabled = true; if (await N.kjorOppdateringer()) { N.oppdaterTopp(); } };
  }

  document.addEventListener('click', e => { const b = e.target.closest('[data-mer-gaa]'); if (!b) return; if (b.dataset.merGaa === 'vedlikehold') arkVedlikehold(); else location.href = b.dataset.merGaa; });

  registrer('mer', { tittel: 'Mer', ikon: '…', tegn, mobil: false });
})();
