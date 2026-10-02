// Kalenderen på PC (K4, eieren godkjente 2. oktober 2026). Kalles fra kalender.js når skjermen er bred.
// Uke (standard) og dag tegnes her med tidsakse i 30-minuttersrader; dag har én kolonne per kursholder. Måned tegnes av kalender.js som før.
// Cellene har data-dato, data-akse (HH:MM) og data-kol (kursholder-id i dag, tom i uke = kursholderen beholdes): slippmål for dra og slipp i K5.
// Samling 2 og 3 har data-låst på brikka: dagene flyttes fra kursets dato, ikke hver for seg (se kalender.php).
import {el,badge,today,date,shift} from './ui.js';

export const PC_BRED='(min-width:1101px)';
export const erBred=()=>typeof matchMedia==='function'&&matchMedia(PC_BRED).matches;

const RAD_MIN=30,RAD_PX=26;
const minutter=t=>{const m=/^(\d{1,2}):(\d{2})/.exec(t||'');return m?Number(m[1])*60+Number(m[2]):null;};
const klokke=m=>`${String(Math.floor(m/60)).padStart(2,'0')}:${String(m%60).padStart(2,'0')}`;
function tidsrom(e){const s=minutter(e.tid);if(s===null)return null;let sl=minutter(e.slutt);if(sl===null||sl<=s)sl=Math.min(1440,s+60);return {s,sl};}

// Segmentpille Dag · Uke · Måned. Valgt visning er den som står (uke når kalenderen åpnes).
export function segment(modus,velg){
 const knapper=[['dag','Dag'],['uke','Uke'],['maaned','Måned']].map(([v,n])=>el('button',{type:'button',class:'kp-seg','data-modus':v,'aria-pressed':String(v===modus),text:n,onclick:()=>{knapper.forEach(k=>k.setAttribute('aria-pressed',String(k.dataset.modus===v)));velg(v);}}));
 return el('div',{class:'kp-segment',role:'group','aria-label':'Kalendervisning'},knapper);
}

// Brikker som overlapper legges side om side. Bredden deles per klynge (de som henger sammen), ikke for hele dagen.
function sideOmSide(liste){
 const sortert=liste.slice().sort((a,b)=>(a.s-b.s)||(a.sl-b.sl));let klynge=[],baner=[],slutt=-1;
 const lukk=()=>{const av=Math.max(1,baner.length);klynge.forEach(p=>p.av=av);klynge=[];baner=[];slutt=-1;};
 for(const p of sortert){if(klynge.length&&p.s>=slutt)lukk();let b=baner.findIndex(t=>t<=p.s);if(b===-1){b=baner.length;baner.push(p.sl);}else baner[b]=p.sl;p.bane=b;slutt=Math.max(slutt,p.sl);klynge.push(p);}
 if(klynge.length)lukk();return sortert;
}

function brikke(e,apne,plass){
 const laast=String(e.id).startsWith('saml-');
 const attr={type:'button',class:`event kp-brikke ${e.avlyst?'cancelled':''}`,'data-id':String(e.id),title:e.tittel,onclick:()=>apne(e)};
 if(laast)attr['data-låst']=true;
 if(plass)attr.style=`top:${plass.top}px;height:${plass.hoyde}px;left:calc(${plass.bane/plass.av*100}% + 3px);width:calc(${100/plass.av}% - 6px)`;
 return el('button',attr,el('small',{text:`${e.tid||''}${e.slutt?'–'+e.slutt:''}`}),el('strong',{text:e.tittel}),el('span',{text:[e.holder,e.samling,e.avlyst?'Avlyst':null,e.kap?`${e.pameldt||0}/${e.kap} påmeldt`:e.type].filter(Boolean).join(' · ')}));
}

