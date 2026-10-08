/* Enklere admin (eieren 08.10.2026, «Ok, bygg det» på visningen): fem faste valg I dag · Kalender · Folk · Penger · Mer,
   «Må gjøres» som seks fliser med rødt merke bare når noe venter, «I dag på verkstedet» som fliser, «+» på alle sider,
   og Mer som bare fliser. Sakene kommer ferdig fra oversikt.php (maGjores); handlingene er de som finnes fra før. */
import {el,api,button,link,sheet,confirm,toast,today} from './ui.js';
import {popIdagArk} from './malebord.js';
import {leggTilVelg} from './kalender-meny.js';
import {hentMer,merHandlinger,mandagFlis} from './ma-gjores-mer.js';

export const NAV=[['idag','I dag','◉'],['kalender','Kalender','▦'],['folk','Folk','♧'],['penger','Penger','○'],['mer','Mer','☷']];
/* Sidene som nås fra Mer (Tilbake går til Mer). */
export const MER_SIDER=['medlemmene','kurs','butikk','verksted','marked','innhold','synlighet','oppsett','verktoy'];

/* Felles flis: hele flisen er trykkbar, rødt merke bare når noe venter. */
export function flis({ikon='',tittel,tekst='',merke=0,rolig=false,fremhevet=false,href,onclick,data={}}){
 const barn=[el('span',{class:'flis-ikon','aria-hidden':'true',text:ikon}),el('span',{class:'flis-tittel',text:tittel}),
  merke?el('span',{class:'flis-merke',text:String(merke)}):null,tekst?el('span',{class:'flis-tekst',text:tekst}):null];
 const attrs={class:'flis'+(rolig?' rolig':'')+(fremhevet?' fremhevet':''),onclick,...data};
 return href?el('a',{...attrs,href},barn):el('button',{...attrs,type:'button'},barn);
}
export const fliser=(...barn)=>el('div',{class:'fliser'},barn);
const etikett=tekst=>el('h2',{class:'flis-etikett',text:tekst});

/* ── Må gjøres ─────────────────────────────────────────────────────────── */
const GRUPPER=[['Meldinger','✉'],['Medlemmer','♧'],['Betaling','kr'],['Kurs','◇'],['Verksted','⌂'],['Butikk','▢']];
/* «Sett som sett» på nye påmeldinger lagres på serveren (migrasjon 265, idé 2 08.10); før den er kjørt huskes det i nettleseren. */
const SETT='lissom-sett-pameldinger';
const settLes=()=>{try{return new Set(JSON.parse(localStorage.getItem(SETT)||'[]'));}catch{return new Set();}};
const settLegg=id=>{const s=settLes();s.add(id);try{localStorage.setItem(SETT,JSON.stringify([...s].slice(-300)));}catch{}};
const gaa=h=>{if(location.hash===h)window.dispatchEvent(new HashChangeEvent('hashchange'));else location.hash=h;};

