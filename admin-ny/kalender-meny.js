// Resten av kalenderen etter den godkjente skissen (lissom-kalender-skisse.html, 3. oktober 2026):
// høyreklikk-menyer (hMeny, visMeny, tomPlassMeny, dagMeny, notatMeny, venteMeny, kursMeny) med bokstavtaster,
// dra og slipp på PC (koblePC, flyttBekreft med konflikt og «Angre»), hurtigtaster, sidelista, listevisning,
// dagsrapport og på mobil: samme valg i arket, «+»-knappen og kort for avlyste og tynt besatte økter.
// Alt står bak bryteren «Vis/kalendermeny» (kalender.php sender den i «brytere»). Av = kalenderen er som før.
// Ingen nye endepunkter: kurs.php (endredato, dato, nydato, avlys, gjenopprett, visFullt), pamelding.php flytt,
// venteliste.php gi-plass, verkstedet.php kalendernotat/kalendernotatVekk, apningstider.php steng og deltakerliste.php.
// Flytting varsler ikke deltakerne (som i dag); bekreftelsen sier det.
import {el,api,button,link,today,date,shift,sheet,form,field,confirm,toast,courseMutation} from './ui.js';
import {courseStart} from './kursstart-og-betaling.js';
import {startKurs} from './kursstart3.js';
import {menyPaa,gjentaPaa,kursstartPaa,leggTilDeltaker,erOkt,naar,merker,typeKlasse,dagSum,kursholdere,skjulSveve} from './kalender-ark.js';
import {dupliser,kortDato} from './kalender-gjenta.js';

// ── Konteksten kalender.js setter ved hver tegning ──────────────────
// {rot, bred, hendelser(), finn(id), refresh(), apne(e), ark(e,fane), nyDato(kursId,dag,forslag), notat(e,dag,fra),
//  flytt(e), visning(modus,dag), sok(tekst), fokusSok(), dag()}
let ktx=null;
export const settKontekst=c=>{ktx=c;};

const minutter=t=>{const m=/^(\d{1,2}):(\d{2})/.exec(t||'');return m?Number(m[1])*60+Number(m[2]):null;};
const klokke=m=>`${String(Math.floor(m/60)%24).padStart(2,'0')}:${String(m%60).padStart(2,'0')}`;
// Varigheten i minutter for denne dagen; slutt før start = over midnatt.
const varighet=e=>{const s=minutter(e.tid),sl=minutter(e.dagSlutt||e.slutt);if(s===null||sl===null)return 120;return (sl<=s?sl+1440:sl)-s;};
const holderNavn=id=>Number(id)===0?'Ikke tildelt':(kursholdere().find(h=>Number(h.id)===Number(id))?.navn||'Ikke tildelt');
const ferdig=async r=>{toast(r?.beskjed||'Lagret.');await ktx?.refresh();};
const kjor=async(ep,body)=>{try{await ferdig(await api(ep,body));}catch(err){toast(err.message);}};

// «Angre» i 6 sekunder (skissen: toast med Angre). Bruker meldingsfeltet som toast() i ui.js.
export function angre(tekst,fn){
 const boks=el('div',{class:'toast kal-angre',role:'status'},el('span',{text:tekst}),el('button',{type:'button',class:'kal-angre-knapp',text:'Angre',onclick:async()=>{boks.remove();try{await fn();}catch(err){toast(err.message);}}}));
 document.querySelector('#meldinger')?.replaceChildren(boks);setTimeout(()=>boks.remove(),6000);
}

