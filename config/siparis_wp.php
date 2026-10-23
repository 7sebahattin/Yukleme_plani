<?php
// =========================================================
// config/siparis_wp.php — Siparişler › ⋮ "WP Paylaş" sevkiyat kartı verisi (v394; include-only)
//
// siparis_paylas.php (GET JSON ucu) çağırır; çıktı/exit/yazma YOK (audit uçta). _siparis_ortak.php YÜKLÜ varsayılır
// (sipui_kalem_coz / sipui_tarih / sipui_sayi). TEK sevkiyat, KAPALI / iptal dahil; silinmiş ya da başka depodaki → null.
// Değerler HAM METİN (HTML yok; kaçış istemcide assets/siparis_wp.js). Boş değer '—'. Alan sırası sahibindir.
// Nakliyeci / plakalar YALNIZ gerçek alanlar (planlı değerler okunmaz — v382 kuralı). Parti no, tur numarasıyla DOLDURULMAZ.
// =========================================================
declare(strict_types=1);
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/siparis_yazdir.php';   // v395: Alıcı Ülke = siparis_yazdir_ulke() (OGL Excel eşlemesi, TEK kaynak)

/** Kart alanları — sıra ve etiketler sahibindir (DEĞİŞTİRME). @return list<array{k:string,etiket:string}> */
function siparis_wp_alanlari(): array
{
    return [
        ['k' => 'parti',     'etiket' => 'Parti No'],
        ['k' => 'tarih',     'etiket' => 'Yükleme tarihi'],
        ['k' => 'siparis',   'etiket' => 'Sipariş No'],
        ['k' => 'navlun',    'etiket' => 'Navlun No'],
        ['k' => 'nakliyeci', 'etiket' => 'Nakliyeci'],
        ['k' => 'plaka',     'etiket' => 'Plakalar'],
        ['k' => 'sofor',     'etiket' => 'Şoför adı'],
        ['k' => 'tel',       'etiket' => 'Şoför telefonu'],
        ['k' => 'gumruk',    'etiket' => 'Gümrük'],
        ['k' => 'ulke',      'etiket' => 'Alıcı Ülke'],
    ];
}

/** Boş/boşluk → '—', değilse kırpılmış metin. */
function siparis_wp_deger(mixed $v): string
{
    $t = trim((string)($v ?? ''));
    return $t !== '' ? $t : '—';
}

/** "N / M" (palet / koli, binlik noktalı); ikisi de yoksa '—'; biri yoksa o taraf '—' (sipui_sayi). */
function siparis_wp_palet_koli(mixed $palet, mixed $koli): string
{
    $var = static fn($x): bool => $x !== null && $x !== '' && is_numeric($x);
    if (!$var($palet) && !$var($koli)) return '—';
    return sipui_sayi($palet) . ' / ' . sipui_sayi($koli);
}

/** Paylaşılan PNG'nin dosya adı (uzantısız): "WP-<sipariş no>"; kişisel veri İÇERMEZ. */
function siparis_wp_dosya_adi(string $orderRef, int $sid): string
{
    $s = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($orderRef)), '-.');
    return 'WP-' . ($s !== '' ? $s : 'sevkiyat-' . $sid);
}

/**
 * Tek sevkiyat + kalemleri + parti no'ları (SALT OKUNUR). null: id ≤ 0, yok, silinmiş ya da aktif depoda görünmüyor
 * (varlık sızdırmamak için hepsi aynı sonuç). Şoför / yurt içi gümrük kolonları YALNIZ kuruluysa SELECT'e girer.
 * @return array{sev:array<string,mixed>,kalem:list<array<string,mixed>>,parti:list<string>,sofor:bool,gumruk:bool}|null
 */
