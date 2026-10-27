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
      const d = await N.api('betalinger.php');
      el.innerHTML = hode('Betalinger', 'Registrerte innbetalinger og refusjoner.')
        + '<label class="felt"><small>Søk betaling</small><input type="search" data-regnskap-sok placeholder="Navn, formål eller beløp"></label><div data-regnskap-liste></div>';
      const liste = el.querySelector('[data-regnskap-liste]');
      const vis = q => {
        const treff = (d.betalinger || []).filter(p => [p.medlem, p.formal, p.belop, p.maate, p.referanse].some(v => String(v || '').toLocaleLowerCase('nb-NO').includes(q)));
        liste.innerHTML = treff.map(p => rad(p.medlem || p.formal || 'Betaling', [p.tidspunkt, p.maate, p.formal, p.status, p.refundert ? 'Refundert ' + p.refundert : ''].filter(Boolean).join(' · '), p.belop)).join('') || tom;
      };
      vis('');
      el.querySelector('[data-regnskap-sok]').oninput = e => vis(e.target.value.trim().toLocaleLowerCase('nb-NO'));
    } catch (e) { feil(el, e); }
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