// ── Høyreklikk-menyen (hMeny). Hvert valg: [tekst, handling, tast, rød]. null = skillelinje, false = ikke med. ──
let lag=null;
export function lukkMeny(){if(lag){lag.remove();lag=null;document.removeEventListener('keydown',menyTast,true);}}
function menyTast(ev){
 if(!lag)return;const bs=[...lag.querySelectorAll('[role=menuitem]')];
 if(ev.key==='Escape'){ev.preventDefault();ev.stopPropagation();lukkMeny();return;}
 if(ev.key==='ArrowDown'||ev.key==='ArrowUp'){ev.preventDefault();const j=bs.indexOf(document.activeElement)+(ev.key==='ArrowDown'?1:-1);bs[(j+bs.length)%bs.length]?.focus();return;}
 if(ev.ctrlKey||ev.metaKey||ev.altKey||ev.key.length!==1)return;
 const b=bs.find(x=>x.dataset.tast&&x.dataset.tast.toLocaleLowerCase('nb-NO')===ev.key.toLocaleLowerCase('nb-NO'));
 if(b){ev.preventDefault();ev.stopPropagation();b.click();}
}
const rydd=valg=>valg.filter(v=>v!==false).filter((v,i,a)=>v!==null||(i>0&&i<a.length-1&&a[i-1]!==null));
export function hMeny(x,y,overskrift,valg){
 lukkMeny();skjulSveve();
 const liste=rydd(valg);
 const meny=el('div',{class:'kal-hmeny',role:'menu','aria-label':overskrift||'Valg'},overskrift?el('div',{class:'kal-hmeny-tittel',text:overskrift}):null,
  liste.map(v=>v?el('button',{type:'button',role:'menuitem',class:v[3]?'kal-rod':null,'data-tast':v[2]||null,onclick:()=>{lukkMeny();v[1]();}},el('span',{text:v[0]}),v[2]?el('kbd',{text:v[2]}):null):el('hr',{})));
 const slor=el('div',{class:'kal-slor',onclick:lukkMeny,oncontextmenu:ev=>{ev.preventDefault();lukkMeny();}});
 lag=el('div',{class:'kal-hlag'},slor,meny);document.body.append(lag);
 meny.style.left=Math.max(8,Math.min(x,innerWidth-meny.offsetWidth-8))+'px';
 meny.style.top=Math.max(8,Math.min(y,innerHeight-meny.offsetHeight-8))+'px';
 document.addEventListener('keydown',menyTast,true);
 meny.querySelector('[role=menuitem]')?.focus();
}
// Mobil: de samme valgene som liste i et ark.
export function valgArk(overskrift,valg){
 const liste=rydd(valg);let s;
 s=sheet(overskrift,el('div',{class:'kal-valgliste',role:'menu'},liste.map(v=>v?el('button',{type:'button',role:'menuitem',class:`button ${v[3]?'danger':''}`,onclick:()=>{s.close();v[1]();}},v[0]):el('hr',{}))));
 s.dlg.classList.add('kal-valgark');return s;
}
const vis=(ev,overskrift,valg)=>{if(ev&&ktx?.bred){ev.preventDefault();hMeny(ev.clientX,ev.clientY,overskrift,valg);}else valgArk(overskrift,valg);};

