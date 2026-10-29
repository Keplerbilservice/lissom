/* Markedsføring i ny admin, med de eksisterende admin-endepunktene. */
(() => {
  'use strict';
  const N = window.NA, esc = N.esc;
  let el = null, data = null, innhold = {}, seoKart = {}, seoModul = null;
  const api = (sti, kropp) => N.api(sti, kropp === undefined ? {} : {data: kropp});
  const knapp = (tekst, handling, id = '') => `<button class="knapp liten" type="button" data-marked="${handling}" data-id="${esc(id)}">${tekst}</button>`;

  async function oppdater() {
    const [m, i, k] = await Promise.all([api('marked.php'), api('innhold.php'), api('innhold.php?fil=seo-kart')]);
    data = m; innhold = i.innhold || {}; seoKart = k.sider || {};
    seoModul = seoModul || await import('/admin-ny/seogeo.js');
    if (!el?.isConnected) return;
    el.innerHTML = `<div class="head"><div><div class="eyebrow">Lissom</div><h1>Markedsføring</h1></div></div>
      <div class="knapper">${knapp('Kampanjer', 'kampanjer')}${knapp('Send e-post eller SMS til medlemmer', 'beskjed')}${knapp('Lag nytt utkast', 'nyutkast')}${knapp('Oppsett', 'oppsett')}</div>
      <div class="grid"><section class="kort"><small>Kurs med mange ledige plasser</small><h2>${(data.kursTomme || []).length}</h2></section><section class="kort"><small>Utkast</small><h2>${(data.utkast || []).length}</h2></section><section class="kort"><small>Kommentarer som venter</small><h2>${data.innboksVenter || 0}</h2></section></div>
      <h2>Kurs som kan løftes fram</h2>${(data.kursTomme || []).map(k => `<div class="rad"><span class="tekst"><b>${esc(k.tittel)}</b><small>${esc(k.dato)} · ${k.ledige} av ${k.kapasitet} plasser ledig</small></span>${knapp('Åpne kurset', 'kurs', k.kursId)}${knapp('Lag markedsføring', 'boost', k.kursId)}</div>`).join('') || '<p class="tom">Ingen kurs på lista.</p>'}
      <h2>SEO og GEO</h2><div class="knapper">${knapp('Fyll inn og lagre SEO', 'seo-fyll')}${knapp('Optimaliser for GEO', 'geo-fyll')}${knapp('Rediger per side', 'seo-sider')}</div>
      <section class="kort">${seoModul.SIDER.map(s => `<div class="rad"><span>${esc(s.navn)}</span><small>SEO ${seoModul.seoScore({...seoKart[s.id], ...lesJson(innhold['SEO/' + s.id])})} · GEO ${seoModul.geoScore(lesJson(innhold['GEO/' + s.id]))}</small>${knapp('Åpne', 'seo-side', s.id)}</div>`).join('')}</section>
      <h2>Synlighet</h2><section class="kort">${[['Vis/autosvar', 'Automatiske svar på kommentarer', false], ['Vis/salgsuke', 'Kampanje på forsiden', true], ['Banner/pa', 'Banner under toppbildet', true]].map(([k, navn, std]) => `<div class="bryter"><b>${navn}</b><button class="av" data-marked="bryter" data-id="${k}" aria-pressed="${innhold[k] === undefined ? std : innhold[k] === 'ja'}" aria-label="${navn}"></button></div>`).join('')}</section>
      <h2>Utkast</h2>${(data.utkast || []).map(u => `<div class="rad"><span class="tekst"><b>${esc(u.tittel)}</b><small>${esc(u.type)} · ${esc(u.created_at || '')}</small></span>${knapp('Åpne', 'utkast', u.id)}</div>`).join('') || '<p class="tom">Ingen utkast.</p>'}
      <h2>Godkjent og klar til bruk</h2>${(data.godkjente || []).map(u => `<div class="rad"><span class="tekst"><b>${esc(u.tittel)}</b><small>${esc(u.type)}</small></span>${knapp('Åpne', 'utkast', u.id)}</div>`).join('') || '<p class="tom">Ingen godkjente utkast.</p>'}
      <h2>Søkeord</h2>${knapp('Legg til søkeord', 'sokeordny')}${(data.sokeord || []).map(s => `<div class="rad"><span class="tekst"><b>${esc(s.ord)}</b><small>${esc([s.maalside, s.notat].filter(Boolean).join(' · '))}</small></span>${knapp('Rediger', 'sokeord', s.id)}</div>`).join('')}
      <h2>Analyse</h2><section class="kort"><p>${esc(data.analyse?.mangler || (data.analyse?.gaTilkoblet ? 'Google Analytics er koblet til.' : ''))}</p>${(data.analyse?.kilder || []).map(k => `<div class="rad"><span>${esc(k.navn)}</span><b>${k.antall}</b></div>`).join('')}</section>`;
  }
  function lesJson(s) { try { return s ? JSON.parse(s) || {} : {}; } catch { return {}; } }

  async function fyllSeo(geo = false) {
    try {
      let katalog = [];
      if (geo) {
        const r = await fetch('/api/kurs.php', {credentials: 'same-origin', cache: 'no-store'});
        if (!r.ok) throw Error('Kunne ikke hente kursene for GEO-forslag.');
        katalog = (await r.json()).kurs || [];
      }
      const endringer = {};
      for (const s of seoModul.SIDER) {
        const nokkel = (geo ? 'GEO/' : 'SEO/') + s.id;
        const fra = lesJson(innhold[nokkel]);
        const forslag = geo ? seoModul.geoForslag(s, katalog) : seoKart[s.id];
        if (!forslag) continue;
        const ny = {...forslag, ...fra};
        if (JSON.stringify(ny) !== JSON.stringify(fra)) endringer[nokkel] = JSON.stringify(ny);
      }
      const antall = Object.keys(endringer).length;
      if (!antall) { N.toast('Ingen tomme felt å fylle.'); return; }
      if (!await N.bekreft(geo ? 'Optimaliser for GEO?' : 'Fyll inn SEO?', 'Fyll tomme felt på ' + antall + ' sider med forslagene og publiser dem. Egne tekster beholdes.', geo ? 'Optimaliser' : 'Fyll inn')) return;
      const r = await api('innhold.php', {endringer}); N.toast(esc(r.beskjed || antall + ' sider er oppdatert.')); await oppdater();
    } catch (e) { N.toast(esc(e.message)); }
  }

  function arkSeoSide(id) {
    const s = seoModul.SIDER.find(x => x.id === id); if (!s) return;
    const SEO = ['tittel', 'meta', 'slug', 'h1', 'altTekst', 'internlenker', 'schema', 'ogTittel'];
    const GEO = ['sporsmal', 'kortSvar', 'fakta', 'hvemFor'];
    const seo = {...(seoKart[id] || {}), ...lesJson(innhold['SEO/' + id])}, geo = lesJson(innhold['GEO/' + id]);
    const felt = (navn, verdi, del) => `<label class="felt"><small>${esc(navn)}</small><textarea data-seo-felt="${del}:${esc(navn)}">${esc(verdi || '')}</textarea></label>`;
    const inn = N.apneArk(`${N.arkHode('SEO og GEO · ' + esc(s.navn))}<h3>SEO</h3>${SEO.map(k => felt(k, seo[k], 'seo')).join('')}<h3>GEO</h3>${GEO.map(k => felt(k, geo[k], 'geo')).join('')}
      <div class="ark-fot"><button class="knapp" type="button" data-lukk>Avbryt</button><button class="knapp hoved" type="button" data-seo-lagre>Lagre og publiser</button></div>`, {bred: true});
    inn.querySelector('[data-seo-lagre]').onclick = async () => {
      const lest = del => Object.fromEntries([...inn.querySelectorAll(`[data-seo-felt^="${del}:"]`)].map(f => [f.dataset.seoFelt.slice(del.length + 1), f.value]));
      if (!await N.bekreft('Publiser SEO/GEO-tekst?', 'Endringene på ' + s.navn + ' blir synlige på nettsiden.', 'Publiser')) return;
      try { const r = await api('innhold.php', {endringer: {['SEO/' + id]: JSON.stringify(lest('seo')), ['GEO/' + id]: JSON.stringify(lest('geo'))}}); N.toast(esc(r.beskjed || 'Lagret.')); await oppdater(); }
      catch (e) { N.toast(esc(e.message)); }
    };
  }

  async function lagBoost(kursId) {
    if (!await N.bekreft('Lag markedsføring?', 'Lag artikkel, innlegg, nyhetsbrev og medlemsmelding for kurset. Dette bruker AI-budsjettet; ingenting publiseres nå.', 'Lag forslag')) return;
    try { const r = await api('ai.php', {handling: 'kursboost', kursId}); await oppdater(); arkKursboost(r.id); }
    catch (e) { N.toast(esc(e.message)); }
  }

  async function arkKursboost(id) {
    try {
      const p = (await api('kursboost.php?id=' + id)).pakke;
      const navn = {instagram: 'Instagram', facebook: 'Facebook', artikkel: 'Artikkel', nyhetsbrev: 'Nyhetsbrevutkast', medlemmer: 'Melding til medlemmer'};
      const inn = N.apneArk(`${N.arkHode('Kursboost · ' + esc(p.kurs))}
        ${p.valgt ? `<img src="/${esc(p.valgt.replace(/^\/+/, ''))}" alt="Valgt bilde" style="width:100%;max-height:300px;object-fit:cover">` : '<p>Velg bilde før publisering i sosiale medier.</p>'}
        <div class="knapper"><button class="knapp" data-boost-bibliotek>Velg fra bildearkiv</button><button class="knapp" data-boost-bilder>Lag tre bildeforslag med AI</button></div><div data-boost-arkiv hidden></div>
        ${(p.bilder || []).map(b => `<button class="knapp liten" data-boost-velg="${esc(b)}"><img src="/${esc(String(b).replace(/^\/+/, ''))}" alt="Bildeforslag" style="width:100px;height:80px;object-fit:cover"> Bruk bildet</button>`).join('')}
        ${Object.entries(p.deler || {}).map(([del, r]) => `<section class="kort"><h3>${esc(navn[del] || del)}${r.gjort ? ' · utført' : ''}</h3><textarea data-boost-tekst="${del}" rows="6">${esc(r.tekst || '')}</textarea>
          ${r.gjort ? '' : `<button class="knapp liten" data-boost-del="${del}">${del === 'medlemmer' ? 'Kontroller utsending' : del === 'nyhetsbrev' ? 'Lag nyhetsbrevutkast' : 'Kontroller og publiser'}</button>`}</section>`).join('')}`, {bred: true});
      inn.onclick = async e => {
        const velg = e.target.closest('[data-boost-velg]');
        if (velg) { try { await api('kursboost.php', {handling: 'velg', id, bilde: velg.dataset.boostVelg}); arkKursboost(id); } catch (err) { N.toast(esc(err.message)); } return; }
        if (e.target.closest('[data-boost-bilder]')) {
          if (!await N.bekreft('Lag bildeforslag?', 'Dette bruker AI-budsjettet.', 'Lag bilder')) return;
          try { await api('kursboost.php', {handling: 'bilder', id}); arkKursboost(id); } catch (err) { N.toast(esc(err.message)); } return;
        }
        if (e.target.closest('[data-boost-bibliotek]')) {
          const boks = inn.querySelector('[data-boost-arkiv]'); boks.hidden = !boks.hidden;
          if (!boks.hidden && !boks.dataset.lest) {
            try { const d = await api('bilder.php'); boks.dataset.lest = '1'; boks.innerHTML = [...(d.egne || []), ...(d.medfolgende || [])].map(r => `<button class="knapp liten" data-boost-velg="${esc(r.url)}"><img src="/${esc(String(r.mini || r.url).replace(/^\/+/, ''))}" alt="" style="width:80px;height:60px;object-fit:cover"></button>`).join(''); }
            catch (err) { boks.innerHTML = `<p class="feil">${esc(err.message)}</p>`; }
          }
          return;
        }
        const b = e.target.closest('[data-boost-del]'); if (!b) return;
        const del = b.dataset.boostDel, tekst = inn.querySelector(`[data-boost-tekst="${del}"]`)?.value.trim() || '';
        if (!tekst) { N.toast('Teksten er tom.'); return; }
        if (del === 'medlemmer') {
          sessionStorage.setItem('lissom-boost-medlem:' + id, tekst);
          N.lukkArk(true); N.gaTil('beskjeder', {boost: id}); return;
        }
        if (!await N.bekreft(del === 'nyhetsbrev' ? 'Lag nyhetsbrevutkast?' : 'Publiser denne delen?', del === 'nyhetsbrev' ? 'Teksten lagres som utkast. Ingen e-post sendes nå.' : 'Teksten og det valgte bildet publiseres nå.', 'Bekreft')) return;
        try { const r = await api('kursboost.php', {handling: 'del', id, del, tekst}); N.toast(esc(r.beskjed || 'Utført.'));
          const utkastId = r.pakke?.deler?.nyhetsbrev?.gjort?.utkastId;
          if (del === 'nyhetsbrev' && utkastId) N.gaTil('beskjeder', {utkast: utkastId}); else arkKursboost(id);
        } catch (err) { N.toast(esc(err.message)); }
      };
    } catch (e) { N.toast(esc(e.message)); }
  }

  async function bryter(nokkel) {
    const info = {'Vis/autosvar': ['Automatiske svar på kommentarer', false], 'Vis/salgsuke': ['Kampanje på forsiden', true], 'Banner/pa': ['Banner under toppbildet', true]}[nokkel];
    if (!info) return;
    const paa = innhold[nokkel] === undefined ? info[1] : innhold[nokkel] === 'ja';
    if (!await N.bekreft('Endre synlighet?', (paa ? 'Slå av ' : 'Slå på ') + info[0] + '. Endringen gjelder straks.', 'Bekreft')) return;
    try { const r = await api('innhold.php', {endringer: {[nokkel]: paa ? 'nei' : 'ja'}}); N.toast(esc(r.beskjed || 'Lagret.')); await oppdater(); }
    catch (e) { N.toast(esc(e.message)); }
  }
  async function tegn(side) {
    el = side; el.innerHTML = '<p class="laster">Henter markedsføring …</p>';
    try { await oppdater(); } catch (e) { el.innerHTML = `<p class="feil">${esc(e.message)}</p>`; }
  }

  function nyttUtkast() {
    const inn = N.apneArk(`${N.arkHode('Lag nytt utkast')}<form><label class="felt"><small>Type</small><select name="handling"><option value="artikkel">Artikkel</option><option value="sosialt">Sosialt innlegg</option><option value="nyhetsbrev">Nyhetsbrev</option></select></label>
      <label class="felt" data-ai-tema><small>Tema for artikkel eller innlegg</small><input name="emne" required maxlength="191"></label>
      <label class="felt" data-ai-brev hidden><small>Type nyhetsbrev (bygges på kommende kurs)</small><select name="slag"><option value="maned">Månedens nyhetsbrev</option><option value="host">Høstnyhetsbrev</option><option value="jul">Julekampanje</option><option value="medlem">Medlemsbrev</option></select></label>
      <label class="felt"><small>Kanal for sosialt innlegg</small><select name="kanal"><option>Instagram</option><option>Facebook</option><option>TikTok</option><option>LinkedIn</option></select></label>
      <label class="felt"><small>Format for sosialt innlegg</small><select name="form"><option value="innlegg">Innlegg</option><option value="story">Story</option><option value="reels">Reels</option><option value="karusell">Karusell</option></select></label>
      <div class="ark-fot"><button class="knapp" type="button" data-lukk>Avbryt</button><button class="knapp hoved" type="submit">Lag utkast med AI</button></div></form>`);
    inn.querySelector('[name=handling]').onchange = e => {
      const brev = e.target.value === 'nyhetsbrev';
      inn.querySelector('[data-ai-tema]').hidden = brev; inn.querySelector('[data-ai-brev]').hidden = !brev;
      inn.querySelector('[name=emne]').required = !brev;
    };
    inn.querySelector('form').onsubmit = async e => {
      e.preventDefault(); const f = e.target.elements, handling = f.handling.value, emne = f.emne.value.trim();
      if ((handling !== 'nyhetsbrev' && !emne) || !await N.bekreft('Lag AI-utkast?', 'Dette bruker AI-kvoten og lager et utkast som må leses før bruk.', 'Lag utkast')) return;
      try { const r = await api('ai.php', {handling, emne, om: emne, kanal: f.kanal.value, form: f.form.value, slag: f.slag.value}); N.toast(esc(r.beskjed || 'Utkastet er klart.')); await oppdater(); arkUtkast(r.id); }
      catch (err) { N.toast(esc(err.message)); }
    };
  }

  async function arkUtkast(id) {
    try {
      const u = (await api('marked.php?utkast=' + id)).utkast;
      if (u.type === 'kursboost') { arkKursboost(id); return; }
      const inn = N.apneArk(`${N.arkHode(esc(u.tittel))}<p class="muted">${esc([u.type, u.status, u.kontekst].filter(Boolean).join(' · '))}</p>
        <form><label class="felt"><small>Tittel</small><input name="tittel" value="${esc(u.tittel)}" required></label><label class="felt"><small>Tekst</small><textarea name="tekst" rows="16" required>${esc(u.tekst)}</textarea></label>
        <div class="ark-fot"><button class="knapp" type="button" data-lukk>Lukk</button>${['utkast', 'godkjent'].includes(u.status) ? '<button class="knapp" type="submit">Lagre tekst</button>' : ''}
        ${u.status === 'utkast' ? `${knapp('Legg bort', 'forkast', id)}${knapp('Godkjenn', 'godkjenn', id)}${['artikkel', 'seo'].includes(u.type) ? knapp('Publiser artikkel nå', 'publiserny', id) : ''}` : ''}
        ${u.status === 'godkjent' && ['artikkel', 'seo'].includes(u.type) ? knapp('Publiser artikkel', 'publiser', id) : ''}
        ${u.status === 'godkjent' && u.type === 'sosialt' && ['Instagram', 'Facebook'].includes(u.kanal) ? knapp('Publiser på ' + esc(u.kanal), 'metapubliser', id) : ''}
        ${u.status === 'godkjent' && u.type === 'nyhetsbrev' ? knapp('Bruk i medlemsmelding', 'brev', id) : ''}</div></form>`, {bred: true});
      inn.querySelector('form').onsubmit = async e => {
        e.preventDefault();
        try { const r = await api('ai.php', {handling: 'endre', id, tittel: e.target.elements.tittel.value, tekst: e.target.elements.tekst.value}); N.toast(esc(r.beskjed)); await oppdater(); arkUtkast(id); }
        catch (err) { N.toast(esc(err.message)); }
      };
      inn.onclick = async e => {
        const b = e.target.closest('[data-marked]'); if (!b) return;
        const h = b.dataset.marked;
        if (h !== 'forkast' && (inn.querySelector('[name=tittel]').value !== u.tittel || inn.querySelector('[name=tekst]').value !== u.tekst)) {
          N.toast('Lagre endringene i utkastet før du bruker det.'); return;
        }
        if (h === 'brev') { N.lukkArk(true); N.gaTil('beskjeder', {utkast: id}); return; }
        const tekst = h === 'metapubliser' ? 'Dette publiserer innlegget på ' + u.kanal + ' nå.' : h === 'publiser' || h === 'publiserny' ? 'Artikkelen blir synlig på nettsiden nå.' : h === 'godkjenn' ? 'Utkastet tas i bruk. En artikkel blir lagret som kladd.' : 'Utkastet legges bort.';
        if (!await N.bekreft('Bekreft handlingen?', tekst, h === 'forkast' ? 'Legg bort' : 'Bekreft')) return;
        try { const r = h === 'metapubliser' ? await api('meta.php', {handling: 'publiser', utkastId: id, kanal: u.kanal})
          : await api('ai.php', {handling: h === 'publiserny' ? 'godkjenn' : h, id, ...(h === 'publiserny' ? {publiser: true} : {})});
          N.toast(esc(r.beskjed || 'Lagret.')); await oppdater(); }
        catch (err) { N.toast(esc(err.message)); }
      };
    } catch (e) { N.toast(esc(e.message)); }
  }

  function sokeord(s = {}) {
    const inn = N.apneArk(`${N.arkHode(s.id ? 'Rediger søkeord' : 'Nytt søkeord')}<form><label class="felt"><small>Søkeord</small><input name="ord" value="${esc(s.ord || '')}" required></label><label class="felt"><small>Målside</small><input name="maalside" value="${esc(s.maalside || '')}"></label><label class="felt"><small>Notat</small><textarea name="notat">${esc(s.notat || '')}</textarea></label><div class="ark-fot"><button class="knapp" type="button" data-lukk>Avbryt</button>${s.id ? knapp('Fjern', 'sokeordfjern', s.id) : ''}<button class="knapp hoved" type="submit">Lagre</button></div></form>`);
    inn.querySelector('form').onsubmit = async e => {
      e.preventDefault(); const f = e.target.elements;
      try { const r = await api('marked.php', {handling: 'sokeord', id: s.id || 0, ord: f.ord.value, maalside: f.maalside.value, notat: f.notat.value}); N.lukkArk(true); N.toast(esc(r.beskjed)); await oppdater(); }
      catch (err) { N.toast(esc(err.message)); }
    };
    inn.onclick = async e => {
      if (!e.target.closest('[data-marked="sokeordfjern"]')) return;
      if (!await N.bekreft('Fjern søkeordet?', 'Søkeordet fjernes fra listen.', 'Fjern')) return;
      try { const r = await api('marked.php', {handling: 'sokeord', id: s.id, fjern: true}); N.toast(esc(r.beskjed)); await oppdater(); }
      catch (err) { N.toast(esc(err.message)); }
    };
  }

  function oppsett() {
    const i = data.innstillinger || {};
    const navn = {gaId: 'Google Analytics ID', gtmId: 'Google Tag Manager ID', metaPiksel: 'Meta piksel ID', aiTak: 'AI-tak i kroner', googleBedrift: 'Google bedriftsprofil'};
    const inn = N.apneArk(`${N.arkHode('Markedsoppsett')}<form>${Object.entries(navn).map(([k, t]) => `<label class="felt"><small>${t}</small><input name="${k}" value="${esc(i[k] || '')}"></label>`).join('')}<div class="ark-fot"><button class="knapp" type="button" data-lukk>Avbryt</button><button class="knapp hoved" type="submit">Lagre</button></div></form>`);
    inn.querySelector('form').onsubmit = async e => {
      e.preventDefault(); const endring = {handling: 'innstilling'};
      for (const k of Object.keys(navn)) if (e.target.elements[k].value !== String(i[k] || '')) endring[k] = e.target.elements[k].value;
      if (Object.keys(endring).length === 1) { N.toast('Ingen endringer å lagre.'); return; }
      try { const r = await api('marked.php', endring); N.lukkArk(true); N.toast(esc(r.beskjed)); await oppdater(); }
      catch (err) { N.toast(esc(err.message)); }
    };
  }

  document.addEventListener('click', e => {
    const b = e.target.closest('#arbeid [data-marked]'); if (!b || !el?.isConnected) return;
    const h = b.dataset.marked, id = Number(b.dataset.id);
    if (h === 'kampanjer') N.gaTil('kampanjer');
    else if (h === 'beskjed') N.gaTil('beskjeder');
    else if (h === 'nyutkast') nyttUtkast();
    else if (h === 'utkast') arkUtkast(id);
    else if (h === 'sokeordny') sokeord();
    else if (h === 'sokeord') sokeord((data.sokeord || []).find(x => Number(x.id) === id));
    else if (h === 'oppsett') oppsett();
    else if (h === 'kurs') N.gaTil('kurs', {kurs: id});
    else if (h === 'seo-fyll') fyllSeo(false);
    else if (h === 'geo-fyll') fyllSeo(true);
    else if (h === 'seo-sider') el.querySelector('[data-marked="seo-side"]')?.click();
    else if (h === 'seo-side') arkSeoSide(b.dataset.id);
    else if (h === 'bryter') bryter(b.dataset.id);
    else if (h === 'boost') lagBoost(id);
  });
  N.registrerSide('marked', {tittel: 'Markedsføring', tegn, mobil: true});
})();
