/* Medlemmene (eieren, 8. oktober 2026, fasit: skjermen «Medlemmene» i enklere-admin-visning.html).
   Én side for alt medlemmene ser: «Legg ut til medlemmene» (Beskjed, Internt kurs, Vare, Kampanje),
   «Dette ser medlemmene» (samme forhåndsvisning som Min side: /admin?apne=fhmedlem) og «Slå av og på»
   (de samme Vis/*-bryterne som Min side, MINSIDE_MODULER i innhold-og-kurs.js). Forhåndsvisningen lastes
   på nytt med en gang en bryter endres. Gamle #minside peker hit.
   Gjenbruk: beskjed.php (handling=tavle = bare Min side; til=medlemmer + ogsaaSms = den vanlige utsendingen),
   kurs.php (lagre med tema «Kun for medlemmer», som internkursene i gamle admin), produkter.php
   (kunMedlemmer=ja, ikke i nettbutikken), campaignEditor fra kampanjer.js (publikum=medlemmer).
   .mm-flis er midlertidig: slås sammen med felles .flis når den finnes i design.css. */
import {campaignEditor} from './kampanjer.js';
import {MINSIDE_MODULER} from './innhold-og-kurs.js';
import {newDate} from './kalender.js';
import {el,api,button,link,card,sheet,form,field,confirm,toast} from './ui.js';

const head=(name,text,actions=[])=>el('div',{class:'page-head'},el('div',{},el('p',{class:'eyebrow',text:'Lissom · Arbeidsrom'}),el('h1',{text:name}),el('p',{class:'muted',text})),el('div',{class:'actions'},actions));
const etikett=text=>el('p',{class:'mm-etikett',text});
const FLIS_CSS='.mm-etikett{font-size:13px;letter-spacing:.12em;text-transform:uppercase;font-weight:700;color:var(--muted);margin:26px 2px 10px}.mm-fliser{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.mm-flis{border:1px solid var(--line);border-radius:16px;background:var(--paper);padding:16px 14px;text-align:left;display:grid;gap:4px;align-content:start;min-height:96px}.mm-flis:hover{border-color:#a2502b}.mm-flis .mm-fi{font-size:20px;color:#a2502b}.mm-flis .mm-ft{font-family:Bitter,Georgia,serif;font-weight:700}.mm-flis .mm-fu{font-size:15px;color:var(--muted)}.mm-rad{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 0;border-bottom:1px solid var(--line)}.mm-rad:last-child{border-bottom:0}.mm-rad>div{min-width:0}.mm-rad p{margin:2px 0 0;font-size:15px}.mm-rad .actions{flex:none}@media(max-width:760px){.mm-fliser{grid-template-columns:repeat(2,minmax(0,1fr))}}';
function stil(){if(document.getElementById('mm-stil'))return;document.head.append(el('style',{id:'mm-stil',text:FLIS_CSS}));}
const flis=(ikon,tittel,under,onclick)=>el('button',{type:'button',class:'mm-flis','data-ny':tittel,onclick},el('span',{class:'mm-fi','aria-hidden':'true',text:ikon}),el('span',{class:'mm-ft',text:tittel}),el('span',{class:'mm-fu',text:under}));

/* Beskjed: «Legg ut» = bare Min side. «Legg ut og send SMS» = den vanlige utsendingen til alle aktive medlemmer (e-post + SMS + Min side). */
function nyBeskjed(){const emne=el('input',{type:'text',name:'emne','aria-label':'Overskrift',placeholder:'Beskjed fra Lissom',maxlength:'191'});const tekst=el('textarea',{name:'tekst','aria-label':'Tekst',placeholder:'Skriv teksten medlemmene skal se …'});const feil=el('p',{role:'alert',class:'notice error',hidden:true});let opptatt=false;
 const verdier=()=>({tekst:tekst.value.trim(),...(emne.value.trim()?{emne:emne.value.trim()}:{})});
 const kjor=async(body,ferdig)=>{if(opptatt)return;const v=verdier();if(v.tekst.length<3){feil.textContent='Skriv en melding først.';feil.hidden=false;return;}opptatt=true;feil.hidden=true;try{const d=await api('beskjed.php',{...body,...v});s.close();toast(d.beskjed||ferdig);}catch(e){feil.textContent=e.message;feil.hidden=false;}finally{opptatt=false;}};
 const s=sheet('Beskjed til alle medlemmer',el('div',{},el('div',{class:'form-grid'},el('label',{class:'field wide'},el('span',{text:'Overskrift'}),emne),el('label',{class:'field wide'},el('span',{text:'Tekst'}),tekst)),feil,el('div',{class:'sheet-footer'},button('Avbryt',()=>s.close()),button('Legg ut og send SMS',async()=>{if(!await confirm('Legg ut og send SMS?','Beskjeden legges ut på Min side og sendes til alle aktive medlemmer på e-post og SMS.','Send'))return;kjor({til:'medlemmer',ogsaaSms:'ja'},'Lagt ut og sendt.');}),button('Legg ut',()=>kjor({handling:'tavle'},'Lagt ut på Min side.'),'primary'))));setTimeout(()=>tekst.focus(),0);}