// ── Valgene ──────────────────────────────────────────────────────────
let utklipp=null;
function start(e){const id=Number(e.oktId||e.id);if(kursstartPaa())startKurs(e,{refresh:ktx.refresh,kursholdere:kursholdere(),flytt:ktx.flytt,naar:naar(e),leggTil:etter=>leggTilDeltaker(id,etter)});else courseStart(id,ktx.refresh);}
export function oktValg(e){
 const id=Number(e.oktId||e.id);const ark=f=>ktx.ark(e,f);
 return [
  e.kap&&!e.avlyst?['▶ Start kurset',()=>start(e),'S']:false,
  ['Åpne og se deltakerne',()=>ark('deltakere'),'Å'],
  e.kap&&!e.avlyst?['Legg til deltaker',()=>leggTilDeltaker(id,ferdig),'L']:false,
  e.kap?['Ta betalt',()=>ark('deltakere'),'B']:false,
  e.kap?['Send beskjed til alle',()=>ark('kursdagen'),'E']:false,
  null,
  ['Rediger økta',()=>ark('rediger'),'R'],
  ['Flytt tidspunkt',()=>ktx.flytt(e),'F'],
  ['Bytt kursholder',()=>ark('rediger'),'K'],
  ktx.bred&&e.kursId?['Kopier økta',()=>{utklipp=e;toast('Kopiert. Høyreklikk på en ledig plass og velg «Lim inn».');},'C']:false,
  gjentaPaa()&&!e.avlyst&&e.kursId?['Dupliser til neste uke',async()=>{try{toast(await dupliser(e));await ktx.refresh();}catch(err){if(err.message!=='Avbrutt.')toast(err.message);}},'D']:false,
  gjentaPaa()&&e.kursId?['Gjenta økta …',()=>ktx.nyDato(e.kursId,e.dato,{fra:e.tid,til:e.dagSlutt||e.slutt,kursholderId:e.kursholderId??0,ofte:'uke'}),'G']:false,
  [e.visFullt?'Åpne for påmelding':'Vis som fullbooket',async()=>{if(await confirm('Endre bookingmuligheten?',e.visFullt?'Åpne datoen for nye påmeldinger.':'Sperr datoen for nye påmeldinger. Eksisterende deltakere beholdes.','Bekreft'))await kjor('kurs.php',{handling:'visFullt',oktId:id,paa:e.visFullt?'nei':'ja'});},'U'],
  (e.venteliste||[]).length?['Gi plass fra ventelista',()=>ark('venteliste'),'V']:false,
  e.kap?['Meld keramikken klar',()=>ark('kursdagen'),'H']:false,
  e.kap?['Last ned deltakerliste',()=>{location.href='/api/admin/deltakerliste.php?okt='+id;},'N']:false,
  null,
  [e.avlyst?'Gjenopprett økta':'Avlys økta …',async()=>{if(await confirm(e.avlyst?'Gjenopprett':'Avlys dato',`${e.avlyst?'Gjenopprett':'Avlys'} ${e.tittel} ${e.dato}. Kontroller påmeldte og varsling etterpå.`,e.avlyst?'Gjenopprett':'Avlys dato'))await kjor('kurs.php',{handling:e.avlyst?'gjenopprett':'avlys',oktId:id});},'A',!e.avlyst],
 ];
}
function tomValg(dag,tid,kol){
 const holder=kol!==''&&kol!=null&&!String(kol).startsWith('navn:')?Number(kol):undefined;
 return [
  ['Ny kursdato her',()=>ktx.nyDato(0,dag,{fra:tid,til:klokke(Math.min(minutter(tid)+120,1439)),kursholderId:holder}),'K'],
  ['Notat her',()=>ktx.notat({},dag,tid),'N'],
  utklipp?[`Lim inn «${utklipp.tittel}» her`,()=>limInn(utklipp,dag,tid,holder),'V']:false,
  null,
  ['Dagsrapport for dagen',()=>dagsrapport(dag),'R'],
  ['Vis bare denne dagen',()=>ktx.visning('dag',dag),'D'],
 ];
}
async function limInn(u,dag,tid,holder){
 const sl=minutter(tid)+varighet(u);const slutt=sl<1440?klokke(sl):'23:59';
 if(!await confirm('Lime inn økta?',`${u.tittel} legges inn ${kortDato(dag)} kl. ${tid}–${slutt}.`,'Lim inn'))return;
 const body={handling:'nydato',kursId:u.kursId,start:`${dag}T${tid}`,slutt:`${dag}T${slutt}`,kursholderId:holder!==undefined?holder:(Number.isInteger(u.kursholderId)?u.kursholderId:0)};
 if(u.kap)body.kapasitet=u.kap;
 try{await courseMutation(body);await ferdig({beskjed:`${u.tittel} er lagt inn ${kortDato(dag)} kl. ${tid}.`});}catch(err){if(err.message!=='Avbrutt.')toast(err.message);}
}
function dagValg(dag){
 return [
  ['Vis dagen',()=>ktx.visning('dag',dag),'D'],
  ['Ny kursdato denne dagen',()=>ktx.nyDato(0,dag),'K'],
  ['Notat for hele dagen',()=>ktx.notat({},dag),'N'],
  ['Dagsrapport',()=>dagsrapport(dag),'R'],
  null,
  ['Steng dagen (ferie/helligdag)',async()=>{if(await confirm('Stenge dagen?',`${kortDato(dag)} blir stengt for booking. Kurs som står den dagen, avlyses ikke.`,'Steng dagen'))await kjor('apningstider.php',{handling:'steng',dato:dag});},'T',true],
 ];
}
const notatBody=(e,dato,id)=>({handling:'kalendernotat',id,dato,fra:e.tid||'09:00',til:e.slutt||'',tekst:e.tittel});
function notatValg(e){
 return [
  ['Rediger',()=>ktx.notat(e),'R'],
  ['Flytt til neste dag',async()=>{try{await api('verkstedet.php',notatBody(e,shift(e.dato,1),e.notatId));await ktx.refresh();angre('Notatet er flyttet.',async()=>{await api('verkstedet.php',notatBody(e,e.dato,e.notatId));await ktx.refresh();toast('Angret.');});}catch(err){toast(err.message);}},'F'],
  ['Kopier til hele uka',async()=>{const man=shift(e.dato,-((date(e.dato).getDay()+6)%7));const dager=Array.from({length:7},(_,i)=>shift(man,i)).filter(d=>d!==e.dato);
   if(!await confirm('Kopiere notatet?',`«${e.tittel}» legges inn på de andre ${dager.length} dagene i uka.`,'Kopier'))return;
   try{for(const d of dager)await api('verkstedet.php',notatBody(e,d,0));await ferdig({beskjed:'Notatet ligger nå på alle dagene.'});}catch(err){toast(err.message);}},'U'],
  null,
  ['Slett',async()=>{if(!await confirm('Slett notat','Fjern notatet fra kalenderen.','Slett notat'))return;
   try{await api('verkstedet.php',{handling:'kalendernotatVekk',id:e.notatId});await ktx.refresh();angre('Notatet er slettet.',async()=>{await api('verkstedet.php',notatBody(e,e.dato,0));await ktx.refresh();toast('Angret.');});}catch(err){toast(err.message);}},'S',true],
 ];
}
let vente=[],kurs=[];
function venteValg(w){return [['Gi plass nå',()=>giPlass(w),'G'],['Se alle datoer for kurset',()=>ktx.sok(w.kurs),'D']];}
function kursValg(k){
 return [
  ['Ny kursdato',()=>ktx.nyDato(k.id,ktx.dag()),'K'],
  gjentaPaa()?['Gjenta hver uke …',()=>ktx.nyDato(k.id,ktx.dag(),{ofte:'uke'}),'G']:false,
  ['Rediger kurset',()=>{location.hash='kurs';},'R'],
  ['Se alle datoer',()=>ktx.sok(k.tittel),'D'],
 ];
}

