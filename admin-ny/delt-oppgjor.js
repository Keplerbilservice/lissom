import {form,field,confirm,money} from './ui.js';
export function splitPayment(title,save){
 return form(title,[field('kontant','Kontant i kroner','number',{min:0,step:.01}),field('vipps','Mottatt Vipps i kroner','number',{min:0,step:.01}),field('gavekort','Gavekort i kroner','number',{min:0,step:.01}),field('kode','Gavekortkode')],{},async v=>{
  const deler=[['Kontant',v.kontant],['Vipps',v.vipps],['Gavekort',v.gavekort]].filter(([,belop])=>belop>0).map(([maate,belop])=>({maate,belop,...(maate==='Gavekort'?{kode:v.kode}:{})}));
  if(deler.length<2)throw Error('Fyll inn minst to deler. Bruk vanlig betalingsregistrering for én betalingsmåte.');
  if(deler.some(r=>r.maate==='Gavekort')&&!v.kode.trim())throw Error('Oppgi gavekortkoden.');
  if(!await confirm('Registrer delt oppgjør?','Registrer '+deler.map(r=>money(Math.round(r.belop*100))+' '+r.maate.toLowerCase()).join(' + ')+'. Registrer bare betaling som faktisk er mottatt. Gavekortdelen trekkes fra saldoen.','Registrer'))throw Error('Avbrutt.');
  await save(deler);
 });
}
