/* Designmaler — underlogoene og profilbildene, som i gamle admin (designmalAdminVals() i
   lissom-2108.html, eieren 28.09). Ingen serverkall: lista er filene i design/underlogoer/
   og Lissoms egne logoer der nettsida bruker dem. Brun logo på gul flate, gul logo på brun. */
import{el}from'./ui.js';
const FLATE={gul:'var(--yellow)',brun:'var(--ink)',lys:'var(--paper)'};
let kopiert='';
function kort(etikett,flate,filer){
 const vis=filer[0];
 const kopier=el('button',{class:'button',type:'button',text:kopiert===vis?'Kopiert ✓':'Kopier lenke'});
 kopier.onclick=()=>{const ferdig=()=>{kopiert=vis;document.querySelectorAll('[data-dm-kopier]').forEach(b=>{b.textContent=b.dataset.dmKopier===vis?'Kopiert ✓':'Kopier lenke';});};try{navigator.clipboard.writeText('https://lissom.no/'+vis).then(ferdig,ferdig);}catch{ferdig();}};
 kopier.dataset.dmKopier=vis;
 // To filer i samme format (ikonene) skilles med størrelsen i navnet.
 const formater=filer.map(f=>{const ext=f.split('.').pop().toUpperCase();const flere=filer.filter(g=>g.split('.').pop().toUpperCase()===ext).length>1;const px=(f.match(/(\d{2,4})(?=\.\w+$)/)||[])[1]||(/apple-touch/.test(f)?'180':'');return el('a',{class:'button',href:'/'+f,download:f.split('/').pop(),'data-dm-last':'/'+f,text:flere&&px?ext+' '+px:ext});});
 return el('div',{'data-dm-fil':'/'+vis,style:'border:1px solid var(--line);border-radius:14px;overflow:hidden;display:grid;background:#fff'},
  el('div',{style:`background:${FLATE[flate]};aspect-ratio:4/3;display:flex;align-items:center;justify-content:center;padding:12px`},el('img',{src:'/'+vis,alt:etikett,loading:'lazy',style:'max-width:100%;max-height:100%;object-fit:contain;display:block'})),
  el('div',{style:'padding:12px;display:grid;gap:8px'},el('strong',{text:etikett}),el('div',{class:'actions',style:'gap:6px'},formater,kopier)));
}
// Underlogoene: PNG først — den kan brukes i innlegg og velges i bildevelgeren.
function under(mappe){
 const b=(navn,farge,ext)=>ext.map(x=>'design/underlogoer/'+mappe+'/'+navn+'-'+farge+'.'+x);
 return[kort('Logo · brun','gul',b('lissom-'+mappe,'brun',['png','jpg','svg','pdf'])),kort('Logo · gul','brun',b('lissom-'+mappe,'gul',['png','jpg','svg','pdf'])),kort('Profilbilde · brun','gul',b('profilbilde-'+mappe,'brun',['png','jpg','svg'])),kort('Profilbilde · gul','brun',b('profilbilde-'+mappe,'gul',['png','jpg','svg']))];
}
function lissom(){return[
 kort('Hovedlogo','gul',['logo-lockup.svg']),kort('Hovedlogo · gul','brun',['logo-lockup-yellow.svg']),kort('Hovedlogo · lys','lys',['logo-lockup-cream.svg']),
 kort('Koppen','gul',['mark-cup.svg']),kort('Koppen · gul','brun',['mark-cup-yellow.svg']),kort('Koppen · lys','brun',['mark-cup-cream.svg']),
 kort('Koppen · kopp','gul',['mark-cup-top.svg']),kort('Koppen · skål','gul',['mark-cup-saucer.svg']),kort('Ordmerket','gul',['wordmark-lissom.svg']),
 kort('Hjertet','lys',['mark-heart.png']),kort('Hjertet · maske','brun',['heart-logo-mask.png']),
 kort('E-post · toppen','gul',['e-post-logo-topp.png']),kort('E-post · signatur','lys',['lissom-signatur-logo.png']),kort('E-post · logo med hjerte','lys',['e-post-logo.png']),kort('E-post · gavelapp','lys',['e-post-gavelapp.png']),
 kort('Ikon · nettleser','lys',['favicon.svg','favicon-32.png','favicon.ico']),kort('Ikon · telefon','lys',['icon-512.png','icon-192.png','apple-touch-icon.png'])];}
export function designTemplates(){
 const grupper=[['Lissom',lissom()],['Date Night',under('date-night')],['Paint on Pots',under('paint-on-pots')],['Sip & Clay',under('sip-and-clay')]];
 return el('div',{},el('div',{class:'page-head'},el('div',{},el('p',{class:'eyebrow',text:'Lissom · Arbeidsrom'}),el('h1',{text:'Designmaler'}))),
  ...grupper.map(([navn,filer])=>el('section',{class:'card',style:'margin-bottom:22px'},el('h2',{text:navn}),el('div',{style:'display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px'},filer))));
}
