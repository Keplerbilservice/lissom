/* Ny admin, del C: Mer (eierens GO 08.10.2026, prototypen lissom-enklere-admin-2).
   Det som brukes sjeldnere. Kortene åpner sidene i ny admin. */
(() => {
  'use strict';
  const NA = () => window.NyAdmin || window;
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const registrer = (id, opp) => { const f = NA().registrerSide; if (typeof f === 'function') f(id, opp); else document.addEventListener('DOMContentLoaded', () => registrer(id, opp), { once: true }); };

  // [tittel, undertekst, adresse]. Adresse null = finnes ikke på nett ennå.
  const KORT = [
    ['Rapporter', 'Omsetning og inntektskilder', '#penger'],
    ['Markedsføring', 'Nyhetsbrev, SMS, kampanjer', '#marked'],
    ['Keramikkmaler', 'Dokumenter med former og oppskrifter', '#maler'],
    ['Vedtak', 'Regler og priser', null],
    ['Eksport', 'Regnskap og lister', '#regnskap'],
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

  async function penger(el) {
    const N = NA();
    el.innerHTML = '<p class="laster">Henter omsetning …</p>';
    try {
      const d = await N.api('oversikt.php');
      const kilder = d.omsetning?.kilder || {};
      let valgt = 'idag';
      const tegnPenger = () => {
        const p = kilder[valgt] || {sumEksOre: 0, kilder: []};
        el.innerHTML = `<div class="head"><div><h1>Penger</h1><p class="muted">Innbetalinger vises når betalingen er bekreftet.</p></div></div>
          <div class="faner">${[['idag', 'I dag'], ['maned', 'Denne måneden'], ['forrige', 'Forrige måned']].map(([id, navn]) => `<button type="button" data-penger-periode="${id}" aria-pressed="${id === valgt}">${navn}</button>`).join('')}</div>
          <section class="kort"><h2>Omsetning uten mva</h2><strong>${N.kr(p.sumEksOre || 0)}</strong></section>
          <h2>Hvor pengene kommer fra</h2>${(p.kilder || []).map(r => `<details class="kort"><summary>${esc(r.navn)} · ${N.kr(r.eksOre || 0)}</summary>${(r.salg || []).map(s => `<div class="rad"><span class="tekst"><b>${esc(s.hva)}</b><small>${esc(s.dato)}</small></span><strong>${N.kr(s.eksOre || 0)}</strong></div>`).join('') || '<p>Ingen salg i perioden.</p>'}</details>`).join('') || '<p>Ingen salg i perioden.</p>'}
          <div class="knapper"><button class="knapp" data-penger-til="regnskap">Dagsoppgjør og månedsrapport</button><button class="knapp" data-penger-til="betalinger">Betalinger</button><button class="knapp" data-penger-til="medlemmer">Medlemmer og betalingsstatus</button><button class="knapp" data-penger-til="kasse">Kasse</button></div>`;
        el.querySelectorAll('[data-penger-periode]').forEach(b => b.onclick = () => { valgt = b.dataset.pengerPeriode; tegnPenger(); });
        el.querySelectorAll('[data-penger-til]').forEach(b => b.onclick = () => N.gaTil(b.dataset.pengerTil));
      };
      tegnPenger();
    } catch (e) { el.innerHTML = `<p class="feil">${esc(e.message)}</p>`; }
  }

  registrer('mer', { tittel: 'Mer', ikon: '…', tegn, mobil: false });
  registrer('penger', { tittel: 'Penger', ikon: '○', tegn: penger, mobil: false });
})();
