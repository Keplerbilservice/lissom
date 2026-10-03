// Kalenderen, bølge 1 (eieren godkjente skissen 3. oktober 2026): fargekoder per type, typeknapper, merker på kortene,
// svevekort på PC, Paint on Pots-tidene slått sammen, dagsoppsummering og økt-arket med fanene Deltakere · Venteliste · Kursdagen · Rediger.
// Alt står bak bryteren «Vis/kalenderark» (kalender.php sender den i «brytere»). Av = kalenderen er som før, og ingenting her brukes.
// Arket lager ingen nye veier: alt går til endepunktene som finnes (pamelding.php, venteliste.php, beskjed.php, ferdigbrent.php, kursholdere.php, kurs.php).
import {el,api,button,link,badge,date,sheet,form,field,confirm,toast,courseMutation} from './ui.js';
import {bookingPayments,courseStart} from './kursstart-og-betaling.js';

// ── Bryteren ─────────────────────────────────────────────────────────
let brytere={},holdere=[];
// Fra hvert svar fra kalender.php: bryterne, og kursholderne til «Rediger» i arket.
export const settBrytere=(b,kh)=>{if(b&&typeof b==='object')brytere=b;if(Array.isArray(kh))holdere=kh;};
export const arkPaa=()=>brytere.kalenderark===true;
export const kursholdere=()=>holdere;

// ── Typene og fargene (som klTypeInfo i gamle admin) ────────────────
export const TYPER=[['kurs','Kurs'],['event','Event'],['pop','Paint on Pots'],['brenning','Brenning'],['verksted','Verksted'],['notat','Notat']];
// Brenning er skjult når kalenderen åpnes, som før. Valget huskes mellom oppfriskninger.
export const skjulte=new Set(['brenning']);
export const synligType=e=>!skjulte.has(e.type);
export const typeKlasse=e=>`kal-t-${TYPER.some(([t])=>t===e.type)?e.type:'kurs'}`;
// Trykk på en farge viser eller skjuler typen. tegn: tegner kalenderen på nytt.
export function typeKnapper(tegn,klasse=''){
 return el('div',{class:`kal-typer ${klasse}`,role:'group','aria-label':'Vis typer'},TYPER.map(([t,n])=>el('button',{type:'button',class:'kal-type','data-type':t,'aria-pressed':String(!skjulte.has(t)),onclick:ev=>{skjulte.has(t)?skjulte.delete(t):skjulte.add(t);ev.currentTarget.setAttribute('aria-pressed',String(!skjulte.has(t)));tegn();}},el('span',{class:`kal-prikk kal-t-${t}`,'aria-hidden':true}),n)));
}
export const filterEndret=()=>!(skjulte.size===1&&skjulte.has('brenning'));

// ── Merkene på kortene ──────────────────────────────────────────────
const erOkt=e=>Number(e.oktId)>0&&['kurs','event','pop'].includes(e.type);
export const ubetalte=e=>(e.deltakere||[]).filter(p=>p.status==='Ikke betalt').length;
export function merker(e,{kort=false}={}){
 if(e.avlyst)return [el('span',{class:'kal-m kal-m-skylder',text:'Avlyst'})];
 if(!e.kap)return [];
 const ut=[el('span',{class:'kal-m kal-m-plass',text:`${e.pameldt||0}/${e.kap}`})];
 const n=ubetalte(e);
 if(n)ut.push(el('span',{class:'kal-m kal-m-skylder',text:`${n} ubetalt`}));else if(e.pameldt)ut.push(el('span',{class:'kal-m kal-m-ok',text:'Alle betalt'}));
 if(e.nye)ut.push(el('span',{class:'kal-m kal-m-ny',text:kort?`+${e.nye}`:`+${e.nye} nye`}));
 if(!kort&&(e.deltakere||[]).some(p=>p.merknad))ut.push(el('span',{class:'kal-m kal-m-merk',text:'✎ merknad'}));
 return ut;
}
export function initialer(e){
 const d=e.deltakere||[];if(!d.length)return null;
 return el('span',{class:'kal-bilder','aria-hidden':true},d.slice(0,5).map(p=>el('span',{text:(String(p.navn||'?').trim()[0]||'?').toLocaleUpperCase('nb-NO')})),d.length>5?el('span',{text:`+${d.length-5}`}):null);
}
// «2 økter · 17 påmeldt» for én dag: kurs, eventer og Paint on Pots som ikke er avlyst.
export function dagSum(hendelser){
 const k=hendelser.filter(e=>!e.avlyst&&['kurs','event','pop'].includes(e.type));
 if(!k.length)return '–';const p=k.reduce((a,e)=>a+(Number(e.pameldt)||0),0);
 return `${k.length} ${k.length===1?'økt':'økter'} · ${p} påmeldt`;
}

