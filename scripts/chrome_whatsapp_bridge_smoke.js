'use strict';
// =========================================================
// scripts/chrome_whatsapp_bridge_smoke.js
//
// ASYA WHATSAPP WEB BRIDGE (Aşama 1) — Chrome Eklentisi JS Smoke Testi
// Manifest V3 izin sınırı, güvenlik kısıtları ve content.js aday tespiti birim testleri.
// =========================================================

const fs = require('fs');
const path = require('path');

const extDir = path.join(__dirname, '..', 'chrome-extension', 'asya-whatsapp-bridge');
const content = require(path.join(extDir, 'content.js'));

let tests = 0;
let fails = 0;
function ok(label, cond) {
  tests++;
  if (!cond) {
    fails++;
    console.error('FAIL:', label);
  }
}

// 1. Manifest V3 ve izin sınırları
const manifestPath = path.join(extDir, 'manifest.json');
const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
ok('manifest_version === 3', manifest.manifest_version === 3);
ok('host_permissions yalnız web.whatsapp.com ve asya.scai.tr',
  Array.isArray(manifest.host_permissions) &&
  manifest.host_permissions.length === 2 &&
  manifest.host_permissions.includes('https://web.whatsapp.com/*') &&
  manifest.host_permissions.includes('https://asya.scai.tr/*'));
ok('minimal Chrome izinleri (activeTab + storage + sidePanel)',
  Array.isArray(manifest.permissions) &&
  manifest.permissions.every((p) => ['activeTab', 'storage', 'sidePanel'].includes(p)));
ok('side_panel sağ yan panel tanımlı',
  manifest.side_panel && manifest.side_panel.default_path === 'popup.html');
ok('content_scripts yalnız web.whatsapp.com ile eşleşir',
  manifest.content_scripts.length === 1 &&
  manifest.content_scripts[0].matches.length === 1 &&
  manifest.content_scripts[0].matches[0] === 'https://web.whatsapp.com/*');

// 2. Yasaklı WhatsApp iç API / güvenlik ihlali taraması
for (const file of ['content.js', 'service-worker.js', 'popup.js']) {
  const src = fs.readFileSync(path.join(extDir, file), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/^\s*\/\/.*$/gm, '');
  ok(`${file} IndexedDB/localStorage/WebSocket kullanmaz`,
    !/\b(indexedDB|localStorage|sessionStorage|new\s+WebSocket|webpackChunk|__reactFiber)\b/.test(src));
}

// 3. Tarih ayracı & sistem mesajı elemesi
ok('tarih ayracı elenir', content.isSystemOrDateText('Bugün') && content.isSystemOrDateText('27.11.2026'));
ok('sistem mesajı elenir', content.isSystemOrDateText('Mesajlar uçtan uca şifrelidir.') && content.isSystemOrDateText('Bu mesaj silindi'));

// 4. Sıradan sohbet vs sentetik beyan adayı tespiti
const chatMsg = content.scoreBeyanCandidate('Günaydın arkadaşlar kolay gelsin');
ok('sıradan sohbet beyan adayı sayılmaz', chatMsg.isCandidate === false);

const shortReply = content.scoreBeyanCandidate('Tamam teşekkürler');
ok('kısa yanıt beyan adayı sayılmaz', shortReply.isCandidate === false);

const syntheticBeyan = content.scoreBeyanCandidate('Yeni beyan\nParti: 101\nBrüt: 5000\nNet: 4500\nPalet: 10\nKasa: 500');
ok('yeni beyan + alan sinyalleri aday olarak tespit edilir', syntheticBeyan.isCandidate === true && syntheticBeyan.score >= 70);

const syntheticUpper = content.scoreBeyanCandidate('YENİ BEYAN\nAlıcı: Test\nÜrün: Test\nNet: 1000');
ok('büyük/küçük harf toleransı çalışır', syntheticUpper.isCandidate === true);

// 5. Devam mesajı ilişkilendirme vs ilgisiz mesajları birleştirmeme
const msgs = [
  { client_id: '1', source_message_id: 'm1', sender_name: 'Ali', message_timestamp: '10:00', raw_text: 'Günaydın' },
  { client_id: '2', source_message_id: 'm2', sender_name: 'Ali', message_timestamp: '10:01', raw_text: 'Yeni beyan\nParti: 55\nPalet: 10\nBrüt: 4000\nNet: 3500' },
  { client_id: '3', source_message_id: 'm3', sender_name: 'Ali', message_timestamp: '10:01', raw_text: 'Ek 4 palet 1200 brüt 1000 net' },
  { client_id: '4', source_message_id: 'm4', sender_name: 'Veli', message_timestamp: '10:02', raw_text: 'Ek 2 palet 500 brüt' },
  { client_id: '5', source_message_id: 'm5', sender_name: 'Ali', message_timestamp: '10:05', raw_text: 'Yeni beyan\nParti: 56\nPalet: 8\nBrüt: 3000\nNet: 2700' }
];
const cands = content.detectBeyanCandidates(msgs);
ok('iki ayrı beyan adayı çıkar, ilgisiz mesajlar birleştirilmez', cands.length === 2);
ok('aynı göndericinin açık devam mesajı ilişkilendirilir', cands[0].merged_count === 2 && cands[0].raw_text.includes('Ek 4 palet'));
ok('farklı göndericinin mesajı önceki beyana birleştirilmez', !cands[0].raw_text.includes('500 brüt'));
ok('ikinci yeni beyan ayrı aday olarak kalır', cands[1].raw_text.includes('Parti: 56'));

// 6. pre-plain-text ayrıştırma
const pre = content.parsePrePlainText('[14:32, 27.11.2026] Ahmet Yılmaz: ');
ok('data-pre-plain-text tarih ve gönderen ayrıştırılır', pre.timestamp === '14:32, 27.11.2026' && pre.sender === 'Ahmet Yılmaz');

console.log(`chrome_whatsapp_bridge_smoke.js: ${tests} test, ${fails} başarısız`);
process.exit(fails === 0 ? 0 : 1);