// Gi plass fra ventelista: på en bestemt økt (dra og slipp) eller med valg av dato (fra lista).
export async function giPlass(w,okt){
 if(okt){
  if(await confirm('Gi kursplass?',`Sett ${w.navn} på ${okt.tittel} ${kortDato(okt.dato)} kl. ${okt.tid}. ${w.navn} får beskjed om plassen. Kontroller betaling og bekreftelse etterpå.`,'Gi plass'))await kjor('venteliste.php',{handling:'gi-plass',id:w.id,oktId:Number(okt.oktId||okt.id)});
  return;
 }
 if(!(w.datoer||[]).length){toast('Ingen datoer med ledig plass.');return;}
 form('Gi plass til '+w.navn,[field('oktId','Dato','number',{velg:true,options:w.datoer.map(x=>[x.oktId,`${x.kurs} · ${x.naar} (${x.ledige} ledige)`])})],{},async v=>{await ferdig(await api('venteliste.php',{handling:'gi-plass',id:w.id,oktId:v.oktId}));},{submitLabel:'Gi plass',successText:false});
}
// «Legg til deltaker» uten økt: velg en kommende økt (30 dager fram), så skjemaet som finnes (leggTilDeltaker).
export async function leggTilVelg(){
 let l;try{const fra=today();l=((await api(`kalender.php?fra=${fra}&til=${shift(fra,30)}`)).hendelser||[]).filter(e=>erOkt(e)&&e.kap&&!e.avlyst&&e.dato>=fra&&!String(e.id).startsWith('saml-'));}catch(err){toast(err.message);return;}
 l.sort((a,b)=>`${a.dato}T${a.tid}`.localeCompare(`${b.dato}T${b.tid}`));
 if(!l.length){toast('Ingen kommende økter med påmelding.');return;}
 form('Legg til deltaker',[field('oktId','Økt','number',{velg:true,options:l.map(e=>[e.oktId,`${e.tittel} · ${kortDato(e.dato)} ${e.tid} (${e.pameldt||0} påmeldt${(e.pameldt||0)===1?'':'e'})`])})],{},async v=>{setTimeout(()=>leggTilDeltaker(v.oktId,ferdig));},{submitLabel:'Videre',successText:false});
}
async function hentVente(){try{vente=(await api('venteliste.php')).venteliste||[];}catch{vente=[];}return vente;}
async function velgVente(){const l=await hentVente();if(!l.length){toast('Ingen står på venteliste.');return;}valgArk('Fra ventelista',l.map(w=>[`${w.navn} · ${w.kurs}`,()=>giPlass(w)]));}

