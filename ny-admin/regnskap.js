/* Regnskapsførerens avgrensede sider i gjeldende admin. Tilgang kontrolleres også i hvert API. */
(() => {
  'use strict';
  const N = window.NA;
  const esc = N.esc;
  const kr = N.kr;
  let maaned = new Date().toLocaleDateString('sv-SE', {timeZone: 'Europe/Oslo'}).slice(0, 7);
  const tom = '<p class="tom">Ingen registreringer i perioden.</p>';
  const hode = (t, under) => `<div class="head"><div><div class="eyebrow">Lissom · regnskap</div><h1>${t}</h1><p class="muted">${under}</p></div></div>`;
  const periode = () => `<label class="felt"><small>Måned</small><input type="month" data-regnskap-maaned value="${esc(maaned)}" aria-label="Regnskapsmåned"></label>`;
  const csv = (sti, navn) => `<a class="knapp" href="/api/admin/${sti}">${navn}</a>`;
  const rad = (navn, tekst, belop) => `<div class="rad"><span class="tekst"><b>${esc(navn)}</b><small>${esc(tekst || '')}</small></span>${belop === undefined ? '' : `<strong>${esc(belop)}</strong>`}</div>`;
  const feil = (el, e) => { el.innerHTML = `<p class="feil">${esc(e.message || 'Kunne ikke hente regnskapet.')}</p>`; };

  async function regnskap(el) {
    try {
      const [ok, dag] = await Promise.all([N.api('okonomi.php'), N.api('dagsoppgjor.php?maaned=' + encodeURIComponent(maaned))]);
      el.innerHTML = hode('Regnskap og dagsoppgjør', 'Bilag fra registrerte betalinger.')
        + `<div class="knapper">${periode()}${csv('dagsoppgjor.php?maaned=' + encodeURIComponent(maaned) + '&csv=ja', 'Last ned dagsoppgjør')}${csv('transaksjoner.php?maaned=' + encodeURIComponent(maaned), 'Last ned transaksjoner')}<button class="knapp" data-regnskap-rapport>Månedsrapport</button><button class="knapp" data-regnskap-oppsett>Kontoer og mva</button></div>`
        + `<div class="grid"><section class="kort"><small>Omsetning · ${esc(ok.maaned)}</small><h2>${esc(ok.omsetningEks)}</h2><small>Uten mva</small></section><section class="kort"><small>Mva · ${esc(ok.maaned)}</small><h2>${esc(ok.mva)}</h2></section><section class="kort"><small>Innbetalt · ${esc(ok.maaned)}</small><h2>${esc(ok.omsetning)}</h2></section></div>`
        + `<p class="muted">${esc(dag.utenfor || '')}</p>${(dag.manglerKonto || []).length ? '<p class="feil">Kontoer mangler i regnskapsoppsettet. Kontroller før eksport.</p>' : ''}`
        + `<h2>Dagsoppgjør · ${esc(maaned)}</h2>`
        + ((dag.bilag || []).map(b => `<section class="kort"><h3>${esc(b.dato || b.dag || 'Dagsoppgjør')}</h3><p>${kr(b.sumOre)}</p>${(b.linjer || []).map(l => rad(l.hva, 'Konto ' + (l.konto || 'mangler'), kr(l.belopOre))).join('')}${(b.inn || []).map(i => rad('Innbetalt · ' + i.maate, 'Konto ' + (i.konto || 'mangler'), kr(i.belopOre))).join('')}<p>${b.balanse ? 'I balanse' : 'Kontroller balansen'}</p></section>`).join('') || tom);
      el.querySelector('[data-regnskap-maaned]').onchange = e => { if (/^\d{4}-\d{2}$/.test(e.target.value)) { maaned = e.target.value; regnskap(el); } };
      el.querySelector('[data-regnskap-rapport]').onclick = async () => {
        try {
          const r = await N.api('manedsrapport.php?maaned=' + encodeURIComponent(maaned));
          N.apneArk(`${N.arkHode('Månedsrapport · ' + esc(r.navn))}<div class="radliste">${(r.kilder || []).map(k => rad(k.navn, 'Uten mva ' + kr(k.eksOre) + ' · mva ' + kr(k.mvaOre), kr(k.bruttoOre))).join('')}${rad('Omsetning', 'Uten mva ' + kr(r.sumEksOre) + ' · mva ' + kr(r.mvaOre), kr(r.sumBruttoOre))}</div><p class="muted">Bekreftede betalinger minus refusjoner. Oversikten er ikke et regnskapsbilag.</p><div class="ark-fot"><button class="knapp" data-lukk>Lukk</button></div>`);
        } catch (e) { N.toast(esc(e.message)); }
      };
      el.querySelector('[data-regnskap-oppsett]').onclick = () => oppsett(dag.oppsett, el);
    } catch (e) { feil(el, e); }
  }

  function oppsett(verdier, el) {
    const felt = Object.entries(verdier || {}).map(([navn, verdi]) => `<label class="felt"><small>${esc(navn.replace(/^regnskap_/, '').replaceAll('_', ' '))}</small><input name="${esc(navn)}" value="${esc(verdi)}" inputmode="numeric"></label>`).join('');
    const inn = N.apneArk(`${N.arkHode('Kontoer og mva')}<form id="regnskap-oppsett"><div class="grid">${felt}</div><div class="ark-fot"><button class="knapp" type="button" data-lukk>Avbryt</button><button class="knapp hoved" type="submit">Lagre oppsett</button></div></form>`);
    inn.querySelector('form').onsubmit = async e => {
      e.preventDefault();
      const data = Object.fromEntries(new FormData(e.target));
      if (!await N.bekreft('Lagre regnskapsoppsett?', 'Endre kontoer og mva-koder i dagsoppgjør og eksport.', 'Lagre')) { oppsett(data, el); return; }
      try {
        await N.api('dagsoppgjor.php', {data});
        N.lukkArk(true); await regnskap(el);
      } catch (err) { N.toast(esc(err.message)); }
    };
  }

  async function betalinger(el) {
    try {
      const booking = Number(N.params().get('booking')) || 0;
      const d = await N.api('betalinger.php' + (booking > 0 ? '?bookingId=' + booking : ''));
      el.innerHTML = hode('Betalinger', 'Registrerte innbetalinger og refusjoner.')
        + (booking > 0 ? `<p class="muted">Betalinger for påmelding ${booking}</p><button class="knapp liten" data-regnskap-alle>Vis alle betalinger</button>` : '')
        + '<label class="felt"><small>Søk betaling</small><input type="search" data-regnskap-sok placeholder="Navn, formål eller beløp"></label><div data-regnskap-liste></div>';
      const liste = el.querySelector('[data-regnskap-liste]');
      const vis = q => {
        const treff = (d.betalinger || []).filter(p => [p.medlem, p.formal, p.belop, p.maate, p.referanse].some(v => String(v || '').toLocaleLowerCase('nb-NO').includes(q)));
        liste.innerHTML = treff.map(p => `<div class="rad"><span class="tekst"><b>${esc(p.medlem || p.formal || 'Betaling')}</b><small>${esc([p.tidspunkt, p.maate, p.formal, p.kurs, p.kursDato, p.status, p.refundert ? 'Refundert ' + p.refundert : ''].filter(Boolean).join(' · '))}</small></span><strong>${esc(p.belop)}</strong>${!N.meg?.regnskap && p.referanse && ((p.kanRefunderes && p.belopOre > p.refundertOre) || p.bookingId || p.ordreId) ? `<button class="knapp liten" type="button" data-regnskap-betaling="${p.id}">Åpne</button>` : ''}</div>`).join('') || tom;
      };
      vis('');
      el.querySelector('[data-regnskap-sok]').oninput = e => vis(e.target.value.trim().toLocaleLowerCase('nb-NO'));
      el.querySelector('[data-regnskap-alle]')?.addEventListener('click', () => N.gaTil('betalinger'));
      liste.onclick = e => {
        const id = Number(e.target.closest('[data-regnskap-betaling]')?.dataset.regnskapBetaling);
        const p = (d.betalinger || []).find(r => r.id === id);
        if (p) arkBetaling(p, el);
      };
    } catch (e) { feil(el, e); }
  }

  function refusjonsnokkel(referanse, belop) {
    const navn = 'lissom-refund:' + referanse + ':' + belop;
    let id = sessionStorage.getItem(navn);
    if (!id) { id = crypto.randomUUID(); sessionStorage.setItem(navn, id); }
    return {navn, id};
  }

  function arkBetaling(p, el) {
    const igjen = Math.max(0, p.belopOre - p.refundertOre);
    const inn = N.apneArk(`${N.arkHode(esc(p.medlem || p.formal || 'Betaling'))}
      <p>${esc([p.tidspunkt, p.maate, p.formal, p.kurs, p.kursDato, p.status].filter(Boolean).join(' · '))}</p>
      <p>Betalt: ${esc(p.belop)}${p.refundert ? ' · Refundert: ' + esc(p.refundert) : ''}</p>
      <div class="ark-fot"><button class="knapp" type="button" data-lukk>Lukk</button>
        ${p.referanse && (p.bookingId || p.ordreId) ? '<button class="knapp" type="button" data-kvittering>Send kvittering på nytt</button>' : ''}
        ${p.referanse && p.kanRefunderes && igjen > 0 ? '<button class="knapp rod" type="button" data-refunder>Refunder</button>' : ''}</div>`);
    inn.querySelector('[data-kvittering]')?.addEventListener('click', async () => {
      if (!await N.bekreft('Send kvittering?', 'Send kvitteringen for ' + p.belop + ' på nytt.', 'Send')) return;
      try { const r = await N.api('betalinger.php', {data: {handling: 'kvittering', referanse: p.referanse}}); N.lukkArk(true); N.toast(esc(r.beskjed || 'Kvittering sendt.')); }
      catch (e) { N.toast(esc(e.message)); }
    });
    inn.querySelector('[data-refunder]')?.addEventListener('click', () => {
      const form = N.apneArk(`${N.arkHode('Refunder betaling')}<p>Gjenstår: ${kr(igjen)}</p>
        <form data-refusjon><label class="felt"><small>Delbeløp i hele kroner (tomt = alt)</small><input name="belop" type="number" min="1" max="${Math.floor(igjen / 100)}" step="1"></label>
        <div class="ark-fot"><button class="knapp" type="button" data-lukk>Avbryt</button><button class="knapp hoved" type="submit">Refunder</button></div></form>`);
      form.querySelector('form').onsubmit = async e => {
        e.preventDefault();
        const belop = e.target.elements.belop.value.trim() === '' ? 0 : Number(e.target.elements.belop.value);
        if (belop !== 0 && (!Number.isInteger(belop) || belop < 1 || belop * 100 > igjen)) { N.toast('Ugyldig beløp.'); return; }
        if (!await N.bekreft('Refunder betalingen?', 'Betal tilbake ' + kr(belop ? belop * 100 : igjen) + ' gjennom Vipps.', 'Refunder')) return;
        const knapp = e.target.querySelector('[type=submit]');
        knapp.disabled = true;
        try {
          const nokkel = refusjonsnokkel(p.referanse, belop);
          const r = await N.api('betalinger.php', {data: {referanse: p.referanse, belop, operasjonId: nokkel.id}});
          sessionStorage.removeItem(nokkel.navn);
          N.lukkArk(true);
          N.toast(esc(r.merknad || 'Refusjonen er registrert.'));
          await betalinger(el);
        } catch (err) { N.toast(esc(err.message)); knapp.disabled = false; }
      };
    });
  }

  async function timeliste(el) {
    try {
      const d = await N.api('timeliste.php?periode=' + encodeURIComponent(maaned));
      el.innerHTML = hode('Timeliste', 'Lønnede kursholdertimer og status for bekreftelse.')
        + `<div class="knapper">${periode()}${csv('timeliste.php?periode=' + encodeURIComponent(maaned) + '&format=csv', 'Last ned timeliste')}</div>`
        + `<h2>Summer</h2>${(d.summer || []).map(s => rad(s.navn, s.timer + ' timer' + (s.ubekreftet ? ' · ' + s.ubekreftet + ' ubekreftet' : ''), s.sum)).join('') || tom}`
        + `<h2>Registrerte timer</h2>${(d.rader || []).map(r => rad(r.navn, [r.dato, r.timer + ' timer', r.hva, r.bekreftet ? 'Bekreftet' : 'Ikke bekreftet'].join(' · '), r.sum || 'Timesats mangler')).join('') || tom}`;
      el.querySelector('[data-regnskap-maaned]').onchange = e => { if (/^\d{4}-\d{2}$/.test(e.target.value)) { maaned = e.target.value; timeliste(el); } };
    } catch (e) { feil(el, e); }
  }

  N.registrerSide('regnskap', {tittel: 'Regnskap', tegn: regnskap, mobil: true});
  N.registrerSide('betalinger', {tittel: 'Betalinger', tegn: betalinger, mobil: true});
  N.registrerSide('timeliste', {tittel: 'Timeliste', tegn: timeliste, mobil: true});
})();
