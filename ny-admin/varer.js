/* Ny admin, del C: Varer og lager (eierens GO 08.10.2026, prototypen lissom-enklere-admin-2).
   Liste, søk, pris, lager +/− og «Vis i butikken». Ekte data: api/admin/produkter.php
   (samme vare-API som admin-ny › Butikk og kassa). Lagringen sender hele varen tilbake slik
   den står, så felt denne skjermen ikke viser (beskrivelse, bilde, leverandør, min/maks …)
   ikke blir tømt. */
(() => {
  'use strict';
  const NA = () => window.NyAdmin || window;
  const kall = (sti, data) => NA().api('/api/admin/' + sti, data === undefined ? { metode: 'GET' } : { metode: 'POST', data });
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const kr = n => `${Number(n || 0).toLocaleString('nb-NO')} kr`;
  const toast = h => NA().toast(h);
  const registrer = (id, opp) => { const f = NA().registrerSide; if (typeof f === 'function') f(id, opp); else document.addEventListener('DOMContentLoaded', () => registrer(id, opp), { once: true }); };

  const S = { varer: [], sok: '', el: null, opptatt: false };
  // Arket: kopi av varen som endres, og lageret slik det sto da arket ble åpnet.
  let A = null;

  function lagerMerke(v) {
    if (v.lager === null) return '<span class="merke">Ikke lagerstyrt</span>';
    const lav = v.lagerMin !== null ? v.lager <= v.lagerMin : v.lager <= 3;
    return `<span class="merke ${lav ? 'rod' : v.lager <= 10 ? 'gul' : 'gronn'}">${v.lager} på lager</span>`;
  }
  const hvor = v => [v.kunMedlemmer ? 'Internbutikk' : '', v.iNettbutikk ? 'Nettbutikk' : ''].filter(Boolean).join(' og ') || 'Bare i admin';

  function tegnListe() {
    const boks = document.getElementById('var-liste'); if (!boks) return;
    const q = S.sok.trim().toLowerCase();
    const V = S.varer.filter(v => !q || [v.tittel, v.kategori, v.artikkelnr].some(x => String(x || '').toLowerCase().includes(q)));
    boks.innerHTML = V.map(v => `<button class="rad radknapp" data-var="${v.id}"><span class="tekst"><b>${esc(v.tittel)}</b>
        <small>${v.kategori ? esc(v.kategori) + ' · ' : ''}${kr(v.pris)} · ${hvor(v)}${v.status !== 'publisert' ? ' · skjult' : ''}</small></span>
        ${lagerMerke(v)}<span class="knapp liten">Endre</span></button>`).join('') || '<p class="muted">Ingen treff.</p>';
    const ey = document.getElementById('var-antall'); if (ey) ey.textContent = `${S.varer.length} varer`;
  }

  async function hent() {
    try { S.varer = (await kall('produkter.php')).varer || []; } catch (e) { toast(esc(e.message)); }
    tegnListe();
  }

  async function tegn(el) {
    S.el = el;
    el.innerHTML = `<div class="head"><div><div class="eyebrow" id="var-antall">Henter …</div><h1>Varer og lager</h1></div><button class="knapp hoved" data-var-ny="1">＋ Ny vare</button></div>
      <div style="margin-bottom:16px"><input id="var-sok" type="search" placeholder="Søk i varer" aria-label="Søk i varer" value="${esc(S.sok)}"
        style="width:100%;max-width:420px;min-height:48px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:var(--paper);font:inherit"></div>
      <section class="kort" id="var-liste"><p class="muted">Henter …</p></section>`;
    el.querySelector('#var-sok').addEventListener('input', e => { S.sok = e.target.value; tegnListe(); });
    await hent();
    const hh = NA().hentHvert;
    if (typeof hh === 'function') hh(15000, () => { if (el.isConnected && S.el === el && !A) hent(); });
  }

  const felt = 'padding:11px;border-radius:9px;border:1px solid #c6b1a0;background:var(--field);font:inherit';
  function arkVare() {
    const v = A.vare;
    NA().apneArk(`<div class="ark-head"><h2>${v.id ? esc(v.tittel) : 'Ny vare'}</h2><button class="lukk" data-var-gjor="lukk" aria-label="Lukk">×</button></div>
      <label style="display:grid;gap:5px"><small>Navn</small><input id="var-tittel" value="${esc(v.tittel)}" maxlength="191" style="${felt}"></label>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <label style="display:grid;gap:5px"><small>Pris</small><input id="var-pris" value="${v.id ? esc(v.pris) : ''}" inputmode="numeric" style="${felt}"></label>
        <label style="display:grid;gap:5px"><small>Kategori</small><input id="var-kat" value="${esc(v.kategori || '')}" maxlength="64" style="${felt}"></label>
      </div>
      <div style="display:grid;gap:5px"><small>På lager</small><div class="ant stor">
        <button data-var-lager="-1" aria-label="Færre">−</button>
        <input id="var-lager" value="${v.lager === null ? '' : v.lager}" inputmode="numeric" aria-label="Antall på lager" placeholder="Ikke lagerstyrt" style="${felt};width:150px;text-align:center">
        <button data-var-lager="1" aria-label="Flere">+</button></div></div>
      <div class="bryter"><div><b>Vis i butikken</b><br><small>Av: varen er skjult</small></div><button class="av" data-var-bryter="status" aria-pressed="${v.status === 'publisert'}" aria-label="Vis i butikken"></button></div>
      <div class="bryter"><div><b>Internbutikken</b><br><small>Medlemmer ser den på Min side</small></div><button class="av" data-var-bryter="kunMedlemmer" aria-pressed="${!!v.kunMedlemmer}" aria-label="Internbutikken"></button></div>
      <div class="bryter"><div><b>Nettbutikken</b><br><small>Kunder ser den på lissom.no</small></div><button class="av" data-var-bryter="iNettbutikk" aria-pressed="${!!v.iNettbutikk}" aria-label="Nettbutikken"></button></div>
      <p class="feil" id="var-feil" role="alert" hidden style="color:var(--red)"></p>
      <div class="ark-fot"><button class="knapp" data-var-gjor="lukk">Avbryt</button><button class="knapp hoved" data-var-gjor="lagre">Lagre</button></div>`);
  }

  function lesFelter() {
    const v = A.vare;
    v.tittel = (document.getElementById('var-tittel')?.value || '').trim();
    v.prisRaa = (document.getElementById('var-pris')?.value || '').replace(/\s+/g, '');
    v.kategori = (document.getElementById('var-kat')?.value || '').trim();
    const l = (document.getElementById('var-lager')?.value || '').trim();
    v.lager = l === '' ? null : Math.max(0, parseInt(l, 10) || 0);
  }

  async function lagre(knapp) {
    if (S.opptatt) return;
    lesFelter();
    const v = A.vare, feil = document.getElementById('var-feil');
    const vis = t => { feil.textContent = t; feil.hidden = false; };
    if (!v.tittel) return vis('Varen må ha et navn.');
    if (!/^\d+$/.test(v.prisRaa)) return vis('Skriv prisen i hele kroner.');
    S.opptatt = true; knapp.disabled = true;
    try {
      let lager = v.lager;
      // Solgt noe imens arket sto åpent? Da legges endringen på det som står nå, ikke over det.
      if (v.id && lager !== null && A.lagerFoer !== null) {
        const fersk = ((await kall('produkter.php')).varer || []).find(x => x.id === v.id);
        if (fersk && fersk.lager !== null && fersk.lager !== A.lagerFoer) lager = Math.max(0, fersk.lager + (lager - A.lagerFoer));
      }
      const ja = b => (b ? 'ja' : 'nei');
      const d = await kall('produkter.php', {
        handling: 'lagre', id: v.id || 0, tittel: v.tittel, pris: Number(v.prisRaa), kategori: v.kategori,
        lager: lager === null ? '' : String(lager), status: v.status, mva: v.mva ?? 25,
        beskrivelse: v.beskrivelse || '', bilde: v.bilde || '',
        kunMedlemmer: ja(v.kunMedlemmer), iNettbutikk: ja(v.iNettbutikk),
        artikkelnr: v.artikkelnr || '', leverandorId: v.leverandorId || 0, kanBestilles: ja(v.kanBestilles),
        lagerMin: v.lagerMin === null || v.lagerMin === undefined ? '' : String(v.lagerMin),
        lagerMaks: v.lagerMaks === null || v.lagerMaks === undefined ? '' : String(v.lagerMaks),
        leire: ja(v.leire),
      });
      A = null; NA().lukkArk(true);
      toast(`<b>${esc(d.beskjed || 'Lagret.')}</b> Kassa og butikken er oppdatert.`);
      hent();
    } catch (e) { vis(e.message); } finally { S.opptatt = false; knapp.disabled = false; }
  }

  document.addEventListener('click', e => {
    const b = e.target.closest('[data-var],[data-var-ny],[data-var-gjor],[data-var-lager],[data-var-bryter]'); if (!b) return;
    if (b.dataset.var) {
      const v = S.varer.find(x => x.id === Number(b.dataset.var)); if (!v) return;
      A = { vare: { ...v }, lagerFoer: v.lager }; return arkVare();
    }
    if (b.dataset.varNy) {
      A = { vare: { id: 0, tittel: '', pris: 0, kategori: '', lager: 0, status: 'kladd', kunMedlemmer: true, iNettbutikk: false, mva: 25,
        lagerMin: null, lagerMaks: null, leire: false, kanBestilles: false }, lagerFoer: null };
      return arkVare();
    }
    if (!A) return;
    if (b.dataset.varLager) {
      const i = document.getElementById('var-lager'); if (!i) return;
      const n = i.value.trim() === '' ? 0 : (parseInt(i.value, 10) || 0);
      i.value = String(Math.max(0, n + Number(b.dataset.varLager)));
      i.dispatchEvent(new Event('input', { bubbles: true }));
      return;
    }
    if (b.dataset.varBryter) {
      const k = b.dataset.varBryter, v = A.vare;
      if (k === 'status') v.status = v.status === 'publisert' ? 'kladd' : 'publisert'; else v[k] = !v[k];
      b.setAttribute('aria-pressed', String(k === 'status' ? v.status === 'publisert' : !!v[k]));
      b.dispatchEvent(new Event('input', { bubbles: true }));
      return;
    }
    if (b.dataset.varGjor === 'lukk') { NA().lukkArk(); return; }
    if (b.dataset.varGjor === 'lagre') lagre(b);
  });
  // Arket lukket uten lagring (×, Esc, klikk utenfor): da er det ikke lenger noe «åpent ark» som stopper oppdateringen.
  document.addEventListener('close', e => { if (e.target.tagName === 'DIALOG') A = null; }, true);

  registrer('varer', { tittel: 'Varer', ikon: '◇', tegn, mobil: false });
})();