// ── Paint on Pots: tidene en dag slås sammen til én linje ───────────
// Bare tider laget av åpningstida (auto). Tider ingen har booket, kommer ikke fra kalender.php (Apent::skjulUtenBooking).
// En tid som står alene, vises som før.
export function slaaSammen(hendelser){
 const grupper=new Map();
 for(const e of hendelser){if(e.type==='pop'&&e.auto&&!e.avlyst){const n=`${e.dato}|${e.kursId}`;if(!grupper.has(n))grupper.set(n,[]);grupper.get(n).push(e);}}
 const samlet=new Map();
 for(const [n,l] of grupper){if(l.length<2)continue;const s=[...l].sort((a,b)=>String(a.tid).localeCompare(String(b.tid)));
  samlet.set(n,{...s[0],id:`pop-${s[0].dato}-${s[0].kursId}`,oktId:0,tid:s[0].tid,slutt:s.reduce((m,x)=>String(x.slutt||x.tid)>m?String(x.slutt||x.tid):m,''),pameldt:s.reduce((a,x)=>a+(Number(x.pameldt)||0),0),kap:0,deltakere:s.flatMap(x=>x.deltakere||[]),venteliste:[],nye:s.reduce((a,x)=>a+(Number(x.nye)||0),0),sammen:s});}
 const ut=[],brukt=new Set();
 for(const e of hendelser){const n=`${e.dato}|${e.kursId}`;if(e.type==='pop'&&e.auto&&!e.avlyst&&samlet.has(n)){if(!brukt.has(n)){brukt.add(n);ut.push(samlet.get(n));}continue;}ut.push(e);}
 return ut;
}
export const tiderTekst=e=>`${e.sammen.length} tider booket`;
// «Vis tidene»: lista under linja. Hver tid åpner sitt eget ark.
export function tidene(e,apne){
 const liste=el('div',{class:'kal-tider',hidden:true},e.sammen.map(t=>el('button',{type:'button',class:'kal-tid',onclick:ev=>{ev.stopPropagation();apne(t);}},`${t.tid} · ${t.pameldt||0} pers.`)));
 const knapp=el('button',{type:'button',class:'kal-vis-tider','aria-expanded':'false',text:'Vis tidene',onclick:ev=>{ev.stopPropagation();liste.hidden=!liste.hidden;knapp.textContent=liste.hidden?'Vis tidene':'Skjul tidene';knapp.setAttribute('aria-expanded',String(!liste.hidden));}});
 return [knapp,liste];
}

