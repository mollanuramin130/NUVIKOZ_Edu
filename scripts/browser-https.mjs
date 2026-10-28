// TLS terminators used only by disposable integration CI. No certificate is committed.
import https from 'node:https';
import http from 'node:http';
import fs from 'node:fs';
const key = fs.readFileSync(process.env.EDU_TEST_KEY);
const cert = fs.readFileSync(process.env.EDU_TEST_CERT);
for (const [port, upstream] of [[3443, 3000], [8443, 8000]]) {
  https.createServer({ key, cert }, (req, res) => {
    const proxy = http.request({ hostname: '127.0.0.1', port: upstream, method: req.method, path: req.url, headers: { ...req.headers, host: req.headers.host, 'x-forwarded-proto': 'https' } }, response => {
      res.writeHead(response.statusCode, response.headers); response.pipe(res);
    });
    proxy.on('error', () => { res.writeHead(502); res.end('Backend starting'); });
    req.pipe(proxy);
  }).listen(port, '0.0.0.0');
}