// ── Flytt økta (dra og slipp): bekreftelse med konflikt og påmeldte, «Angre» i 6 s ──
export async function flyttBekreft(e,dag,tid,kol){
 const id=Number(e.oktId||e.id);
 if(!erOkt(e)||e.avlyst)return;
 // Kurs over flere dager: dagene hører sammen og flyttes med «Flytt tidspunkt» (som kjenner samlingene).
 if(String(e.id).startsWith('saml-')||(e.dagSlutt&&e.slutt&&e.dagSlutt!==e.slutt)||(ktx.hendelser()||[]).some(x=>String(x.id).startsWith('saml-'+id+'-'))){toast('Kurs over flere dager flyttes med «Flytt tidspunkt».');ktx.flytt(e);return;}
 const varig=varighet(e),ns=minutter(tid),ne=ns+varig;
 const nyHolder=kol!==''&&kol!=null&&!String(kol).startsWith('navn:')?Number(kol):null;
 const bytt=nyHolder!==null&&nyHolder!==Number(e.kursholderId||0);
 const holderId=bytt?nyHolder:Number(e.kursholderId||0);
 if(dag===e.dato&&tid===e.tid&&!bytt)return;
 const opptatt=holderId?(ktx.hendelser()||[]).find(x=>erOkt(x)&&!x.avlyst&&Number(x.oktId)!==id&&x.dato===dag&&Number(x.kursholderId)===holderId&&minutter(x.tid)<ne&&ns<minutter(x.tid)+varighet(x)):null;
 const n=Number(e.pameldt)||0;
 const ja=await new Promise(svar=>{let s;const slutt=v=>{svar(v);s.close();};
  s=sheet('Flytte økta?',el('div',{class:'kal-flytt'},
   el('p',{},el('strong',{text:e.tittel}),el('br'),`${kortDato(e.dato)} ${e.tid} → `,el('strong',{text:`${kortDato(dag)} ${tid}–${klokke(ne)}`}),bytt?[el('br'),`Kursholder: ${e.holder||'Ikke tildelt'} → `,el('strong',{text:holderNavn(nyHolder)})]:null),
   opptatt?el('p',{class:'notice error kal-konflikt',role:'alert',text:`${holderNavn(holderId)} er opptatt da: ${opptatt.tittel} ${opptatt.tid}–${opptatt.dagSlutt||opptatt.slutt}.`}):null,
   n?el('p',{class:'kal-paameldte',text:`${n} ${n===1?'påmeldt':'påmeldte'}. De får ikke beskjed automatisk.`}):null,
   el('div',{class:'sheet-footer'},button('Avbryt',()=>slutt(false)),button(opptatt?'Flytt likevel':'Ja, flytt økta',()=>slutt(true),'primary'))));
  s.dlg.addEventListener('close',()=>svar(false));});
 if(!ja)return;
 const tidspunkt=(d,s,m)=>({start:`${d}T${klokke(s)}`,slutt:`${m>=1440?shift(d,1):d}T${klokke(m%1440)}`});
 const s0=minutter(e.tid);const gml=tidspunkt(e.dato,s0,s0+varig),ny=tidspunkt(dag,ns,ne);
 try{
  await courseMutation({handling:'endredato',oktId:id,...ny});
  if(bytt)await courseMutation({handling:'dato',oktId:id,kursholderId:nyHolder});
 }catch(err){if(err.message!=='Avbrutt.')toast(err.message);await ktx.refresh();return;}
 await ktx.refresh();
 angre(`Flyttet til ${kortDato(dag)} kl. ${tid}.`,async()=>{
  await courseMutation({handling:'endredato',oktId:id,...gml});
  if(bytt)await courseMutation({handling:'dato',oktId:id,kursholderId:Number(e.kursholderId||0)});
  await ktx.refresh();toast('Angret.');});
}
async function byttDato(b,mal){
 if(!await confirm('Bytte dato?',`Flytt ${b.navn} til ${mal.tittel} ${kortDato(mal.dato)} kl. ${mal.tid}. ${b.navn} får e-post om den nye datoen.`,'Bytt dato'))return;
 await kjor('pamelding.php',{handling:'flytt',id:b.id,oktId:Number(mal.oktId||mal.id)});
}

