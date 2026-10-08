/* Neste leirebestilling (eieren 08.10.2026): én skjerm øverst i Handlelister. Frist (innstillinger), per leverandør varene med bilde og hvem som har
   bestilt hvor mye, frakten og hvor mange den deles på, «Send bestilling» (handling bestill) og «Krev inn med Vipps» (handling krav — gjelder alle
   medlemmene, som før). Under: tidligere bestillinger med status Bestilt → Kommet → Hentet; Kommet og Hentet settes med ett trykk (migrasjon 260). */
import {el,button,badge,card,form,field,list,toast} from './ui.js';
const bilde=v=>v.bilde?el('img',{class:'vare-bilde',src:v.bilde,alt:'',loading:'lazy'}):el('span',{class:'vare-bilde','aria-hidden':'true'});
export function leireBestilling(d,save,act){const L=d.leire;if(!L)return null;
 const trykk=async(b,body)=>{b.disabled=true;try{const r=await save('handlelister.php',body);toast(r.beskjed||'Oppdatert.');}catch(e){toast(e.message);b.disabled=false;}};
 const ettTrykk=(tekst,body,kind='')=>{const b=button(tekst,()=>trykk(b,body),kind);return b;};
 const frist=()=>form('Frist for neste leirebestilling',[field('dato','Frist','date')],{dato:L.frist.dato},v=>save('handlelister.php',{handling:'frist',dato:v.dato||''}));
 const vare=v=>el('div',{class:'row'},el('div',{class:'vare-venstre'},bilde(v),el('div',{},el('strong',{text:v.navn}),el('small',{text:[v.antall+' stk.',v.pris?v.pris+' per stk.':''].filter(Boolean).join(' · ')}),el('div',{class:'leire-hvem'},v.hvem.map(h=>badge(h.navn+' '+h.antall))))));
 const lev=r=>el('section',{class:'leire-lev','data-leverandor':r.id},el('h3',{text:r.navn}),list(r.varer,vare),el('p',{class:'muted leire-frakt',text:r.frakt?`Frakt ${r.frakt}, delt på ${r.deltPaa}.`:'Frakt er ikke satt.'}),el('div',{class:'actions'},button('Send bestilling',()=>act('handlelister.php',{handling:'bestill',leverandorId:r.id},`Send bestillingen til ${r.navn} (${r.epost}). Linjene markeres som bestilt.`,'Send bestilling'),'primary')));
 const neste=card('Neste leirebestilling',
  el('div',{class:'row'},el('div',{},el('strong',{text:'Frist'}),el('small',{text:L.frist.tekst||'Ikke satt'})),el('div',{class:'actions'},button(L.frist.dato?'Endre frist':'Sett frist',frist),L.neste.length?button('Krev inn med Vipps',()=>act('handlelister.php',{handling:'krav'},'Send betalingskrav til medlemmene som ikke allerede har fått krav. Kontroller priser og frakt først.','Send betalingskrav')):null)),
  L.neste.length?L.neste.map(lev):el('p',{class:'empty',text:'Ingen varer venter på neste bestilling.'}));
 const status=b=>badge(b.status,b.status==='Hentet'?'good':b.status==='Kommet'?'warn':'');
 const bestilling=b=>el('article',{class:'card list-item leire-bestilling'},
  el('div',{class:'row'},el('div',{},el('strong',{text:[b.leverandor,b.nr].filter(Boolean).join(' · ')}),el('small',{text:'Bestilt '+b.dato})),el('div',{class:'actions'},status(b),L.harStatus&&!b.kommet?ettTrykk('Kommet',{handling:'kommet',ider:b.ider},'primary'):null)),
  b.medlemmer.map(m=>el('div',{class:'row'},el('div',{},el('strong',{text:m.navn}),el('small',{text:m.varerTekst})),el('div',{class:'actions'},m.intern?null:m.hentet?badge('Hentet','good'):L.harStatus&&b.kommet?ettTrykk('Hentet',{handling:'hentet',ider:m.ider}):null))));
 const tidligere=card('Tidligere bestillinger',list(L.tidligere,bestilling,'Ingen bestillinger de siste 120 dagene.'));
 return el('div',{class:'leirebestilling'},neste,tidligere);}