// Én tavle: tidsakse til venstre og én kolonne per «kol» ({dato, kol, hode, hendelser, idag}).
function tavle(kolonner,fra,til,apne){
 const rader=(til-fra)/RAD_MIN,hoyde=rader*RAD_PX;
 const akse=el('div',{class:'kp-akse',style:`height:${hoyde}px`,'aria-hidden':true});
 for(let m=fra;m<til;m+=60)akse.append(el('span',{class:m===fra?'forst':null,style:`top:${(m-fra)/RAD_MIN*RAD_PX}px`,text:klokke(m)}));
 const hode=el('div',{class:'kp-hode'},el('div',{class:'kp-hjorne'}));
 const kropp=el('div',{class:'kp-kropp'},akse);
 for(const k of kolonner){
  const utenTid=k.hendelser.filter(e=>!tidsrom(e));
  hode.append(el('div',{class:`kp-kolhode ${k.idag?'today':''}`,'data-dato':k.dato,'data-kol':k.kol},k.hode,utenTid.map(e=>brikke(e,apne,null))));
  const kol=el('div',{class:`kp-kol ${k.idag?'today':''}`,style:`height:${hoyde}px`});
  for(let i=0;i<rader;i++)kol.append(el('div',{class:'kp-celle','data-dato':k.dato,'data-akse':klokke(fra+i*RAD_MIN),'data-kol':k.kol}));
  const plassert=sideOmSide(k.hendelser.map(e=>({e,...tidsrom(e)})).filter(p=>p.s!==undefined&&p.s!==null));
  for(const p of plassert){const s=Math.max(p.s,fra),sl=Math.min(p.sl,til);kol.append(brikke(p.e,apne,{top:(s-fra)/RAD_MIN*RAD_PX+1,hoyde:Math.max(RAD_PX-2,(sl-s)/RAD_MIN*RAD_PX-2),bane:p.bane,av:p.av}));}
  kropp.append(kol);
 }
 return el('div',{class:'kp-tavle',style:`--kp-n:${kolonner.length}`},hode,kropp);
}

// Tidsrommet som vises: en time før det første og en time etter det siste, som i gamle admin. Tom periode: 10–20.
function ramme(hendelser){
 const t=hendelser.map(tidsrom).filter(Boolean);if(!t.length)return [600,1200];
 return [Math.max(0,Math.floor(Math.min(...t.map(x=>x.s))/60)*60-60),Math.min(1440,Math.ceil(Math.max(...t.map(x=>x.sl))/60)*60+60)];
}

const dagHode=(dag,stengte,lang)=>[el('h3',{text:date(dag).toLocaleDateString('nb-NO',{weekday:lang?'long':'short',day:'numeric',month:lang?'long':'short'})}),Object.hasOwn(stengte,dag)?badge('Stengt','warn'):null];

// Kolonnene i dagsvisningen: den som vanligvis holder kursene (standard), og de andre når de har noe denne dagen.
// Det som ikke har kursholder (notater, brenninger, kurs uten tildelt holder) står i en egen kolonne «Ikke tildelt», bare når det finnes.
function holderKolonner(dag,hendelser,kursholdere){
 const navn=new Set(hendelser.map(e=>e.holder).filter(Boolean));
 const kol=kursholdere.filter(h=>h.standard||navn.has(h.navn)).map(h=>({id:String(h.id),navn:h.navn}));
 for(const n of navn)if(!kol.some(k=>k.navn===n))kol.push({id:'',navn:n});
 if(hendelser.some(e=>!e.holder)||!kol.length)kol.push({id:'0',navn:'Ikke tildelt',uten:true});
 return kol.map(k=>({dato:dag,kol:k.id,idag:false,hode:el('h3',{text:k.navn}),hendelser:hendelser.filter(e=>k.uten?!e.holder:e.holder===k.navn)}));
}

// modus: 'uke' | 'dag'. start: første dag. hendelser: allerede filtrert på søk og type i kalender.js.
export function kalenderPc({modus,start,hendelser,stengte={},kursholdere=[],apne}){
 if(modus==='dag'){
  const dagens=hendelser.filter(e=>e.dato===start);const [fra,til]=ramme(dagens);
  const el1=el('div',{class:'kp',  'data-visning':'dag'},el('div',{class:`kp-dagtittel ${start===today()?'today':''}`},dagHode(start,stengte,true)),tavle(holderKolonner(start,dagens,kursholdere),fra,til,apne));
  return {el:el1,antall:dagens.length};
 }
 const dager=Array.from({length:7},(_,i)=>shift(start,i));const uka=hendelser.filter(e=>dager.includes(e.dato));const [fra,til]=ramme(uka);
 const kolonner=dager.map(dag=>({dato:dag,kol:'',idag:dag===today(),hode:dagHode(dag,stengte,false),hendelser:uka.filter(e=>e.dato===dag)}));
 return {el:el('div',{class:'kp','data-visning':'uke'},tavle(kolonner,fra,til,apne)),antall:uka.length};
}
