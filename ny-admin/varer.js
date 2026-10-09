/* Ny admin, del C: Varer og lager (eierens GO 08.10.2026, prototypen lissom-enklere-admin-2).
   Utvidet 09.10.2026 (eieren): faner Internlager · Lissom kolleksjon · Medlemskolleksjon · Alle · Handleliste,
   rediger alle felt og fjern vare, minimum og maks per vare, og handlelista for verkstedets lager.

   Ekte data, ingen egne API-er:
     api/admin/produkter.php   varene (samme vare-API som admin-ny › Butikk og kassa), «Lite på lager»,
                               «Bestill mer» (bestillMer) og verkstedets linjer i handlelista (handleliste,
                               handlelisteFjern, handlet).
     api/admin/medlemssalg.php medlemmenes varer (medlemskolleksjonen): endre og slett.
     api/admin/bilder.php      bildene å velge mellom, og opplasting.

   Hvilken fane en vare hører til, kommer fra feltene som finnes:
     Internlager        kunMedlemmer (internbutikken) eller bare i admin (verken intern- eller nettbutikk)
     Lissom kolleksjon  iNettbutikk (nettbutikken på lissom.no)
     Medlemskolleksjon  medlemssalg (member_sales)
   En vare som er både intern og i nettbutikken, står i begge.

   Lagringen sender hele varen tilbake slik den står, så felt denne skjermen ikke viser (artikkelnr, mva, leire …)
   ikke blir tømt. Lageret sendes som differanse (lagerEndring), så et salg imens arket står åpent ikke overskrives. */
