// テスト用の依存ゼロ静的サーバー。
// ツールは /assets/divp-core.js のように絶対パスで共通モジュールを読むため、
// file:// では動かない。リポジトリのルートを Heteml の公開ルートに見立てて配信する。
// api/*.php は PHP なので当然動かない。テストは未ログイン相当（fetch失敗）で走る。
const http = require('http');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const PORT = Number(process.env.PORT || 4173);
const TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.svg': 'image/svg+xml',
};

const server = http.createServer((req, res) => {
  const rel = decodeURIComponent(req.url.split('?')[0]).replace(/^\/+/, '');
  const file = path.resolve(ROOT, rel);
  // ルート外への脱出を防ぐ
  if (!file.startsWith(ROOT)) { res.writeHead(403).end('forbidden'); return; }
  fs.readFile(file, (err, buf) => {
    if (err) { res.writeHead(404).end('not found'); return; }
    res.writeHead(200, { 'Content-Type': TYPES[path.extname(file).toLowerCase()] || 'application/octet-stream' });
    res.end(buf);
  });
});

server.listen(PORT, () => console.log('static server on http://127.0.0.1:' + PORT));