// ── Dra og slipp og menyene på PC (koblePC i skissen) ───────────────
const celleVed=(x,y)=>document.elementsFromPoint(x,y).find(n=>n.classList?.contains('kp-celle'));
const oktVed=(x,y)=>{const b=document.elementsFromPoint(x,y).find(n=>n.matches?.('.kp-brikke[data-id]'));const e=b&&ktx.finn(b.dataset.id);return e&&erOkt(e)&&e.kap&&!e.avlyst?{b,e}:null;};
const kanDras=e=>e&&erOkt(e)&&!e.avlyst&&!String(e.id).startsWith('saml-');
let dra=null;
const fjernMerke=()=>document.querySelectorAll('.kal-over,.kal-slipp-over').forEach(x=>x.classList.remove('kal-over','kal-slipp-over'));
// Brikkene som kan dras, får draggable etter hver tegning.
export function merkDra(rot){for(const b of rot.querySelectorAll('.kp-brikke[data-id]'))if(kanDras(ktx.finn(b.dataset.id)))b.draggable=true;}
export function koblePC(flate){
 flate.addEventListener('contextmenu',ev=>{
  const t=ev.target;let r;
  if((r=t.closest('[data-vente]'))){const w=vente[Number(r.dataset.vente)];if(w)vis(ev,`${w.navn} · venter på ${w.kurs}`,venteValg(w));return;}
  if((r=t.closest('[data-kurs]'))){const k=kurs[Number(r.dataset.kurs)];if(k)vis(ev,k.tittel,kursValg(k));return;}
  if((r=t.closest('.kp-brikke[data-id],.kal-lr[data-id]'))){const e=ktx.finn(r.dataset.id);if(!e)return;
   if(erOkt(e))vis(ev,`${e.tittel} · ${date(e.dato).toLocaleDateString('nb-NO',{weekday:'long'})} ${e.tid}`,oktValg(e));
   else if(e.type==='notat'&&e.notatId)vis(ev,'Notat',notatValg(e));return;}
  if((r=t.closest('.kp-kolhode[data-dato]'))&&r.dataset.kol===''){vis(ev,kortDato(r.dataset.dato),dagValg(r.dataset.dato));return;}
  if(t.closest('.kp-dagtittel')){vis(ev,kortDato(ktx.dag()),dagValg(ktx.dag()));return;}
  if((r=t.closest('.kp-celle'))){vis(ev,`${kortDato(r.dataset.dato)} kl. ${r.dataset.akse}`,tomValg(r.dataset.dato,r.dataset.akse,r.dataset.kol));}
 });
 flate.addEventListener('click',ev=>{
  const t=ev.target;let r;
  if((r=t.closest('[data-vente]'))){const w=vente[Number(r.dataset.vente)];if(w)giPlass(w);return;}
  if((r=t.closest('[data-kurs]'))){const k=kurs[Number(r.dataset.kurs)];if(k)ktx.nyDato(k.id,ktx.dag());return;}
  if(t.closest('.kp-brikke,button,a,input,select'))return;
  if((r=t.closest('.kp-kolhode[data-dato]'))&&r.dataset.kol===''){ktx.visning('dag',r.dataset.dato);return;}
  if((r=t.closest('.kp-celle'))){hMeny(ev.clientX,ev.clientY,`${kortDato(r.dataset.dato)} kl. ${r.dataset.akse}`,tomValg(r.dataset.dato,r.dataset.akse,r.dataset.kol));}
 });
 flate.addEventListener('dragstart',ev=>{
  skjulSveve();const t=ev.target;let r;
  if((r=t.closest?.('[data-booking]'))){const b=r.closest('.kp-brikke[data-id]');dra={type:'delt',id:Number(r.dataset.booking),navn:r.dataset.navn,fra:b?String(ktx.finn(b.dataset.id)?.oktId):''};}
  else if((r=t.closest?.('.kp-brikke[data-id]'))){const e=ktx.finn(r.dataset.id);if(!kanDras(e)){ev.preventDefault();return;}dra={type:'okt',e};}
  else if((r=t.closest?.('[data-kurs]')))dra={type:'kurs',k:kurs[Number(r.dataset.kurs)]};
  else if((r=t.closest?.('[data-vente]')))dra={type:'vente',w:vente[Number(r.dataset.vente)]};
  else return;
  ev.dataTransfer.setData('text/plain',dra.type);ev.dataTransfer.effectAllowed='move';
 });
 const mal=ev=>{
  if(!dra)return null;
  if(dra.type==='okt'||dra.type==='kurs'){const c=celleVed(ev.clientX,ev.clientY);return c?{node:c,klasse:'kal-over'}:null;}
  const o=oktVed(ev.clientX,ev.clientY);if(!o||(dra.type==='delt'&&String(o.e.oktId)===dra.fra))return null;return {node:o.b,klasse:'kal-slipp-over',e:o.e};
 };
 flate.addEventListener('dragover',ev=>{const m=mal(ev);fjernMerke();if(m){ev.preventDefault();ev.dataTransfer.dropEffect='move';m.node.classList.add(m.klasse);}});
 flate.addEventListener('drop',ev=>{
  const m=mal(ev);fjernMerke();const d=dra;dra=null;if(!m||!d)return;ev.preventDefault();
  if(d.type==='okt')flyttBekreft(d.e,m.node.dataset.dato,m.node.dataset.akse,m.node.dataset.kol);
  else if(d.type==='kurs'){const kol=m.node.dataset.kol;const holder=kol!==''&&kol!=null&&!kol.startsWith('navn:')?Number(kol):undefined;const tid=m.node.dataset.akse;ktx.nyDato(d.k.id,m.node.dataset.dato,{fra:tid,til:klokke(Math.min(minutter(tid)+120,1439)),kursholderId:holder});}
  else if(d.type==='vente')giPlass(d.w,m.e);
  else if(d.type==='delt')byttDato({id:d.id,navn:d.navn},m.e);
 });
 flate.addEventListener('dragend',()=>{dra=null;fjernMerke();});
}

