// Medlemschatten i arbeidsrommet (eieren 04.10.2026). Samme rom og samme endepunkt som chatten i gamle admin
// (lissom-2108.html, «Chat»-snarveien på Kalender): /api/chat.php. Ingen egne regler her — serveren sier hvem som
// kan slette og hente tilbake (kanSlette/kanHente), skjermen tegner bare etter det.
import {el,api,button} from './ui.js';
export const chatPlaces=[['Chat','#chat']];
let poll=null;
export function chatScreens(){
 async function chat(){
  if(poll){clearInterval(poll);poll=null;}
  let meldinger=[],siste=0,nye=0,feil='',henter=false,sender=false;
  // Lest (idé 1, 08.10.2026): det som står framme her, er lest. Teller ned Meldinger-flisen på I dag.
  let lestSendt=0;
  function merkLest(){if(!siste||siste<=lestSendt||document.hidden)return;const til=siste;lestSendt=til;api('../chat.php',{handling:'lest',siste:til}).catch(()=>{lestSendt=0;});}
  const status=el('p',{class:'muted','aria-live':'polite'});
  const nytt=el('span',{class:'badge warn',text:'Nytt',style:'display:none'});
  const liste=el('div',{class:'list',style:'max-height:55vh;max-height:55dvh;overflow-y:auto;gap:10px;display:grid;margin-bottom:14px'});
  let stodNede=true;
  liste.addEventListener('scroll',()=>{stodNede=liste.scrollHeight-liste.scrollTop-liste.clientHeight<40;});
  const felt=el('textarea',{rows:1,placeholder:'Skriv','aria-label':'Skriv',maxlength:500,style:'min-height:0;resize:none;flex:1;min-width:0;padding:9px 14px;line-height:1.45;border:1px solid #c6b1a0;border-radius:11px;background:#fffdf9'});
  const send=button('Send',()=>sendNaa(),'primary');
  function hoyde(){const s=getComputedStyle(felt);const linje=parseFloat(s.lineHeight)||24;const luft=parseFloat(s.paddingTop)+parseFloat(s.paddingBottom)+parseFloat(s.borderTopWidth)+parseFloat(s.borderBottomWidth);felt.style.height='auto';felt.style.height=Math.min(felt.scrollHeight,Math.round(linje*6+luft))+'px';}
  felt.addEventListener('input',()=>{nye=0;feil='';hoyde();tegnStatus();});
  // Enter sender, Shift+Enter gir ny linje — som i gamle admin.
  felt.addEventListener('keydown',ev=>{if(ev.key!=='Enter'||ev.shiftKey||ev.isComposing)return;ev.preventDefault();sendNaa();});
  function tegnStatus(){status.textContent=feil||(nye>0?(nye===1?'Én ny melding':nye+' nye meldinger'):'Synlig for alle medlemmer');nytt.style.display=nye>0?'':'none';}
  function rad(m){
   const egen=!!m.egen;
   const kanAngre=(m.kanSlette===undefined?egen:!!m.kanSlette)&&!m.slettet;
   const kanHente=m.kanHente===undefined?(egen&&!!m.slettet):!!m.kanHente;
   return el('div',{style:`display:grid;gap:3px;justify-items:${egen?'end':'start'}`},
    el('small',{text:`${m.navn} ${m.tid}`,style:'font-size:13px'}),
    el('span',{text:m.tekst,style:`display:inline-block;max-width:min(520px,92%);padding:8px 14px;border-radius:14px;line-height:1.5;white-space:pre-wrap;overflow-wrap:anywhere;background:${egen?'var(--ink)':'var(--ground)'};color:${egen?'var(--paper)':'var(--ink)'}${m.slettet?';font-style:italic':''}`}),
    kanAngre||kanHente?el('div',{class:'actions'},
     kanAngre?Object.assign(button('Slett',()=>angre(m.id,false)),{title:'Slett meldingen'}):null,
     kanHente?Object.assign(button('Angre sletting',()=>angre(m.id,true)),{title:'Angre sletting'}):null):null);
  }
  function tegn(){const fulgte=stodNede;liste.replaceChildren(...meldinger.map(rad));if(fulgte)liste.scrollTop=liste.scrollHeight;tegnStatus();}
  async function hent(forste){
   if(henter)return;henter=true;
   try{const d=await api('../chat.php'+(!forste&&siste?'?etter='+siste:''));
    if(d&&d.meldinger){const gamle=forste?[]:meldinger;const ny=d.meldinger.filter(m=>!gamle.some(g=>g.id===m.id));
     if(!forste)nye+=ny.filter(m=>!m.egen).length;
     meldinger=gamle.concat(ny);siste=d.siste||siste;tegn();merkLest();}
   }catch(e){if(forste){feil=e.message;tegnStatus();}}
   finally{henter=false;}
  }
  async function sendNaa(){
   // Teksten hentes fra feltet, ikke fra en kopi — som i gamle admin.
   const t=(felt.value||'').trim();if(!t||sender)return;sender=true;
   felt.value='';hoyde();feil='';tegnStatus();
   try{await api('../chat.php',{tekst:t});henter=false;stodNede=true;await hent(false);}
   catch(e){felt.value=t;hoyde();feil=e.status?(e.message||'Meldingen gikk ikke.'):'Fikk ikke kontakt med serveren.';tegnStatus();}
   finally{sender=false;}
  }
  async function angre(id,tilbake){
   if(sender)return;sender=true;
   try{await api('../chat.php',{handling:tilbake?'angre-slett':'slett',id});
    if(tilbake){henter=false;await hent(true);}
    else{meldinger=meldinger.map(m=>m.id===id?{...m,tekst:'Meldingen er slettet',slettet:true,slettetAvMeg:true,kanHente:true}:m);tegn();}
   }catch(e){feil=e.message||'Gikk ikke.';tegnStatus();}
   finally{sender=false;}
  }
  await hent(true);
  // Spør etter nytt hvert 20. sekund så lenge chatten står framme.
  poll=setInterval(()=>{if(!liste.isConnected){clearInterval(poll);poll=null;return;}hent(false);},20000);
  tegnStatus();
  const side=el('div',{},
   el('div',{class:'page-head'},el('div',{},el('p',{class:'eyebrow',text:'Lissom · Arbeidsrom'}),el('h1',{text:'Medlemschat'}),el('div',{class:'actions'},status,nytt))),
   el('section',{class:'card',id:'admin-chat'},liste,el('div',{style:'display:flex;gap:10px;align-items:flex-end'},felt,send)));
  requestAnimationFrame(()=>{liste.scrollTop=liste.scrollHeight;hoyde();});
  return side;
 }
 return{chat};
}
