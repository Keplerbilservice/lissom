// «Ny kursdato» med gjentakelse og «Dupliser til neste uke» (kalenderplanen, bølge 3, 3. oktober 2026).
// Etter skissen (nyKursDialog, gjentaDatoer). Står bak bryteren «Vis/kalendergjenta» (kalender.php sender den i «brytere»);
// av = «Ny kursdato» er skjemaet i kalender.js som før, og det finnes ingen dupliser-knapp.
// Én gang går til kurs.php «nydato» (som før, med ferieadvarsel). Hver uke / Annenhver uke går til «nydatoer» i én transaksjon;
// stengte dager vises overstrøket og sendes ikke, og det serveren hopper over (stengt, dublett, finnes) meldes etterpå.
// Ingenting er forhåndsvalgt der det er et valg (eieren, 27.09.2026), unntatt «Én gang».
import {el,api,button,toast,sheet,confirm,courseMutation,iso,date,shift} from './ui.js';

const MND=['jan','feb','mar','apr','mai','jun','jul','aug','sep','okt','nov','des'];
const UKEDAG=['søn','man','tir','ons','tor','fre','lør'];
export const kortDato=s=>{const d=date(s);return `${UKEDAG[d.getDay()]} ${d.getDate()}. ${MND[d.getMonth()]}`;};

// Datoene en gjentakelse gir, som 'YYYY-MM-DD'. ofte: en | uke | annen. lengde: 1 | 2 | 3 | 6 (måneder), aar, dato, antall.
export function gjentaDatoer(start,ofte,lengde,tilDato,antall){
 if(ofte==='en')return [start];
 const steg=ofte==='uke'?7:14;const ut=[];
 if(lengde==='antall'){const n=Math.min(60,Math.max(0,Math.floor(Number(antall)||0)));for(let x=start;ut.length<n;x=shift(x,steg))ut.push(x);return ut;}
 const d=date(start);let slutt;
 if(lengde==='aar')slutt=`${d.getFullYear()}-12-31`;
 else if(lengde==='dato')slutt=tilDato||'';
 else slutt=iso(new Date(d.getFullYear(),d.getMonth()+Number(lengde),d.getDate()));
 for(let x=start;x<=slutt&&ut.length<120;x=shift(x,steg))ut.push(x);
 return ut;
}

const LENGDER=[['1','1 mnd'],['2','2 mnd'],['3','3 mnd'],['6','6 mnd'],['aar','Ut året'],['dato','Til dato'],['antall','Antall ganger']];
const OFTE=[['en','Én gang'],['uke','Hver uke'],['annen','Annenhver uke']];

