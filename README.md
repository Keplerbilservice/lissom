# Vipps Login + betaling — backend-pakke for Lissom

Serverless-funksjoner for Vercel (gratisnivå holder). Kjører mot Vipps TESTMILJØ
(apitest.vipps.no) — bytt til api.vipps.no ved lansering (se nederst).

## Utrulling (én gang, ca. 10 min)

1. Opprett gratis konto på vercel.com (logg inn med GitHub-kontoen Keplerbilservice).
2. Opprett nytt prosjekt fra denne mappen (`vipps-backend/`).
3. Under Settings → Environment Variables, legg inn:
   - `VIPPS_CLIENT_ID` = test-client_id fra portalen
   - `VIPPS_CLIENT_SECRET` = test-client_secret
   - `VIPPS_SUB_KEY` = Ocp-Apim-Subscription-Key (primary, test)
   - `VIPPS_BASE` = `https://apitest.vipps.no`
   - `FRONTEND_URL` = `https://keplerbilservice.github.io/lissom/`
4. Deploy. Du får en adresse, f.eks. `https://lissom-backend.vercel.app`.
5. I Vipps-portalen (testsalgsenheten): legg til redirect-URI
   `https://lissom-backend.vercel.app/api/callback`
6. I nettsiden: sett `VIPPS_BACKEND` til Vercel-adressen (jeg gjør dette når adressen finnes).

## Filer

- `api/login.js` — starter innloggingen, sender bruker til Vipps
- `api/callback.js` — mottar koden fra Vipps, bytter til token, henter profil, sender bruker tilbake til Min side
- `vercel.json` — ruteoppsett

## Ved lansering (test → prod)

- Bytt alle fire miljøvariablene til prod-verdiene (`VIPPS_BASE` = `https://api.vipps.no`)
- Legg prod-redirect-URI-en inn på prod-salgsenheten i portalen
- Ingen kodeendringer.
