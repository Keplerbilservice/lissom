/* Beskjeder med mottakervalg og faktisk e-postforhåndsvisning. */
(() => {
  'use strict';
  const N = window.NA, esc = N.esc;
  let side = null, kurs = [], planer = [], utkast = null, boost = 0;
  const api = (sti, data) => N.api(sti, data === undefined ? {} : {data});
  const felt = (navn, tekst, verdi = '', type = 'text') => `<label class="felt"><small>${tekst}</small>${type === 'textarea' ? `<textarea name="${navn}">${esc(verdi)}</textarea>` : `<input name="${navn}" type="${type}" value="${esc(verdi)}">`}</label>`;

  async function tegn(el) {
    side = el; el.innerHTML = '<p class="laster">Henter mottakerne …</p>';
    try {
      const [d, m] = await Promise.all([api('pameldte.php'), api('medlemmer.php')]);
      kurs = d.okter || []; planer = m.planer || [];
      const id = Number(N.params().get('utkast'));
      boost = Number(N.params().get('boost')) || 0;
      utkast = id > 0 ? (await api('marked.php?utkast=' + id)).utkast : null;
      if (boost > 0) {
        const p = (await api('kursboost.php?id=' + boost)).pakke;
        if (p.deler?.medlemmer?.gjort) throw Error('Denne kursboosten er allerede sendt til medlemmene.');
        utkast = {type: 'nyhetsbrev', tittel: p.epostEmne || 'Beskjed fra Lissom',
          tekst: sessionStorage.getItem('lissom-boost-medlem:' + boost) || p.deler?.medlemmer?.tekst || '', bilde: p.valgt || ''};
      }
      if (utkast && !['nyhetsbrev', 'medlemsbrev'].includes(utkast.type)) throw Error('Dette utkastet er ikke en e-post.');
      el.innerHTML = `<div class="head"><div><h1>Beskjeder</h1><p class="muted">Velg mottakere og kontroller e-posten før utsending.</p></div></div><button class="knapp hoved" data-beskjed-ny>Ny beskjed</button>`;
      if (utkast) skjema();
    } catch (e) { el.innerHTML = `<p class="feil">${esc(e.message)}</p>`; }
  }

  function skjema(tidligere = null) {
    const inn = N.apneArk(`${N.arkHode('Ny beskjed')}<form data-beskjed-skjema>${boost ? `<input type="hidden" name="kursboost" value="${boost}">` : ''}
      ${boost ? '<input type="hidden" name="til" value="medlemmer"><p>Mottakere: alle aktive medlemmer</p>' : `<label class="felt"><small>Mottakere</small><select name="til"><option value="medlemmer">Medlemmer</option><option value="okt">Deltakere på kursdato</option><option value="en">Én person</option></select></label>
      <label class="felt"><small>Kursdato</small><select name="oktId"><option value="">Velg dato</option>${kurs.map(o => `<option value="${o.oktId}">${esc(o.tittel)} · ${esc(o.naar)}</option>`).join('')}</select></label>
      <label class="felt"><small>Medlemsgruppe</small><select name="type"><option value="">Alle aktive</option><option value="prove">På prøve</option>${planer.map(p => `<option value="${esc(p.navn)}">${esc(p.navn)}</option>`).join('')}</select></label>`}
      ${felt('navn', 'Mottakerens navn')}${felt('epost', 'Mottakerens e-post', '', 'email')}${felt('telefon', 'Mottakerens telefon', '', 'tel')}
      ${felt('emne', 'Emne', utkast?.tittel || '')}${felt('tekst', 'Melding', utkast?.tekst || '', 'textarea')}
      ${felt('bilde', 'Bildeadresse', utkast?.bilde || '')}${felt('bildetekst', 'Bildetekst')}${felt('knappTekst', 'Knappetekst')}${felt('knappUrl', 'Knappens nettadresse', '', 'url')}
      <label><input name="ogsaaSms" type="checkbox"> Send også SMS</label><label><input name="kursbevis" type="checkbox"> Legg ved kursbevis</label>
      <div class="ark-fot"><button class="knapp" type="button" data-lukk>Avbryt</button><button class="knapp hoved" type="submit">Forhåndsvis</button></div></form>`, {bred: true});
    if (tidligere) for (const [k, verdi] of Object.entries(tidligere)) {
      const felt = inn.querySelector('form').elements.namedItem(k);
      if (felt) { if (felt.type === 'checkbox') felt.checked = verdi === 'ja'; else felt.value = verdi; }
    }
    inn.querySelector('form').onsubmit = async e => {
      e.preventDefault(); const f = e.target, v = Object.fromEntries(new FormData(f));
      v.ogsaaSms = f.elements.ogsaaSms.checked ? 'ja' : 'nei';
      v.kursbevis = f.elements.kursbevis.checked ? 'ja' : 'nei';
      if (!v.emne?.trim() || !v.tekst?.trim()) { N.toast('Skriv emne og melding.'); return; }
      if (v.til === 'en' && !v.epost && !(v.ogsaaSms === 'ja' && v.telefon)) { N.toast('Oppgi e-post eller telefon til mottakeren.'); return; }
      if (v.til === 'okt' && !v.oktId) { N.toast('Velg kursdato.'); return; }
      try { const p = await api('beskjed.php', {handling: 'forhandsvis', ...v}); forhåndsvis(v, p.html); }
      catch (err) { N.toast(esc(err.message)); }
    };
  }

  function forhåndsvis(v, html) {
    const hvem = v.til === 'en' ? v.epost || v.telefon : v.til === 'medlemmer' ? v.type || 'alle aktive medlemmer' : kurs.find(o => Number(o.oktId) === Number(v.oktId))?.tittel || 'kursdeltakere';
    const inn = N.apneArk(`${N.arkHode('Kontroller før utsending')}<p>Til: ${esc(hvem)}${v.ogsaaSms === 'ja' ? ' · også SMS' : ''}</p><iframe title="E-postforhåndsvisning" sandbox="" style="width:100%;height:480px;border:1px solid var(--line)"></iframe>
      <div class="ark-fot"><button class="knapp" type="button" data-beskjed-tilbake>Tilbake</button><button class="knapp hoved" type="button" data-beskjed-send>Send beskjed</button></div>`, {bred: true});
    inn.querySelector('iframe').srcdoc = html || '';
    inn.querySelector('[data-beskjed-tilbake]').onclick = () => skjema(v);
    inn.querySelector('[data-beskjed-send]').onclick = async () => {
      if (!await N.bekreft('Send beskjed?', 'Send «' + v.emne + '» til ' + hvem + (v.ogsaaSms === 'ja' ? ' og som SMS' : '') + '.', 'Send')) return;
      try { const r = await api('beskjed.php', v); if (boost) sessionStorage.removeItem('lissom-boost-medlem:' + boost); N.toast(esc(r.beskjed || 'Beskjeden er lagt i kø.')); N.lukkArk(true); }
      catch (err) { N.toast(esc(err.message)); }
    };
  }

  document.addEventListener('click', e => { if (e.target.closest('[data-beskjed-ny]') && side?.isConnected) { utkast = null; boost = 0; skjema(); } });
  N.registrerSide('beskjeder', {tittel: 'Beskjeder', tegn, mobil: true});
})();