// courseId: kurset som foreslås (0 = ingen). dag: datoen som foreslås. o: {kursholdere, refresh}.
// forslag (fra menyen og dra og slipp, kalender-meny.js): {fra, til, kursholderId, ofte}. Hvor lenge velges alltid selv.
export async function nyKursdatoGjenta(courseId,dag,o,forslag={}){
 let kurs,stengte=[];
 try{kurs=(await api('kurs.php')).kurs||[];}catch(e){toast(e.message);return;}
 try{stengte=(await api('apningstider.php')).stengte||[];}catch{stengte=[];}
 let ofte=['en','uke','annen'].includes(forslag.ofte)?forslag.ofte:'en',lengde='',bort=new Set();
 const velg=(navn,valg,id)=>el('select',{'aria-label':navn,id},el('option',{value:'',text:navn,disabled:true,selected:true}),valg.map(([v,t])=>el('option',{value:String(v),text:t})));
 const kursFelt=velg('Kurs',kurs.map(k=>[k.id,k.tittel]));
 if(Number.isInteger(courseId)&&courseId>0&&kurs.some(k=>k.id===courseId))kursFelt.value=String(courseId);
 const holderFelt=velg('Kursholder',[...(o.kursholdere||[]).map(h=>[h.id,h.navn]),[0,'Ikke tildelt']]);
 if(forslag.kursholderId!=null&&forslag.kursholderId!==''&&[...holderFelt.options].some(x=>x.value===String(forslag.kursholderId)&&!x.disabled))holderFelt.value=String(forslag.kursholderId);
 const datoFelt=el('input',{type:'date',value:dag,required:true,'aria-label':'Første dag'});
 const fraFelt=el('input',{type:'time',value:forslag.fra||'18:00',required:true,'aria-label':'Starter kl.'});
 const tilFelt=el('input',{type:'time',value:forslag.til||'20:00',required:true,'aria-label':'Slutter kl.'});
 const tilDato=el('input',{type:'date','aria-label':'Siste dato'});
 const antall=el('input',{type:'number',min:1,max:60,step:1,inputmode:'numeric','aria-label':'Antall ganger'});
 const felt=(navn,input,klasse='')=>el('label',{class:`field ${klasse}`},el('span',{text:navn}),input);
 const ofteRad=el('div',{class:'kp-segment kal-gj-valg',role:'group','aria-label':'Gjentakelse'});
 const lengdeRad=el('div',{class:'kp-segment kal-gj-valg',role:'group','aria-label':'Hvor lenge'});
 const tilDatoFelt=felt('Siste dato',tilDato),antallFelt=felt('Antall ganger',antall);
 const lengdeBoks=el('div',{class:'kal-gj-blokk',hidden:true},el('b',{text:'Hvor lenge'}),lengdeRad,el('div',{class:'form-grid'},tilDatoFelt,antallFelt));
 const forhand=el('div',{class:'kal-gj-forhand','aria-live':'polite'});
 const feil=el('p',{role:'alert',class:'notice error',hidden:true});
 const avbryt=button('Avbryt',()=>s.close());
 const lagre=el('button',{class:'button primary',type:'button',text:'Bekreft og legg inn'});
 const knapper=(rad,valg,aktiv,sett)=>rad.replaceChildren(...valg.map(([v,t])=>el('button',{type:'button',class:'kp-seg','aria-pressed':String(v===aktiv),text:t,onclick:()=>{sett(v);vis();}})));
 // Datoene som blir lagt inn: uten de stengte og dem som er tatt bort.
 const alle=()=>datoFelt.value?gjentaDatoer(datoFelt.value,ofte,lengde,tilDato.value,antall.value):[];
 const med=()=>alle().filter(x=>!bort.has(x)&&!stengte.includes(x));
 function gyldig(){
  if(!kursFelt.value||!holderFelt.value||!datoFelt.value||!fraFelt.value||!tilFelt.value||tilFelt.value<=fraFelt.value)return false;
  if(ofte==='en')return true;
  if(!lengde||(lengde==='dato'&&!tilDato.value)||(lengde==='antall'&&!(Number(antall.value)>=1)))return false;
  return med().length>0;
 }
 function vis(){
  knapper(ofteRad,OFTE,ofte,v=>{ofte=v;});
  knapper(lengdeRad,LENGDER,lengde,v=>{lengde=v;});
  lengdeBoks.hidden=ofte==='en';tilDatoFelt.hidden=lengde!=='dato';antallFelt.hidden=lengde!=='antall';
  const ds=alle(),m=med();
  if(!datoFelt.value)forhand.replaceChildren();
  else if(ofte==='en')forhand.replaceChildren(el('b',{text:`1 dato: ${kortDato(datoFelt.value)} kl. ${fraFelt.value}`}));
  else if(!lengde||!ds.length)forhand.replaceChildren(el('span',{class:'muted',text:'Velg hvor lenge.'}));
  else forhand.replaceChildren(
   el('div',{class:'kal-gj-topp'},el('b',{text:m.length?`${m.length} datoer · ${kortDato(m[0])} – ${kortDato(m[m.length-1])}`:'0 datoer'}),el('small',{text:'Trykk på en dato for å ta den bort'})),
   el('div',{class:'kal-gj-datoer'},ds.map(x=>{const st=stengte.includes(x),av=bort.has(x);
    return el('button',{type:'button',class:`kal-gj-dato ${st||av?'kal-gj-av':''}`,'data-dato':x,'aria-pressed':String(!st&&!av),disabled:st,title:st?'Stengt':null,text:kortDato(x)+(st?' · stengt':''),onclick:()=>{av?bort.delete(x):bort.add(x);vis();}});})));
  lagre.disabled=!gyldig();
 }
 for(const f of [kursFelt,holderFelt,datoFelt,fraFelt,tilFelt,tilDato,antall])f.addEventListener('input',()=>{if(f===datoFelt)bort.clear();vis();});
 lagre.onclick=async()=>{if(!gyldig())return;lagre.disabled=true;feil.hidden=true;
  const kursId=Number(kursFelt.value),kursholderId=Number(holderFelt.value),navn=kursFelt.selectedOptions[0]?.textContent||'Kurset';
  try{
   if(ofte==='en'){const dato=datoFelt.value;
    await courseMutation({handling:'nydato',kursId,kursholderId,start:`${dato}T${fraFelt.value}`,slutt:`${dato}T${tilFelt.value}`});
    toast(`${navn} er lagt inn ${kortDato(dato)} kl. ${fraFelt.value}.`);
   }else{
    const r=await api('kurs.php',{handling:'nydatoer',kursId,kursholderId,datoer:med().map(x=>({start:`${x} ${fraFelt.value}`,slutt:`${x} ${tilFelt.value}`}))});
    const inn=r.lagtInn||[],hopp=r.hoppet||[];
    const grunn={stengt:'stengt',dublett:'to ganger',finnes:'finnes alt',ugyldig:'ugyldig'};
    toast((inn.length?`${inn.length} datoer for ${navn} er lagt inn, siste ${kortDato(inn[inn.length-1].start.slice(0,10))}.`:'Ingen datoer ble lagt inn.')
     +(hopp.length?` Hoppet over: ${hopp.map(h=>`${kortDato(h.start.slice(0,10))} (${grunn[h.grunn]||h.grunn})`).join(', ')}.`:''));
   }
   s.close();await o.refresh();
  }catch(e){if(e.message!=='Avbrutt.'){feil.textContent=e.message;feil.hidden=false;}lagre.disabled=false;}};
 const s=sheet('Ny kursdato',el('div',{class:'kal-gj'},
  el('div',{class:'form-grid'},felt('Kurs',kursFelt,'wide'),felt('Første dag',datoFelt,'wide'),felt('Starter kl.',fraFelt),felt('Slutter kl.',tilFelt),felt('Kursholder',holderFelt,'wide')),
  el('div',{class:'kal-gj-blokk'},el('b',{text:'Gjentakelse'}),ofteRad),
  lengdeBoks,forhand,feil,el('div',{class:'sheet-footer'},avbryt,lagre)));
 s.dlg.classList.add('kal-gj-ark');
 vis();
 return s;
}

