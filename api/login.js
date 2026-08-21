// Starter Vipps Login: redirecter brukeren til Vipps' autorisasjonsside.
const crypto = require('crypto');

module.exports = (req, res) => {
  const state = crypto.randomBytes(16).toString('hex');
  // state lagres i en kortlevd cookie og verifiseres i callback (CSRF-vern)
  res.setHeader('Set-Cookie', `vipps_state=${state}; HttpOnly; Secure; SameSite=Lax; Max-Age=600; Path=/`);
  const params = new URLSearchParams({
    client_id: process.env.VIPPS_CLIENT_ID,
    response_type: 'code',
    scope: 'openid name phoneNumber',
    state,
    redirect_uri: `https://${req.headers.host}/api/callback`,
  });
  res.statusCode = 302;
  res.setHeader('Location', `${process.env.VIPPS_BASE}/access-management-1.0/access/oauth2/auth?${params}`);
  res.end();
};
