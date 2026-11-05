<?php
// =========================================================
// config/siparis_odeme_aviz.php — OGL ÖDEME AVİZOSU → Ödemeler (v490; include-only, çıktı basmaz)
//
// Siparişler › Mailden al › "Ödeme sorgula" bu dosyayı kullanır: OGL'nin Almanca ödeme avizosu PDF'i (doc_type payment_advice,
// config/siparis_pdf.php) doğrulama kapılarından geçerse ödeme ONAY SORMADAN siparis_odemeler'e yazılır; avizodaki fatura
// numaraları sistemdeki invoice'larla eşlenir (YALNIZ BİLGİ — ödeme sevkiyata BAĞLANMAZ, genel cari kuralı sürer).
//
// Değişmezler (değiştirmeden önce oku):
//  • ŞEMA YOK. Ödeme yazmanın TEK yolu siparis_odeme_ekle() (config/siparis_odeme.php). Avizo kalemleri hks_kv'de:
//    'siparis_odeme_aviz_<odeme_id>' (JSON, en çok 500 kalem). Belge satırı (siparis_belgeler) AÇILMAZ.
//  • Bu dosya IMAP'a gitmez (mail_ek_hazirla çağrılmaz) ve tablo oluşturmaz; yalnız siparis_odeme_ekle() ile ödeme yazar.
//  • Tekrar koruması: istek_id = avizonun ANLAMSAL içeriği (dosya sha256'sı DEĞİL) + UNIQUE; iptal edilmiş ödeme yeniden
//    diriltilmez; aynı tarih+tutar+PB'de AKTİF ödeme (elle girilmiş olabilir) varsa yeni satır yazılmaz.
//  • Fatura eşlemesi hata verirse ödemeyi ENGELLEMEZ (esleme_hata). Para birimleri asla toplanmaz.
// =========================================================
declare(strict_types=1);
// Doğrudan istek → 404. TAM YOL karşılaştırılır (basename değil — bkz. config/siparis_yazdir.php, v385).
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/siparis_core.php';
require_once __DIR__ . '/siparis_invoice.php';   // siparis_inv_kv_oku/yaz + SIPARIS_INV_GIRDI_KV_ON (yalnız fonksiyon tanımlar, dompdf YÜKLEMEZ)

/** Avizo ayrıştırıcı/kapı sürümü: ayrıştırıcı düzeltilince bump edilir → yalnız Ödeme düğmesi eski `okunamadi`/`taninmadi` kayıtları yeniden dener. */
const SIPARIS_ODEME_AVIZO_SURUM = 'a2';   // v492: debit note desteği → eski `taninmadi` (debit PDF'i tanınmıyordu) Ödeme düğmesinde bir kez yeniden denenir
const SIPARIS_ODEME_AVIZ_KV = 'siparis_odeme_aviz_';
const SIPARIS_ODEME_AVIZ_MAX = 500;

/** 6237744 → "62.377,44" (siparis_cari.php'ye bağımlı olmamak için yerel biçimleyici). */
function siparis_odeme_aviz_para(int $k): string
{
    $a = abs($k);
    return ($k < 0 ? '-' : '') . number_format(intdiv($a, 100), 0, ',', '.') . ',' . str_pad((string)($a % 100), 2, '0', STR_PAD_LEFT);
}

function siparis_odeme_aviz_anahtar(int $odemeId): string { return SIPARIS_ODEME_AVIZ_KV . $odemeId; }

/**
 * OP-Nummer çözümü. IHR + 13 hane = ihracat faturası (ana); sonunda D + Roma rakamı = düzeltme/kredi. Önek "26-"/"26/" yıl kısaltmasıdır.
 * ogl_ic ("2027-138760"), ro, bg ve tanınmayanlar eşleme adayı DEĞİLDİR (govde = null).
 * @return array{tur:string,govde:?string,sonek:string,onek:string}
 */