(() => {
  'use strict';
  const NA = () => window.NyAdmin || window;
  const kall = (sti, data) => NA().api('/api/admin/' + sti, data === undefined ? { metode: 'GET' } : { metode: 'POST', data });
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const kr = n => `${Number(n || 0).toLocaleString('nb-NO')} kr`;
  const toast = h => NA().toast(h);
  const registrer = (id, opp) => { const f = NA().registrerSide; if (typeof f === 'function') f(id, opp); else document.addEventListener('DOMContentLoaded', () => registrer(id, opp), { once: true }); };

  const FANER = [['intern', 'Internlager'], ['lissom', 'Lissom kolleksjon'], ['medlem', 'Medlemskolleksjon'], ['alle', 'Alle'], ['handle', 'Handleliste']];
  const S = { varer: [], salg: [], lite: [], handleliste: [], harHL: false, leverandorer: [], fane: 'alle', sok: '', el: null, opptatt: false };
  // Arket: kopi av varen som endres, og lageret slik det sto da arket ble åpnet. type = 'vare' | 'salg' | 'hl'.
  let A = null;

  const erIntern = v => !!v.kunMedlemmer || !v.iNettbutikk;
  const erLissom = v => !!v.iNettbutikk;
  const underMin = v => v.lager !== null && v.lagerMin !== null && v.lagerMin !== undefined && v.lager <= v.lagerMin;
  const bildeSrc = b => !b ? '' : (/^https?:\/\//.test(b) ? b : '/' + String(b).replace(/^\//, ''));

  function lagerMerke(v) {
    if (v.lager === null) return '<span class="merke">Ikke lagerstyrt</span>';
    const lav = v.lagerMin !== null ? v.lager <= v.lagerMin : v.lager <= 3;
    return `<span class="merke ${lav ? 'rod' : v.lager <= 10 ? 'gul' : 'gronn'}">${v.lager} på lager</span>`;
  }
  const hvor = v => [v.kunMedlemmer ? 'Internbutikk' : '', v.iNettbutikk ? 'Nettbutikk' : ''].filter(Boolean).join(' og ') || 'Bare i admin';
  const grenser = v => (v.lagerMin !== null && v.lagerMin !== undefined) || (v.lagerMaks !== null && v.lagerMaks !== undefined)
    ? ` · min ${v.lagerMin ?? '–'} · maks ${v.lagerMaks ?? '–'}` : '';
  const SALG_STATUS = { publisert: ['I butikken', 'gronn'], til_godkjenning: ['Venter på godkjenning', 'gul'], avvist: ['Avvist', 'rod'], skjult: ['Skjult', ''], solgt: ['Solgt', ''] };

  const vareRad = v => `<button class="rad radknapp" data-var="${v.id}"><span class="tekst"><b>${esc(v.tittel)}</b>
      <small>${v.kategori ? esc(v.kategori) + ' · ' : ''}${kr(v.pris)} · ${hvor(v)}${v.status !== 'publisert' ? ' · skjult' : ''}${grenser(v)}</small></span>
      ${underMin(v) ? '<span class="merke rod">Under minimum</span>' : ''}${lagerMerke(v)}</button>`;
  const salgRad = g => {
    const [t, f] = SALG_STATUS[g.status] || [g.status, ''];
    return `<button class="rad radknapp" data-salg="${g.id}"><span class="tekst"><b>${esc(g.tittel)}</b>
      <small>${esc(g.laget)} · ${esc(g.pris)}${g.kategori ? ' · ' + esc(g.kategori) : ''}${(g.antall || 1) > 1 ? ' · ' + g.antall + ' stk' : ''}</small></span>
      <span class="merke ${f}">${esc(t)}</span></button>`;
  };

  function treff(felt) {
    const q = S.sok.trim().toLowerCase();
    return !q || felt.some(x => String(x || '').toLowerCase().includes(q));
  }

  function tegnFaner() {
    const f = document.getElementById('var-faner'); if (!f) return;
    f.innerHTML = FANER.map(([k, n]) => `<button type="button" data-var-fane="${k}" aria-pressed="${S.fane === k}">${n}${k === 'handle' && S.handleliste.length ? ` (${S.handleliste.length})` : ''}</button>`).join('');
  }

  function tegnListe() {
    tegnFaner();
    const boks = document.getElementById('var-liste'); if (!boks) return;
    const ey = document.getElementById('var-antall'); if (ey) ey.textContent = `${S.varer.length + S.salg.length} varer`;
    if (S.fane === 'handle') return tegnHandleliste(boks);
    const varer = S.fane === 'medlem' ? [] : S.varer.filter(v => S.fane === 'alle' || (S.fane === 'intern' ? erIntern(v) : erLissom(v)));
    const salg = S.fane === 'medlem' || S.fane === 'alle' ? S.salg : [];
    const V = varer.filter(v => treff([v.tittel, v.kategori, v.artikkelnr]));
    const G = salg.filter(g => treff([g.tittel, g.kategori, g.medlem, g.laget]));
    boks.innerHTML = V.map(vareRad).join('') + G.map(salgRad).join('') || '<p class="muted">Ingen treff.</p>';
  }

  function tegnHandleliste(boks) {
    if (!S.harHL) { boks.innerHTML = '<p class="feil">Dette krever oppdatering 253. Kjør oppdateringene først.</p>'; return; }
    const paaLista = new Set(S.handleliste.map(l => l.produktId));
    const lite = S.lite.filter(r => treff([r.vare]));
    const linjer = S.handleliste.filter(l => treff([l.vare, l.leverandor]));
    boks.innerHTML = `<div class="kort-head"><h2>Under minimum</h2></div>
      ${lite.map(r => `<div class="rad"><span class="tekst"><b>${esc(r.vare)}</b>
          <small>${r.antall} igjen · min ${r.min} · maks ${r.maks ?? '–'}${r.bestill > 0 ? ` · forslag ${r.bestill} stk` : ''}</small></span>
          <span class="knapper">${paaLista.has(r.id) ? '<span class="merke gronn">På handlelista</span>'
            : `<button class="knapp liten" data-hl-legg="${r.id}" data-hl-antall="${r.bestill}">Legg i handlelista</button>`}</span></div>`).join('')
        || '<p class="tom">Ingen varer er under minimum.</p>'}
      <div class="kort-head" style="margin-top:22px"><h2>Handlelista</h2><button class="knapp liten" data-hl-ny="1">＋ Legg til vare</button></div>
      ${linjer.map(l => `<div class="rad"><span class="tekst"><b>${esc(l.vare)}</b>
          <small>${l.antall} stk${l.leverandor ? ' · ' + esc(l.leverandor) : ''}${l.lager !== null ? ' · ' + l.lager + ' på lager' : ''}</small></span>
          <span class="knapper"><button class="knapp liten" data-hl-handlet="${l.linjeId}">Handlet</button>
          <button class="knapp liten rod" data-hl-fjern="${l.linjeId}" aria-label="Fjern ${esc(l.vare)} fra handlelista">Fjern</button></span></div>`).join('')
        || '<p class="tom">Handlelista er tom.</p>'}`;
  }

  async function hent() {
    try {
      const [d, m] = await Promise.all([kall('produkter.php'), kall('medlemssalg.php').catch(() => ({ salg: [] }))]);
      S.varer = d.varer || []; S.lite = d.litePaaLager || []; S.handleliste = d.handleliste || [];
      S.harHL = !!d.harHandleliste; S.leverandorer = d.leverandorer || []; S.salg = m.salg || [];
    } catch (e) { toast(esc(e.message)); }
    tegnListe();
  }

  // #varer?vare=ID (fra søket) åpner varen, #varer?fane=handle åpner handlelista. Søket i topplinja filtrerer innen fanen.
  async function tegn(el, params) {
    S.el = el; S.sok = '';
    const fane = params?.get?.('fane'); if (FANER.some(f => f[0] === fane)) S.fane = fane;
    const vareId = Number(params?.get?.('vare')) || 0;
    el.innerHTML = `<div class="head"><div><div class="eyebrow" id="var-antall">Henter …</div><h1>Varer og lager</h1></div><button class="knapp hoved" data-var-ny="1">＋ Ny vare</button></div>
      <div class="faner" id="var-faner" role="group" aria-label="Velg varer"></div>
      <section class="kort" id="var-liste"><p class="muted">Henter …</p></section>`;
    tegnFaner();
    await hent();
    if (vareId) { const v = S.varer.find(x => x.id === vareId); if (v) { A = { type: 'vare', vare: { ...v }, lagerFoer: v.lager }; arkVare(); } }
    const hh = NA().hentHvert;
    if (typeof hh === 'function') hh(15000, () => { if (el.isConnected && S.el === el && !A && !S.opptatt) hent(); });
  }

  // ── Rediger vare ─────────────────────────────────────────────────────
  const felt = 'padding:11px;border-radius:9px;border:1px solid #c6b1a0;background:var(--field);font:inherit;width:100%';
  const tall = n => (n === null || n === undefined ? '' : String(n));
  function bildeBoks(b) {
    return b ? `<img src="${esc(bildeSrc(b))}" alt="" style="width:96px;height:96px;object-fit:cover;border-radius:12px;border:1px solid var(--line)">`
      : '<span class="merke">Ingen bilde</span>';
  }
  function arkVare() {
    const v = A.vare;
    const lev = S.leverandorer.map(l => `<option value="${l.id}"${l.id === v.leverandorId ? ' selected' : ''}>${esc(l.navn)}</option>`).join('');
    NA().apneArk(`<div class="ark-head"><h2>${v.id ? esc(v.tittel) : 'Ny vare'}</h2><button class="lukk" data-var-gjor="lukk" aria-label="Lukk">×</button></div>
      <label style="display:grid;gap:5px"><small>Navn</small><input id="var-tittel" value="${esc(v.tittel)}" maxlength="191" style="${felt}"></label>
      <label style="display:grid;gap:5px"><small>Beskrivelse</small><textarea id="var-beskr" rows="3" style="${felt};min-height:90px">${esc(v.beskrivelse || '')}</textarea></label>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <label style="display:grid;gap:5px"><small>Pris</small><input id="var-pris" value="${v.id ? esc(v.pris) : ''}" inputmode="numeric" style="${felt}"></label>
        <label style="display:grid;gap:5px"><small>Kategori</small><input id="var-kat" value="${esc(v.kategori || '')}" maxlength="64" style="${felt}"></label>
      </div>
      <div style="display:grid;gap:5px"><small>Bilde</small><div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <span id="var-bilde-vis">${bildeBoks(v.bilde)}</span>
        <button type="button" class="knapp liten" data-var-gjor="bilder">Velg bilde</button>
        <button type="button" class="knapp liten" data-var-gjor="utenbilde"${v.bilde ? '' : ' hidden'}>Fjern bilde</button></div>
        <div id="var-bilder" hidden></div></div>
      <div style="display:grid;gap:5px"><small>På lager</small><div class="ant stor">
        <button data-var-lager="-1" aria-label="Færre">−</button>
        <input id="var-lager" value="${v.lager === null ? '' : v.lager}" inputmode="numeric" aria-label="Antall på lager" placeholder="Ikke lagerstyrt" style="${felt};width:150px;text-align:center">
        <button data-var-lager="1" aria-label="Flere">+</button></div></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <label style="display:grid;gap:5px"><small>Minimum på lager</small><input id="var-min" value="${tall(v.lagerMin)}" inputmode="numeric" style="${felt}"></label>
        <label style="display:grid;gap:5px"><small>Maks på lager</small><input id="var-maks" value="${tall(v.lagerMaks)}" inputmode="numeric" style="${felt}"></label>
      </div>
      <label style="display:grid;gap:5px"><small>Leverandør</small><select id="var-lev" style="${felt}"><option value="0">Ingen</option>${lev}</select></label>
      <div class="bryter"><div><b>Vis i butikken</b><br><small>Av: varen er skjult</small></div><button class="av" data-var-bryter="status" aria-pressed="${v.status === 'publisert'}" aria-label="Vis i butikken"></button></div>
      <div class="bryter"><div><b>Internbutikken</b><br><small>Medlemmer ser den på Min side</small></div><button class="av" data-var-bryter="kunMedlemmer" aria-pressed="${!!v.kunMedlemmer}" aria-label="Internbutikken"></button></div>
      <div class="bryter"><div><b>Nettbutikken</b><br><small>Kunder ser den på lissom.no</small></div><button class="av" data-var-bryter="iNettbutikk" aria-pressed="${!!v.iNettbutikk}" aria-label="Nettbutikken"></button></div>
      <p class="feil" id="var-feil" role="alert" hidden style="color:var(--red)"></p>
      <div class="ark-fot">${v.id ? '<button class="knapp rod" data-var-gjor="fjern" style="margin-right:auto">Fjern vare</button>' : ''}<button class="knapp" data-var-gjor="lukk">Avbryt</button><button class="knapp hoved" data-var-gjor="lagre">Lagre</button></div>`);
  }

  // Bildene fra api/admin/bilder.php (samme som bildevelgeren i admin-ny), og opplasting.
  async function visBilder() {
    const boks = document.getElementById('var-bilder'); if (!boks) return;
    if (!boks.hidden) { boks.hidden = true; return; }
    boks.hidden = false; boks.innerHTML = '<p class="laster">Henter …</p>';
    try {
      const d = await kall('bilder.php');
      const alle = [...(d.egne || []), ...(d.medfolgende || [])];
      boks.innerHTML = `<label style="display:grid;gap:5px;margin:8px 0"><small>Last opp et bilde</small><input type="file" id="var-opplast" accept="image/jpeg,image/png,image/webp"></label>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(84px,1fr));gap:8px;max-height:260px;overflow:auto">${alle.map(r =>
          `<button type="button" data-var-bildevalg="${esc(r.url)}" aria-label="Bruk bildet ${esc(r.navn || r.url)}" style="border:1px solid var(--line);border-radius:10px;padding:0;background:none;min-height:84px">
            <img src="${esc(bildeSrc(r.mini || r.url))}" alt="" loading="lazy" style="width:100%;height:84px;object-fit:cover;border-radius:9px"></button>`).join('')}</div>`;
    } catch (e) { boks.innerHTML = `<p class="feil">${esc(e.message)}</p>`; }
  }
  function settBilde(url) {
    if (!A || A.type !== 'vare') return;
    A.vare.bilde = url;
    const vis = document.getElementById('var-bilde-vis'); if (vis) vis.innerHTML = bildeBoks(url);
    const ut = document.querySelector('[data-var-gjor="utenbilde"]'); if (ut) ut.hidden = !url;
    const boks = document.getElementById('var-bilder'); if (boks) boks.hidden = true;
    NA().arkEndret?.(true);
  }
  async function lastOpp(fil) {
    const fd = new FormData(); fd.append('handling', 'last-opp'); fd.append('bilde', fil);
    const r = await fetch('/api/admin/bilder.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    let d = {}; try { d = await r.json(); } catch { /* tomt svar */ }
    if (!r.ok || d.ok === false || !d.url) throw Error(d.feil || 'Kunne ikke laste opp bildet.');
    return d.url;
  }

  const lesTall = id => { const t = (document.getElementById(id)?.value || '').trim(); return t === '' ? null : Math.max(0, parseInt(t, 10) || 0); };
  function lesFelter() {
    const v = A.vare;
    v.tittel = (document.getElementById('var-tittel')?.value || '').trim();
    v.beskrivelse = (document.getElementById('var-beskr')?.value || '').trim();
    v.prisRaa = (document.getElementById('var-pris')?.value || '').replace(/\s+/g, '');
    v.kategori = (document.getElementById('var-kat')?.value || '').trim();
    v.lager = lesTall('var-lager');
    v.lagerMin = lesTall('var-min');
    v.lagerMaks = lesTall('var-maks');
    v.leverandorId = Number(document.getElementById('var-lev')?.value || 0);
  }

  async function lagre(knapp) {
    if (S.opptatt) return;
    lesFelter();
    const v = A.vare, feil = document.getElementById('var-feil');
    const vis = t => { feil.textContent = t; feil.hidden = false; };
    if (!v.tittel) return vis('Varen må ha et navn.');
    if (!/^\d+$/.test(v.prisRaa)) return vis('Skriv prisen i hele kroner.');
    if (v.lagerMin !== null && v.lagerMaks !== null && v.lagerMaks < v.lagerMin) return vis('Maks kan ikke være lavere enn minimum.');
    S.opptatt = true; knapp.disabled = true;
    try {
      const lager = v.lager;
      // Lageret som differanse, lagt på det som står i basen i én UPDATE (produkter.php lagerEndring). Et salg
      // imens arket sto åpent blir ikke overskrevet, og endres bare navnet, røres ikke lageret (Codex 08.10.2026).
      const delta = v.id && lager !== null && A.lagerFoer !== null ? { lagerEndring: lager - A.lagerFoer } : {};
      const ja = b => (b ? 'ja' : 'nei');
      const d = await kall('produkter.php', {
        handling: 'lagre', id: v.id || 0, tittel: v.tittel, pris: Number(v.prisRaa), kategori: v.kategori,
        lager: lager === null ? '' : String(lager), ...delta, status: v.status, mva: v.mva ?? 25,
        beskrivelse: v.beskrivelse || '', bilde: v.bilde || '',
        kunMedlemmer: ja(v.kunMedlemmer), iNettbutikk: ja(v.iNettbutikk),
        artikkelnr: v.artikkelnr || '', leverandorId: v.leverandorId || 0, kanBestilles: ja(v.kanBestilles),
        lagerMin: tall(v.lagerMin), lagerMaks: tall(v.lagerMaks),
        leire: ja(v.leire),
      });
      A = null; NA().lukkArk(true);
      toast(`<b>${esc(d.beskjed || 'Lagret.')}</b> Kassa og butikken er oppdatert.`);
      hent();
    } catch (e) { vis(e.message); } finally { S.opptatt = false; knapp.disabled = false; }
  }

  // «Fjern vare»: produkter.php handling=slett. En vare som er solgt før, skjules i stedet (kvitteringene beholdes).
  async function fjernVare() {
    const v = A.vare;
    if (!await NA().bekreft('Fjern vare?', `Fjern ${v.tittel}. Varer som er solgt før, skjules i stedet, så kjøpshistorikken beholdes.`, 'Fjern vare')) return;
    try { const d = await kall('produkter.php', { handling: 'slett', id: v.id }); toast(esc(d.beskjed || 'Fjernet.')); }
    catch (e) { toast(esc(e.message)); }
    hent();
  }

  // ── Medlemskolleksjonen ──────────────────────────────────────────────
  function arkSalg() {
    const g = A.vare;
    const [t, f] = SALG_STATUS[g.status] || [g.status, ''];
    NA().apneArk(`<div class="ark-head"><h2>${esc(g.tittel)}</h2><button class="lukk" data-var-gjor="lukk" aria-label="Lukk">×</button></div>
      <p><span class="merke ${f}">${esc(t)}</span> <small>${esc(g.laget)} · ${esc(g.medlem)}</small></p>
      ${g.bilde ? `<img src="${esc(g.bilde)}" alt="" style="width:120px;height:120px;object-fit:cover;border-radius:12px;border:1px solid var(--line)">` : ''}
      <label style="display:grid;gap:5px"><small>Navn</small><input id="salg-tittel" value="${esc(g.tittel)}" maxlength="191" style="${felt}"></label>
      <label style="display:grid;gap:5px"><small>Beskrivelse</small><textarea id="salg-beskr" rows="3" style="${felt};min-height:90px">${esc(g.tekst || '')}</textarea></label>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
        <label style="display:grid;gap:5px"><small>Pris</small><input id="salg-pris" value="${g.prisKr ?? ''}" inputmode="numeric" style="${felt}"></label>
        <label style="display:grid;gap:5px"><small>Kategori</small><input id="salg-kat" value="${esc(g.kategori || '')}" maxlength="32" style="${felt}"></label>
        <label style="display:grid;gap:5px"><small>Antall</small><input id="salg-antall" value="${g.antall || 1}" inputmode="numeric" style="${felt}"></label>
      </div>
      <p class="feil" id="var-feil" role="alert" hidden style="color:var(--red)"></p>
      <div class="ark-fot"><button class="knapp rod" data-var-gjor="fjern" style="margin-right:auto">Fjern vare</button><button class="knapp" data-var-gjor="lukk">Avbryt</button><button class="knapp hoved" data-var-gjor="lagre">Lagre</button></div>`);
  }
  async function lagreSalg(knapp) {
    if (S.opptatt) return;
    const g = A.vare, feil = document.getElementById('var-feil');
    const vis = t => { feil.textContent = t; feil.hidden = false; };
    const tittel = (document.getElementById('salg-tittel')?.value || '').trim();
    const pris = (document.getElementById('salg-pris')?.value || '').replace(/\s+/g, '');
    const antall = (document.getElementById('salg-antall')?.value || '').trim();
    if (!tittel) return vis('Varen må ha et navn.');
    if (!/^\d+$/.test(pris)) return vis('Skriv prisen i hele kroner.');
    S.opptatt = true; knapp.disabled = true;
    try {
      const d = await kall('medlemssalg.php', { handling: 'endre', id: g.id, tittel, pris: Number(pris), antall: Number(antall) || 1,
        beskrivelse: document.getElementById('salg-beskr')?.value || '', kategori: document.getElementById('salg-kat')?.value || '' });
      A = null; NA().lukkArk(true); toast(esc(d.beskjed || 'Lagret.')); hent();
    } catch (e) { vis(e.message); } finally { S.opptatt = false; knapp.disabled = false; }
  }
  async function fjernSalg() {
    const g = A.vare;
    if (!await NA().bekreft('Fjern vare?', `Fjern ${g.tittel} fra medlemskolleksjonen. Varen og bildet slettes.`, 'Fjern vare')) return;
    try { const d = await kall('medlemssalg.php', { handling: 'slett', id: g.id }); toast(esc(d.beskjed || 'Fjernet.')); }
    catch (e) { toast(esc(e.message)); }
    hent();
  }

  // ── Handlelista ──────────────────────────────────────────────────────
  // Legg til: produkter.php bestillMer med antall (samme linje som «Bestill mer»). Varen må ha leverandør.
  function arkLeggTil(vareId, antall) {
    const valg = S.varer.filter(v => v.lager !== null).sort((a, b) => a.tittel.localeCompare(b.tittel, 'nb'));
    A = { type: 'hl' };
    NA().apneArk(`<div class="ark-head"><h2>Legg i handlelista</h2><button class="lukk" data-var-gjor="lukk" aria-label="Lukk">×</button></div>
      <label style="display:grid;gap:5px"><small>Vare</small><select id="hl-vare" style="${felt}"><option value="">Velg vare</option>${valg.map(v =>
        `<option value="${v.id}"${v.id === vareId ? ' selected' : ''}>${esc(v.tittel)} (${v.lager} på lager)</option>`).join('')}</select></label>
      <label style="display:grid;gap:5px"><small>Antall</small><input id="hl-antall" value="${antall > 0 ? antall : ''}" inputmode="numeric" style="${felt};width:150px"></label>
      <p class="feil" id="var-feil" role="alert" hidden style="color:var(--red)"></p>
      <div class="ark-fot"><button class="knapp" data-var-gjor="lukk">Avbryt</button><button class="knapp hoved" data-var-gjor="hl-lagre">Legg til</button></div>`);
  }
  async function hlLagre(knapp) {
    const feil = document.getElementById('var-feil');
    const vis = t => { feil.textContent = t; feil.hidden = false; };
    const id = Number(document.getElementById('hl-vare')?.value || 0);
    const antall = parseInt((document.getElementById('hl-antall')?.value || '').trim(), 10);
    if (!id) return vis('Velg vare.');
    if (!(antall > 0)) return vis('Skriv hvor mange som skal kjøpes.');
    knapp.disabled = true;
    try { const d = await kall('produkter.php', { handling: 'bestillMer', id, antall }); A = null; NA().lukkArk(true); toast(esc(d.beskjed)); hent(); }
    catch (e) { vis(e.message); } finally { knapp.disabled = false; }
  }
  async function hlHandling(knapp, data) {
    if (S.opptatt) return;
    S.opptatt = true; knapp.disabled = true;
    try {
      const d = await kall('produkter.php', data);
      toast(esc(d.beskjed || 'Lagret.'));
      await hent();
    } catch (e) { toast(esc(e.message)); knapp.disabled = false; } finally { S.opptatt = false; }
  }

  document.addEventListener('click', e => {
    const b = e.target.closest('[data-var],[data-salg],[data-var-ny],[data-var-fane],[data-var-gjor],[data-var-lager],[data-var-bryter],[data-var-bildevalg],[data-hl-legg],[data-hl-ny],[data-hl-handlet],[data-hl-fjern]'); if (!b) return;
    if (b.dataset.varFane) { S.fane = b.dataset.varFane; tegnListe(); return; }
    if (b.dataset.var) {
      const v = S.varer.find(x => x.id === Number(b.dataset.var)); if (!v) return;
      A = { type: 'vare', vare: { ...v }, lagerFoer: v.lager }; return arkVare();
    }
    if (b.dataset.salg) {
      const g = S.salg.find(x => x.id === Number(b.dataset.salg)); if (!g) return;
      A = { type: 'salg', vare: { ...g } }; return arkSalg();
    }
    if (b.dataset.varNy) {
      const nett = S.fane === 'lissom';
      A = { type: 'vare', vare: { id: 0, tittel: '', pris: 0, kategori: '', lager: 0, status: 'kladd', kunMedlemmer: !nett, iNettbutikk: nett, mva: 25,
        lagerMin: null, lagerMaks: null, leire: false, kanBestilles: false, leverandorId: 0, beskrivelse: '', bilde: '' }, lagerFoer: null };
      return arkVare();
    }
    if (b.dataset.hlLegg) {
      const n = Number(b.dataset.hlAntall) || 0;
      if (n > 0) return hlHandling(b, { handling: 'bestillMer', id: Number(b.dataset.hlLegg) });
      return arkLeggTil(Number(b.dataset.hlLegg), 0);
    }
    if (b.dataset.hlNy) return arkLeggTil(0, 0);
    if (b.dataset.hlHandlet) return hlHandling(b, { handling: 'handlet', linjeId: Number(b.dataset.hlHandlet) });
    if (b.dataset.hlFjern) return hlHandling(b, { handling: 'handlelisteFjern', linjeId: Number(b.dataset.hlFjern) });
    if (!A) return;
    if (b.dataset.varBildevalg) return settBilde(b.dataset.varBildevalg);
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
    const g = b.dataset.varGjor;
    if (g === 'lukk') { NA().lukkArk(); return; }
    if (g === 'bilder') return visBilder();
    if (g === 'utenbilde') return settBilde('');
    if (g === 'hl-lagre') return hlLagre(b);
    if (g === 'fjern') return A.type === 'salg' ? fjernSalg() : fjernVare();
    if (g === 'lagre') return A.type === 'salg' ? lagreSalg(b) : lagre(b);
  });
  document.addEventListener('change', async e => {
    if (e.target.id !== 'var-opplast' || !A || A.type !== 'vare') return;
    const fil = e.target.files?.[0]; if (!fil) return;
    e.target.disabled = true;
    try { settBilde(await lastOpp(fil)); toast('Bildet er lastet opp.'); }
    catch (err) { toast(esc(err.message)); } finally { e.target.disabled = false; }
  });
  // Arket lukket uten lagring (×, Esc, klikk utenfor): da er det ikke lenger noe «åpent ark» som stopper oppdateringen.
  document.addEventListener('close', e => { if (e.target.tagName === 'DIALOG') A = null; }, true);

  registrer('varer', { tittel: 'Varer', ikon: '◇', tegn, mobil: false, sok: q => { S.sok = q; tegnListe(); } });
})();
