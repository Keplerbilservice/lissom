/* «⚡ Test Vipps» og «⚑ Meld inn feil» i admin-ny — samme kall og samme tekster som gamle admin
   (testVipps() og feilSend() i lissom-2108.html). Ingen nytt API: /api/vipps-test.php og /api/feil.php.
   Vipps-sjekken oppretter en prøvebetaling på én krone og avbryter den med det samme (som før). Eieren 04.10. */
import{el,button,badge,sheet,toast}from'./ui.js';

let vippsJobber=false;
/** Gjør de ekte kallene mot Vipps og viser hva som virker. */
export async function testVipps(knapp){
 if(vippsJobber)return;
 vippsJobber=true;
 const forTekst=knapp?knapp.textContent:'';
 if(knapp){knapp.textContent='⚡ Sjekker Vipps …';knapp.disabled=true;}
 let v;
 try{
  const r=await fetch('/api/vipps-test.php',{credentials:'same-origin',cache:'no-store'});
  const d=await r.json();
  // Kommer det ikke en fasit, er svaret selv feilen — vis den.
  v=(!d||!d.kort)?{kort:[{hva:'Sjekken',ok:false,sier:(d&&(d.feil||d.beskjed))||'Serveren svarte ikke som ventet.'}],alt_ok:false}:d;
 }catch{v={kort:[{hva:'Sjekken',ok:false,sier:'Fikk ikke kontakt med serveren.'}],alt_ok:false};}
 vippsJobber=false;
 if(knapp){knapp.textContent=forTekst;knapp.disabled=false;}
 // Det rå svaret fra Vipps, når noe ikke gikk. Ingen nøkler ligger i det.
 const detalj=[(v.token||{}).svar||'',(v.betaling||{}).svar||'',(v.recurring||{}).svar||''].filter(Boolean).join('\n\n');
 const s=sheet(v.alt_ok?'Alt virker':'Noe mangler ennå',el('div',{},
  el('p',{class:'eyebrow',text:'Vipps'}),
  el('p',{text:v.alt_ok?'Kurs kan betales og medlemskap kan trekkes. Prøvebetalingen på én krone ble avbrutt med det samme — ingen penger er flyttet.':'Linjene under sier hva som er på plass og hva som ikke er det.'}),
  el('div',{class:'list'},(v.kort||[]).map(l=>el('div',{class:'row'},el('div',{},el('strong',{text:l.hva}),el('p',{text:l.sier})),badge(l.ok?'✓':'✕',l.ok?'good':'bad')))),
  detalj?el('div',{style:'margin-top:18px'},el('small',{text:'Det Vipps svarte, ord for ord:'}),el('pre',{text:detalj,style:'white-space:pre-wrap;overflow-wrap:anywhere;font-size:13px'})):null,
  el('div',{class:'sheet-footer'},button('Lukk',()=>s.close()),button('Sjekk på nytt',()=>{s.close();testVipps(knapp);},'primary'))));
}

/** Dialogen der admin melder inn en feil. Kaller ferdig() når meldingen er tatt imot. */
export function meldFeil(ferdig){
 let bilde='',sender=false;
 const tekst=el('textarea',{'aria-label':'Skriv hva du prøvde å gjøre, og hva som skjedde',placeholder:'Feks. «Jeg skulle velge betaling på workshop 2. september, men listen var tom.»'});
 const kontakt=el('input',{type:'text','aria-label':'E-post eller telefon, om du vil ha svar (valgfritt)',placeholder:'ola@epost.no'});
 const feil=el('p',{role:'alert',class:'notice error',hidden:true});
 const visFeil=t=>{feil.textContent=t;feil.hidden=!t;};
 const bildeBoks=el('div',{});
 function tegnBilde(){
  if(bilde){bildeBoks.replaceChildren(el('img',{src:bilde,alt:'Skjermbildet du har valgt',style:'display:block;width:100%;max-height:220px;object-fit:contain;border:1px solid var(--line);border-radius:9px'}),el('div',{class:'actions',style:'margin-top:8px'},button('Fjern bildet',()=>{bilde='';tegnBilde();})));return;}
  const fil=el('input',{type:'file',accept:'image/jpeg,image/png,image/webp','aria-label':'Velg et bilde fra maskinen eller telefonen',onchange:e=>{const f=e.target.files&&e.target.files[0];e.target.value='';if(!f)return;
   if(f.size>7*1024*1024){visFeil('Bildet er for stort. Ta gjerne et utsnitt av skjermen.');return;}
   const leser=new FileReader();leser.onload=()=>{bilde=String(leser.result||'');visFeil('');tegnBilde();};leser.onerror=()=>visFeil('Fikk ikke lest bildet.');leser.readAsDataURL(f);}});
  bildeBoks.replaceChildren(el('label',{class:'field'},'Velg et bilde fra maskinen eller telefonen',fil));
 }
 tegnBilde();
 const send=button('Send inn',async()=>{
  if(sender)return;
  const t=String(tekst.value||'').trim();
  if(!t){visFeil('Skriv litt om hva som gikk galt, så vet vi hvor vi skal lete.');return;}
  sender=true;send.textContent='Sender …';send.disabled=true;visFeil('');
  let d={};
  try{
   const r=await fetch('/api/feil.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({
    side:(location.pathname+location.search+location.hash).slice(0,300),skjerm:innerWidth+'×'+innerHeight,
    slag:'melding',melding:t.slice(0,2000),kontakt:String(kontakt.value||'').trim().slice(0,191),feiltekst:'',bilde})});
   d=await r.json();
  }catch{d={};}
  if(d&&d.ok){s.close();toast('Takk — feilen er meldt inn. Verkstedet får den sammen med hvilken side og nettleser du var på.');if(ferdig)ferdig();return;}
  sender=false;send.textContent='Send inn';send.disabled=false;
  visFeil((d&&d.feil)||'Fikk ikke sendt. Prøv igjen om litt.');
 },'primary');
 const s=sheet('Hva gikk galt?',el('div',{},
  el('p',{class:'eyebrow',text:'Meld inn feil'}),
  el('label',{class:'field'},'Skriv hva du prøvde å gjøre, og hva som skjedde',tekst),
  el('label',{class:'field',style:'margin-top:16px'},'E-post eller telefon, om du vil ha svar (valgfritt)',kontakt),
  el('div',{class:'field',style:'margin-top:16px'},el('span',{text:'Skjermbilde, om du har ett (valgfritt)'}),bildeBoks),
  el('p',{class:'muted',text:'Vi får automatisk med hvilken side du står på, nettleseren din og skjermstørrelsen. Ingenting annet.'}),
  feil,
  el('div',{class:'sheet-footer'},button('Avbryt',()=>s.close()),send)));
 tekst.focus();
}
