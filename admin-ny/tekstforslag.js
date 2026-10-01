import {api,form,field,confirm} from './ui.js';
export function textSuggestion({name,where,fields,context,save}) {
 return form('Tekstforslag · '+name,[field('felt','Hvilken tekst?','text',{options:fields.map(([key,label])=>[key,label])})],{},async choice=>{
  const [key,label,format]=fields.find(([key])=>key===choice.felt);
  if(!await confirm('Lag tekstforslag?','Bruk AI-oppsettet til å foreslå '+label.toLowerCase()+'. Forbruk belastes innenfor kostnadstaket.','Lag forslag'))throw Error('Avbrutt.');
  const proposal=await api('ai.php',{handling:'felttekst',felt:label,om:name,hvor:where,form:format||'avsnitt',kontekst:Object.fromEntries(Object.entries(context).filter(([,v])=>['string','number','boolean'].includes(typeof v)))});
  form('Kontroller tekstforslaget',[field('tekst',label,'textarea',{required:true,help:'Kostnad: '+proposal.kostnad+'. Kontroller opplysningene før lagring.'})],{tekst:proposal.tekst},async v=>{
   if(!await confirm('Lagre teksten?','Erstatt '+label.toLowerCase()+' for '+name+'. Teksten blir synlig der innholdet er publisert.','Lagre'))throw Error('Avbrutt.');
   await save({[key]:v.tekst});
  });
 },{submitLabel:'Lag forslag',successText:false});
}