// ── Svevekort på PC: deltakerne og betalingen når musa står over kortet ──
let sveve=null;
const kanSveve=()=>typeof matchMedia==='function'&&matchMedia('(hover:hover) and (pointer:fine)').matches;
export function skjulSveve(){if(sveve)sveve.hidden=true;}
// Tegnes kalenderen på nytt eller byttes siden mens kortet vises, forsvinner brikka uten «mouseleave».
if(typeof addEventListener==='function')addEventListener('hashchange',skjulSveve);
export function svevekort(node,e){
 if(!e.kap||e.sammen)return;
 node.addEventListener('mouseenter',()=>{if(!kanSveve())return;if(!sveve||!sveve.isConnected){sveve=el('div',{class:'kal-sveve',role:'tooltip'});document.body.append(sveve);}
  const d=e.deltakere||[];
  sveve.replaceChildren(el('h4',{text:e.tittel}),el('div',{class:'muted',text:[naar(e),e.holder].filter(Boolean).join(' · ')}),el('div',{class:'kal-merker'},merker(e)),
   el('ul',{},d.slice(0,8).map(p=>el('li',{},el('span',{},p.navn,p.ny?el('span',{class:'kal-m kal-m-ny',text:'ny'}):null),el('span',{class:`kal-m ${p.status==='Betalt'?'kal-m-ok':p.status==='Ikke betalt'?'kal-m-skylder':'kal-m-plass'}`,text:p.status==='Ikke betalt'?'Ubetalt':p.status}))),d.length>8?el('li',{class:'muted',text:`+${d.length-8} til`}):null));
  const r=node.getBoundingClientRect();let x=r.right+8;if(x+270>innerWidth)x=r.left-268;sveve.style.left=Math.max(8,x)+'px';sveve.style.top=Math.max(8,Math.min(r.top,innerHeight-330))+'px';sveve.hidden=false;});
 node.addEventListener('mouseleave',skjulSveve);node.addEventListener('click',skjulSveve);
}

// ── Økt-arket ───────────────────────────────────────────────────────
const naar=e=>`${date(e.dato).toLocaleDateString('nb-NO',{weekday:'short',day:'numeric',month:'short'})} · ${e.tid||''}${e.slutt?'–'+e.slutt:''}`;
const typeNavn=t=>(TYPER.find(([k])=>k===t)||[,'Kurs'])[1];
const minutter=t=>{const m=/^(\d{1,2}):(\d{2})/.exec(t||'');return m?Number(m[1])*60+Number(m[2]):null;};
// Varigheten i timer for akkurat denne dagen (dagSlutt fra kalender.php; på dag 1 av et flerdagerskurs er «slutt» siste dags sluttid).
// Slutt før start = over midnatt.
const timer=e=>{const s=minutter(e.tid),sl=minutter(e.dagSlutt||e.slutt);if(s===null||sl===null)return 0;return ((sl<=s?sl+1440:sl)-s)/60;};
const tall=t=>String(Math.round(t*100)/100).replace('.',',');
let arkFane='deltakere';

// Paint on Pots-linja i måneden: ett ark med tidene, hver åpner sitt eget.
export function tiderArk(e,apne){const s=sheet(`${e.tittel} · ${tiderTekst(e)}`,el('div',{class:'kal-tider'},e.sammen.map(t=>el('button',{type:'button',class:'kal-tid',onclick:()=>{s.close();apne(t);}},`${t.tid} · ${t.pameldt||0} pers.`))));}

