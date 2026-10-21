/* Ny admin: Keramikkmaler (eieren 21.10.2026, «ja takk» til visningen Wn3nbyiB9z3u4PDCoXr8Ea).
   Mer › Keramikkmaler åpnet før Dokumenter i admin-ny, der malene lå som en lang liste under «Spør verkstedet»,
   og knappene under «Leser fra» (av/på for AI-en) så ut som noe man kunne trykke seg inn på.

   Ekte data, ingen nye API-er:
     api/admin/dokumenter.php  kortene (kategorier) og dokumentene. Malene er underkortene til «Keramikk maler»
                               (slug «maler»); hver mal har bilde, Mal, Monteringsguide og ev. stegbilder.
     api/dokument.php          ?kort=<id> er bildet på malen, ?id=<id> åpner dokumentet. */
(() => {
  'use strict';
  const NA = () => window.NyAdmin || window;
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const registrer = (id, opp) => { const f = NA().registrerSide; if (typeof f === 'function') f(id, opp); else document.addEventListener('DOMContentLoaded', () => registrer(id, opp), { once: true }); };

  // Samme gruppering som Component.malGruppe i lissom-2108.html (mappenavnene til eieren).
  const gruppe = s => /^mal-\d{2}-/.test(s) ? 'Maler 1 til 20' : s.startsWith('mal-20-55-') ? 'Maler 20 til 55'
    : s.startsWith('mal-55-85-') ? 'Maler 55 til 85' : s.startsWith('mal-85-100-') ? 'Maler 85 til 100'
    : s.startsWith('mal-ekstra-') ? 'Ekstra maler' : s.startsWith('mal-lyslykter-') ? 'Lyslykter' : '';
  const GRUPPER = ['Maler 1 til 20', 'Maler 20 til 55', 'Maler 55 til 85', 'Maler 85 til 100', 'Ekstra maler', 'Lyslykter'];
  const erSteg = d => /^Steg \d+$/.test(d.navn) && String(d.mime).indexOf('image/') === 0;

  const S = { maler: [], felles: [], valgt: '', sok: '', el: null };

  async function hent() {
    const d = await NA().api('dokumenter.php');
    const kort = d.kategorier || [], dok = d.dokumenter || [];
    const hoved = kort.find(k => k.slug === 'maler');
    if (!hoved) { S.maler = []; S.felles = []; return; }
    S.felles = dok.filter(x => x.kategori === hoved.id);
    S.maler = kort.filter(k => k.forelder === hoved.id).map(k => ({
      ...k, gruppe: gruppe(k.slug), dok: dok.filter(x => x.kategori === k.id),
    }));
  }

  const dokTekst = m => {
    const vanlige = m.dok.filter(d => !erSteg(d)).map(d => d.navn);
    const steg = m.dok.length - vanlige.length;
    return vanlige.join(' · ') + (steg ? ' · ' + steg + ' stegbilder' : '');
  };

  function tegnListe() {
    const el = S.el; if (!el) return;
    const q = S.sok.trim().toLocaleLowerCase('nb-NO');
    const treff = S.maler.filter(m => (!S.valgt || m.gruppe === S.valgt) && (!q || m.navn.toLocaleLowerCase('nb-NO').includes(q)));
    el.querySelector('[data-mal-antall]').textContent = treff.length + ' av ' + S.maler.length + ' maler';
    el.querySelectorAll('[data-mal-gruppe]').forEach(b => b.setAttribute('aria-pressed', String((b.dataset.malGruppe || '') === S.valgt)));
    const liste = el.querySelector('[data-mal-liste]');
    if (!treff.length) { liste.innerHTML = '<div class="kort tom">Ingen maler heter det. Prøv et annet ord.</div>'; return; }
    liste.innerHTML = GRUPPER.filter(g => treff.some(m => m.gruppe === g)).map(g => {
      const ms = treff.filter(m => m.gruppe === g);
      return `<section class="mal-gruppe"><h2>${esc(g)} <small>${ms.length} ${ms.length === 1 ? 'mal' : 'maler'}</small></h2><div class="mal-grid">${ms.map(m => `
        <button type="button" class="mal-kort" data-mal="${m.id}">
          ${m.harBilde ? `<img src="/api/dokument.php?kort=${m.id}" alt="" loading="lazy">` : '<span class="mal-utenbilde">Uten bilde</span>'}
          <span class="mal-t"><b>${esc(m.navn)}</b><small>${esc(dokTekst(m))}</small></span>
        </button>`).join('')}</div></section>`;
    }).join('');
  }

  function apne(id) {
    const m = S.maler.find(x => x.id === id); if (!m) return;
    const vanlige = m.dok.filter(d => !erSteg(d)), steg = m.dok.filter(erSteg);
    const N = NA();
    N.apneArk(`${N.arkHode(esc(m.navn))}
      <p class="eyebrow">${esc(m.gruppe)}</p>
      ${m.harBilde ? `<img class="mal-arkbilde" src="/api/dokument.php?kort=${m.id}" alt="${esc(m.navn)}">` : ''}
      <div class="mal-knapper">${vanlige.map((d, i) => `<a class="knapp${i === 0 ? ' hoved' : ''}" href="/api/dokument.php?id=${d.id}" target="_blank" rel="noopener"><span>${d.navn === 'Mal' ? 'Åpne malen' : esc(d.navn)}</span><small>${esc(d.type)}</small></a>`).join('') || '<p class="tom">Ingen dokumenter i denne malen.</p>'}</div>
      ${steg.length ? `<p class="eyebrow" style="margin-top:18px">Stegbilder</p><div class="mal-steg">${steg.map(d => `<a href="/api/dokument.php?id=${d.id}" target="_blank" rel="noopener"><img src="/api/dokument.php?id=${d.id}" alt="${esc(d.navn)}" loading="lazy"><small>${esc(d.navn)}</small></a>`).join('')}</div>` : ''}`);
  }

  async function tegn(el) {
    S.el = el;
    el.innerHTML = `<div class="head"><div><div class="eyebrow">Former og oppskrifter</div><h1>Keramikkmaler</h1></div><div class="eyebrow" data-mal-antall></div></div>
      <p class="laster">Henter …</p>`;
    try { await hent(); } catch (e) { if (S.el === el) el.querySelector('.laster').outerHTML = `<p class="feil">${esc(e.message)}</p>`; return; }
    if (S.el !== el) return;
    el.innerHTML = `<div class="head"><div><div class="eyebrow">Former og oppskrifter</div><h1>Keramikkmaler</h1></div><div class="eyebrow" data-mal-antall></div></div>
      <div class="mal-verktoy"><input class="sok" type="search" data-mal-sok placeholder="Søk etter mal, f.eks. kopp eller lykt" aria-label="Søk etter mal" value="${esc(S.sok)}">
        ${S.felles.map(d => `<a class="knapp" href="/api/dokument.php?id=${d.id}" target="_blank" rel="noopener">${esc(d.navn)}</a>`).join('')}</div>
      <div class="faner" role="group" aria-label="Velg gruppe">${['', ...GRUPPER].map(g => `<button type="button" data-mal-gruppe="${esc(g)}" aria-pressed="false">${g ? esc(g) : 'Alle'}</button>`).join('')}</div>
      <div data-mal-liste class="mal-liste"></div>`;
    el.querySelector('[data-mal-sok]').addEventListener('input', e => { S.sok = e.target.value; tegnListe(); });
    el.addEventListener('click', e => {
      const g = e.target.closest('[data-mal-gruppe]'); if (g) { S.valgt = g.dataset.malGruppe || ''; tegnListe(); return; }
      const m = e.target.closest('[data-mal]'); if (m) apne(Number(m.dataset.mal));
    });
    tegnListe();
  }

  registrer('maler', { tittel: 'Keramikkmaler', ikon: '…', tegn, mobil: false });
})();