function siparis_wp_oku(PDO $pdo, int $sid): ?array
{
    if ($sid <= 0) return null;
    $sofor  = siparis_sofor_hazir($pdo);
    $gumruk = siparis_yurtici_gumruk_hazir($pdo);
    $cols = 's.id, s.depo, s.order_ref, s.po_belge_id, s.alici_kodu, s.alici_adi, s.navlun_no, s.yukleme_tarihi, s.plakalar, s.nakliyeci, s.toplam_palet, s.toplam_koli'
          . ($sofor ? ', s.sofor_adi, s.sofor_telefon' : '') . ($gumruk ? ', s.yurtici_gumruk' : '');
    [$dsql, $dprm] = depo_sql_column('s.depo', ':udm');
    $st = $pdo->prepare('SELECT ' . $cols . ' FROM siparis_sevkiyatlar s WHERE s.id = :wpid AND s.deleted_at IS NULL' . $dsql . ' LIMIT 1');
    $st->execute([':wpid' => $sid] + $dprm);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || !siparis_gorunur($r)) return null;
    $kq = $pdo->prepare('SELECT kalibre, sira, urun, aciklama, cesit, koli_icerik, pf, po_palet, etkin_palet, etkin_koli
        FROM siparis_kalemler WHERE sevkiyat_id = ? ORDER BY sira, (kalibre + 0), kalibre, id');
    $kq->execute([$sid]);
    $ph = siparis_beyan_parti_haritasi($pdo, [$sid]);   // can_beyan('read') yoksa [] (hata yutar)
    $kalemler = $kq->fetchAll(PDO::FETCH_ASSOC);
    return ['sev' => $r, 'kalem' => $kalemler, 'adresler' => siparis_wp_bosaltma_adresleri($pdo, $r, $kalemler), 'parti' => array_map('strval', (array)($ph[$sid]['liste'] ?? [])),
            'sofor' => $sofor, 'gumruk' => $gumruk];
}

/**
 * v425 — kartın altındaki küçük "Boşaltma adresi" notu: PO belgesinde BU siparişin (order ref) satırlarında palet dağıtılan
 * boşaltma noktaları (LIDL ROMAN vb.) + PO'nun "Unloading addresses" listesindeki numara ve tam adres. Alakasız adres BASILMAZ:
 * yalnız palet>0 olan nokta; kartta görünen kalibrelerle sınırlı (kalem süzgeci sipui_kalem_coz). PO yoksa/okunamazsa boş (kart
 * eskisi gibi). Salt okunur, hata yutulur.
 * @return list<array{ad:string,kod:string,adres:string}>
 */
