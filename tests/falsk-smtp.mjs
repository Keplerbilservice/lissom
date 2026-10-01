/** Loopback-only SMTP receiver. Captures email and never forwards it. */
import net from 'node:net';
export async function startSmtpReceiver() {
  const messages = [], attempts = [], sockets = new Set(); let retryCount = 0;
  const server = net.createServer(socket => {
    sockets.add(socket); socket.on('close', () => sockets.delete(socket)); socket.on('error', () => {});
    let buffer = '', recipient = '', lines = [], data = false, auth = 0;
    const send = text => socket.write(text + '\r\n');
    send('220 local-test ESMTP');
    socket.on('data', chunk => {
      buffer += chunk.toString('utf8');
      while (buffer.includes('\n')) {
        const pos = buffer.indexOf('\n'), line = buffer.slice(0, pos).replace(/\r$/, '');
        buffer = buffer.slice(pos + 1);
        if (data) {
          if (line !== '.') { lines.push(line); continue; }
          data = false; const message = { recipient, raw: lines.join('\n') }; attempts.push(message);
          if (recipient === 'retry@example.test' && retryCount++ === 0) send('451 temporary test failure');
          else { messages.push(message); send('250 accepted locally'); }
        } else if (auth) { send(auth++ === 1 ? '334 UGFzc3dvcmQ6' : '235 authenticated'); if (auth === 3) auth = 0; }
        else if (line.startsWith('EHLO')) send('250-local-test\r\n250 AUTH LOGIN');
        else if (line === 'AUTH LOGIN') { auth = 1; send('334 VXNlcm5hbWU6'); }
        else if (line.startsWith('MAIL FROM:')) send('250 sender accepted');
        else if (line.startsWith('RCPT TO:')) { recipient = line.match(/<([^>]+)>/)?.[1] || ''; send(recipient === 'reject@example.test' ? '550 recipient rejected for test' : '250 recipient accepted'); }
        else if (line === 'DATA') { data = true; lines = []; send('354 enter message'); }
        else if (line === 'QUIT') { send('221 goodbye'); socket.end(); }
        else send('500 unknown command');
      }
    });
  });
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
  return { messages, attempts, port: server.address().port,
    close: async () => { for (const socket of sockets) socket.destroy(); await new Promise(resolve => server.close(resolve)); } };
}