/* Internt kurs: vanlig kurs merket «Kun for medlemmer» (samme som internkursene i gamle admin). Etter lagring: legg til dato. */
function nyttInternkurs(refresh){form('Internt kurs',[field('tittel','Kursnavn','text',{required:true,wide:true}),field('pris','Pris i kroner','number',{min:0,required:true}),field('kapasitet','Antall plasser','number',{min:1,required:true,default:8}),field('status','Synlighet','text',{velg:true,options:[['kladd','Utkast'],['publisert','Publisert']]}),field('om','Beskrivelse','textarea')],{},async v=>{if(v.status==='publisert'&&!await confirm('Publiser kurset?',`«${v.tittel}» blir synlig for medlemmene på Min side.`,'Publiser'))throw Error('Publisering avbrutt.');const d=await api('kurs.php',{handling:'lagre',id:0,...v,type:'kurs',tema:'Kun for medlemmer',sms:'nei'});toast(d.beskjed||'Lagret.');if(d.id)setTimeout(()=>newDate(d.id),0);else refresh();},{successText:false});}

/* Vare i internbutikken: samme lagring som Butikk, med Internt på og Nettbutikken av. */
function nyVare(refresh){form('Vare i internbutikken',[field('tittel','Varenavn','text',{required:true,wide:true}),field('pris','Pris i kroner','number',{min:0,required:true}),field('lager','Antall på lager','number',{min:0,help:'Tomt felt betyr ubegrenset lager.'}),field('kategori','Kategori'),field('status','Synlighet','text',{velg:true,options:[['kladd','Utkast'],['publisert','Publisert']]}),field('beskrivelse','Beskrivelse','textarea')],{},async v=>{if(v.status==='publisert'&&!await confirm('Publiser varen?',`Vis «${v.tittel}» i internbutikken.`,'Publiser'))throw Error('Publisering avbrutt.');await api('produkter.php',{handling:'lagre',id:0,...v,lager:v.lager??'',kunMedlemmer:'ja',iNettbutikk:'nei',kanBestilles:'nei',leire:'nei'});refresh();});}

/* Kampanje til medlemmer: redigeringen fra Kampanjer, med «Til medlemmer» valgt. */
function nyKampanje(harMedlemmer,refresh){const s=sheet('Kampanje til medlemmer',el('div',{}));const ed=campaignEditor({publikum:'medlemmer'},{harMedlemmer,done:()=>{s.close();refresh();},cancel:()=>s.close()});s.dlg.querySelector('.sheet-inner').append(ed);}

export function medlemmeneScreens(refresh){
 async function medlemmene(){stil();const [d,km]=await Promise.all([api('innhold.php'),api('kampanjer.php').catch(()=>null)]);const harMedlemmer=km?.harMedlemmer!==false;const mk=(km?.kampanjer||[]).find(k=>k.publikum==='medlemmer'&&k.ute);
  const rows=MINSIDE_MODULER.map(([name,key,fallback=true,note=''])=>({name,key,fallback,note:key==='Vis/medlemskampanje'?(mk?'Kampanjen: '+mk.navn:'Ingen kampanje valgt ennå. Lag og velg den under Kampanjer.'):note,on:d.innhold[key]===undefined?fallback:fallback?d.innhold[key]!=='nei':d.innhold[key]==='ja'}));
  const frame=el('iframe',{title:'Min side slik et medlem ser den',src:'/admin?apne=fhmedlem',style:'width:390px;max-width:100%;height:820px;border:1px solid #decdbc;border-radius:24px;background:#fff;display:block;margin:auto'});
  const size=w=>()=>{frame.style.width=w;};/* Ny lasting av samme adresse: src settes på nytt (reload() før første lasting kunne stoppe den). */const oppdater=()=>{frame.src=frame.getAttribute('src');};
  const bryter=r=>{const b=el('button',{type:'button',class:'bryter',role:'switch','aria-checked':String(r.on),'aria-label':`${r.name}: ${r.on?'på':'av'}`,onclick:async()=>{if(b.disabled)return;b.disabled=true;const paa=!r.on;try{await api('innhold.php',{endringer:{[r.key]:paa?'ja':'nei'}});r.on=paa;b.setAttribute('aria-checked',String(paa));b.setAttribute('aria-label',`${r.name}: ${paa?'på':'av'}`);oppdater();}catch(e){toast(e.message);}finally{b.disabled=false;}}});return b;};
  const rad=r=>{const node=el('div',{class:'mm-rad','data-modul':r.key},el('div',{},el('strong',{text:r.name}),r.note?el('p',{class:'muted',text:r.note}):null),el('div',{class:'actions'},r.key==='Vis/medlemskampanje'?(mk?button('Rediger',()=>{const s=sheet('Kampanje til medlemmer',el('div',{}));s.dlg.querySelector('.sheet-inner').append(campaignEditor(mk,{harMedlemmer,done:()=>{s.close();refresh();},cancel:()=>s.close()}));}):link('Kampanjer','#kampanjer')):null,bryter(r)));return node;};
  return el('div',{},head('Medlemmene','Legg ut til medlemmene, se Min side slik de ser den, og slå deler av og på.'),
   etikett('Legg ut til medlemmene'),
   el('div',{class:'mm-fliser'},flis('✉','Beskjed','Til alle medlemmer',nyBeskjed),flis('◇','Internt kurs','Bare for medlemmer',()=>nyttInternkurs(refresh)),flis('▢','Vare','I internbutikken',()=>nyVare(refresh)),flis('★','Kampanje','Banner på Min side',()=>nyKampanje(harMedlemmer,refresh))),
   el('div',{class:'grid',style:'margin-top:26px'},
    el('section',{class:'card'},el('div',{class:'card-head'},el('h2',{text:'Dette ser medlemmene'}),el('div',{class:'actions'},button('Mobil',size('390px')),button('PC',size('100%')))),el('p',{class:'muted',text:'Viser oppsettet med dine egne navn og tall, ikke opplysningene til et ekte medlem.'}),frame),
    card('Slå av og på',el('div',{},rows.map(rad)))));}
 return{medlemmene,minside:async()=>{history.replaceState(null,'','#medlemmene');return medlemmene();}};
}
