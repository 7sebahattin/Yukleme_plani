<?php
// =========================================================
// siparis_kaydet.php — Sevkiyat / belge değişikliklerinin TEK POST ucu (PRG)
//
// islem (beyaz liste): alan · kilit_ac · durum · catisma · not · belge_bagla · yeniden_isle · yeni   → siparis.write
//                      belge_sil · sil                                                                → siparis.delete
// Sıra: csrf_check → kapı → kaydı yükle + görünürlük (depo) → kütüphane çağrısı → flash + yönlendirme.
// Mutasyonu ve audit'i kütüphane yapar (iki kez audit yazılmaz); burada yalnız belge bağlama/yeniden işleme audit'lenir.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/siparis_eslestir.php';
require_once __DIR__ . '/_siparis_ortak.php';
$auth_user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: siparisler.php'); exit; }
csrf_check($_POST['csrf'] ?? null);

$islem = (string)($_POST['islem'] ?? '');
$yazIslemleri = ['alan', 'kilit_ac', 'durum', 'catisma', 'not', 'belge_bagla', 'yeniden_isle', 'yeni'];
$silIslemleri = ['belge_sil', 'sil'];
if (in_array($islem, $silIslemleri, true)) require_siparis('delete');
else require_siparis('write');

$pdo = db();
$uid = (int)$auth_user['id'];
$anchorlar = ['bilgi', 'kalemler', 'belgeler', 'pkl', 'beyan', 'catismalar', 'gecmis', 'uyarilar'];
$anchor = (string)($_POST['donus'] ?? '');
$anchor = in_array($anchor, $anchorlar, true) ? $anchor : '';

$git = function (string $url): void { header('Location: ' . $url); exit; };
$hata = function (string $m, string $url = 'siparisler.php') use ($git): void { set_flash('error', $m); $git($url); };
$sevUrl = fn(int $sid): string => 'siparis_view.php?id=' . $sid . ($anchor !== '' ? '#' . $anchor : '');

if (!siparis_sema_hazir($pdo)) $hata('Sipariş modülü henüz kurulmadı.');
if (!in_array($islem, array_merge($yazIslemleri, $silIslemleri), true)) $hata('Geçersiz işlem.');