// ── Sidelista på PC: Venteliste · Legg til deltaker · Dra inn kurs ──
let sideSkjult=false;try{sideSkjult=localStorage.getItem('kal-sideliste')==='skjult';}catch{}
export const sidelisteSkjult=()=>sideSkjult;
export function byttSideliste(){sideSkjult=!sideSkjult;try{localStorage.setItem('kal-sideliste',sideSkjult?'skjult':'vist');}catch{}}
export function sideliste(){
 const teller=el('span',{class:'kal-teller'});
 const venteBoks=el('div',{class:'kal-sl-liste'},el('small',{class:'muted',text:'Henter …'}));
 const kursBoks=el('div',{class:'kal-sl-liste'},el('small',{class:'muted',text:'Henter …'}));
 const aside=el('aside',{class:'kal-sideliste','aria-label':'Sideliste'},
  el('section',{class:'kal-boks'},el('h3',{},'Venteliste ',teller),venteBoks,el('small',{text:'Dra personen inn på en økt, eller trykk for å gi plass.'})),
  el('section',{class:'kal-boks'},el('h3',{text:'Legg til deltaker'}),button('+ Legg til deltaker',leggTilVelg,'primary kal-bred'),el('small',{text:'Eller dra en deltaker fra en økt til en annen dato.'})),
  el('section',{class:'kal-boks'},el('h3',{text:'Dra inn kurs'}),kursBoks,link('+ Nytt kurs','#kurs','kal-bred'),el('small',{text:'Dra kurset til dag og klokkeslett.'})));
 hentVente().then(l=>{teller.textContent=String(l.length);venteBoks.replaceChildren(...(l.length?l.map((w,i)=>el('div',{class:'kal-dra',draggable:'true','data-vente':String(i),role:'button',tabindex:0,'aria-label':`${w.navn}, venter på ${w.kurs}`},el('b',{text:w.navn}),el('small',{text:w.kurs}))):[el('small',{class:'muted',text:'Ingen venter.'})]));});
 api('kurs.php').then(d=>{kurs=(d.kurs||[]).filter(k=>k.status!=='arkivert');kursBoks.replaceChildren(...kurs.map((k,i)=>el('span',{class:'kal-kursbrikke',draggable:'true','data-kurs':String(i),role:'button',tabindex:0},el('i',{class:`kal-prikk ${typeKlasse(k)}`,'aria-hidden':true}),k.tittel)));}).catch(err=>kursBoks.replaceChildren(el('small',{class:'muted',text:err.message})));
 return aside;
}

// ── Listevisning: én rad per hendelse, dag for dag ───────────────────
export function listeVisning(hendelser,apne){
 const dager=[...new Set(hendelser.map(e=>e.dato))].sort();
 if(!dager.length)return el('p',{class:'empty',text:'Ingen hendelser.'});
 return el('div',{class:'kal-liste'},dager.map(d=>{const l=hendelser.filter(e=>e.dato===d).sort((a,b)=>String(a.tid).localeCompare(String(b.tid)));
  return el('section',{},el('h3',{},date(d).toLocaleDateString('nb-NO',{weekday:'long',day:'numeric',month:'long'}),' ',el('span',{class:'kal-sum',text:dagSum(l)})),
   l.map(e=>el('button',{type:'button',class:'kal-lr','data-id':String(e.id),onclick:()=>apne(e)},el('span',{class:`kal-m ${typeKlasse(e)} kal-lr-tid`,text:`${e.tid||''}${e.slutt?'–'+e.slutt:''}`}),el('span',{class:'kal-lr-hva'},el('b',{text:e.tittel}),e.holder?el('small',{text:e.holder}):null),el('span',{class:'kal-merker'},merker(e)))));}));
}

