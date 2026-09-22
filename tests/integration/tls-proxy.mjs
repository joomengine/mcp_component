import fs from 'node:fs';
import http from 'node:http';
import https from 'node:https';

// Test-only TLS termination for the disposable installed Joomla fixture.
// The client trusts the generated certificate; certificate checks stay enabled.
const [targetValue, portValue, keyPath, certificatePath, listenHost = '127.0.0.1', canonicalHost] = process.argv.slice(2);
const target = new URL(targetValue);
const port = Number(portValue);
const authority = canonicalHost ?? target.host;
const authorityMatch = /^127\.0\.0\.1:([1-9][0-9]{0,4})$/.exec(authority);
if (target.protocol !== 'http:' || target.hostname !== '127.0.0.1'
    || target.pathname !== '/' || target.search || target.hash || target.username
    || target.password || !['127.0.0.1', '0.0.0.0'].includes(listenHost) || !Number.isInteger(port) || port < 1024 || port > 65535
    || (canonicalHost !== undefined && (authorityMatch === null || Number(authorityMatch[1]) > 65535))) {
  throw new Error('Only the local disposable HTTP fixture may be proxied.');
}

const server = https.createServer({
  key: fs.readFileSync(keyPath),
  cert: fs.readFileSync(certificatePath),
}, (request, response) => {
  const headers = { ...request.headers, host: authority };
  delete headers.connection;
  const upstream = http.request({
    hostname: target.hostname,
    port: target.port,
    method: request.method,
    path: request.url,
    headers,
  }, incoming => {
    response.writeHead(incoming.statusCode, incoming.headers);
    incoming.pipe(response);
  });
  upstream.setTimeout(120000, () => upstream.destroy());
  upstream.on('error', () => {
    if (!response.headersSent) response.writeHead(502);
    response.end();
  });
  request.on('aborted', () => upstream.destroy());
  request.pipe(upstream);
});
server.listen(port, listenHost);
for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => server.close(() => process.exit(0)));
}
