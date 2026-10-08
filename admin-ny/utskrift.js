// Utskrifter flyttet fra gammel admin (lissom-2108.html) inn i arbeidsrommet, 8. oktober 2026:
// månedsrapporten på Penger (lagRapport), den faste Vipps-koden til disken i kassa (utFastLag/utFastSkrivUt)
// og «Skriv ut dagen» i kalenderen (klSkrivUt). Utskriften går gjennom en skjult ramme, så ingen
// sprettoppvindu kan bli blokkert, og bare arket skrives ut — ikke menyen og resten av siden.
import {el,api,button,sheet,form,field,toast,today,date,money} from './ui.js';
import {erOkt} from './kalender-ark.js';

const esc=t=>String(t??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]);

/** Skriver ut et eget, lite HTML-ark. */
export function skrivUt(tittel,kropp,stil=''){
 const ramme=el('iframe',{title:tittel,'aria-hidden':'true',tabindex:'-1',style:'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden'});
 ramme.srcdoc='<!doctype html><html lang="nb"><head><meta charset="utf-8"><title>'+esc(tittel)+'</title><style>'
  +'body{margin:0;padding:32px;font-family:Georgia,serif;color:#4D1D12}table{width:100%;border-collapse:collapse;font-size:14px}'
  +'th,td{padding:8px 6px;border-bottom:1px solid #E8DCC8;text-align:left;vertical-align:top}th{font-size:12px;letter-spacing:.06em}'
  +'.tall{text-align:right;white-space:nowrap}.sum td{font-weight:700;border-top:2px solid #4D1D12}small,.liten{font-size:11px;color:#8a6f5f}'
  +'@page{margin:14mm}'+stil+'</style></head><body>'+kropp+'</body></html>';
 ramme.onload=()=>{try{ramme.contentWindow.focus();ramme.contentWindow.print();}catch(e){toast('Fikk ikke skrevet ut.');}setTimeout(()=>ramme.remove(),60000);};
 document.body.append(ramme);
}

// ── Månedsrapport (Penger) ──────────────────────────────────────────
export function manedsrapport(){
 const naa=today().slice(0,7);
 form('Månedsrapport',[field('maaned','Måned','month',{required:true,max:naa})],{maaned:naa},async v=>{
  if(!/^\d{4}-\d{2}$/.test(v.maaned||''))throw Error('Velg en måned.');
  const r=await api('manedsrapport.php?maaned='+encodeURIComponent(v.maaned));
  visRapport(r);
 },{submitLabel:'Vis rapporten',successText:false});
}
function rapportHtml(r){
 const rad=(n,k,sterk)=>'<tr'+(sterk?' class="sum"':'')+'><td>'+esc(n)+'</td><td class="tall">'+esc(money(k.eksOre))+'</td><td class="tall">'+esc(money(k.mvaOre))+'</td><td class="tall">'+esc(money(k.bruttoOre))+'</td></tr>';
 return '<div class="liten" style="letter-spacing:.14em">LISSOM · MÅNEDSRAPPORT</div>'
  +'<h1 style="font-size:26px;margin:10px 0 24px">'+esc(r.navn)+'</h1>'
  +'<table><thead><tr><th>Kilde</th><th class="tall">Uten mva</th><th class="tall">Mva</th><th class="tall">Med mva</th></tr></thead><tbody>'
  +(r.kilder||[]).map(k=>rad(k.navn,k)).join('')
  +rad('Omsetning',{eksOre:r.sumEksOre,mvaOre:r.mvaOre,bruttoOre:r.sumBruttoOre},true)
  +'</tbody></table>'
  +(r.sumBruttoOre===0&&r.sumEksOre===0?'<p class="liten">Det er ikke registrert betalinger i denne måneden.</p>':'')
  +'<p class="liten" style="margin-top:24px">Generert '+esc(new Date().toLocaleDateString('nb-NO'))+' · Lissom Keramikk &amp; Håndverk AS · Org.nr. 938 280 819 MVA<br>'
  +'Tallene er bekreftede betalinger i perioden, minus refusjoner. Rapporten er en oversikt, ikke et regnskapsbilag.</p>';
}
function visRapport(r){
 const celle=(t,kl='')=>el('td',{class:kl,text:t});
 const tabell=el('table',{class:'rapport-tabell',style:'width:100%;border-collapse:collapse'},
  el('thead',{},el('tr',{},el('th',{text:'Kilde',style:'text-align:left'}),el('th',{text:'Uten mva',style:'text-align:right'}),el('th',{text:'Mva',style:'text-align:right'}),el('th',{text:'Med mva',style:'text-align:right'}))),
  el('tbody',{},(r.kilder||[]).map(k=>el('tr',{},celle(k.navn),...[k.eksOre,k.mvaOre,k.bruttoOre].map(o=>el('td',{style:'text-align:right;white-space:nowrap',text:money(o)})))),
   el('tr',{},el('td',{},el('strong',{text:'Omsetning'})),...[r.sumEksOre,r.mvaOre,r.sumBruttoOre].map(o=>el('td',{style:'text-align:right;white-space:nowrap'},el('strong',{text:money(o)}))))));
 let s;
 s=sheet('Månedsrapport · '+r.navn,el('div',{},tabell,
  el('p',{class:'muted',text:'Bekreftede betalinger i perioden, minus refusjoner. Rapporten er en oversikt, ikke et regnskapsbilag.'}),
  el('div',{class:'sheet-footer'},button('Lukk',()=>s.close()),button('Skriv ut',()=>skrivUt('Månedsrapport '+r.navn,rapportHtml(r)),'primary'))));
}

