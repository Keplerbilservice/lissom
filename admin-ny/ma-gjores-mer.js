/* Må gjøres, idé 3, 4, 5 og 7 (eieren 08.10.2026, visningen «ideer-visning»). Sakene kommer fra ma-gjores-mer.php;
   ramme.js legger dem inn i flisene og bruker handlingene her. Utsendingene er de som finnes (ferdigbrent.php, beskjed.php, kursboost). */
import {el,api,button,sheet,confirm,toast,form,field,card,money} from './ui.js';
import {boostPackage} from './kursboost.js';

export const HILSEN='Hei! Vi savner deg på verkstedet. Ovnen er varm og det er god plass på torsdager.';
export const hentMer=()=>api('ma-gjores-mer.php').catch(()=>null);

function grenserSkjema(g){
 form('Grensene',[field('andel','Kurs fylt under (%)','number',{min:1,max:100,step:1,required:true}),field('dager','Dager igjen til kursstart','number',{min:1,max:120,step:1,required:true}),field('innom','Dager uten innstempling','number',{min:1,max:365,step:1,required:true})],g||{andel:50,dager:21,innom:21},async v=>{const r=await api('ma-gjores-mer.php',{handling:'grenser',...v});toast(r.beskjed);});
}

/* Handlingene per sakstype. Samme mønster som resten i ramme.js: trykk() låser knappen, ferdig() tar saken bort. */
export function merHandlinger(s,{trykk,ferdig,lukk,grenser}){
 return {
  ovn:()=>[
   trykk('Send «Klar til henting»',async()=>{if(!await confirm('Send «Klar til henting»?',`Publiser ${s.kurs} på lissom.no/ferdigbrent og legg e-post i kø til de som ikke har fått beskjed.`,'Send'))return;let tekst='';for(const oktId of s.okter||[]){const r=await api('ferdigbrent.php',{handling:'meld-alle',oktId});tekst=r.beskjed||tekst;}ferdig(tekst||'Beskjeden er lagt i kø.');},'primary'),
   trykk('Marker hentet',async()=>{if(!await confirm('Marker hentet?',`Merk alle ${s.antall} som hentet.`,'Marker hentet'))return;const r=await api('ma-gjores-mer.php',{handling:'hentet',brenningId:s.id});ferdig(r.beskjed);})],
  tregt:()=>[
   trykk('Lag markedsføring',async()=>{if(!await confirm('Lag markedsføring?',`Lag en kursboost for «${s.kurs}»: artikkel, innlegg, nyhetsbrev og melding til medlemmer. Dette bruker AI-budsjettet. Ingenting publiseres før du har kontrollert det.`,'Lag markedsføring'))return;toast('AI-en skriver. Dette tar gjerne et halvt minutt.');const r=await api('ai.php',{handling:'kursboost',kursId:s.kursId});lukk();if(r.id)await boostPackage(r.id,()=>{});},'primary'),
   button('Send til medlemmene',()=>{lukk();const q=new URLSearchParams({ny:'1',til:'medlemmer',emne:'Ledige plasser: '+s.kurs,tekst:`Hei {navn}! Det er fortsatt ledige plasser på ${s.kurs}, ${s.dato}. Meld deg på her: ${s.url}`});location.hash='#beskjeder?'+q.toString();}),
   trykk('Vent en uke',async()=>{const r=await api('ma-gjores-mer.php',{handling:'skjul',nokkel:'tregt:'+s.id});ferdig(r.beskjed);}),
   button('Endre grensene',()=>grenserSkjema(grenser))],
  ikkeinnom:()=>[
   button('Send hilsen',()=>{lukk();sendHilsen(s,ferdig);},'primary'),
   trykk('Ikke nå',async()=>{const r=await api('ma-gjores-mer.php',{handling:'skjul',nokkel:'ikkeinnom'});ferdig(r.beskjed);}),
   button('Endre grensene',()=>grenserSkjema(grenser))]
 };
}

/* «Send hilsen»: forslaget kan endres, sendes først etter «Er du sikker?». SMS til de med mobilnummer, ellers e-post (beskjed.php, til=en). */
function sendHilsen(s,ferdig){
 const folk=s.medlemmer||[];
 form('Send hilsen',[field('tekst','Hilsen','textarea',{required:true})],{tekst:HILSEN},async v=>{
  if(!await confirm('Er du sikker?',`Hilsenen sendes til ${folk.length} ${folk.length===1?'medlem':'medlemmer'}: ${folk.map(m=>m.navn).join(', ')}. SMS til de med mobilnummer, ellers e-post.`,'Send'))throw Error('Avbrutt.');
  let sendt=0;const feil=[];
  for(const m of folk){try{await api('beskjed.php',{til:'en',navn:m.navn,emne:'Hilsen fra Lissom',tekst:v.tekst,...(m.telefon?{telefon:m.telefon,ogsaaSms:'ja'}:{epost:m.epost,ogsaaSms:'nei'})});sendt++;}catch(e){feil.push(m.navn);}}
  ferdig(`Hilsenen er lagt i kø til ${sendt} ${sendt===1?'medlem':'medlemmer'}.`+(feil.length?' Kom ikke fram til: '+feil.join(', ')+'.':''));
 },{submitLabel:'Send',successText:false});
}

