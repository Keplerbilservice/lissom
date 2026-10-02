// Kalenderen på mobil (K3, eieren godkjente 2. oktober 2026, 390 px).
// Øverst «Går nå» og «Neste» (mønster fra gamle admin, lissom-2108.html: kalNaa*),
// så Dag · Uke · Måned, en ukestripe man sveiper mellom ukene, og valgt dag som timekort.
// Hele kortet åpner hendelsesarket som finnes fra før (eventDetails i kalender.js).
// Ingen piler, ingen «Åpne»-knapper, ingenting valgt på forhånd i filteret.
import {el,api,badge,today,date,iso,shift} from './ui.js';

export const SMAL='(max-width:760px)';
export const erSmal=()=>matchMedia(SMAL).matches;
// Dagen som er valgt i mobilkalenderen. Ny kursdato og Nytt kalendernotat foreslår den (kalender.js).
export const valgtDag=()=>valgt;

// Visningen husker seg selv mellom oppfriskninger (et ark som lagrer, kaller refresh()).
let modus='dag',valgt=today(),sok='',sokApen=false;const typer=new Set();
const maaneder=new Map();

const mnd=d=>d.slice(0,7);
const sisteIMnd=ym=>{const[y,m]=ym.split('-').map(Number);return iso(new Date(y,m,0));};
const nesteMnd=ym=>{const[y,m]=ym.split('-').map(Number);return iso(new Date(y,m,1)).slice(0,7);};
const mandag=d=>shift(d,-((date(d).getDay()+6)%7));
const minutter=t=>{const[h,m]=String(t||'0:0').split(':').map(Number);return(h||0)*60+(m||0);};
const naaMin=()=>{const p=Object.fromEntries(new Intl.DateTimeFormat('nb-NO',{timeZone:'Europe/Oslo',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(new Date()).map(x=>[x.type,x.value]));return Number(p.hour)*60+Number(p.minute);};
// Hvor lenge et kurs varer. Slutt før start betyr at det går over midnatt (22:00–01:00 er tre timer).
const varighet=e=>{const s=minutter(e.tid);let sl=e.slutt?minutter(e.slutt):s+60;if(sl<s)sl+=1440;return sl-s;};
// Går kurset nå? Et kurs som startet i går og går over midnatt, går til det slutter i dag.
const gaarNaa=(e,idag,n)=>{const s=minutter(e.tid);const siden=e.dato===idag?n-s:e.dato===shift(idag,-1)?n+1440-s:-1;return siden>=0&&siden<varighet(e);};
// «Neste» letes etter i denne og neste måned; finnes ingen der, hentes én måned til av gangen, høyst SOK_MND måneder fram.
const SOK_MND=6;
// «Går nå» og «Neste» regnes på nytt hvert minutt mens kalenderen vises. Én timer; den stoppes når kalenderen ikke vises.
let naTimer=0;
// Det «Går nå» og «Neste» viser: kurs, Paint on Pots og eventer. Ikke vakter, brenning, notater eller innsjekk (som gamle admin).
const erKurs=e=>!e.avlyst&&['kurs','pop','event'].includes(e.type);
const plass=e=>e.kap?`${e.pameldt||0}/${e.kap} påmeldt`:'';
const tidsrom=e=>`${e.tid||''}${e.slutt?'–'+e.slutt:''}`;
const dagNavn=(d,o)=>date(d).toLocaleDateString('nb-NO',o);
const stor=t=>t.charAt(0).toLocaleUpperCase('nb-NO')+t.slice(1);

// Én måned av gangen, som PC-kalenderen. Lagres til neste oppfriskning.
function hentMnd(ym){if(!maaneder.has(ym))maaneder.set(ym,api(`kalender.php?fra=${ym}-01&til=${sisteIMnd(ym)}`).then(d=>({hendelser:(d.hendelser||[]).filter(e=>String(e.dato).startsWith(ym)),stengte:d.stengte||{}})).catch(e=>{maaneder.delete(ym);throw e;}));return maaneder.get(ym);}
async function sikre(dager){const yms=[...new Set(dager.map(mnd))];await Promise.all(yms.map(hentMnd));}
async function lastet(ym){return maaneder.has(ym)?await maaneder.get(ym):{hendelser:[],stengte:{}};}
async function alleLastet(){const ut=[],stengt={};for(const ym of maaneder.keys()){const d=await lastet(ym);ut.push(...d.hendelser);Object.assign(stengt,d.stengte);}return{hendelser:ut,stengte:stengt};}

function treff(e){if(typer.size&&!typer.has(e.type))return false;const ord=sok.toLocaleLowerCase('nb-NO').trim().split(/\s+/).filter(Boolean);const tekst=`${e.tittel} ${e.holder||''} ${(e.deltakere||[]).map(p=>p.navn).join(' ')}`.toLocaleLowerCase('nb-NO');return ord.every(w=>tekst.includes(w));}
const sortert=l=>[...l].sort((a,b)=>String(a.tid).localeCompare(String(b.tid)));

// Sveip sidelengs på et felt. touch-action:pan-y i stilen lar nettleseren rulle loddrett selv;
// bare et tydelig sidelengs drag blar.
function sveip(node,bla){let x0=0,y0=0,dx=0,retning=null;
 node.addEventListener('touchstart',ev=>{const t=ev.touches[0];x0=t.clientX;y0=t.clientY;dx=0;retning=null;node.style.transition='none';},{passive:true});
 node.addEventListener('touchmove',ev=>{const t=ev.touches[0];dx=t.clientX-x0;const dy=t.clientY-y0;if(!retning&&(Math.abs(dx)>8||Math.abs(dy)>8))retning=Math.abs(dx)>Math.abs(dy)?'x':'y';if(retning==='x')node.style.transform=`translateX(${dx*.6}px)`;},{passive:true});
 const slipp=()=>{node.style.transition='';node.style.transform='';if(retning==='x'&&Math.abs(dx)>50)bla(dx<0?1:-1);retning=null;};
 node.addEventListener('touchend',slipp);node.addEventListener('touchcancel',()=>{dx=0;slipp();});}

export async function kalenderMobil({title,eventDetails,handlinger}){
 maaneder.clear();clearInterval(naTimer);
 const idag=today();
 // I går med: et kurs som startet i går kveld og går over midnatt, kan gå nå.
 await sikre([shift(idag,-1),idag,nesteMnd(mnd(idag))+'-01',...(modus==='maaned'?[valgt]:[mandag(valgt),shift(mandag(valgt),6)])]);

 // ── Går nå og Neste ────────────────────────────────────────────────
 const naBoks=el('div',{class:'kalm-naboks'});
 async function oppdaterNa(){
  const idag=today(),n=naaMin();
  const finnNeste=async()=>[...(await alleLastet()).hendelser.filter(erKurs)].filter(e=>e.dato>idag||(e.dato===idag&&minutter(e.tid)>n)).sort((a,b)=>`${a.dato}T${a.tid}`.localeCompare(`${b.dato}T${b.tid}`))[0]||null;
  await sikre([shift(idag,-1),idag,nesteMnd(mnd(idag))+'-01']);
  const gaar=sortert((await alleLastet()).hendelser.filter(e=>erKurs(e)&&gaarNaa(e,idag,n)))[0]||null;
  let neste=await finnNeste();
  try{for(let i=2,ym=nesteMnd(nesteMnd(mnd(idag)));!neste&&i<=SOK_MND;i++,ym=nesteMnd(ym)){await hentMnd(ym);neste=await finnNeste();}}catch{}
  naBoks.replaceChildren(...[gaar?kort(gaar,'Går nå','gaar'):null,neste?kort(neste,'Neste','neste'):null].filter(Boolean));
 }
 const kort=(e,merke,klasse)=>{const idag=today();const fyll=e.kap?Math.min(100,Math.round((e.pameldt||0)/e.kap*100)):0;const naar=e.dato===idag?tidsrom(e):`${dagNavn(e.dato,{weekday:'short',day:'numeric',month:'short'})} · ${tidsrom(e)}`;
  return el('button',{type:'button',class:`kalm-na ${klasse}`,onclick:()=>eventDetails(e)},el('span',{class:'kalm-na-e',text:merke}),el('strong',{class:'kalm-na-h',text:e.tittel}),el('span',{class:'kalm-na-p',text:[naar,e.holder].filter(Boolean).join(' · ')}),e.kap?[el('span',{class:'kalm-na-bar','aria-hidden':true},el('span',{style:`width:${fyll}%`})),el('span',{class:'kalm-na-p',text:plass(e)})]:null);};

 // ── Styring: Dag · Uke · Måned, I dag og søk ──────────────────────
 const segment=el('div',{class:'kalm-seg',role:'group','aria-label':'Kalendervisning'});
 const sokKnapp=el('button',{type:'button',class:'kalm-ikon','aria-label':'Søk i kalender','aria-expanded':String(sokApen),onclick:()=>{sokApen=!sokApen;panel.hidden=!sokApen;sokKnapp.setAttribute('aria-expanded',String(sokApen));if(sokApen)felt.focus();}});
 sokKnapp.innerHTML='<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="10.5" cy="10.5" r="6.5"/><path d="M15.5 15.5 21 21"/></svg>';
 const styring=el('div',{class:'kalm-styr'},segment,el('button',{type:'button',class:'kalm-pille',text:'I dag',onclick:()=>{valgt=today();tegn();}}),sokKnapp);

 // Søk, dato og type bak søkeikonet. Ingen type er valgt på forhånd: tomt filter viser alt.
 const felt=el('input',{type:'search',value:sok,placeholder:'Søk kurs, kursholder eller deltaker','aria-label':'Søk i kalender',oninput:ev=>{sok=ev.target.value;tegn();}});
 const velgDato=el('input',{type:'date',value:valgt,'aria-label':'Velg dato',onchange:ev=>{if(ev.target.value){valgt=ev.target.value;if(modus==='uke')modus='dag';tegn();}}});
 const typeRad=el('div',{class:'kalm-typer',role:'group','aria-label':'Hendelsestype'});
 const panel=el('div',{class:'kalm-panel'},felt,velgDato,typeRad);panel.hidden=!sokApen;

 const innhold=el('div',{class:'kalm-innhold'});
 const maanedTekst=el('p',{class:'kalm-mnd','aria-live':'polite'});
 const hode=title('Kalender','',[]);hode.classList.add('kalm-hode');

 async function tegn(){
  const dager=modus==='maaned'?[valgt]:[mandag(valgt),shift(mandag(valgt),6)];
  try{await sikre(dager);}catch(e){innhold.replaceChildren(el('p',{class:'empty',text:e.message}));return;}
  const {hendelser,stengte}=await alleLastet();const synlige=hendelser.filter(treff);
  const paaDag=d=>sortert(synlige.filter(e=>e.dato===d));
  velgDato.value=valgt;
  segment.replaceChildren(...[['dag','Dag'],['uke','Uke'],['maaned','Måned']].map(([v,t])=>el('button',{type:'button',class:'kalm-pille','aria-pressed':String(modus===v),text:t,onclick:()=>{modus=v;tegn();}})));
  const kjente=[...new Set(hendelser.map(e=>e.type))].sort();
  typeRad.replaceChildren(...kjente.map(t=>el('button',{type:'button',class:'kalm-pille','aria-pressed':String(typer.has(t)),text:t,onclick:()=>{typer.has(t)?typer.delete(t):typer.add(t);tegn();}})));
  sokKnapp.classList.toggle('aktiv',!!(sok.trim()||typer.size));

  const timekort=e=>el('button',{type:'button',class:`kalm-kort ${e.avlyst?'avlyst':''}`,onclick:()=>eventDetails(e)},el('span',{class:'kalm-tid'},el('b',{text:e.tid||''}),e.slutt?el('small',{text:e.slutt}):null),el('span',{class:'kalm-hva'},el('strong',{text:e.tittel}),el('small',{text:[e.holder,e.avlyst?'Avlyst':null,e.kap?plass(e):e.type].filter(Boolean).join(' · ')})));
  const dagListe=(d,overskrift)=>{const l=paaDag(d);return el('section',{class:'kalm-dag'},el(overskrift,{text:stor(dagNavn(d,{weekday:'long',day:'numeric',month:'long'}))}),Object.hasOwn(stengte,d)?badge('Stengt','warn'):null,l.length?l.map(timekort):el('p',{class:'muted',text:'Ingen hendelser'}));};
  const dagKnapp=(d,klasse)=>el('button',{type:'button',class:`${klasse} ${d===today()?'idag':''}`,'aria-pressed':String(d===valgt),'aria-current':d===today()?'date':null,'aria-label':dagNavn(d,{weekday:'long',day:'numeric',month:'long'})+(paaDag(d).length?`, ${paaDag(d).length} hendelser`:''),onclick:()=>{valgt=d;if(modus==='uke')modus='dag';tegn();}},el('i',{text:dagNavn(d,{weekday:'short'}).replace('.','')}),el('b',{text:String(date(d).getDate())}),el('span',{class:paaDag(d).length?'kalm-prikk':'kalm-prikk tom','aria-hidden':true}));

  let flate,liste;
  if(modus==='maaned'){
   const ym=mnd(valgt),forste=`${ym}-01`,antall=date(sisteIMnd(ym)).getDate();
   flate=el('div',{class:'kalm-mnd-rute'},Array.from({length:(date(forste).getDay()+6)%7},()=>el('span',{'aria-hidden':true})),Array.from({length:antall},(_,i)=>dagKnapp(shift(forste,i),'kalm-celle')));
   maanedTekst.textContent=dagNavn(forste,{month:'long',year:'numeric'});
   sveip(flate,s=>{const[y,m]=ym.split('-').map(Number);valgt=iso(new Date(y,m-1+s,1));tegn();});
   liste=dagListe(valgt,'h2');
  }else{
   const start=mandag(valgt);
   flate=el('div',{class:'kalm-uke'},Array.from({length:7},(_,i)=>dagKnapp(shift(start,i),'kalm-ukedag')));
   maanedTekst.textContent=dagNavn(start,{month:'long',year:'numeric'});
   sveip(flate,s=>{valgt=shift(valgt,7*s);tegn();});
   if(modus==='dag')liste=dagListe(valgt,'h2');
   else{const fulle=Array.from({length:7},(_,i)=>shift(start,i)).filter(d=>paaDag(d).length);liste=fulle.length?el('div',{},fulle.map(d=>dagListe(d,'h3'))):el('p',{class:'muted',text:'Ingen hendelser'});}
  }
  innhold.replaceChildren(maanedTekst,flate,liste);
 }
 await tegn();
 await oppdaterNa();

 const rot=el('div',{class:'kalm'},hode,
  naBoks,
  styring,panel,innhold,
  el('div',{class:'kalm-handlinger'},handlinger));
 naTimer=setInterval(()=>{if(!rot.isConnected){clearInterval(naTimer);return;}if(!document.hidden)oppdaterNa().catch(()=>{});},60000);
 return rot;
}