function siparis_odeme_aviz_no_coz(string $no): array
{
    $no = strtoupper(trim($no));
    if (preg_match('~^(?:(\d{2})[-/])?(IHR\d{13})(?:D([IVX]{1,6}))?$~', $no, $m)) {
        $sonek = (string)($m[3] ?? '');
        return ['tur' => $sonek !== '' ? 'duzeltme' : 'ana', 'govde' => $m[2], 'sonek' => $sonek !== '' ? 'D' . $sonek : '', 'onek' => (string)($m[1] ?? '')];
    }
    $tur = 'diger';
    if (preg_match('~^\d{4}-\d+RO$~', $no)) $tur = 'ro';
    elseif (preg_match('~^\d{4}-\d+BG$~', $no)) $tur = 'bg';
    elseif (preg_match('~^\d{4}-\d+$~', $no)) $tur = 'ogl_ic';
    return ['tur' => $tur, 'govde' => null, 'sonek' => '', 'onek' => ''];
}

/**
 * Tekrar-gönderim anahtarı: avizonun anlamsal içeriği (mektup tarihi, toplam, PB, sıralı satırlar). Aynı avizo farklı mail/bayt ile gelse AYNI id.
 * `avz-` + 32 hex = 36 karakter (siparis_odeme_ekle /^[A-Za-z0-9_-]{8,40}$/ ister).
 */
function siparis_odeme_aviz_istek_id(array $doc): string
{
    $satir = [];
    foreach ((array)($doc['rows'] ?? []) as $r) $satir[] = (string)$r['no'] . '|' . (string)$r['op_date'] . '|' . (int)$r['zahlung_kurus'];
    sort($satir, SORT_STRING);
    $anahtar = 'avz1|' . (string)($doc['header']['date'] ?? '') . '|' . (int)($doc['total']['amount_kurus'] ?? 0) . '|' . (string)($doc['total']['currency'] ?? '') . '|' . implode("\n", $satir);
    return 'avz-' . substr(sha1($anahtar), 0, 32);
}

// ── fatura no → sevkiyat eşlemesi (yalnız bilgi) ────────────────────────

/**
 * govde → ['durum'=>'eslesti'|'belirsiz', 'v'=>list<{id,ref,tk}>]. Eşleşmeyen gövde haritada YOKTUR.
 * Sabit sorgu sayısı: hks_kv (1) + fatura doğrulaması (≤2/500 sevkiyat) + sevkiyat (1) + belge (1).
 * Aday = hks_kv `siparis_invoice_<sid>` girdisinin `no`'su ana fatura ve gövdesi avizodaki kümede; ardından siparis_cari_fatura_fiyatlari()
 * ile YALNIZ üretilmiş + belgesi hâlâ geçerli olanlar kalır (yoksa fonksiyon eksik demektir → hiçbiri eşleşmez, fail-soft).
 * @param list<string> $govdeler
 * @return array<string,array{durum:string,v:list<array{id:int,ref:string,tk:?int}>}>
 */
