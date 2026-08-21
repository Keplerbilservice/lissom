// Mottar ?code=... fra Vipps, bytter mot token (client_secret_post),
// henter brukerprofil og sender brukeren tilbake til Min side.
module.exports = async (req, res) => {
  const url = new URL(req.url, `https://${req.headers.host}`);
  const code = url.searchParams.get('code');
  const state = url.searchParams.get('state');
  const cookieState = (req.headers.cookie || '').match(/vipps_state=([a-f0-9]+)/)?.[1];
  if (!code || !state || state !== cookieState) {
    res.statusCode = 400;
    return res.end('Ugyldig innloggingsforsøk. Prøv igjen fra lissom.no.');
  }
  const redirectUri = `https://${req.headers.host}/api/callback`;
  // 1) Bytt code mot token — client_secret_post: secret i body
  const tokenRes = await fetch(`${process.env.VIPPS_BASE}/access-management-1.0/access/oauth2/token`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'Ocp-Apim-Subscription-Key': process.env.VIPPS_SUB_KEY,
    },
    body: new URLSearchParams({
      grant_type: 'authorization_code',
      code,
      redirect_uri: redirectUri,
      client_id: process.env.VIPPS_CLIENT_ID,
      client_secret: process.env.VIPPS_CLIENT_SECRET,
    }),
  });
  if (!tokenRes.ok) {
    res.statusCode = 502;
    return res.end('Innloggingen feilet hos Vipps. Prøv igjen.');
  }
  const { access_token } = await tokenRes.json();
  // 2) Hent profil fra userinfo-endepunktet
  const userRes = await fetch(`${process.env.VIPPS_BASE}/vipps-userinfo-api/userinfo`, {
    headers: {
      Authorization: `Bearer ${access_token}`,
      'Ocp-Apim-Subscription-Key': process.env.VIPPS_SUB_KEY,
    },
  });
  const user = userRes.ok ? await userRes.json() : {};
  // 3) Enkel sesjon: signert cookie med navn/e-post (byttes ut med ordentlig
  //    sesjonslager når medlemsregisteret kommer).
  const profil = Buffer.from(JSON.stringify({
    navn: user.name || '', epost: user.email || '', telefon: user.phone_number || '',
  })).toString('base64url');
  res.setHeader('Set-Cookie', [
    `lissom_bruker=${profil}; Secure; SameSite=Lax; Max-Age=86400; Path=/`,
    'vipps_state=; Max-Age=0; Path=/',
  ]);
  res.statusCode = 302;
  res.setHeader('Location', `${process.env.FRONTEND_URL}#minside`);
  res.end();
};
