import http from 'node:http';
const ledger = new Map();
const answer = (res,code,data) => { res.writeHead(code, {'Content-Type':'application/json'}); res.end(JSON.stringify(data)); };
http.createServer(async (req,res) => {
  let raw=''; for await (const c of req) raw += c;
  const path = new URL(req.url,'http://localhost').pathname;
  if(path === '/accesstoken/get') return answer(res,200,{access_token:'refund-browser-synthetic', expires_in:3600});
  if(path === '/ledger') return answer(res,200,Array.from(ledger.values()));
  if(path === '/health') return answer(res,200,{ok:true});
  const m=path.match(/^\/epayment\/v1\/payments\/([^/]+)\/refund$/);
  if(m && req.method==='POST') {
    const body=JSON.parse(raw);
    const key=req.headers['idempotency-key'];
    if(!key) return answer(res,400,{error:'Missing key'});
    const previous=ledger.get(key);
    if(previous && JSON.stringify(previous.body)!==JSON.stringify(body)) return answer(res,409,{error:'Different body'});
    const entry=previous || {ref:decodeURIComponent(m[1]),key,body,response:{state:'REFUNDED',pspReference:'synthetic-'+ledger.size}};
    ledger.set(key,entry); return answer(res,200,entry.response);
  }
  return answer(res,404,{error:'Unknown synthetic route'});
}).listen(Number(process.env.REFUND_STUB_PORT || 8165),'127.0.0.1');
