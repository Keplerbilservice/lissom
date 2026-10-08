// Paint on Pots, samlet (eieren 07.10 «ok, bygg det» og 08.10 «totalt nytt opplegg»).
// Innstillinger: åpningstid og ukedager (kurs.php ukeplan), besøkslengde, plassgrensen (ressursen «Paint on Pots»
// under Ressurser — lagres bare der), pris og betalingsvalg (vises). Unntak per dato: Åpen / Fullt / Ingen PoP /
// Andre tider, og «Fullt resten av dagen» på Oversikt. Dagsliste med reservasjonene: ankomst, slutt, kontakt,
// antall og betaling; åpne en for kontakt, kommentar, «Ta betalt»/betalingshistorikk, endre tid eller antall
// (sjekkes mot tilgjengeligheten), avbestille og oppmøte. Ingenting her sender SMS; bookinger røres bare når admin ber om det.
import {el,api,button,link,badge,card,sheet,form,field,confirm,toast,today,shift,date,money} from './ui.js';
import {bookingPayments} from './kursstart-og-betaling.js';

const UKEDAG=['søn','man','tir','ons','tor','fre','lør'];
const UKEDAG_LANG=['søndag','mandag','tirsdag','onsdag','torsdag','fredag','lørdag'];
const MND=['jan','feb','mar','apr','mai','jun','jul','aug','sep','okt','nov','des'];
const STATUS={apen:'Åpen',fullt:'Fullt',stengt:'Ingen PoP',tider:'Andre tider',ingen:'Ikke åpent'};
export const ukedagNavn=iso=>UKEDAG_LANG[date(iso).getDay()];
const dagTekst=iso=>{const d=date(iso);return `${UKEDAG_LANG[d.getDay()]} ${d.getDate()}. ${MND[d.getMonth()]}`;};
const timer=min=>{const t=Number(min)/60;return (Number.isInteger(t)?String(t):String(t).replace('.',','))+' t';};
const grenseTekst=k=>k.utenGrense?'Ingen plassgrense':`${k.stoler} samtidig`;
const betalingMerke=s=>badge(s,s==='Betalt'||s==='Gratis'?'good':s==='Delvis betalt'?'warn':'bad');

// «9 av 20» per tidsrom (flest til stede samtidig).
function oktRader(okter){
 if(!okter.length)return el('p',{class:'empty',text:'Ingen reservasjoner ennå.'});
 return el('div',{class:'list'},okter.map(o=>el('div',{class:'row'},el('strong',{text:`${o.fra}–${o.til}`}),badge(o.stoler?`${o.booket} av ${o.stoler}`:`${o.antall} pers.`,o.stoler&&o.booket>=o.stoler?'bad':'good'))));
}

// a) Kortet øverst på Oversikt: dagens tidsrom og én stor knapp.
export async function popIdagKort(){
 let d;try{d=await api('malebord.php');}catch{return null;}
 const boks=card('Paint on Pots i dag');
 const tegn=d=>{
  const s=d.idag.status;
  const merke=s==='fullt'?badge(d.idag.fra?`Fullt fra ${d.idag.fra}`:'Fullt hele dagen','bad'):s==='stengt'?badge('Ingen PoP i dag','bad'):s==='tider'?badge(`Åpent ${d.idag.fra}–${d.idag.til}`,'good'):null;
  const hoved=!d.klar?null:(s==='fullt'||s==='stengt')
   ?button('Åpne igjen',async ev=>{ev.target.disabled=true;try{const r=await api('malebord.php',{handling:'apne',dato:d.idag.dato});toast(r.beskjed||'Åpent igjen.');tegn(r);}catch(e){toast(e.message);ev.target.disabled=false;}},'primary pop-bred')
   :el('button',{class:'button pop-fullt',type:'button',text:'Fullt resten av dagen',onclick:async ev=>{ev.target.disabled=true;try{const r=await api('malebord.php',{handling:'restenAvDagen'});toast(r.beskjed||'Lagret.');tegn(r);}catch(e){toast(e.message);ev.target.disabled=false;}}});
  boks.replaceChildren(...[el('h2',{text:'Paint on Pots i dag'}),merke,oktRader(d.idag.okter),el('div',{class:'pop-knapper'},hoved,button('Paint on Pots: dager og reservasjoner',()=>popUkeArk()))].filter(Boolean));
 };
 tegn(d);return boks;
}

