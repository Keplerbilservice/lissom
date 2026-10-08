/* Glasurkalkulator og glasurlapp (eieren 08.10.2026: det gamle admin pensjoneres).
   Samme utregning som «Utregning» under Oppskrifter i det gamle admin (lissom-2108.html, kalkLinjer/kalkTotal):
   ca. 1 kg tørrstoff per liter, råvarene fordelt etter andelen sin i oppskriften, fargestoff i prosent av
   tørrstoffet i tillegg. Lappen er den samme som skrivUtLapp der: oppskriftsnavn (med fargestoff), dato og batch.
   Batchnummeret er B-ÅÅMMDD-n, der n teller opp per dag i denne nettleseren. */
import {el,button,sheet,toast} from './ui.js';

const tall=v=>parseFloat(String(v??'').replace(',','.'));
const datoTekst=()=>new Date().toLocaleDateString('nb-NO',{day:'numeric',month:'long',year:'numeric'});
const dagKode=()=>{const d=new Date();return String(d.getFullYear()).slice(2)+String(d.getMonth()+1).padStart(2,'0')+String(d.getDate()).padStart(2,'0');};
function nesteBatch(tell){const kode=dagKode();const nokkel='lissom-glasurbatch-'+kode;let n=1;try{n=(parseInt(localStorage.getItem(nokkel),10)||0)+1;if(tell)localStorage.setItem(nokkel,String(n));}catch(e){/* uten lagring: 1 */}return 'B-'+kode+'-'+n;}

/* Gram per linje. raavarer = [[navn, andel], …] fra verksted.php; farger = [{navn, pst}]. */
export function regnUt(raavarer,liter,farger){const m=1000*(liter>0?liter:0);const sum=(raavarer||[]).reduce((s,b)=>s+(Number(b[1])||0),0);const linjer=(raavarer||[]).map(b=>({navn:b[0],pst:(Number(b[1])||0)+' %',gram:sum>0?Math.round(m*(Number(b[1])||0)/sum):0})).concat((farger||[]).map(f=>({navn:f.navn+' (fargestoff)',pst:f.pst+' %',gram:Math.round(m*f.pst/100)})));const fargeG=(farger||[]).reduce((s,f)=>s+Math.round(m*f.pst/100),0);return {linjer,total:m+fargeG};}

export const lappNavn=(navn,farger)=>navn+((farger||[]).length?' + '+farger.map(f=>f.navn+' '+f.pst+' %').join(', '):'');

function skrivUt(navn,dato,batch){const w=window.open('','_blank','width=420,height=320');if(!w){toast('Nettleseren stoppet utskriftsvinduet. Tillat sprettoppvinduer for admin.');return false;}
 /* Navnet er skrevet i et fritekstfelt: det skal vises, ikke kjøres. */
 const esc=t=>String(t??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
 w.document.write('<html lang="nb"><head><meta charset="utf-8"><title>Glasurlapp</title></head><body style="margin:0;display:flex;align-items:center;justify-content:center;height:100vh;font-family:Georgia,serif;"><div style="border:2px solid #4D1D12;border-radius:10px;padding:20px 24px;width:300px;color:#4D1D12;"><div style="font-size:10px;letter-spacing:.14em;">LISSOM · GLASUR</div><div style="font-size:19px;font-weight:700;margin:8px 0 10px;">'+esc(navn)+'</div><div style="font-size:12px;">Dato: '+esc(dato)+'</div><div style="font-size:12px;">Batch: '+esc(batch)+'</div></div><scr'+'ipt>window.print()</scr'+'ipt></body></html>');
 w.document.close();return true;}

/* Arket «Utregning» for én oppskrift. r = {navn, raavarer}. */
export function glasurKalkulator(r){
 let liter=1;const farger=[];
 const literInn=el('input',{type:'number',min:0,step:0.5,value:1,inputmode:'decimal','aria-label':'Antall liter jeg skal lage'});
 const fNavn=el('input',{type:'text',placeholder:'F.eks. jernoksid','aria-label':'Fargestoff'});
 const fPst=el('input',{type:'text',inputmode:'decimal',placeholder:'%','aria-label':'Prosent'});
 const fargeListe=el('div',{class:'list'});const tabell=el('div',{});const lapp=el('div',{});
 function leggTil(){const navn=fNavn.value.trim();const pst=tall(fPst.value);if(!navn||!(pst>0)){toast('Skriv navn og prosent for fargestoffet.');return;}farger.push({navn,pst});fNavn.value='';fPst.value='';tegn();fNavn.focus();}
 function tegn(){const {linjer,total}=regnUt(r.raavarer,liter,farger);
  fargeListe.replaceChildren(...farger.map((f,i)=>el('div',{class:'row'},el('span',{text:f.navn+' · '+f.pst+' %'}),button('Fjern',()=>{farger.splice(i,1);tegn();}))));
  tabell.replaceChildren(el('div',{class:'row'},el('small',{text:'Materiale'}),el('small',{text:'%'}),el('small',{text:'Gram'})),...linjer.map(l=>el('div',{class:'row'},el('span',{text:l.navn}),el('span',{class:'muted',text:l.pst}),el('strong',{text:l.gram+' g'}))),el('div',{class:'row'},el('strong',{text:'Totalt'}),el('strong',{text:total+' g tørrstoff'})));
  lapp.replaceChildren(el('p',{class:'eyebrow',text:'Lissom · Glasurlapp'}),el('strong',{text:lappNavn(r.navn,farger)}),el('p',{class:'muted',text:'Dato: '+datoTekst()}),el('p',{class:'muted',text:'Batch: '+nesteBatch(false)}));}
 literInn.addEventListener('input',()=>{const v=tall(literInn.value);liter=v>0?v:0;tegn();});
 fPst.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();leggTil();}});
 tegn();
 sheet('Utregning · '+r.navn,el('div',{},
  el('div',{class:'form-grid'},el('label',{class:'field wide'},el('span',{text:'Antall liter jeg skal lage'}),literInn),
   el('label',{class:'field'},el('span',{text:'Fargestoff (i tillegg til basen)'}),fNavn),el('label',{class:'field'},el('span',{text:'Prosent'}),fPst)),
  el('div',{class:'actions',style:'margin:12px 0 18px'},button('Legg til',leggTil)),fargeListe,
  el('section',{class:'card',style:'margin-top:18px'},tabell,el('p',{class:'muted',text:'Regner ca. 1 kg tørrstoff per liter ferdig glasur. Fargestoff kommer i tillegg — 3 % av 1 000 g er 30 g.'})),
  el('section',{class:'card',style:'margin-top:18px'},lapp,el('div',{class:'actions',style:'margin-top:12px'},button('Skriv ut lapp',()=>{if(skrivUt(lappNavn(r.navn,farger),datoTekst(),nesteBatch(true)))tegn();},'primary')))));
}