function maGjores(d,ctx,mer){
 const sett=d.settPaaServer?new Set():settLes();
 /* Idé 3, 4, 5 (08.10): saker fra ma-gjores-mer.php; henting som «Ovnen er ferdig» dekker, tas ut. */
 const dekker=new Set(mer?.dekker||[]);
 const saker=[...(d.maGjores||[]).filter(s=>!(s.type==='pamelding'&&sett.has(s.id))&&!(s.type==='henting'&&dekker.has(s.id))),...(mer?.saker||[])];
 const gjort=new Set();const nokkel=s=>s.type+':'+s.id;
 const igjen=g=>saker.filter(s=>s.gruppe===g&&!gjort.has(nokkel(s)));
 const boks=el('div',{class:'fliser ma-gjores'});
 function tegn(){boks.replaceChildren(...GRUPPER.map(([g,ikon])=>{const n=igjen(g).filter(s=>s.teller!==false).length;
  return flis({ikon,tittel:g,tekst:n?'':'Ingenting nytt',merke:n,rolig:!n,data:{'data-gruppe':g},onclick:()=>visGruppe(g)});}));}
 function visGruppe(g){const l=igjen(g);
  const ark=sheet(g,l.length?el('div',{class:'sak-liste'},l.map(s=>el('button',{type:'button',class:'sak','data-sak':nokkel(s),onclick:()=>{ark.close();setTimeout(()=>visSak(s),0);}},el('strong',{text:s.tittel}),s.under?el('small',{text:s.under}):null))):el('p',{class:'empty',text:'Ingenting nytt her.'}));}
 function visSak(s){
  let ark=null;
  const ferdig=tekst=>{gjort.add(nokkel(s));ark?.close();toast(tekst||'Gjort.');tegn();if(igjen(s.gruppe).length)setTimeout(()=>visGruppe(s.gruppe),0);};
  const trykk=(tekst,gjor,kind='')=>{const b=button(tekst,async()=>{b.disabled=true;try{await gjor();}catch(e){toast(e.message);}finally{b.disabled=false;}},kind);return b;};
  const handlinger=({
   chat:()=>[link('Svar','#chat','primary'),trykk('Marker som lest',async()=>{await api('../chat.php',{handling:'lest',siste:s.siste});ferdig('Merket som lest.');})],
   henvendelse:()=>[link('Svar','#foresporsler','primary'),trykk('Ferdig',async()=>{await api('foresporsler.php',{id:s.id,status:'besvart'});ferdig('Merket som besvart.');})],
   innboks:()=>[link('Svar','#innboks','primary')],
   feil:()=>[link('Svar','#feilmeldinger','primary'),trykk('Løst',async()=>{await api('feilrapporter.php',{handling:'status',id:s.id,status:'lukket'});ferdig('Merket som løst.');})],
   soknad:()=>[trykk('Godkjenn',async()=>{if(!await confirm('Godkjenn søknaden?','Godkjenn søknaden fra '+s.under+'. Betalingen i Vipps settes i gang etter medlemskapet.','Godkjenn'))return;const r=await api('soknader.php',{id:s.id,vedtak:'godkjent'});ferdig(r.beskjed||'Søknaden er godkjent.');},'primary'),trykk('Avslå',async()=>{const r=await api('soknader.php',{id:s.id,vedtak:'avslatt'});ferdig(r.beskjed||'Søknaden er avslått.');})],
   frys:()=>[trykk('Godkjenn frys',async()=>{const r=await api('frys.php',{handling:'godkjenn',id:s.id});ferdig(r.beskjed||'Frysen er godkjent.');},'primary'),trykk('Avslå',async()=>{const r=await api('frys.php',{handling:'avslag',id:s.id});ferdig(r.beskjed||'Frysen er avslått.');})],
   bidrag:()=>[s.bilde?trykk('Godkjenn til galleriet',async()=>{const r=await api('medlemsforslag.php',{handling:'godkjenn',id:s.id,instagram:'0',galleri:'1'});ferdig(r.beskjed||'Godkjent til galleriet.');},'primary'):link('Godkjenn','#medlemsbidrag','primary'),trykk('Avvis',async()=>{if(!await confirm('Avvis','Avvis bidraget. Opplastingen slettes.','Avvis'))return;const r=await api('medlemsforslag.php',{handling:'avvis',id:s.id});ferdig(r.beskjed||'Bidraget er avvist.');},'danger')],
   betaling:()=>[button('Åpne medlemmet',()=>{ark.close();setTimeout(()=>ctx.medlem(s.id),0);},'primary')],
   pamelding:()=>[trykk('Sett som sett',async()=>{const r=await api('pamelding.php',{handling:'sett',id:s.id});if(!r||!r.lagret)settLegg(s.id);ferdig('Satt som sett.');},'primary'),link('Åpne kurset','#'+s.rute)],
   venteliste:()=>[trykk('Tilby plassen til '+(s.fornavn||'første på lista'),async()=>{const r=await api('venteliste.php',{handling:'varsle',id:s.id});ferdig(r.beskjed);},'primary')],
   henting:()=>[button('Send «Klar til henting»',()=>{ark.close();setTimeout(()=>ctx.henting(s.id),0);},'primary')],
   leire:()=>[link('Åpne bestillingen','#handlelister','primary')],
   dugnad:()=>[link('Åpne dugnad','#dugnad','primary')],
   lager:()=>[trykk('Bestill mer',async()=>{const r=await api('produkter.php',{handling:'bestillMer',id:s.id});ferdig(r.beskjed);},'primary'),button('Fyll på',()=>{ark.close();setTimeout(()=>ctx.fyllPaa(s.id,s.vare),0);})],
   taut:()=>[],
   ...merHandlinger(s,{trykk,ferdig,lukk:()=>ark?.close(),grenser:mer?.grenser})
  })[s.type]||(()=>[link('Åpne','#'+s.rute,'primary')]);
  ark=sheet(s.tittel,el('div',{class:'sak-ark'},s.melding||s.under?el('p',{class:'sak-melding',text:s.melding||s.under}):null,s.type==='taut'?ctx.taUt(d.taUtLeire):null,el('div',{class:'actions'},handlinger())));
 }
 boks.apneSak=type=>{const s=saker.find(x=>x.type===type&&!gjort.has(nokkel(x)));if(s)visSak(s);};
 tegn();return boks;
}

