<?php
// =========================================================
// config/siparis_evrak.php — "Evrak takımı" adımı için TEK SEFERLİK veri geçişi (v400)
// siparis_core.php sonunda yüklenir. Şema DEĞİŞMEZ (yalnız veri UPDATE); çalıştırma yalnız migrate.php kartından.
// =========================================================
declare(strict_types=1);

/** Tek çalıştırmada taşınacak en çok sevkiyat (kalan sayı kartta görünür; tekrar basılır). */
const SIPARIS_EVRAK_GECIS_PARTI = 1000;

/**
 * v400 öncesi/sonrası ayrımı için işaret: `siparis_durum_degistir()` v400'den itibaren "Mail gönderildi" audit'inin
 * new_values'una `evrak_akisi:1` yazar (audit_log_event JSON_UNESCAPED_* ile kodlar → bu alt dize aynen görünür).
 */
const SIPARIS_EVRAK_GECIS_ISARET = '"evrak_akisi":1';

/**
 * Geçiş adayı koşulu: durum `mail_gonderildi` VE v400 akışında işaretlendiğini gösteren audit satırı YOK
 * (eski mail → eskiden süreci kapatıyordu; yeni mail → evrak takımı bekliyor, DOKUNULMAZ). Tarih kıyaslanmaz.
 * Pozisyonel parametre kullanır (named tekrarı HY093 verirdi); audit_log tablosu yoksa işaret koşulu atlanır.
 * @return array{0:string,1:list<string>}
 */
function siparis_evrak_gecis_where(PDO $pdo): array
{
    $sql = "s.durum = 'mail_gonderildi'";
    $par = [];
    if (siparis_tablo_var($pdo, 'audit_log')) {
        $sql .= " AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.module = 'siparis' AND a.action = 'siparis_mail_gonderildi'"
              . ' AND a.record_id = s.id AND a.new_values LIKE ?)';
        $par[] = '%' . SIPARIS_EVRAK_GECIS_ISARET . '%';
    }
    return [$sql, $par];
}

/** Taşınmayı bekleyen sevkiyat sayısı (silinmiş olanlar DAHİL); sorgu hatası → -1. */
function siparis_evrak_gecis_say(PDO $pdo): int
{
    try {
        [$w, $p] = siparis_evrak_gecis_where($pdo);
        $st = $pdo->prepare('SELECT COUNT(*) FROM siparis_sevkiyatlar s WHERE ' . $w);
        $st->execute($p);
        return (int)$st->fetchColumn();
    } catch (PDOException $e) {
        error_log('[siparis_evrak_gecis_say] ' . $e->getMessage());
        return -1;
    }
}

/** @return list<array{id:int,order_ref:string,mail_gonderildi_at:?string,deleted_at:?string}> ilk $limit aday (id sırası) */
function siparis_evrak_gecis_adaylar(PDO $pdo, int $limit = 50): array
{
    $limit = max(1, min(500, $limit));
    try {
        [$w, $p] = siparis_evrak_gecis_where($pdo);
        $st = $pdo->prepare('SELECT s.id, s.order_ref, s.mail_gonderildi_at, s.deleted_at FROM siparis_sevkiyatlar s WHERE ' . $w . ' ORDER BY s.id LIMIT ' . $limit);
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[siparis_evrak_gecis_adaylar] ' . $e->getMessage());
        return [];
    }
}

/**
 * Adayları `evrak_gonderildi`ye taşır (idempotent; `durum_at` DEĞİŞMEZ → kapanış zamanı eskisi gibi kalır).
 * Her taşınan sevkiyata zaman çizelgesi satırı, topluca TEK özet audit (`siparis_evrak_gecis`).
 * @return array{ok:bool,adet?:int,kalan?:int,hata?:string}
 */
function siparis_evrak_gecis_uygula(PDO $pdo, int $uid): array
{
    if (!siparis_tablo_var($pdo, 'siparis_sevkiyatlar') || !siparis_tablo_var($pdo, 'siparis_olaylar')) {
        return ['ok' => false, 'hata' => 'Önce Sipariş tablolarını oluşturun.'];
    }
    try {
        $adet = siparis_tx($pdo, function () use ($pdo, $uid): int {
            [$w, $p] = siparis_evrak_gecis_where($pdo);
            $st = $pdo->prepare('SELECT s.id FROM siparis_sevkiyatlar s WHERE ' . $w . ' ORDER BY s.id LIMIT ' . SIPARIS_EVRAK_GECIS_PARTI . siparis_kilit_sql($pdo));
            $st->execute($p);
            $idler = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $tasinan = [];
            $simdi = siparis_simdi();
            $up = $pdo->prepare("UPDATE siparis_sevkiyatlar SET durum = 'evrak_gonderildi', updated_at = ?, updated_by = ? WHERE id = ? AND durum = 'mail_gonderildi'");
            foreach ($idler as $id) {
                $up->execute([$simdi, $uid, $id]);
                if ($up->rowCount() !== 1) continue;   // arada değişmiş → atla
                siparis_olay($pdo, $id, 'evrak_gonderildi', 'mail_gonderildi', 'evrak_gonderildi',
                    'Toplu geçiş (v400): evrak takımı adımı eklenmeden önce kapanmış sevkiyat', null, $uid);
                $tasinan[] = $id;
            }
            if ($tasinan) {
                siparis_audit('siparis_evrak_gecis', null, ['durum' => 'mail_gonderildi'],
                    ['durum' => 'evrak_gonderildi', 'adet' => count($tasinan), 'idler' => $tasinan], $uid);
            }
            return count($tasinan);
        });
    } catch (PDOException $e) {
        error_log('[siparis_evrak_gecis_uygula] ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Geçiş yapılamadı (ayrıntı sunucu günlüğünde).'];
    }
    return ['ok' => true, 'adet' => $adet, 'kalan' => max(0, siparis_evrak_gecis_say($pdo))];
}