/** Sevkiyatı yükler; yoksa hata, depoda görünmüyorsa 403. */
$sevYukle = function (int $sid) use ($pdo, $hata): array {
    $sev = siparis_sevkiyat($pdo, $sid);
    if ($sev === null) $hata('Sevkiyat bulunamadı.');
    if (!siparis_gorunur($sev)) forbidden('Bu sevkiyat aktif depoda görünmüyor.');
    return $sev;
};
/** Belgeyi yükler; yoksa hata, görünmüyorsa 403. */
$belgeYukle = function (int $bid) use ($pdo, $hata): array {
    $st = $pdo->prepare('SELECT * FROM siparis_belgeler WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$bid]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) $hata('Belge bulunamadı.');
    if (!siparis_belge_gorunur($pdo, $b)) forbidden('Bu belge aktif depoda görünmüyor.');
    return $b;
};

// ── alan / not / kilit_ac ───────────────────────────────
if ($islem === 'alan' || $islem === 'not' || $islem === 'kilit_ac') {
    $varlik = $islem === 'not' ? 'sevkiyat' : (string)($_POST['varlik'] ?? '');
    if (!in_array($varlik, ['sevkiyat', 'kalem'], true)) $hata('Geçersiz kayıt türü.');
    $id = $islem === 'not' ? (int)($_POST['sid'] ?? 0) : (int)($_POST['id'] ?? 0);
    $v = siparis_varlik_getir($pdo, $varlik, $id);
    if ($v === null) $hata('Kayıt bulunamadı.');
    $sid = (int)$v['sid'];
    if (!siparis_gorunur($v['sev'])) forbidden('Bu sevkiyat aktif depoda görünmüyor.');
    $url = $sevUrl($sid);
    $tanimlar = siparis_alan_tanimlari()[$varlik];

    if ($islem === 'kilit_ac') {
        $alan = (string)($_POST['alan'] ?? '');
        $r = siparis_alan_kilidi_kaldir($pdo, $varlik, $id, $alan, $uid);
        set_flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Elle kilit kaldırıldı; değer belgedeki kaynağa döndü.' : (string)($r['hata'] ?? 'Kilit kaldırılamadı.'));
        $git($url);
    }
    if ($islem === 'not') $alanlar = ['notlar' => $_POST['deger'] ?? ''];
    elseif (isset($_POST['alanlar']) && is_array($_POST['alanlar'])) $alanlar = $_POST['alanlar'];
    else $alanlar = [(string)($_POST['alan'] ?? '') => $_POST['deger'] ?? ''];

    $elle = siparis_json_coz($v['row']['elle_alanlar'] ?? null);
    $guncel = 0; $hatalar = []; $uyarilar = [];
    foreach ($alanlar as $alan => $deger) {
        $alan = (string)$alan;
        if (!isset($tanimlar[$alan]) || empty($tanimlar[$alan]['elle'])) { $hatalar[] = 'Bu alan düzenlenemez.'; continue; }
        if ($varlik === 'sevkiyat' && in_array($alan, siparis_sofor_alanlari(), true) && !siparis_sofor_hazir($pdo)) { $hatalar[] = 'Şoför alanları için şema güncellemesi gerekli (yönetici: Şema Güncelleme › Siparişler — Şoför alanları).'; continue; }
        if ($varlik === 'sevkiyat' && $alan === 'yurtici_gumruk' && !siparis_yurtici_gumruk_hazir($pdo)) { $hatalar[] = 'Yurt içi gümrük için şema güncellemesi gerekli (yönetici: Şema Güncelleme › Siparişler — Yurt içi gümrük).'; continue; }
        $tip = (string)$tanimlar[$alan]['tip'];
        $yeni = is_scalar($deger) ? siparis_deger_normalize(trim((string)$deger), $tip) : null;
        $mevcut = siparis_deger_normalize($v['row'][$alan] ?? null, $tip);
        $kilitli = isset($elle[$alan]);
        if ($yeni === null && $kilitli && in_array($alan, ['etkin_palet', 'etkin_koli'], true)) {      // boş = hesaplanana dön
            $r = siparis_alan_kilidi_kaldir($pdo, $varlik, $id, $alan, $uid);
            if ($r['ok']) $guncel++; else $hatalar[] = (string)($r['hata'] ?? 'Kilit kaldırılamadı.');
            continue;
        }
        if (siparis_deger_esit($mevcut, $yeni, $tip)) continue;          // değişmeyen alan kilitlenmez
        $r = siparis_alan_elle($pdo, $varlik, $id, $alan, is_scalar($deger) ? (string)$deger : '', $uid);
        if ($r['ok']) { $guncel++; if (!empty($r['uyari'])) $uyarilar[] = (string)$r['uyari']; }
        else $hatalar[] = (string)($r['hata'] ?? 'Değer kaydedilemedi.');
    }
    if ($hatalar) set_flash('error', implode(' ', array_unique($hatalar)));
    elseif ($guncel > 0) set_flash('success', ($guncel === 1 ? 'Alan güncellendi (elle girildi, kilitli).' : $guncel . ' alan güncellendi.') . ($uyarilar ? ' ' . implode(' ', $uyarilar) : ''));
    else set_flash('success', 'Değişiklik yok.');
    $git($url);
}

// ── durum ───────────────────────────────────────────────
if ($islem === 'durum') {
    $sid = (int)($_POST['sid'] ?? 0);
    $sev = $sevYukle($sid);
    $hedef = (string)($_POST['hedef'] ?? '');
    $gerekce = trim((string)($_POST['gerekce'] ?? ''));
    $url = $sevUrl($sid);
    // Mail onayı yalnız İLERİ işarette istenir; evrak takımından mail'e geri alma (gerekçeli) onay istemez (v397)
    if ($hedef === 'mail_gonderildi' && (string)$sev['durum'] !== 'evrak_gonderildi' && ($_POST['onay_gonderdim'] ?? '') !== '1') {
        $hata("PKL'yi müşteriye gönderdiğinizi onaylayın.", $url);
    }
    if ($hedef === 'evrak_gonderildi' && ($_POST['onay_evrak'] ?? '') !== '1') {
        $hata('Evrak takımını müşteriye gönderdiğinizi onaylayın.', $url);
    }
    $r = siparis_durum_degistir($pdo, $sid, $hedef, $uid, $gerekce, ($_POST['onay_eksik'] ?? '') === '1');
    $mesaj = 'Durum güncellendi: ' . (siparis_durumlar()[$hedef]['etiket'] ?? $hedef) . '.';
    if ($hedef === 'mail_gonderildi') $mesaj = 'Mail gönderildi işaretlendi — sevkiyat evrak takımı bekliyor.';
    elseif ($hedef === 'evrak_gonderildi') $mesaj = 'Evrak takımı gönderildi işaretlendi; sevkiyat kapandı.';
    set_flash($r['ok'] ? 'success' : 'error', $r['ok'] ? $mesaj : (string)($r['hata'] ?? 'Durum değiştirilemedi.'));
    $git($url);
}

// ── çatışma kararı ──────────────────────────────────────
if ($islem === 'catisma') {
    $cid = (int)($_POST['catisma_id'] ?? 0);
    $st = $pdo->prepare('SELECT sevkiyat_id FROM siparis_catismalar WHERE id = ?');
    $st->execute([$cid]);
    $sid = (int)($st->fetchColumn() ?: 0);
    if ($sid < 1) $hata('Çatışma bulunamadı.');
    $sevYukle($sid);
    $karar = (string)($_POST['karar'] ?? '');
    $r = siparis_catisma_coz($pdo, $cid, $karar, $uid);
    set_flash($r['ok'] ? 'success' : 'error', $r['ok'] ? ($karar === 'kabul' ? 'Gelen değer kabul edildi.' : 'Mevcut değer korundu.') : (string)($r['hata'] ?? 'Çatışma çözülemedi.'));
    $git($sevUrl($sid));
}

// ── belge bağla / yeniden işle ──────────────────────────
if ($islem === 'belge_bagla' || $islem === 'yeniden_isle') {
    $b = $belgeYukle((int)($_POST['belge_id'] ?? 0));
    $hedefSid = null;
    if ($islem === 'belge_bagla') {
        $hedefSid = (int)($_POST['sevkiyat_id'] ?? 0);
        if ($hedefSid < 1) $hata('Bir sevkiyat seçin.');
        $sevYukle($hedefSid);
    }
    @set_time_limit(60);
    $x = siparis_belge_isle($pdo, (int)$b['id'], $uid, $hedefSid);
    audit_log_event('siparis_belge_yukle', 'siparis', (int)$b['id'], null, [
        'belge_id' => (int)$b['id'], 'tur' => $x['tur'], 'yeni' => 0, 'ok' => $x['ok'] ? 1 : 0, 'islem' => $islem,
        'sevkiyat_ids' => array_map(fn($s) => (int)$s['id'], $x['sevkiyatlar']),
    ]);
    $donusBelge = (int)($_POST['donus_belge'] ?? 0);
    if (!$x['ok']) { set_flash('error', (string)($x['hata'] ?? 'Belge işlenemedi.')); $git($donusBelge > 0 ? 'siparis_belge.php?id=' . $donusBelge : 'siparisler.php#yetim'); }
    $msg = $x['ozet'] . ($x['mesajlar'] ? ' — ' . implode(' ', array_slice($x['mesajlar'], 0, 3)) : '');
    set_flash('success', $islem === 'belge_bagla' ? 'Belge bağlandı: ' . $msg : 'Belge yeniden işlendi: ' . $msg);
    if ($donusBelge > 0) $git('siparis_belge.php?id=' . $donusBelge);
    $git($hedefSid ? 'siparis_view.php?id=' . $hedefSid . '#belgeler' : ($x['sevkiyatlar'] ? 'siparis_view.php?id=' . (int)$x['sevkiyatlar'][0]['id'] . '#belgeler' : 'siparisler.php#yetim'));
}

// ── belge sil ───────────────────────────────────────────
if ($islem === 'belge_sil') {
    $b = $belgeYukle((int)($_POST['belge_id'] ?? 0));
    $sidDonus = (int)($_POST['donus_sid'] ?? 0);
    $r = siparis_belge_sil($pdo, (int)$b['id'], $uid);
    set_flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Belge silindi (uygulanmış değerler geri alınmadı).' : (string)($r['hata'] ?? 'Belge silinemedi.'));
    $git($sidDonus > 0 ? 'siparis_view.php?id=' . $sidDonus . '#belgeler' : 'siparisler.php');
}

// ── sevkiyat sil ────────────────────────────────────────
if ($islem === 'sil') {
    $sid = (int)($_POST['sid'] ?? 0);
    $sevYukle($sid);
    $r = siparis_sevkiyat_sil($pdo, $sid, $uid, (string)($_POST['gerekce'] ?? ''));
    if (!$r['ok']) $hata((string)($r['hata'] ?? 'Sevkiyat silinemedi.'), $sevUrl($sid));
    set_flash('success', 'Sevkiyat silindi.');
    $git('siparisler.php');
}

// ── elle sevkiyat aç ────────────────────────────────────
if ($islem === 'yeni') {
    $r = siparis_sevkiyat_elle_ac($pdo, (string)($_POST['order_ref'] ?? ''), (string)($_POST['tur_no'] ?? ''), (string)($_POST['yukleme_tarihi'] ?? ''), $uid);
    if (!$r['ok']) $hata((string)($r['hata'] ?? 'Sevkiyat açılamadı.'));
    set_flash('success', 'Sevkiyat açıldı. PO / Loading List yüklendiğinde aynı sipariş numarasıyla birleşir.');
    $git('siparis_view.php?id=' . (int)$r['id']);
}

$hata('İşlem tamamlanamadı.');