function siparis_odeme_aviz_eslesmeler(PDO $pdo, array $govdeler): array
{
    $set = array_fill_keys($govdeler, true);
    if (!$set || !function_exists('siparis_cari_fatura_fiyatlari')) return [];
    $st = $pdo->prepare('SELECT anahtar, deger FROM `' . siparis_evrak_mail_kv_tablo() . '` WHERE anahtar LIKE ? LIMIT 20000');
    $st->execute([SIPARIS_INV_GIRDI_KV_ON . '%']);
    $aday = []; $belge = [];   // sid → govde ; sid → belge_id
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!preg_match('~^' . preg_quote(SIPARIS_INV_GIRDI_KV_ON, '~') . '(\d+)$~', (string)$r['anahtar'], $m)) continue;   // siparis_invoice_ayar vb. elenir
        $j = json_decode((string)($r['deger'] ?? ''), true);
        if (!is_array($j)) continue;
        $nc = siparis_odeme_aviz_no_coz((string)($j['no'] ?? ''));
        if ($nc['tur'] !== 'ana' || $nc['govde'] === null || !isset($set[$nc['govde']])) continue;
        $aday[(int)$m[1]] = $nc['govde']; $belge[(int)$m[1]] = (int)($j['belge_id'] ?? 0);
    }
    if (!$aday) return [];
    $gecerli = siparis_cari_fatura_fiyatlari($pdo, array_keys($aday));   // sid → ... (yalnız üretilmiş + geçerli belge)
    $sids = array_values(array_filter(array_keys($aday), static fn(int $s): bool => isset($gecerli[$s])));
    if (!$sids) return [];
    $ph = static fn(array $a): string => implode(',', array_fill(0, count($a), '?'));
    $q = $pdo->prepare('SELECT id, order_ref FROM siparis_sevkiyatlar WHERE deleted_at IS NULL AND id IN (' . $ph($sids) . ')');
    $q->execute($sids);
    $ref = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $ref[(int)$r['id']] = (string)$r['order_ref'];
    $bids = array_values(array_unique(array_filter(array_map(static fn(int $s): int => $belge[$s] ?? 0, array_keys($ref)))));
    $tk = [];
    if ($bids) {
        $q = $pdo->prepare('SELECT id, parse_json FROM siparis_belgeler WHERE id IN (' . $ph($bids) . ')');
        $q->execute($bids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $j = json_decode((string)($r['parse_json'] ?? ''), true);
            if (is_array($j) && isset($j['toplam_kurus']) && is_numeric($j['toplam_kurus'])) $tk[(int)$r['id']] = (int)$j['toplam_kurus'];
        }
    }
    $out = [];
    foreach ($ref as $sid => $oref) {
        $g = $aday[$sid];
        $out[$g]['v'][] = ['id' => $sid, 'ref' => $oref, 'tk' => $tk[$belge[$sid] ?? 0] ?? null];
    }
    foreach ($out as $g => &$e) {
        usort($e['v'], static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        $e['durum'] = count($e['v']) === 1 ? 'eslesti' : 'belirsiz';
        $e['v'] = array_slice($e['v'], 0, 5);
    }
    unset($e);
    return $out;
}

/**
 * Saf: satırlar + eşleme haritası → kv satırları + özet.
 * Satır: n (OP-Nummer) d (OP-Datum) r (Rechnung kuruş) z (Zahlung kuruş) t (tür) g (gövde) s (sonek) e (eslesti|eslesmedi|belirsiz|yok) v (sevkiyatlar) k (ok|fark|? — yalnız ana satır).
 * @return array{satirlar:list<array>,ozet:array}
 */
function siparis_odeme_aviz_satirlar(array $rows, array $harita, bool $eslemeHata = false): array
{
    $sat = []; $fatura = []; $eslesen = []; $eslesmeyen = []; $belirsiz = []; $fark = 0; $diger = 0;
    foreach ($rows as $r) {
        $nc = siparis_odeme_aviz_no_coz((string)$r['no']);
        $g = $nc['govde'];
        $s = ['n' => (string)$r['no'], 'd' => (string)$r['op_date'], 'r' => (int)$r['rechnung_kurus'], 'z' => (int)$r['zahlung_kurus'],
              't' => $nc['tur'], 'g' => (string)($g ?? ''), 's' => $nc['sonek'], 'e' => 'yok', 'v' => []];
        if ($g !== null) {
            $fatura[$g] = true;
            $h = $harita[$g] ?? null;
            if ($h === null) { $s['e'] = 'eslesmedi'; $eslesmeyen[$g] = true; }
            elseif ($h['durum'] === 'belirsiz') { $s['e'] = 'belirsiz'; $s['v'] = $h['v']; $belirsiz[$g] = true; }
            else {
                $s['e'] = 'eslesti'; $s['v'] = $h['v']; $eslesen[$g] = true;
                if ($nc['tur'] === 'ana') {
                    $tk = $h['v'][0]['tk'] ?? null;
                    $s['k'] = $tk === null ? '?' : ((int)$r['rechnung_kurus'] === $tk ? 'ok' : 'fark');
                    if ($s['k'] === 'fark') $fark++;
                }
            }
        } else $diger++;
        $sat[] = $s;
    }
    return ['satirlar' => $sat, 'ozet' => ['kalem' => count($rows), 'fatura' => count($fatura), 'eslesen' => count($eslesen), 'eslesmeyen' => count($eslesmeyen),
        'belirsiz' => count($belirsiz), 'tutar_fark' => $fark, 'diger' => $diger, 'esleme_hata' => $eslemeHata]];
}