/* ── I dag på verkstedet: hvert kurs og Paint on Pots i dag, og «Inne nå» ── */
function verkstedetIdag(kal,pop,inne){
 const idag=(kal.hendelser||[]).filter(e=>e.dato===today()&&!['verksted','brenning','pop','notat'].includes(e.type)&&!e.avlyst).sort((x,y)=>String(x.tid).localeCompare(String(y.tid)));
 const rader=idag.map(e=>({tid:String(e.tid||''),f:flis({ikon:'◇',tittel:e.tittel,tekst:[e.tid+(e.slutt?'–'+e.slutt:''),e.kap?`${e.pameldt||0} påmeldt${(e.pameldt||0)===1?'':'e'}`:''].filter(Boolean).join(' · '),href:'#kalender'})}));
 if(pop){const res=(pop.dag?.reservasjoner||[]).filter(r=>r.mott);const antall=res.reduce((a,r)=>a+(Number(r.antall)||0),0);const s=pop.idag?.status||'apen',vindu=pop.dag?.vindu||'';
  if(!((s==='stengt'||!vindu)&&!antall))rader.push({tid:vindu?vindu.slice(0,5):(res[0]?.fra||''),f:flis({ikon:'◇',tittel:'Paint on Pots',tekst:[vindu,`${antall} ${antall===1?'person':'personer'}`].filter(Boolean).join(' · '),onclick:()=>popIdagArk(pop)})});}
 rader.sort((x,y)=>x.tid.localeCompare(y.tid));
 const navn=(inne||[]).map(p=>p.navn);
 return fliser(...rader.map(r=>r.f),flis({ikon:'♧',tittel:'Inne nå',tekst:navn.length?navn.length+': '+navn.join(', '):'Ingen',href:'#folk?inne=1',data:{'data-inne-na':String(navn.length)}}));
}

export async function idagSide(ctx){
 const [d,kal,pop,mer]=await Promise.all([api('oversikt.php'),api(`kalender.php?fra=${today()}&til=${today()}`).catch(()=>({hendelser:[]})),api('malebord.php').catch(()=>null),hentMer()]);
 const ma=maGjores(d,ctx,mer);
 /* Idé 7: «Ukens oppsummering» øverst på mandager. */
 const mandag=mandagFlis(mer?.mandag,flis,fliser,type=>ma.apneSak(type));
 return el('div',{class:'idag'},ctx.title('I dag'),mandag?etikett('Mandag'):null,mandag,etikett('Må gjøres'),ma,etikett('I dag på verkstedet'),verkstedetIdag(kal,pop,d.verkstedet));
}

/* ── Mer: bare fliser ─────────────────────────────────────────────────── */
export function merSide(ctx){
 return el('div',{},ctx.title('Mer'),fliser(
  flis({ikon:'♧',tittel:'Medlemmene',tekst:'Se det de ser · legg ut internt',href:'#medlemmene',fremhevet:true}),
  flis({ikon:'◇',tittel:'Kurs',tekst:'Kurs, påmeldte, kursholdere',href:'#kurs'}),
  flis({ikon:'▢',tittel:'Butikk',tekst:'Varer, handlelister, leire',href:'#butikk'}),
  flis({ikon:'⌂',tittel:'Verksted',tekst:'Ovn, dugnad, henting',href:'#verksted'}),
  flis({ikon:'✦',tittel:'Markedsføring',tekst:'Kampanjer, SEO, innlegg',href:'#marked'}),
  flis({ikon:'✎',tittel:'Innhold',tekst:'Tekster og bilder',href:'#innhold'}),
  flis({ikon:'◐',tittel:'Synlighet',tekst:'Slå av og på, også kassa',href:'#synlighet'}),
  flis({ikon:'⚙',tittel:'Oppsett',tekst:'Vipps, frakt, maler, brukere …',href:'#oppsett',rolig:true}),
  flis({ikon:'⚙',tittel:'Verktøy',tekst:'Oppdateringer',href:'#verktoy',rolig:true})));
}

/* ── «+»: alt nytt på ett sted, på alle sider ─────────────────────────── */
export function plussKnapp(){
 const valg=[['kr','Ta betalt',()=>gaa('#kasse')],['+','Gi tid',()=>gaa('#gaver?ny=1')],['♧','Legg ut til medlemmene',()=>gaa('#medlemmene')],['◇','Nytt kurs',()=>gaa('#kurs?ny=1')],
  ['▦','Ny booking',null],['▢','Ny vare',()=>gaa('#butikk?ny=1')],['★','Ny kampanje',()=>gaa('#kampanjer?ny=1')],['✉','Beskjed til deltakere',()=>gaa('#beskjeder?ny=1')]];
 return el('button',{type:'button',class:'ny-pluss','aria-label':'Ny',text:'+',onclick:()=>{
  const ark=sheet('Ny',fliser(...valg.map(([ikon,tittel,gjor])=>flis({ikon,tittel,data:{'data-ny-valg':tittel},onclick:()=>{if(gjor)gjor();else{ark.close();setTimeout(leggTilVelg,0);}}}))));}});
}