/* Idé 7: «Ukens oppsummering» øverst på I dag på mandager. apneSak(type) åpner saken i Må gjøres. */
export function mandagFlis(m,flis,fliser,apneSak){
 if(!m)return null;
 const kr=money(m.eksOre);
 const deler=[`Uke ${m.uke}: ${kr}`,m.trege?`${m.trege} kurs må fylles`:'',m.ikkeInnom?`${m.ikkeInnom} ikke innom`:''].filter(Boolean);
 const vis=()=>{
  const endring=m.endring===null?'':m.endring>=0?`${m.endring} % mer enn uka før`:`${-m.endring} % mindre enn uka før`;
  const bor=[m.trege?flis({ikon:'↓',tittel:`${m.trege} kurs må fylles`,tekst:m.tregtForst?`${m.tregtForst.tittel} ${m.tregtForst.dato}`:'',onclick:()=>{ark.close();setTimeout(()=>apneSak('tregt'),0);}}):null,
   m.ikkeInnom?flis({ikon:'♡',tittel:`${m.ikkeInnom} ikke innom`,tekst:'Send en hilsen',onclick:()=>{ark.close();setTimeout(()=>apneSak('ikkeinnom'),0);}}):null].filter(Boolean);
  const ark=sheet('Uke '+m.uke,el('div',{class:'mandag-ark'},
   card('Omsetning uten mva forrige uke',el('p',{class:'stort',text:kr}),endring?el('small',{text:endring}):null),
   bor.length?el('h2',{class:'flis-etikett',text:'Bør gjøres denne uka'}):null,bor.length?fliser(...bor):null,
   el('h2',{class:'flis-etikett',text:'Forrige uke'}),
   fliser(flis({ikon:'+',tittel:`${m.nye} ${m.nye===1?'nytt medlem':'nye medlemmer'}`,tekst:m.nyeNavn||''}),
    flis({ikon:'◇',tittel:`${m.plasser} kursplasser solgt`,tekst:`${m.kurs} kurs`}),
    flis({ikon:'⌂',tittel:`${m.timer} timer`,tekst:'på verkstedet'}))));
 };
 return fliser(flis({ikon:'☀',tittel:'Ukens oppsummering',tekst:deler.join(' · '),data:{'data-mandag':'1'},onclick:vis}));
}

/* Idé 3: «Merk ferdig» på Brenninger. Kursdatoene som lå i ovnen velges (ingenting forhåndsvalgt). */
export async function ovnIkkeFerdigKort(refresh){
 let d;try{d=await api('ma-gjores-mer.php?ovn=1');}catch{return null;}
 if(!d.klar||!d.brenninger.length)return null;
 const merk=b=>{
  const valgt=new Set();
  const rader=d.okter.length?d.okter.map(o=>el('label',{class:'field wide'},el('input',{type:'checkbox','data-okt':o.id,onchange:e=>{e.target.checked?valgt.add(o.id):valgt.delete(o.id);}}),el('span',{text:`${o.tittel} · ${o.naar} · ${o.deltakere} deltakere`}))):[el('p',{class:'empty',text:'Ingen kursdatoer venter på henting.'})];
  const s=sheet('Merk ferdig: '+b.slag,el('div',{},el('p',{text:'Hvilke kursdatoer lå i ovnen?'}),el('div',{class:'list'},rader),el('div',{class:'sheet-footer'},button('Avbryt',()=>s.close()),button('Merk ferdig',async()=>{try{const r=await api('ma-gjores-mer.php',{handling:'ferdig',id:b.id,okter:[...valgt]});s.close();toast(r.beskjed);refresh();}catch(e){toast(e.message);}},'primary'))));
 };
 return card('Ikke merket ferdig',el('div',{class:'list'},d.brenninger.map(b=>el('article',{class:'card list-item'},el('div',{class:'row'},el('div',{},el('strong',{text:b.slag+(b.ovn?' · '+b.ovn:'')}),el('p',{class:'muted',text:b.start+' – '+b.slutt})),el('div',{class:'actions'},button('Merk ferdig',()=>merk(b),'primary')))))));
}