/** Toplu eşleme (hata ödemeyi ENGELLEMEZ). @return array{satirlar:list<array>,ozet:array} */
function siparis_odeme_aviz_esle(PDO $pdo, array $rows, string $pb): array
{
    $harita = []; $hata = false;
    if (strtoupper($pb) === 'EUR') {   // OGL invoice'ları EUR kesilir
        try {
            $govde = [];
            foreach ($rows as $r) { $g = siparis_odeme_aviz_no_coz((string)$r['no'])['govde']; if ($g !== null) $govde[$g] = true; }
            $harita = siparis_odeme_aviz_eslesmeler($pdo, array_keys($govde));
        } catch (Throwable $e) {
            error_log('[siparis_odeme_aviz] esleme: ' . get_class($e));
            $harita = []; $hata = true;
        }
    }
    return siparis_odeme_aviz_satirlar($rows, $harita, $hata);
}

// ── hks_kv ──────────────────────────────────────────────────────────────

function siparis_odeme_aviz_kaydet(PDO $pdo, int $odemeId, array $kv): bool
{
    return siparis_inv_kv_yaz($pdo, siparis_odeme_aviz_anahtar($odemeId), $kv);
}

function siparis_odeme_aviz_oku(PDO $pdo, int $odemeId): ?array
{
    return siparis_inv_kv_oku($pdo, siparis_odeme_aviz_anahtar($odemeId));
}