// Én reservasjon: kontakt, kommentar, betaling og handlingene.
function reservasjonArk(r,etter,maks){
 const s=sheet(`${r.navn} · ${r.fra}–${r.til}`,el('div',{},
  el('div',{class:'list'},
   el('div',{class:'row'},el('span',{text:'Dag og tid'}),el('strong',{text:`${dagTekst(r.dato)}, ${r.fra}–${r.til}`})),
   el('div',{class:'row'},el('span',{text:'Antall'}),el('strong',{text:`${r.antall} ${r.antall===1?'person':'personer'}`})),
   el('div',{class:'row'},el('span',{text:'Betaling'}),el('div',{},betalingMerke(r.betaling),el('small',{text:` ${money(r.betaltOre)} av ${money(r.belopOre)}`}))),
   r.telefon?el('div',{class:'row'},el('span',{text:'Mobil'}),link(r.telefon,'tel:'+r.telefon)):null,
   r.epost?el('div',{class:'row'},el('span',{text:'E-post'}),el('strong',{text:r.epost})):null,
   r.melding?el('div',{class:'row'},el('span',{text:'Kommentar'}),el('p',{text:r.melding})):null,
   r.allergier?el('div',{class:'row'},el('span',{text:'Allergier'}),el('p',{text:r.allergier})):null),
  el('div',{class:'actions',style:'margin-top:16px'},
   button('Ta betalt og betalinger',()=>{s.close();bookingPayments(r.bookingId,etter);},'primary'),
   button('Endre tid',()=>form('Ny ankomsttid',[field('dato','Dag','date',{required:true}),field('tid','Ankomst','time',{required:true,step:900})],{dato:r.dato,tid:r.fra},async v=>{await api('malebord.php',{handling:'flytt',bookingId:r.bookingId,dato:v.dato,tid:v.tid});toast('Flyttet.');s.close();etter&&etter();})),
   button('Endre antall',()=>form('Antall personer',[field('antall','Antall','number',{min:1,max:maks,required:true,help:'Sjekkes mot ledige plasser før det lagres. Beløpet regnes på nytt etter de vanlige reglene.'})],{antall:r.antall},async v=>{await api('malebord.php',{handling:'sjekk',bookingId:r.bookingId,antall:v.antall});await api('pamelding.php',{handling:'endre',id:r.bookingId,antall:v.antall});toast('Antallet er endret.');s.close();etter&&etter();})),
   button(r.mott?'Møtte ikke opp':'Møtte likevel',async()=>{try{await api('pamelding.php',{handling:'status',id:r.bookingId,status:r.mott?'ikke_mott':r.forStatus});toast('Lagret.');s.close();etter&&etter();}catch(e){toast(e.message);}}),
   button('Avbestill',async()=>{if(!await confirm('Avbestill reservasjonen?',`Avbestill ${r.antall} ${r.antall===1?'plass':'plasser'} for ${r.navn}. Betaling gjennom Vipps må refunderes først.`,'Avbestill'))return;try{await api('pamelding.php',{handling:'fjern',id:r.bookingId});toast('Avbestilt.');s.close();etter&&etter();}catch(e){toast(e.message);}},'danger'))));
}

