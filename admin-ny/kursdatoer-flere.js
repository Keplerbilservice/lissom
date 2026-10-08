// Slett flere kursdatoer samtidig (flyttet fra gammel admin, slettValgteOkter/slettOkter, 8. oktober 2026).
// Velg datoene i Kurs › Datoer. Kursene blir stående. Datoer noen har meldt seg på avlyses i stedet
// for å slettes (kurs.php «slettdato» bestemmer det per dato, som før).
import {api,button,form,field,confirm,toast} from './ui.js';

export function slettFlereDatoer(k,refresh){
 const datoer=(k.datoer||[]);
 if(datoer.length<2)return null;
 return button('Slett flere datoer',()=>form('Slett flere datoer · '+k.tittel,
  datoer.map(o=>field('okt'+o.oktId,o.naar+(o.status==='avlyst'?' · avlyst':''),'checkbox')),{},
  async v=>{
   const valgte=datoer.filter(o=>v['okt'+o.oktId]);
   if(!valgte.length)throw Error('Velg minst én dato.');
   const navn=valgte.map(o=>o.naar);
   if(!await confirm(valgte.length===1?'Slette denne datoen?':'Slette disse '+valgte.length+' datoene?',
    navn.slice(0,12).join(', ')+(navn.length>12?' … og '+(navn.length-12)+' til':'')
    +'. Kurset blir stående. Datoer noen har meldt seg på blir avlyst i stedet for slettet — da må du gi beskjed og refundere selv.',
    'Slett'))throw Error('Avbrutt.');
   let slettet=0;const avlyst=[];const feil=[];
   for(const o of valgte){
    try{const d=await api('kurs.php',{handling:'slettdato',oktId:o.oktId});if(d.slettet)slettet++;else avlyst.push(o.naar);}
    catch(e){feil.push(o.naar);}
   }
   toast([slettet===1?'Én dato er tatt bort.':slettet+' datoer er tatt bort.',
    avlyst.length?(avlyst.length===1?'Én dato hadde påmeldte og ble avlyst i stedet.':avlyst.length+' datoer hadde påmeldte og ble avlyst i stedet.'):'',
    feil.length?'Gikk ikke: '+feil.join(', ')+'.':''].filter(Boolean).join(' '));
   refresh();
  },{submitLabel:'Slett de valgte',successText:false}),'danger');
}