// ── Fast Vipps-kode til disken (kassa) ──────────────────────────────
// Én kode som henges opp. Den peker på /betal, der kunden velger hva det gjelder og skriver beløpet selv.
async function qrLast(){
 if(window.qrcode)return window.qrcode;
 await new Promise((ja,nei)=>{const s=el('script',{src:'/vendor/qrcode-2.0.4.js'});s.onload=ja;s.onerror=()=>nei(Error('QR-biblioteket kunne ikke lastes.'));document.head.append(s);});
 return window.qrcode;
}
export async function fastKode(){
 const adresse=location.origin+'/betal';
 let bilde;
 try{const lag=await qrLast();const q=lag(0,'M');q.addData(adresse);q.make();bilde=q.createDataURL(8,16);}catch(e){toast(e.message||'Fikk ikke tegnet koden.');return;}
 const skriv=()=>skrivUt('Betal hos Lissom',
  '<div style="text-align:center;padding-top:24px;font-family:system-ui,sans-serif;color:#2e1002">'
  +'<h1 style="font-size:28px;margin:0 0 8px">Betal med Vipps</h1>'
  +'<p style="margin:0 0 28px;font-size:16px;color:#6b5a50">Skann koden med kameraet på telefonen.</p>'
  +'<img src="'+bilde+'" alt="" style="width:min(420px,90vw);image-rendering:pixelated">'
  +'<p style="margin-top:24px;font-size:14px;color:#6b5a50">'+esc(location.host+'/betal')+'</p></div>');
 let s;
 s=sheet('Fast kode til disken',el('div',{},
  el('p',{text:'Én kode du henger opp. Kunden skriver beløpet selv på '+location.host+'/betal'}),
  el('img',{src:bilde,alt:'QR-kode til '+location.host+'/betal',style:'max-width:280px;width:100%;display:block;margin:16px auto;image-rendering:pixelated'}),
  el('div',{class:'sheet-footer'},button('Lukk',()=>s.close()),button('Skriv ut',skriv,'primary'))));
}

// ── Skriv ut dagen (kalender) ───────────────────────────────────────
export async function skrivUtDag(dag=today()){
 let l;try{l=((await api(`kalender.php?fra=${dag}&til=${dag}`)).hendelser||[]).filter(e=>e.dato===dag&&erOkt(e));}catch(e){toast(e.message);return;}
 l.sort((a,b)=>String(a.tid).localeCompare(String(b.tid)));
 const navn=date(dag).toLocaleDateString('nb-NO',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
 const okt=e=>'<section style="margin:0 0 22px;break-inside:avoid">'
  +'<h2 style="font-size:18px;margin:0 0 4px">'+esc((e.tid||'')+(e.slutt?'–'+e.slutt:''))+' '+esc(e.tittel)+(e.avlyst?' (avlyst)':'')+'</h2>'
  +'<div class="liten">'+esc([`${e.pameldt||0}${e.kap?' av '+e.kap:''} påmeldt${(e.pameldt||0)===1?'':'e'}`,e.holder||'Ikke tildelt'].join(' · '))+'</div>'
  +((e.deltakere||[]).length
   ?'<table style="margin-top:8px"><tbody>'+e.deltakere.map(p=>'<tr><td style="width:24px">☐</td><td>'+esc(p.navn)+(p.antall>1?' ('+esc(p.antall)+')':'')+'</td><td>'+esc(p.status==='Ikke betalt'?'Ikke betalt':'')+'</td><td>'+esc(p.merknad||'')+'</td></tr>').join('')+'</tbody></table>'
   :'<p class="liten">Ingen påmeldte.</p>')
  +'</section>';
 const t=navn.charAt(0).toUpperCase()+navn.slice(1);
 skrivUt('Dagsliste '+dag,'<div class="liten" style="letter-spacing:.14em">LISSOM · DAGSLISTE</div><h1 style="font-size:24px;margin:8px 0 22px">'+esc(t)+'</h1>'
  +(l.length?l.map(okt).join(''):'<p>Ingen kurs denne dagen.</p>'));
}
