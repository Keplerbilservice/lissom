/* Spør verkstedet for admin: samme kall og samme tekster som Verkstedet → Spør verkstedet
   i gamle admin (faqSpor() i lissom-2108.html). Ingen ny AI-kobling — /api/spor-verkstedet.php.
   «Leser fra»: kortene AI-en leser fra; alle er med når ingen er slått av. Eieren 04.10. */
import{el,api}from'./ui.js';
// Står over en ny tegning av Dokumenter-skjermen (refresh etter opplasting o.l.).
const state={sporsmal:'',jobber:false,svar:'',kilder:[],kategorier:[]};
const antallTekst=n=>n===1?'1 dokument':n+' dokumenter';
const kostTekst=ai=>ai?'Brukt denne måneden: ca. '+(ai.brukt||'kr 0')+' av '+(ai.tak||''):'';
export function askWorkshop(d){
 const alle=(d.kategorier||[]).filter(k=>!k.forelder);
 const antallMed=k=>(k.antall||0)+(d.kategorier||[]).filter(b=>b.forelder===k.id).reduce((s,b)=>s+(b.antall||0),0);
 const input=el('input',{type:'text',placeholder:'Skriv spørsmålet ditt','aria-label':'Skriv spørsmålet ditt',style:'flex:1 1 240px;min-width:0;min-height:44px;padding:10px 14px;border:1px solid var(--line);border-radius:11px;background:#fff'});
 input.value=state.sporsmal;
 const knapp=el('button',{class:'button primary',type:'button','data-spor':'ja'});
 const kost=el('small',{'data-spor-kostnad':'ja',text:kostTekst(d.ai)});
 const svar=el('div',{'data-spor-svar':'ja','aria-live':'polite'});
 const leser=el('div',{class:'actions'});
 function tegn(){
  knapp.textContent=state.jobber?'Tenker …':'Spør';knapp.disabled=state.jobber;
  leser.replaceChildren(...alle.map(k=>{const paa=!state.kategorier.length||state.kategorier.includes(k.id);return el('button',{class:`button ${paa?'primary':''}`,type:'button','aria-pressed':String(paa),onclick:()=>{const naa=state.kategorier.length?state.kategorier.slice():alle.map(x=>x.id);const i=naa.indexOf(k.id);if(i===-1)naa.push(k.id);else naa.splice(i,1);state.kategorier=naa.length===alle.length?[]:naa;tegn();}},k.navn,el('small',{text:antallTekst(antallMed(k))}));}));
  svar.replaceChildren(...(state.svar?[el('div',{class:'card',style:'margin-top:18px'},el('p',{text:state.svar,style:'white-space:pre-wrap;margin:0'}),state.kilder.length?el('p',{class:'eyebrow',style:'margin:14px 0 0',text:'Hentet fra Monicas kunnskapsbase'}):null)]:[]));
 }
 async function spor(){
  const sp=(input.value||'').trim();state.sporsmal=input.value;
  if(!sp||state.jobber)return;
  Object.assign(state,{jobber:true,svar:'',kilder:[]});tegn();
  try{
   const r=await fetch('/api/spor-verkstedet.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({sporsmal:sp,kategorier:state.kategorier})});
   const data=await r.json();const ok=r.ok&&data&&data.ok!==false;
   Object.assign(state,{jobber:false,svar:ok?(data.svar||''):(data&&data.feil?data.feil:'AI-en svarte ikke. Prøv igjen.'),kilder:ok?(data.kilder||[]):[]});
   // Som gamle admin (dokHent etter svaret): forbruket denne måneden oppdateres.
   if(ok)api('dokumenter.php').then(n=>{kost.textContent=kostTekst(n.ai);}).catch(()=>{});
  }catch{Object.assign(state,{jobber:false,svar:'Fikk ikke kontakt med serveren.',kilder:[]});}
  tegn();
 }
 knapp.onclick=spor;
 input.addEventListener('input',()=>{state.sporsmal=input.value;});
 input.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();spor();}});
 tegn();
 return el('section',{class:'card',id:'spor-verkstedet',style:'margin-bottom:22px'},el('h2',{text:'Spør verkstedet'}),
  el('div',{style:'display:flex;gap:12px;align-items:center;flex-wrap:wrap'},input,knapp),
  kost.textContent?el('p',{style:'margin:8px 0 0'},kost):null,
  el('p',{class:'eyebrow',style:'margin:18px 0 8px',text:'Leser fra'}),leser,svar);
}