// «Dupliser til neste uke»: samme kurs, tid, plasser og kursholder sju dager senere, via «nydato».
// Et kurs over flere dager dupliseres med alle dagene (samlingene) flyttet en uke fram.
export async function dupliser(e){
 const oktId=Number(e.oktId||e.id);let samlinger=[];
 const d=await api('kurs.php');for(const k of d.kurs||[])for(const x of k.datoer||[])if(x.oktId===oktId)samlinger=x.samlinger||[];
 // Samlingens tider, med øktas egne som reserve når fra/til er tom (kontrolløren 03.10).
 const s0=samlinger[0]||{};const forste={dato:s0.dato||e.dato,fra:s0.fra||e.tid,til:s0.til||e.dagSlutt||e.slutt||''};
 const ny=shift(forste.dato,7);
 if(!await confirm('Dupliser til neste uke?',`${e.tittel} legges inn ${kortDato(ny)} kl. ${forste.fra}${samlinger.length>1?` (alle ${samlinger.length} dagene)`:''}.`,'Dupliser'))throw Error('Avbrutt.');
 const body={handling:'nydato',kursId:e.kursId,start:`${ny}T${forste.fra}`,slutt:forste.til&&forste.til>forste.fra?`${ny}T${forste.til}`:''};
 if(e.kap)body.kapasitet=e.kap;
 // «Ikke tildelt» på originalen blir «Ikke tildelt» på kopien (0), ikke kursets kursholder.
 body.kursholderId=Number.isInteger(e.kursholderId)?e.kursholderId:0;
 if(samlinger.length>1)body.dager=samlinger.slice(1).map(sa=>({dato:shift(sa.dato,7),fra:sa.fra||forste.fra,til:sa.til||forste.til}));
 await courseMutation(body);
 return `${e.tittel} er lagt inn ${kortDato(ny)} kl. ${forste.fra}.`;
}