// e: hendelsen fra kalender.php. o: {refresh, kursholdere, flytt (moveDate i kalender.js)}.
export function oktArk(e,o){
 skjulSveve();
 const id=Number(e.oktId||e.id);const deltakere=e.deltakere||[];const vente=e.venteliste||[];
 if(!e.kap&&arkFane==='deltakere')arkFane='rediger';
 const panel=el('div',{class:'kal-panel',role:'tabpanel'});
 const faner=[['deltakere',`Deltakere (${deltakere.length})`],['venteliste','Venteliste'],['kursdagen','Kursdagen'],['rediger','Rediger']];
 const faneRad=el('div',{class:'kal-faner',role:'tablist'});
 const hode=el('div',{class:'kal-ark-hode'},
  el('span',{class:`kal-m kal-type-pille ${typeKlasse(e)}`,text:typeNavn(e.type)}),
  el('p',{class:'muted',text:[naar(e),e.samling,e.holder||'Ikke tildelt'].filter(Boolean).join(' · ')}),
  e.kap?el('div',{class:'kal-merker'},merker(e)):null,
  e.kap&&!e.avlyst?button('▶ Start kurset',()=>{s.close();courseStart(id,o.refresh);},'primary'):null,
  faneRad);
 const s=sheet(e.tittel,el('div',{},hode,panel));s.dlg.classList.add('kal-ark');
 // Etter en handling: lukk arket, hent kalenderen på nytt og si hva som skjedde.
 const ferdig=async r=>{toast(r?.beskjed||'Lagret.');s.close();await o.refresh();};
 const kjor=async(ep,body)=>{try{await ferdig(await api(ep,body));}catch(err){toast(err.message);}};
 const sporOgKjor=async(tittel,tekst,ja,ep,body)=>{if(await confirm(tittel,tekst,ja))await kjor(ep,body);};
 function vis(f){arkFane=f;faneRad.replaceChildren(...faner.map(([k,n])=>el('button',{type:'button',role:'tab','aria-selected':String(k===f),'data-fane':k,text:n,onclick:()=>vis(k)})));panel.replaceChildren(...[innhold(f)].flat(Infinity).filter(Boolean));}
 function innhold(f){
  if(f==='deltakere'){
   if(!e.kap)return el('p',{class:'muted',text:'Denne hendelsen har ingen påmelding.'});
   return [button('+ Legg til deltaker',leggTil,'primary kal-bred'),
    deltakere.length?deltakere.map(p=>el('div',{class:'kal-delt'},
     el('div',{class:'kal-info'},el('b',{},p.navn,p.ny?el('span',{class:'kal-m kal-m-ny',text:'ny'}):null),el('small',{text:[p.merknad?'✎ '+p.merknad:'',p.antall>1?`${p.antall} plasser`:'',p.tlf,p.epost].filter(Boolean).join(' · ')})),
     el('div',{class:'kal-rad'},p.status==='Betalt'?badge('Betalt','good'):p.status==='Ikke betalt'?button('Ta betalt',()=>{s.close();bookingPayments(p.bookingId,o.refresh);},'primary kal-liten'):badge(p.status),
      el('button',{type:'button',class:'button kal-liten kal-mer','aria-label':`Mer for ${p.navn}`,'aria-haspopup':'menu',text:'⋯',onclick:ev=>deltMeny(p,ev.currentTarget)})))):el('p',{class:'muted',text:'Ingen påmeldte ennå.'})];
  }
  if(f==='venteliste')return vente.length?vente.map(w=>el('div',{class:'kal-delt'},
   el('div',{class:'kal-info'},el('b',{text:w.navn}),el('small',{text:[w.paaKurset?'Venter på kurset':`Plass ${w.posisjon}`,w.varslet?'Varslet':'',w.status].filter(Boolean).join(' · ')})),
   button('Gi plass',()=>sporOgKjor('Gi kursplass?',`Sett ${w.navn} på denne kursdatoen. ${w.navn} får beskjed om plassen. Kontroller betaling og bekreftelse etterpå.`,'Gi plass','venteliste.php',{handling:'gi-plass',id:w.id,oktId:id}),'primary kal-liten'))):el('p',{class:'muted',text:'Ingen står på venteliste til dette kurset.'});
  if(f==='kursdagen'){const t=timer(e),foert=Number(e.timerFoert)||0;
   // Sperre mot dobbeltføring: er det alt ført timer på kursholderen for dette kurset denne dagen, står det over knappen, og knappen spør «Er du sikker?».
   const forTimer=()=>foert>0?sporOgKjor('Er du sikker?',`Det er alt ført ${tall(foert)} t på ${e.holder} for ${e.tittel} ${e.dato}. Før ${tall(t)} t til?`,'Før timer','kursholdere.php',{handling:'timer',id:e.kursholderId,dato:e.dato,timer:Math.round(t*100)/100,hva:e.tittel})
    :sporOgKjor('Før arbeidstimer',`Før ${tall(t)} timer på ${e.holder} for ${e.tittel} ${e.dato}.`,'Før timer','kursholdere.php',{handling:'timer',id:e.kursholderId,dato:e.dato,timer:Math.round(t*100)/100,hva:e.tittel});
   return el('div',{class:'kal-verktoy'},
   e.kap&&!e.avlyst?button('▶ Start kurset',()=>{s.close();courseStart(id,o.refresh);},'primary'):null,
   betalte()?button('Send beskjed til alle',beskjed):null,
   deltakere.length?button('Meld keramikken klar for henting',()=>sporOgKjor('Meld keramikken klar',`De ${deltakere.length} deltakerne får e-post om at keramikken kan hentes.`,'Send','ferdigbrent.php',{handling:'meld-alle',oktId:id})):null,
   e.kursholderId&&t>0&&foert>0?el('p',{class:'muted kal-foert',text:`Ført ${tall(foert)} t ${date(e.dato).toLocaleDateString('nb-NO',{day:'numeric',month:'short'})}`}):null,
   e.kursholderId&&t>0?button(`Før ${tall(t)} t på ${e.holder}`,forTimer):null,
   link('Last ned deltakerliste','/api/admin/deltakerliste.php?okt='+id));}
  return rediger();
 }
 // ⋯ per deltaker. Menyen ligger inne i arket (alt utenfor et åpent ark kan ikke trykkes på).
 function deltMeny(p,anker){
  s.dlg.querySelector('.kal-meny')?.remove();
  const valg=[['Bytt dato',()=>byttDato(p)],['Send bekreftelse på nytt',()=>sporOgKjor('Send bekreftelse',`Send påmeldingsbekreftelse til ${p.navn}.`,'Send bekreftelse','pamelding.php',{handling:'bekreftelse',id:p.bookingId})],
   ['Møtte ikke',()=>sporOgKjor('Møtte ikke',`${p.navn} markeres «Møtte ikke».`,'Møtte ikke','pamelding.php',{handling:'status',id:p.bookingId,status:'ikke_mott'})],
   ['Sperr kursbevis',()=>sporOgKjor('Sperr kursbevis',`Kursbeviset til ${p.navn} sperres.`,'Sperr kursbevis','pamelding.php',{handling:'bevis',id:p.bookingId,sperret:'ja'})],
   ['Sett på venteliste',()=>sporOgKjor('Flytt til venteliste','Frigi plassen til '+p.navn+' og flytt påmeldingen til ventelisten.','Flytt til venteliste','pamelding.php',{handling:'til-venteliste',id:p.bookingId})],
   ['Rediger påmelding (antall, rabatt)',()=>redigerPaamelding(p)],null,
   ['Avbestill …',()=>sporOgKjor('Avbestill',`Avbestill ${p.antall} plasser for ${p.navn}. Kunden kan få e-post. Betaling gjennom Vipps krever refusjon i stedet.`,'Avbestill','pamelding.php',{handling:'fjern',id:p.bookingId}),true]];
  const lukk=()=>{meny.remove();s.dlg.removeEventListener('click',utenfor,true);anker.focus();};
  const utenfor=ev=>{if(!meny.contains(ev.target)&&ev.target!==anker)lukk();};
  const meny=el('div',{class:'kal-meny',role:'menu','aria-label':`Valg for ${p.navn}`,onkeydown:ev=>{const bs=[...meny.querySelectorAll('button')];if(ev.key==='Escape'){ev.preventDefault();ev.stopPropagation();lukk();}if(ev.key==='ArrowDown'||ev.key==='ArrowUp'){ev.preventDefault();const j=bs.indexOf(document.activeElement)+(ev.key==='ArrowDown'?1:-1);bs[(j+bs.length)%bs.length].focus();}}},
   valg.map(v=>v?el('button',{type:'button',role:'menuitem',class:v[2]?'kal-rod':null,text:v[0],onclick:()=>{lukk();v[1]();}}):el('hr',{})));
  s.dlg.append(meny);const r=anker.getBoundingClientRect();
  meny.style.left=Math.max(8,Math.min(r.right-240,innerWidth-250))+'px';meny.style.top=Math.max(8,Math.min(r.bottom+4,innerHeight-meny.offsetHeight-8))+'px';
  setTimeout(()=>s.dlg.addEventListener('click',utenfor,true));meny.querySelector('button').focus();
 }
 // Escape i menyen lukker bare menyen, ikke arket.
 s.dlg.addEventListener('cancel',ev=>{const m=s.dlg.querySelector('.kal-meny');if(m){ev.preventDefault();ev.stopImmediatePropagation();m.remove();}},true);
 async function byttDato(p){
  try{const d=await api('kurs.php');const naa=new Date().toISOString().slice(0,19).replace('T',' ');
   const datoer=((d.kurs||[]).find(k=>k.id===e.kursId)?.datoer||[]).filter(x=>x.oktId!==id&&x.status!=='avlyst'&&String(x.startUtc)>naa&&x.ledige>=(p.antall||1));
   if(!datoer.length){toast('Ingen andre datoer med ledig plass.');return;}
   form('Bytt dato for '+p.navn,[field('oktId','Ny dato','number',{velg:true,options:datoer.map(x=>[x.oktId,`${e.tittel} · ${x.naar} (${x.ledige} ledige)`])})],{},async v=>{await ferdig(await api('pamelding.php',{handling:'flytt',id:p.bookingId,oktId:v.oktId}));},{submitLabel:'Bytt dato',successText:false});
  }catch(err){toast(err.message);}
 }
 // «Betalt» her = alt som ikke er «Ikke betalt» (også Møtte ikke opp og Refundert): beløpet regnes aldri på nytt for dem.
 // Rediger påmelding. pamelding.php «endre» regner beløpet på nytt (pris × antall − rabatt) når antall eller rabatt sendes uten beløp.
 // Derfor sendes bare det som faktisk er endret, og dagens beløp står i skjemaet. Er påmeldingen betalt, beholdes beløpet
 // (det sendes med uendret) til admin selv skriver et nytt. Ingen endring = ingen lagring.
 function redigerPaamelding(p){const betalt=p.status!=='Ikke betalt';const fra={antall:Number(p.antall)||1,rabatt:Number(p.rabatt)||0,belop:(Number(p.belopOre)||0)/100};
  form('Rediger påmelding',[field('antall','Antall','number',{min:1,required:true}),field('rabatt','Rabatt i prosent','number',{min:0,max:100,step:.01}),field('belop','Totalbeløp i kroner','number',{min:0,step:.01,help:betalt?(p.status==='Betalt'?'Påmeldingen er betalt.':`Påmeldingen står som «${p.status}».`)+' Beløpet endres bare hvis du skriver et nytt beløp her.':'Endrer du antall eller rabatt og lar beløpet stå, regnes beløpet ut på nytt.'})],fra,async v=>{
   const endret={};const nyttAntall=v.antall!==null&&v.antall!==fra.antall,nyRabatt=v.rabatt!==null&&Math.round(v.rabatt*100)!==Math.round(fra.rabatt*100),nyttBelop=v.belop!==null&&Math.round(v.belop*100)!==Math.round(fra.belop*100);
   if(nyttAntall)endret.antall=v.antall;
   // Rabatten følger med når antallet endres, ellers regner serveren uten den.
   if(nyRabatt||(nyttAntall&&!nyttBelop))endret.rabatt=nyRabatt?v.rabatt:fra.rabatt;
   if(nyttBelop)endret.belop=v.belop;else if(betalt&&(nyttAntall||nyRabatt))endret.belop=fra.belop;
   if(!Object.keys(endret).length)throw Error('Ingenting å endre.');
   await ferdig(await api('pamelding.php',{handling:'endre',id:p.bookingId,...endret}));},{successText:false});}
 function leggTil(){form('Legg til deltaker',[field('navn','Navn','text',{required:true}),field('telefon','Mobil','tel'),field('epost','E-post','email'),field('antall','Antall','number',{min:1,required:true}),field('betaltMaate','Betaling','text',{velg:true,options:['Ikke betalt','Betaler ved oppmøte','Kontant','Vipps','Gavekort','Faktura','Gratis']}),field('kode','Gavekortkode'),field('varsle','Send bekreftelse','checkbox')],{antall:1},async v=>{await ferdig(await api('pamelding.php',{handling:'legg-til',oktId:id,...v,varsle:v.varsle?'ja':'nei'}));},{submitLabel:'Legg til',successText:false});}
 // beskjed.php til=okt sender bare til påmeldinger med status betalt. Tallet på knappen er derfor de betalte.
 const betalte=()=>deltakere.filter(p=>p.status==='Betalt').length;
 function beskjed(){form('Beskjed til alle på '+e.tittel,[field('tekst','Melding','textarea',{required:true,help:'Sendes på e-post til dem som har betalt.'})],{},async v=>{await ferdig(await api('beskjed.php',{til:'okt',oktId:id,tekst:v.tekst,ogsaaSms:'nei'}));},{submitLabel:`Send til ${betalte()} betalte`,successText:false});}
 // Rediger: plasser og kursholder her. Tidspunktet flyttes med «Flytt tidspunkt», som kjenner samlingene i et flerdagerskurs.
 function rediger(){
  const plasser=el('input',{type:'number',min:0,step:1,value:e.kap||'','aria-label':'Plasser'});
  const holder=el('select',{'aria-label':'Kursholder'},el('option',{value:'0',text:'Ikke tildelt'}),(o.kursholdere||[]).map(h=>el('option',{value:String(h.id),text:h.navn,selected:Number(e.kursholderId)===h.id})));
  const lagre=button('Lagre',async()=>{lagre.disabled=true;try{
    if(String(plasser.value)!==String(e.kap||''))await api('kurs.php',{handling:'plasser',oktId:id,kapasitet:plasser.value});
    if(Number(holder.value)!==Number(e.kursholderId||0))await courseMutation({handling:'dato',oktId:id,kursholderId:Number(holder.value)});
    await ferdig({beskjed:'Endringene er lagret.'});}catch(err){toast(err.message);lagre.disabled=false;}},'primary');
  return [el('div',{class:'kal-skjema'},el('label',{},el('span',{text:'Plasser'}),plasser),el('label',{},el('span',{text:'Kursholder'}),holder),lagre),
   el('div',{class:'kal-verktoy'},
    button('Flytt tidspunkt',()=>{s.close();o.flytt(e);}),
    Object.assign(link('Hele kursoppsettet (navn, pris, tekst, bilder)','#kurs'),{onclick:()=>s.close()}),
    button(e.visFullt?'Åpne for påmelding':'Vis som fullbooket',()=>sporOgKjor('Endre bookingmuligheten?',e.visFullt?'Åpne datoen for nye påmeldinger.':'Sperr datoen for nye påmeldinger. Eksisterende deltakere beholdes.','Bekreft','kurs.php',{handling:'visFullt',oktId:id,paa:e.visFullt?'nei':'ja'})),
    button(e.avlyst?'Gjenopprett økta':'Avlys økta',()=>sporOgKjor(e.avlyst?'Gjenopprett':'Avlys dato',`${e.avlyst?'Gjenopprett':'Avlys'} ${e.tittel} ${e.dato}. Kontroller påmeldte og varsling etterpå.`,e.avlyst?'Gjenopprett':'Avlys dato','kurs.php',{handling:e.avlyst?'gjenopprett':'avlys',oktId:id}),'danger'))];
 }
 vis(e.kap?arkFane:(arkFane==='deltakere'?'rediger':arkFane));
 return s;
}
export {erOkt,naar};