/** Avizo kalemi kaydı olan ödeme idleri (yalnız anahtarlar okunur). @return array<int,true> */
function siparis_odeme_aviz_var_idleri(PDO $pdo): array
{
    $out = [];
    try {
        $st = $pdo->prepare('SELECT anahtar FROM `' . siparis_evrak_mail_kv_tablo() . '` WHERE anahtar LIKE ? LIMIT 20000');
        $st->execute([SIPARIS_ODEME_AVIZ_KV . '%']);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $a) {
            if (preg_match('~^' . preg_quote(SIPARIS_ODEME_AVIZ_KV, '~') . '(\d+)$~', (string)$a, $m)) $out[(int)$m[1]] = true;
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

// ── orkestratör ─────────────────────────────────────────────────────────

/** @return array{id:int,tutar_kurus:int,pb:string,tarih:string,kalem:int,fatura:int,eslesen:int,eslesmeyen:int,belirsiz:int,tutar_fark:int,iptal:bool,deger_eslesme:bool} */
function siparis_odeme_aviz_odeme_bilgi(int $id, array $doc, array $ozet, bool $iptal = false, bool $deger = false): array
{
    return ['id' => $id, 'tutar_kurus' => (int)$doc['total']['amount_kurus'], 'pb' => (string)$doc['total']['currency'], 'tarih' => (string)$doc['header']['date'],
        'kalem' => (int)($ozet['kalem'] ?? count((array)$doc['rows'])), 'fatura' => (int)($ozet['fatura'] ?? 0), 'eslesen' => (int)($ozet['eslesen'] ?? 0),
        'eslesmeyen' => (int)($ozet['eslesmeyen'] ?? 0), 'belirsiz' => (int)($ozet['belirsiz'] ?? 0), 'tutar_fark' => (int)($ozet['tutar_fark'] ?? 0),
        'iptal' => $iptal, 'deger_eslesme' => $deger];
}

/**
 * Doğrulanmış avizo belgesini ödemeye çevirir. Sıra SABİT (bkz. plan): kapılar → tekrar → değer koruması → eşleme → siparis_odeme_ekle → kv → audit.
 * $kaynak = ['m'=>mail id,'h'=>hesap id,'p'=>parça]. Dönüş sonuc: alindi | zaten | okunamadi (kalıcı) | hata (geçici, yeniden denenir).
 * @return array{sonuc:string,mesaj:string,odeme:?array}
 */
function siparis_odeme_aviz_isle(PDO $pdo, array $doc, array $kaynak, int $uid, ?string $bugun = null): array
{
    $bugun = $bugun ?? date('Y-m-d');
    $r = static fn(string $s, string $m, ?array $o = null): array => ['sonuc' => $s, 'mesaj' => $m, 'odeme' => $o];
    if (($doc['ok'] ?? false) !== true) return $r('okunamadi', (string)($doc['hata'] ?? 'Ödeme avizosu okunamadı.'));
    if (empty($doc['header']['sender_ok']) || empty($doc['header']['recipient_ok'])) return $r('okunamadi', 'Avizo OGL Food Trade → Asya Fresh olarak görünmüyor.');
    $kurus = (int)($doc['total']['amount_kurus'] ?? 0);
    $pb = strtoupper((string)($doc['total']['currency'] ?? ''));
    $tarih = (string)($doc['header']['date'] ?? '');
    if ($kurus <= 0) return $r('okunamadi', 'Avizo toplamı sıfır ya da negatif (' . siparis_odeme_aviz_para($kurus) . ' ' . $pb . ') — otomatik yazılmadı; Ödemeler sayfasından elle değerlendirin.');
    if (!in_array($pb, siparis_odeme_para_birimleri(), true)) return $r('okunamadi', 'Para birimi desteklenmiyor (' . $pb . ') — elle girin.');
    if ($tarih === '' || siparis_tarih_coz($tarih) === null) return $r('okunamadi', 'Avizo mektup tarihi bulunamadı ya da belirsiz.');
    $rows = (array)($doc['rows'] ?? []);
    if (!$rows || count($rows) > SIPARIS_ODEME_AVIZ_MAX) return $r('okunamadi', 'Avizo kalem sayısı geçersiz.');
    if ($tarih > $bugun) return $r('hata', 'Avizo tarihi (' . date('d.m.Y', (int)strtotime($tarih)) . ') bugünden ileri — tarih gelince yeniden sorgulayın.');
    if (!siparis_odeme_hazir($pdo)) return $r('hata', 'Ödeme tablosu kurulmadı (migrate.php › Sipariş ödemeleri).');

    $istek = siparis_odeme_aviz_istek_id($doc);
    if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', $istek)) return $r('hata', 'Avizo tekrar anahtarı üretilemedi.');   // kod hatası: istek_id sessizce NULL olmasın
    $tarihG = date('d.m.Y', (int)strtotime($tarih));

    $kvYaz = function (int $id, array $esl, bool $manuel) use ($pdo, $doc, $kaynak, $kurus, $pb, $tarih): bool {
        return siparis_odeme_aviz_kaydet($pdo, $id, [
            'v' => 1, 'kaynak' => 'mail', 'mail_id' => (int)($kaynak['m'] ?? 0), 'parca' => (string)($kaynak['p'] ?? ''), 'hesap_id' => (int)($kaynak['h'] ?? 0),
            'bagli_manuel' => $manuel, 'tarih' => $tarih, 'pb' => $pb, 'toplam_kurus' => $kurus, 'rechnung_kurus' => (int)($doc['sums']['rechnung_kurus'] ?? 0),
            'olusturuldu' => siparis_simdi(), 'ozet' => $esl['ozet'], 'satirlar' => $esl['satirlar'],
        ]);
    };
    $kvOzet = static function (?array $kv, array $yedek): array { return is_array($kv['ozet'] ?? null) ? $kv['ozet'] : $yedek; };

    // 7) aynı avizo daha önce kaydedildi mi (istek_id UNIQUE)
    $st = $pdo->prepare('SELECT id, iptal_at FROM siparis_odemeler WHERE istek_id = ?');
    $st->execute([$istek]);
    $var = $st->fetch(PDO::FETCH_ASSOC);
    if ($var) {
        $id = (int)$var['id']; $iptal = ($var['iptal_at'] ?? null) !== null;
        $kv = siparis_odeme_aviz_oku($pdo, $id);
        if ($kv === null && !$iptal) {   // onarım: ödeme var, kalem kaydı yok
            $esl = siparis_odeme_aviz_esle($pdo, $rows, $pb);
            $kvYaz($id, $esl, false);   // yazılamazsa sonraki sorgu yine onarmayı dener
            $kv = ['ozet' => $esl['ozet']];
        }
        $o = siparis_odeme_aviz_odeme_bilgi($id, $doc, $kvOzet($kv, ['kalem' => count($rows)]), $iptal, false);
        return $r('zaten', $iptal ? 'Bu avizo daha önce kaydedilmiş ve İPTAL edilmiş — yeniden eklenmedi. Gerekirse Ödemeler sayfasından elle girin.' : 'Ödeme zaten kayıtlı (#' . $id . ').', $o);
    }
    // 8) değer koruması: aynı tarih + tutar + PB'de AKTİF ödeme (kaynağı ne olursa olsun; elle girilmiş olabilir) → yenisini yazma
    $st = $pdo->prepare('SELECT id FROM siparis_odemeler WHERE iptal_at IS NULL AND odeme_tarihi = ? AND tutar_kurus = ? AND para_birimi = ? ORDER BY id LIMIT 1');
    $st->execute([$tarih, $kurus, $pb]);
    $ayni = $st->fetchColumn();
    if ($ayni !== false && $ayni !== null) {
        $id = (int)$ayni;
        $kv = siparis_odeme_aviz_oku($pdo, $id);
        if ($kv === null) {   // kalemleri bu ödemeye bağla (bagli_manuel)
            $esl = siparis_odeme_aviz_esle($pdo, $rows, $pb);
            $kvYaz($id, $esl, true);
            $kv = ['ozet' => $esl['ozet']];
        }
        $o = siparis_odeme_aviz_odeme_bilgi($id, $doc, $kvOzet($kv, ['kalem' => count($rows)]), false, true);
        return $r('zaten', 'Aynı tarih ve tutarda ödeme zaten kayıtlı (#' . $id . '; elle girilmiş olabilir) — tekrar eklenmedi.', $o);
    }
    // 9) eşleme (bilgi; hata ödemeyi engellemez)
    $esl = siparis_odeme_aviz_esle($pdo, $rows, $pb);
    // 10) ödeme (TEK yazma yolu)
    $x = siparis_odeme_ekle($pdo, [
        'tutar' => siparis_odeme_aviz_para($kurus), 'tarih' => $tarih, 'para_birimi' => $pb,
        'aciklama' => 'OGL ödeme avizosu ' . $tarihG . ' · mailden · ' . count($rows) . ' kalem',
        'dekont_no' => 'AVZ-' . date('Ymd', (int)strtotime($tarih)), 'istek_id' => $istek,
    ], $uid);
    if (!$x['ok']) return $r('hata', 'Ödeme kaydedilemedi: ' . $x['mesaj']);
    $id = (int)($x['id'] ?? 0);
    if ($id <= 0) {   // yarış: aynı istek_id başka süreçte yazıldı
        $st = $pdo->prepare('SELECT id, iptal_at FROM siparis_odemeler WHERE istek_id = ?');
        $st->execute([$istek]);
        $v2 = $st->fetch(PDO::FETCH_ASSOC);
        if (!$v2) return $r('hata', 'Ödeme kaydı doğrulanamadı — yeniden sorgulayın.');
        $o = siparis_odeme_aviz_odeme_bilgi((int)$v2['id'], $doc, $esl['ozet'], ($v2['iptal_at'] ?? null) !== null, false);
        return $r('zaten', 'Ödeme zaten kayıtlı (#' . (int)$v2['id'] . ').', $o);
    }
    // 11) kalem dökümü
    if (!$kvYaz($id, $esl, false)) return $r('hata', 'Ödeme eklendi (#' . $id . ') ancak avizo kalemleri kaydedilemedi — yeniden sorgulayınca tamamlanır.');
    // 12) audit (yalnız sayılar)
    $z = $esl['ozet'];
    siparis_audit('siparis_odeme_aviz', $id, null, ['kalem' => $z['kalem'], 'fatura' => $z['fatura'], 'eslesen' => $z['eslesen'], 'eslesmeyen' => $z['eslesmeyen'],
        'belirsiz' => $z['belirsiz'], 'mail_id' => (int)($kaynak['m'] ?? 0), 'parca' => (string)($kaynak['p'] ?? ''), 'kaynak' => 'mail'], $uid);
    return $r('alindi', 'Ödeme eklendi (#' . $id . ').', siparis_odeme_aviz_odeme_bilgi($id, $doc, $z));
}
