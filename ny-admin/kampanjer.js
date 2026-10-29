/* Kampanjer for forsiden og Min side, med eksisterende admin-API. */
(() => {
  'use strict';
  const N = window.NA;
  const esc = N.esc;
  let side = null, kampanjer = [], harMedlemmer = false;
  const api = (sti, data) => N.api(sti, data === undefined ? {} : {data});

  async function hent() {
    const d = await api('kampanjer.php');
    kampanjer = d.kampanjer || [];
    harMedlemmer = d.harMedlemmer !== false;
  }

  function kort(k) {
    return `<section class="kort"><div class="kort-head"><h3>${esc(k.navn || k.tittel)}</h3><span class="merke">${k.paa ? 'Vises nå' : k.ute ? 'Valgt, men slått av' : 'Ikke valgt'}</span></div>
      <p>${esc(k.tittel)}${k.pris ? ' · ' + esc(k.pris) + ' kr' : ''}</p><p class="muted">${esc(k.tekst || '')}</p>
      <div class="knapper"><button class="knapp liten" data-kamp-rediger="${k.id}">Rediger</button>
      ${k.publikum === 'medlemmer' ? '<a class="knapp liten" href="/min-side?forhandsvis=medlem" target="_blank" rel="noopener">Se som medlem</a>' : ''}
      <button class="knapp liten" data-kamp-bryter="${k.id}">${k.paa ? 'Slå av' : 'Vis kampanje'}</button>
      ${k.ute ? '' : `<button class="knapp liten rod" data-kamp-slett="${k.id}">Slett</button>`}</div></section>`;
  }

  function tegnListe() {
    if (!side?.isConnected) return;
    side.innerHTML = `<div class="head"><div><div class="eyebrow">Lissom</div><h1>Kampanjer</h1></div><button class="knapp hoved" data-kamp-ny>＋ Ny kampanje</button></div>
      <h2>På nettsiden</h2>${kampanjer.filter(k => k.publikum !== 'medlemmer').map(kort).join('') || '<p class="tom">Ingen kampanjer.</p>'}
      <h2>Til medlemmer</h2>${harMedlemmer ? kampanjer.filter(k => k.publikum === 'medlemmer').map(kort).join('') || '<p class="tom">Ingen medlemskampanjer.</p>' : '<p class="muted">Krever databaseoppdatering 260.</p>'}`;
  }

  async function tegn(el) {
    side = el;
    el.innerHTML = '<p class="laster">Henter kampanjer …</p>';
    try { await hent(); tegnListe(); }
    catch (e) { el.innerHTML = `<p class="feil">${esc(e.message)}</p>`; }
  }

  async function bildeVelger(inn) {
    const boks = inn.querySelector('[data-kamp-bilder]');
    boks.hidden = !boks.hidden;
    if (boks.hidden || boks.dataset.lest) return;
    boks.innerHTML = '<p>Henter bilder …</p>';
    try {
      const d = await api('bilder.php');
      boks.dataset.lest = '1';
      boks.innerHTML = `<label class="felt"><small>Last opp bilde</small><input type="file" data-kamp-opplast accept="image/jpeg,image/png,image/webp"></label>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(84px,1fr));gap:8px;max-height:260px;overflow:auto">${[...(d.egne || []), ...(d.medfolgende || [])].map(r => `<button type="button" data-kamp-bilde="${esc(r.url)}"><img src="/${esc(r.mini || r.url)}" alt="" loading="lazy" style="width:84px;height:84px;object-fit:cover"></button>`).join('')}</div>`;
    } catch (e) { boks.innerHTML = `<p class="feil">${esc(e.message)}</p>`; }
  }

  async function lastOpp(fil) {
    const fd = new FormData(); fd.append('handling', 'last-opp'); fd.append('bilde', fil);
    const r = await fetch('/api/admin/bilder.php', {method: 'POST', body: fd, credentials: 'same-origin'});
    const d = await r.json();
    if (!r.ok || d.ok === false || !d.url) throw Error(d.feil || 'Kunne ikke laste opp bildet.');
    return d.url;
  }

  function skjema(k = {}, publikum = '') {
    const ny = !k.id;
    const inn = N.apneArk(`${N.arkHode(ny ? 'Ny kampanje' : 'Rediger kampanje')}
      <form data-kamp-skjema><label class="felt"><small>Vises for</small><select name="publikum" ${ny ? 'required' : 'disabled'}><option value="" ${!publikum ? 'selected' : ''}>Velg målgruppe</option><option value="nett" ${publikum === 'nett' ? 'selected' : ''}>Alle på nettsiden</option>${harMedlemmer ? `<option value="medlemmer" ${publikum === 'medlemmer' ? 'selected' : ''}>Medlemmer</option>` : ''}</select></label>
      <label class="felt"><small>Navn i admin</small><input name="navn" maxlength="191" value="${esc(k.navn || '')}"></label>
      <label class="felt"><small>Merke</small><input name="merke" maxlength="191" value="${esc(k.merke || '')}"></label>
      <label class="felt"><small>Overskrift</small><input name="tittel" maxlength="191" required value="${esc(k.tittel || '')}"></label>
      <label class="felt"><small>Tekst</small><textarea name="tekst" maxlength="2000">${esc(k.tekst || '')}</textarea></label>
      <label class="felt"><small>Pris i kroner (valgfritt)</small><input name="pris" value="${esc(k.pris || '')}"></label>
      <label class="felt"><small>Knappetekst</small><input name="knapp" value="${esc(k.knapp || '')}"></label>
      <label class="felt"><small>Mål for knappen</small><input name="maal" value="${esc(k.maal || 'butikk')}"><small>butikk, medlemsbutikk, kurs, events, medlemskap, gavekort, kurs/slug eller Facebook-lenke</small></label>
      <label class="felt"><small>Bilde</small><input name="bilde" value="${esc(k.bilde || '')}"></label><button class="knapp liten" type="button" data-kamp-velg>Velg eller last opp bilde</button><div data-kamp-bilder hidden></div>
      <div class="ark-fot"><button class="knapp" type="button" data-lukk>Avbryt</button><button class="knapp hoved" type="submit">Lagre kampanje</button></div></form>`, {bred: true});
    inn.querySelector('[data-kamp-velg]').onclick = () => bildeVelger(inn);
    inn.onclick = e => {
      const b = e.target.closest('[data-kamp-bilde]');
      if (b) { inn.querySelector('[name=bilde]').value = b.dataset.kampBilde; inn.querySelector('[data-kamp-bilder]').hidden = true; }
    };
    inn.onchange = async e => {
      if (!e.target.matches('[data-kamp-opplast]') || !e.target.files?.[0]) return;
      try { inn.querySelector('[name=bilde]').value = await lastOpp(e.target.files[0]); inn.querySelector('[data-kamp-bilder]').hidden = true; }
      catch (err) { N.toast(esc(err.message)); }
    };
    inn.querySelector('form').onsubmit = async e => {
      e.preventDefault();
      const data = Object.fromEntries(new FormData(e.target));
      data.id = k.id || 0; data.handling = 'lagre'; data.publikum = ny ? data.publikum : publikum;
      if (k.paa && !await N.bekreft('Oppdatere synlig kampanje?', 'Endringene vises med en gang.', 'Lagre')) return;
      try { const d = await api('kampanjer.php', data); kampanjer = d.kampanjer || kampanjer; N.lukkArk(true); tegnListe(); N.toast(esc(d.beskjed || 'Lagret.')); }
      catch (err) { N.toast(esc(err.message)); }
    };
  }

  document.addEventListener('click', async e => {
    if (!side?.isConnected || !e.target.closest('#arbeid')) return;
    const ny = e.target.closest('[data-kamp-ny]');
    if (ny) { skjema(); return; }
    const rediger = e.target.closest('[data-kamp-rediger]');
    if (rediger) { const k = kampanjer.find(x => x.id === Number(rediger.dataset.kampRediger)); if (k) skjema(k, k.publikum); return; }
    const bryter = e.target.closest('[data-kamp-bryter]'), slett = e.target.closest('[data-kamp-slett]');
    if (!bryter && !slett) return;
    const k = kampanjer.find(x => x.id === Number((bryter || slett).dataset[bryter ? 'kampBryter' : 'kampSlett'])); if (!k) return;
    if (!await N.bekreft(slett ? 'Slette kampanjen?' : k.paa ? 'Slå av kampanjen?' : 'Vis kampanjen?', slett ? 'Kampanjen fjernes permanent.' : 'Dette endrer det besøkende ser med en gang.', slett ? 'Slett' : 'Bekreft')) return;
    try { const d = await api('kampanjer.php', slett ? {handling: 'slett', id: k.id} : {handling: 'bryter', id: k.id, paa: !k.paa}); kampanjer = d.kampanjer || kampanjer; tegnListe(); N.toast(esc(d.beskjed || 'Lagret.')); }
    catch (err) { N.toast(esc(err.message)); }
  });

  N.registrerSide('kampanjer', {tittel: 'Kampanjer', tegn, mobil: true});
})();