// ── Dagsrapport (vis og last ned som CSV) ───────────────────────────
export async function dagsrapport(dag){
 let l;try{l=((await api(`kalender.php?fra=${dag}&til=${dag}`)).hendelser||[]).filter(e=>e.dato===dag&&erOkt(e));}catch(err){toast(err.message);return;}
 l.sort((a,b)=>String(a.tid).localeCompare(String(b.tid)));
 const lastNed=()=>{
  const felt=v=>{const t=String(v??'');return /[;"\n]/.test(t)?`"${t.replace(/"/g,'""')}"`:t;};
  const rader=[['Dato','Tid','Kurs','Kursholder','Deltaker','Antall','Status','Merknad']];
  for(const e of l){const d=e.deltakere||[];if(!d.length)rader.push([dag,e.tid,e.tittel+(e.avlyst?' (avlyst)':''),e.holder||'Ikke tildelt','','','','']);
   for(const p of d)rader.push([dag,e.tid,e.tittel+(e.avlyst?' (avlyst)':''),e.holder||'Ikke tildelt',p.navn,p.antall||1,p.status,p.merknad||'']);}
  const blob=new Blob(['﻿'+rader.map(r=>r.map(felt).join(';')).join('\r\n')],{type:'text/csv;charset=utf-8'});
  const a=el('a',{href:URL.createObjectURL(blob),download:`dagsrapport-${dag}.csv`});document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(a.href),1000);
 };
 let s;
 s=sheet(`Dagsrapport · ${kortDato(dag)}`,el('div',{class:'kal-rapport'},
  l.length?l.map(e=>el('section',{},el('h3',{},`${e.tid} ${e.tittel} `,el('small',{text:[`${e.pameldt||0} påmeldt${(e.pameldt||0)===1?'':'e'}`,e.holder||'Ikke tildelt',e.avlyst?'Avlyst':null].filter(Boolean).join(' · ')})),
   (e.deltakere||[]).length?el('ul',{},e.deltakere.map(p=>el('li',{},el('span',{text:p.navn+(p.antall>1?` (${p.antall})`:'')}),p.status==='Ikke betalt'?el('span',{class:'kal-m kal-m-skylder',text:'ubetalt'}):null,p.merknad?el('i',{class:'muted',text:`(${p.merknad})`}):null))):el('p',{class:'muted',text:'Ingen påmeldte'}))):el('p',{class:'muted',text:'Ingen økter.'}),
  el('div',{class:'sheet-footer'},button('Lukk',()=>s.close()),button('Last ned dagsrapport',lastNed,'primary'))));
 s.dlg.classList.add('kal-rapport-ark');
}

// ── Mobil: samme valg i arket, «+»-knappen og kortene øverst ────────
export function trykkMobil(e,vanlig){
 if(menyPaa()&&erOkt(e))return valgArk(e.tittel,oktValg(e));
 if(menyPaa()&&e.type==='notat'&&e.notatId)return valgArk('Notat',notatValg(e));
 return vanlig(e);
}
export function plussKnapp(dag){
 return el('button',{type:'button',class:'kal-pluss','aria-label':'Legg til',text:'+',onclick:()=>valgArk('Legg til',[['Ny kursdato',()=>ktx.nyDato(0,dag())],['Notat',()=>ktx.notat({},dag())],['Deltaker',leggTilVelg],['Fra ventelista',velgVente]])});
}
// Avlyste og tynt besatte økter (under halvfulle) de neste sju dagene, øverst på mobil.
export function varselkort(hendelser,apne){
 const idag=today(),siste=shift(idag,6);const innen=e=>e.dato>=idag&&e.dato<=siste&&erOkt(e)&&e.type!=='pop'&&!String(e.id).startsWith('saml-');
 const dag=d=>date(d).toLocaleDateString('nb-NO',{weekday:'short'}).replace('.','');
 const tynne=hendelser.filter(e=>innen(e)&&!e.avlyst&&e.kap&&(Number(e.pameldt)||0)/e.kap<.5);
 const avlyste=hendelser.filter(e=>innen(e)&&e.avlyst);
 return [...tynne.map(e=>el('button',{type:'button',class:'kal-obs kal-tynt',onclick:()=>apne(e)},el('span',{text:`Få påmeldte: ${e.tittel} ${dag(e.dato)}`}),el('b',{text:`${e.pameldt||0}/${e.kap}`}))),
  ...avlyste.map(e=>el('button',{type:'button',class:'kal-obs kal-avl',onclick:()=>apne(e)},el('span',{text:`Avlyst: ${e.tittel} ${dag(e.dato)}`}),el('b',{text:'Se'})))];
}

// ── Hurtigtaster (PC): N ny kursdato, 1–4 visning, / søk. Esc lukker menyen (menyTast) og arkene (dialog). ──
if(typeof document!=='undefined')document.addEventListener('keydown',ev=>{
 if(!ktx||!ktx.bred||!menyPaa()||!ktx.rot?.isConnected||lag)return;
 const a=document.activeElement;
 if(document.querySelector('dialog[open]')||/^(INPUT|SELECT|TEXTAREA)$/.test(a?.tagName||'')||a?.isContentEditable||ev.ctrlKey||ev.metaKey||ev.altKey)return;
 const v={'1':'dag','2':'uke','3':'maaned','4':'liste'}[ev.key];
 if(v){ev.preventDefault();ktx.visning(v);return;}
 if(ev.key==='n'||ev.key==='N'){ev.preventDefault();ktx.nyDato(0,ktx.dag());return;}
 if(ev.key==='/'){ev.preventDefault();ktx.fokusSok();}
});
