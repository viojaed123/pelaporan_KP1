import http from 'node:http';
import process from 'node:process';

const port = Number(process.env.PROXY_PORT || 8099);
const tujuan = new URL(process.env.PROXY_TARGET || 'http://127.0.0.1/pelaporan');
const awalan = tujuan.pathname.replace(/\/$/, '');
const portaTujuan = Number(tujuan.port || (tujuan.protocol === 'https:' ? 443 : 80));

const server = http.createServer((req, res) => {
    const jalur = awalan + (req.url || '/');
    const headers = { ...req.headers, host: tujuan.host };

    const naik = http.request({
        hostname: tujuan.hostname,
        port: portaTujuan,
        method: req.method,
        path: jalur,
        headers,
    }, (jawab) => {
        res.writeHead(jawab.statusCode || 502, jawab.headers);
        jawab.pipe(res);
    });

    naik.on('error', (err) => {
        res.writeHead(502, { 'content-type': 'text/plain; charset=utf-8' });
        res.end('Proxy error: ' + err.message);
    });

    req.pipe(naik);
});

server.listen(port, '127.0.0.1', () => {
    console.log('Proxy pelaporan di http://127.0.0.1:' + port);
});