// b) Alt samlet: innstillinger, uka med unntak, og dagslista.
export function popUkeKort(start){
 const boks=el('div',{class:'pop-malebord'});let uke=start||'';let dag=today();
 const etter=()=>last();
 async function last(body){try{const q=`?uke=${uke}&dato=${dag}`;const d=body?await api('malebord.php'+q,{...body,uke,dato:body.dato||dag}):await api('malebord.php'+q);if(body&&d.beskjed)toast(d.beskjed);uke=d.mandag;tegn(d);}catch(e){toast(e.message);}}
 function innstillinger(k){
  const plan=Object.entries(k.ukeplan||{});const forst=plan[0]?.[1]||{};
  const ukedager=plan.length?plan.map(([n])=>UKEDAG[Number(n)%7]).join(', ')+` ${forst.fra}–${forst.til}`:'Følger åpningstidene';
  return el('div',{class:'list'},
   el('div',{class:'row'},el('div',{},el('small',{text:'Åpent for Paint on Pots'}),el('strong',{text:ukedager}),el('small',{text:`Besøket varer ${timer(k.lengde)} fra ankomst`})),
    button('Endre',()=>{const navn=['Mandag','Tirsdag','Onsdag','Torsdag','Fredag','Lørdag','Søndag'];const init={fra:forst.fra||'17:00',til:forst.til||'20:00',plassMinutter:k.lengde};for(const [n] of plan)init['dag'+n]=true;
     form('Åpningstid og besøkslengde',[...navn.map((n,i)=>field('dag'+(i+1),n,'checkbox')),field('fra','Fra','time'),field('til','Til','time'),field('plassMinutter','Besøkslengde (minutter)','number',{min:15,max:1440,step:15})],init,async v=>{const dager=navn.map((_,i)=>v['dag'+(i+1)]?i+1:0).filter(Boolean).join(',');const r=await api('kurs.php',{handling:'ukeplan',kursId:k.id,dager,fra:v.fra,til:v.til,plassMinutter:v.plassMinutter||0,utenPlassgrense:'nei'});toast(r.beskjed||'Lagret.');last();});})),
   el('div',{class:'row'},el('div',{},el('small',{text:'Plassgrense (ressursen «Paint on Pots»)'}),el('strong',{text:grenseTekst(k)}),el('small',{text:'Personer til stede samtidig. Endres under Ressurser; slått av = ingen plassgrense.'})),link('Ressurser','#ressurser')),
   el('div',{class:'row'},el('div',{},el('small',{text:'Pris og betaling'}),el('strong',{text:`${money(k.prisOre)} per person`}),el('small',{text:k.oppmote?'Kunden velger Vipps nå eller betal ved besøket':'Kunden betaler i Vipps ved bestilling'})),link('Kurs','#kurs')));
 }
 function dagsliste(d){
  const x=d.dag;const s=x.status||'apen';
  const valg=[['apen','Åpen'],['fullt','Fullt'],['stengt','Ingen PoP'],['tider','Andre tider']].map(([v,n])=>el('button',{type:'button',class:`button ${s===v?'primary':''}`,'aria-pressed':String(s===v),text:n,disabled:x.dato<today(),onclick:()=>{
   if(v==='tider')return form('Andre tider '+dagTekst(x.dato),[field('fra','Fra','time',{required:true}),field('til','Til','time',{required:true})],{fra:x.fra||'17:00',til:x.til||'20:00'},async f=>{await last({handling:'dag',dato:x.dato,status:'tider',fra:f.fra,til:f.til});});
   last(v==='apen'?{handling:'apne',dato:x.dato}:{handling:'dag',dato:x.dato,status:v});}}));
  const rader=x.reservasjoner;
  return el('div',{},el('h3',{text:`${dagTekst(x.dato)}${x.vindu?' · åpent '+x.vindu:''}`}),el('div',{class:'actions pop-status'},valg),
   rader.length?el('div',{class:'list'},rader.map(r=>el('button',{type:'button',class:'row row-link pop-res',onclick:()=>reservasjonArk(r,etter,d.kurs.maksAntall)},
    el('div',{},el('strong',{text:`${r.fra}–${r.til} · ${r.navn}`}),el('small',{text:`${r.antall} ${r.antall===1?'person':'personer'}${r.telefon?' · '+r.telefon:''}${r.mott?'':' · møtte ikke opp'}`})),betalingMerke(r.betaling)))):el('p',{class:'empty',text:'Ingen reservasjoner denne dagen.'}));
 }
 function tegn(d){
  const dager=d.uke.map(x=>{const dd=date(x.dato);
   const tekst=x.status==='fullt'&&x.fra?`Fullt fra ${x.fra}`:x.status==='tider'?`${x.fra}–${x.til}`:STATUS[x.status];
   return el('button',{type:'button',class:`pop-dag pop-${x.status}${x.dato===d.dag.dato?' pop-valgt':''}`,'aria-pressed':String(x.dato===d.dag.dato),'aria-label':`${dagTekst(x.dato)}: ${tekst}${x.antall?', '+x.antall+' personer':''}`,title:x.vindu||'',
    onclick:()=>{dag=x.dato;last();}},
    el('span',{text:UKEDAG[dd.getDay()]}),el('b',{text:String(dd.getDate())}),el('small',{text:tekst}),x.antall?el('small',{text:`${x.antall} pers.`}):null);});
  const m=date(d.mandag);
  boks.replaceChildren(...[
   d.klar?null:el('p',{class:'notice',text:'Kjør oppdateringene (⚙ Kjør oppdateringer) for å bruke unntak per dato og ressursen «Paint on Pots».'}),
   innstillinger(d.kurs),
   el('div',{class:'pop-ukehode'},button('‹',()=>{uke=shift(d.mandag,-7);dag=uke<today()?today():uke;last();}),el('strong',{text:`Uke fra ${m.getDate()}. ${MND[m.getMonth()]}`}),button('›',()=>{uke=shift(d.mandag,7);dag=uke;last();})),
   el('div',{class:'pop-uke'},dager),
   dagsliste(d)].filter(Boolean));
 }
 boks.append(el('p',{class:'empty',text:'Henter …'}));last();return boks;
}
export function popUkeArk(){sheet('Paint on Pots',popUkeKort());}

// c) Fra kalenderbrikka: merk dagen full eller åpne den igjen.
export function popDagKnapp(dato,popDager,etter){
 const merket=popDager&&popDager[dato]&&popDager[dato].status!=='tider';
 if(dato<today())return null;
 return merket
  ?button('Åpne igjen',async()=>{try{await api('malebord.php',{handling:'apne',dato});toast('Åpent igjen.');etter&&etter();}catch(e){toast(e.message);}},'primary')
  :button(`Merk ${ukedagNavn(dato)} som full`,async()=>{if(!await confirm('Merk dagen som full?',`Ingen nye Paint on Pots-bookinger ${dagTekst(dato)}. Bookinger som er gjort, står.`,'Merk som full'))return;try{await api('malebord.php',{handling:'dag',dato,status:'fullt'});toast('Dagen er merket full.');etter&&etter();}catch(e){toast(e.message);}},'danger');
}
// «Paint on Pots · 9/20» på brikka: flest til stede samtidig i reservasjonens tidsrom.
export function popBrikkeTekst(e){
 const l=e.sammen||[e];const s=l.find(x=>x.popStoler)?.popStoler;
 if(!s)return '';const b=l.reduce((a,x)=>Math.max(a,Number(x.popBooket)||0),0);return `${b}/${s}`;
}