function siparis_wp_bosaltma_adresleri(PDO $pdo, array $sev, array $kalemler): array
{
    $poId = (int)($sev['po_belge_id'] ?? 0);
    if ($poId <= 0) return [];
    try {
        $st = $pdo->prepare('SELECT parse_json FROM siparis_belgeler WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$poId]);
        $pj = siparis_json_coz((string)($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return [];
    }
    return siparis_wp_adres_coz((array)$pj, (string)($sev['order_ref'] ?? ''), $kalemler);
}

/** Saf (DB yok): PO parse_json + order ref + sevkiyat kalemleri → boşaltma adres satırları (PO sırasıyla, tekil). */
function siparis_wp_adres_coz(array $po, string $orderRef, array $kalemler): array
{
    if ($orderRef === '' || empty($po['loadings'])) return [];
    $kal = [];
    foreach ($kalemler as $k) { $c = sipui_kalem_coz($k); if ($c !== null && $c['kalibre'] !== '') $kal[$c['kalibre']] = true; }
    $adres = [];
    foreach ((array)($po['unloading_locations'] ?? []) as $a) $adres[mb_strtoupper(trim((string)($a['name'] ?? '')))] = $a;
    $out = []; $gor = [];
    foreach ((array)$po['loadings'] as $o) {
        if (trim((string)($o['order_ref'] ?? '')) !== $orderRef) continue;
        foreach ((array)($o['items'] ?? []) as $it) {
            $kalibre = (string)($it['pcs_per_box'] ?? '');
            if ($kal && $kalibre !== '' && !isset($kal[$kalibre])) continue;
            foreach ((array)($it['locations'] ?? []) as $l) {
                if (($l['role'] ?? 'location') !== 'location' || (int)($l['pallets'] ?? 0) <= 0) continue;
                $ad = trim((string)($l['name'] ?? ''));
                $anahtar = mb_strtoupper($ad);
                if ($ad === '' || isset($gor[$anahtar])) continue;
                $gor[$anahtar] = true;
                $a = $adres[$anahtar] ?? [];
                $out[] = ['ad' => $ad, 'kod' => trim((string)($a['code'] ?? $l['code'] ?? '')), 'adres' => trim((string)preg_replace('/\s+/u', ' ', (string)($a['address'] ?? '')))];
            }
        }
    }
    return $out;
}

/**
 * Saf (DB/oturum yok): kart JSON'u. $ctx: parti_yetki (bool).
 * @return array{ok:bool,id:int,dosya:string,alanlar:list<array{k:string,etiket:string,deger:string}>,kalem_basliklar:list<string>,
 *   kalemler:list<array{urun:string,kalibre:string,pf:string,palet_koli:string}>,kalem_say:int,toplam:array{etiket:string,palet_koli:string},notlar:list<string>}
 */
function siparis_wp_veri(array $okunan, array $ctx): array
{
    $r = (array)$okunan['sev'];
    $sid = (int)$r['id'];
    $partiYetki = !empty($ctx['parti_yetki']);
    $parti = $partiYetki ? array_values(array_unique(array_filter(array_map('trim', (array)$okunan['parti']), fn($x) => $x !== ''))) : [];
    $tarih = ($r['yukleme_tarihi'] ?? null) !== null ? sipui_tarih((string)$r['yukleme_tarihi']) : '—';   // " ✓" eki YOK
    $deger = [
        'parti'     => $parti ? implode(' / ', $parti) : '—',
        'tarih'     => $tarih,
        'siparis'   => siparis_wp_deger($r['order_ref'] ?? null),
        'navlun'    => siparis_wp_deger($r['navlun_no'] ?? null),
        'nakliyeci' => siparis_wp_deger($r['nakliyeci'] ?? null),
        'plaka'     => siparis_wp_deger($r['plakalar'] ?? null),
        'sofor'     => !empty($okunan['sofor']) ? siparis_wp_deger($r['sofor_adi'] ?? null) : '—',
        'tel'       => !empty($okunan['sofor']) ? siparis_wp_deger($r['sofor_telefon'] ?? null) : '—',
        'gumruk'    => !empty($okunan['gumruk']) ? siparis_wp_deger($r['yurtici_gumruk'] ?? null) : '—',
        'ulke'      => siparis_wp_deger(siparis_yazdir_ulke($r['alici_kodu'] ?? null, $r['alici_adi'] ?? null)),
    ];
    $alanlar = [];
    foreach (siparis_wp_alanlari() as $a) $alanlar[] = ['k' => $a['k'], 'etiket' => $a['etiket'], 'deger' => $deger[$a['k']]];
    $kalemler = [];
    foreach ((array)$okunan['kalem'] as $k) {
        $c = sipui_kalem_coz($k);
        if ($c === null) continue;
        $kalemler[] = ['urun' => $c['urun'] !== '' ? $c['urun'] : '—', 'kalibre' => $c['kalibre'] !== '' ? $c['kalibre'] : '—',
                       'pf' => (($k['pf'] ?? null) !== null && is_numeric($k['pf'])) ? sipui_sayi($k['pf']) : '—',   // palet başına koli
                       'palet_koli' => siparis_wp_palet_koli($c['palet'], $c['koli'])];
    }
    $say = count($kalemler);
    if (!$kalemler) $kalemler[] = ['urun' => '—', 'kalibre' => '—', 'pf' => '—', 'palet_koli' => '—'];   // başlıklar her zaman görünür
    $notlar = [];
    if (!$partiYetki) $notlar[] = 'Parti no için beyan okuma yetkisi gerekir.';
    if (empty($okunan['sofor'])) $notlar[] = 'Şoför alanları kurulmadı.';
    if (empty($okunan['gumruk'])) $notlar[] = 'Yurt içi gümrük alanı kurulmadı.';
    return [
        'ok' => true,
        'id' => $sid,
        'dosya' => siparis_wp_dosya_adi((string)($r['order_ref'] ?? ''), $sid),
        'alanlar' => $alanlar,
        'kalem_basliklar' => ['Ürün', 'Kalibre', 'PF', 'Palet/Koli'],
        'kalemler' => $kalemler,
        'kalem_say' => $say,
        'adres_baslik' => 'Boşaltma adresi',
        'adresler' => array_map(static fn(array $a): array => ['ad' => $a['ad'], 'kod' => $a['kod'], 'adres' => $a['adres']], (array)($okunan['adresler'] ?? [])),
        'toplam' => ['etiket' => 'Toplam', 'palet_koli' => siparis_wp_palet_koli($r['toplam_palet'] ?? null, $r['toplam_koli'] ?? null)],
        'notlar' => $notlar,
    ];
}
