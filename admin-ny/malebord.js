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

// a) Dagens tidsrom og én stor knapp («Fullt resten av dagen» / «Åpne igjen»). Står i arket fra I dag-raden.
export async function popIdagKort(forhand,iArk){
 let d=forhand;if(!d){try{d=await api('malebord.php');}catch{return null;}}
 const boks=card('Paint on Pots i dag');
 const tegn=d=>{
  const s=d.idag.status;
  const merke=s==='fullt'?badge(d.idag.fra?`Fullt fra ${d.idag.fra}`:'Fullt hele dagen','bad'):s==='stengt'?badge('Ingen PoP i dag','bad'):s==='tider'?badge(`Åpent ${d.idag.fra}–${d.idag.til}`,'good'):null;
  const hoved=!d.klar?null:(s==='fullt'||s==='stengt')
   ?button('Åpne igjen',async ev=>{ev.target.disabled=true;try{const r=await api('malebord.php',{handling:'apne',dato:d.idag.dato});toast(r.beskjed||'Åpent igjen.');tegn(r);}catch(e){toast(e.message);ev.target.disabled=false;}},'primary pop-bred')
   :el('button',{class:'button pop-fullt',type:'button',text:'Fullt resten av dagen',onclick:async ev=>{ev.target.disabled=true;try{const r=await api('malebord.php',{handling:'restenAvDagen'});toast(r.beskjed||'Lagret.');tegn(r);}catch(e){toast(e.message);ev.target.disabled=false;}}});
  boks.replaceChildren(...[el('h2',{text:'Paint on Pots i dag'}),merke,oktRader(d.idag.okter),el('div',{class:'pop-knapper'},hoved,iArk?null:button('Paint on Pots: dager og reservasjoner',()=>popUkeArk()))].filter(Boolean));
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
   r.kanKassa?button('Slå inn gjenstander',()=>{s.close();popKassa(r.bookingId,etter);},'primary'):null,
   button('Ta betalt og betalinger',()=>{s.close();bookingPayments(r.bookingId,etter);},r.kanKassa?'':'primary'),
   button('Endre tid',()=>form('Ny ankomsttid',[field('dato','Dag','date',{required:true}),field('tid','Ankomst','time',{required:true,step:900})],{dato:r.dato,tid:r.fra},async v=>{await api('malebord.php',{handling:'flytt',bookingId:r.bookingId,dato:v.dato,tid:v.tid});toast('Flyttet.');s.close();etter&&etter();})),
   button('Endre antall',()=>form('Antall personer',[field('antall','Antall','number',{min:1,max:maks,required:true,help:'Sjekkes mot ledige plasser før det lagres. Beløpet ved booking regnes på nytt (antall × beløp per person). Eldre bookinger og bookinger der gjenstandene er slått inn i kassa regnes ikke om.'})],{antall:r.antall},async v=>{await api('malebord.php',{handling:'sjekk',bookingId:r.bookingId,antall:v.antall});await api('pamelding.php',{handling:'endre',id:r.bookingId,antall:v.antall});toast('Antallet er endret.');s.close();etter&&etter();})),
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
   k.depositum
    ?el('div',{class:'row'},el('div',{},el('small',{text:'Betales ved booking'}),el('strong',{text:`${money(k.prisOre)} per person med Vipps`}),el('small',{text:`Trekkes fra i verkstedet. Avbestilling med refusjon senest ${k.avbestillingTimer??48} timer før.`})),
     button('Endre',()=>form('Betaling ved booking',[field('belop','Beløp per person (kr)','number',{min:1,max:5000,step:1,required:true}),field('frist','Avbestilling med refusjon senest (timer før)','number',{min:0,max:720,step:1,required:true,help:'Gjelder nye bookinger. Bookinger som er gjort beholder beløpet sitt.'})],{belop:Math.round(k.prisOre/100),frist:k.avbestillingTimer??24},async v=>{await last({handling:'betaling',belop:v.belop,frist:v.frist});})))
    :el('div',{class:'row'},el('div',{},el('small',{text:'Pris og betaling'}),el('strong',{text:`${money(k.prisOre)} per person`}),el('small',{text:k.oppmote?'Kunden velger Vipps nå eller betal ved besøket':'Kunden betaler i Vipps ved bestilling'})),link('Kurs','#kurs')),
   k.prisKlar?el('div',{class:'row'},el('div',{},el('small',{text:'Prisnivåer (vises på nettsiden)'}),...(k.nivaer||[]).map(n=>el('strong',{text:`${n.navn} · ${money(n.prisOre)}`,title:n.gjenstander})),el('small',{text:'Glasur og brenning er med i prisen.'})),
    button('Endre',()=>{const rader=[...(k.nivaer||[]),{},{}];const felter=[];const init={};rader.forEach((n,i)=>{felter.push(field('navn'+i,`Nivå ${i+1} · navn`,'text'),field('pris'+i,`Nivå ${i+1} · pris (kr)`,'number',{min:1,max:100000,step:1}),field('gjenstander'+i,`Nivå ${i+1} · gjenstander`,'textarea',{help:'Skilt med komma, slik de vises på nettsiden. Tomt navn og pris fjerner nivået.'}));init['navn'+i]=n.navn||'';init['pris'+i]=n.prisOre?Math.round(n.prisOre/100):null;init['gjenstander'+i]=n.gjenstander||'';});
     form('Prisnivåer',felter,init,async v=>{const nivaer=rader.map((n,i)=>({id:n.id||0,navn:v['navn'+i]||'',pris:v['pris'+i]??'',gjenstander:v['gjenstander'+i]||''})).filter(n=>n.navn||n.pris!=='');if(!await confirm('Lagre prisnivåene?','Prisene vises på nettsiden og brukes i kassa fra nå. Gjenstander som alt er slått inn beholder prisen sin.','Lagre'))throw Error('Avbrutt.');await last({handling:'nivaer',nivaer});});}),
    // Gjenstand → butikkvare (migrasjon 262). Frivillig: en koblet gjenstand trekker lageret når den slås inn i kassa.
    k.lagerKlar?button('Koble til varer',()=>{const felter=[field('hjelp','Valgfritt. En koblet gjenstand trekker lageret når den slås inn i kassa.','heading')];const init={};const rader=[];const valg=[[0,'Ingen kobling'],...(k.varer||[]).map(v=>[v.id,v.tittel+(v.lager!==null?` (${v.lager} på lager)`:'')])];for(const n of (k.nivaer||[]))for(const g of String(n.gjenstander||'').split(',').map(x=>x.trim()).filter(Boolean)){const i=rader.length;rader.push({nivaaId:n.id,gjenstand:g});felter.push(field('v'+i,`${n.navn} · ${g}`,'number',{options:valg}));init['v'+i]=(k.koblinger||{})[n.id+'|'+g]||0;}if(!rader.length){toast('Legg inn gjenstander i prisnivåene først.');return;}form('Gjenstander og lager',felter,init,async v=>{await last({handling:'koblinger',koblinger:rader.map((r,i)=>({...r,produktId:Number(v['v'+i])||0}))});});}):null):null);
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

// I dag (eieren 08.10.2026): Paint on Pots er én vanlig rad blant dagens kurs, ingen egen boks.
// «Paint on Pots · 12:00–20:00 · N personer booket». Trykk åpner arket med dagens knapp og dagslista.
// Ingen PoP i dag (stengt eller ikke åpent, og ingen reservasjoner) → ingen rad.
export async function popIdagRad(){
 let d;try{d=await api('malebord.php');}catch{return null;}
 const res=(d.dag?.reservasjoner||[]).filter(r=>r.mott);
 const antall=res.reduce((a,r)=>a+(Number(r.antall)||0),0);
 const s=d.idag?.status||'apen',vindu=d.dag?.vindu||'';
 if((s==='stengt'||!vindu)&&!antall)return null;
 const fra=vindu?vindu.slice(0,5):(res[0]?.fra||'');
 const merke=s==='fullt'?badge(d.idag.fra?`Fullt fra ${d.idag.fra}`:'Fullt','bad'):s==='stengt'?badge('Ingen PoP i dag','bad'):null;
 const rad=el('button',{type:'button',class:'row row-link pop-res',onclick:()=>popIdagArk(d)},
  el('div',{},el('strong',{text:'Paint on Pots'}),el('small',{text:[vindu,`${antall} ${antall===1?'person':'personer'} booket`].filter(Boolean).join(' · ')})),merke);
 return {tid:fra,rad};
}
export async function popIdagArk(d){
 const topp=await popIdagKort(d,true);
 sheet('Paint on Pots i dag',el('div',{},topp?el('div',{style:'margin-bottom:22px'},topp):null,popUkeKort()));
}

// Kassa: gjenstandene slås inn på bookingen (eieren 8. oktober 2026, «ok, bygg det»). Prisen er nivåets pris
// fra serveren; det som er betalt ved booking trekkes fra. Selve pengene registreres i «Ta betalt» etterpå.
export async function popKassa(bookingId,etter){
 let d;try{d=await api('malebord.php?kassa='+bookingId);}catch(e){toast(e.message);return;}
 // Én rad per gjenstand i hvert nivå (migrasjon 262). Antallet starter på det som alt er slått inn.
 const valg=new Map();for(const r of d.rader){const n=(r.lagret||[]).reduce((a,l)=>a+l.antall,0);if(n)valg.set(r.nokkel,n);}
 const boks=el('div',{});let s;
 const tegn=()=>{
  // Samme regnestykke som PopPris::regnLinjer(): det som alt er slått inn beholder prisen sin, nye får dagens nivåpris.
  const lagretAntall=r=>(r.lagret||[]).reduce((a,l)=>a+l.antall,0);
  const linjeSum=r=>{let igjen=valg.get(r.nokkel)||0,s=0;for(const l of (r.lagret||[])){const t=Math.min(igjen,l.antall);s+=t*l.prisOre;igjen-=t;}return s+igjen*r.prisOre;};
  const sum=d.rader.reduce((a,r)=>a+linjeSum(r),0);const rest=Math.max(0,sum-d.betaltOre);
  boks.replaceChildren(
   el('p',{class:'muted',text:`${d.naar} · ${d.antall} ${d.antall===1?'person':'personer'}`}),
   el('div',{class:'list'},d.rader.map(r=>{const x=valg.get(r.nokkel)||0;return el('div',{class:'row'},el('div',{},el('strong',{text:r.gjenstand||r.nivaa}),el('small',{text:`${r.nivaa} · ${money(r.prisOre)}${r.lager?' · trekker lager':''}`})),
    el('div',{class:'actions'},button('−',()=>{x>1?valg.set(r.nokkel,x-1):valg.delete(r.nokkel);tegn();}),el('strong',{text:String(x),'aria-live':'polite'}),button('+',()=>{if(x>=50||(r.aktiv===false&&x>=lagretAntall(r)))return;valg.set(r.nokkel,x+1);tegn();})));})),
   el('div',{class:'list',style:'margin-top:16px'},
    el('div',{class:'row'},el('span',{text:'Gjenstander'}),el('strong',{text:money(sum)})),
    el('div',{class:'row'},el('span',{text:'Betalt ved booking'}),el('strong',{text:'−'+money(d.betaltOre)})),
    el('div',{class:'row'},el('span',{text:'Å betale'}),el('strong',{class:'stat',text:money(rest)}))),
   el('div',{class:'actions',style:'margin-top:16px'},button('Lagre og ta betalt',async ev=>{
    const nivaer=d.rader.filter(r=>(valg.get(r.nokkel)||0)>0).map(r=>({nivaaId:r.nivaaId,gjenstand:r.gjenstand,antall:valg.get(r.nokkel)}));
    if(!nivaer.length){toast('Velg minst én gjenstand.');return;}
    ev.target.disabled=true;
    try{const r=await api('malebord.php',{handling:'kassa',bookingId,nivaer});toast(r.skyldigOre>0?`Lagret. Å betale: ${money(r.skyldigOre)}.`:'Lagret. Ingenting mer å betale.');s.close();etter&&etter();bookingPayments(bookingId,etter);}
    catch(e){toast(e.message);ev.target.disabled=false;}},'primary')));
 };
 tegn();s=sheet(`Kassa · ${d.navn}`,boks);
}

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
