<?php
// =========================================================
// config/pdks_sifirlama.php — Personel Takibi (çavuş) TEST VERİSİNİ
// sıfırlama çekirdeği. Kullanan tek sayfa: pdks_sifirla.php (yalnız admin).
//
// TEK SEFERLİK bir araçtır: test dönemi bitince çavuşları ve çavuşa bağlı
// her işlemi (oturum, tarama, puantaj, hakediş, düzeltme, ödeme, fiyat)
// siler. İş bitince sayfa + bu dosya depodan kaldırılmalıdır.
//
// GÜVENLİK KATMANLARI:
//   1) Parmak izi — önizlemede görülen sayım (COUNT + MAX(id)) POST'ta
//      yeniden hesaplanır; arada tek bir satır eklenmiş/silinmişse işlem
//      BAŞLAMAZ ("gördüğünden fazlasını silme").
//   2) Yedek — silmeden ÖNCE her tablo aynı veritabanında
//      pdks_yedek_<zaman>_<tablo> adıyla kopyalanır ve satır sayısı
//      doğrulanır. Yedek eksikse silme BAŞLAMAZ.
//   3) Tek transaction — silme FK sırasıyla yapılır; her tablodan silinen
//      satır sayısı yedeğe alınan sayıya EŞİT değilse ROLLBACK (yedekte
//      olmayan bir satır asla silinmez).
//
// DOKUNULMAZ: worker_cards (Kart Havuzu), worker_types, employees /
// employee_cards / attendance_* (kalıcı personel), audit_log.
// =========================================================
declare(strict_types=1);

/**
 * Silinecek tablolar — SİLME SIRASIYLA (önce bağlılar, en son foremen).
 * Yedekten geri yükleme bunun TERSİ sırayla yapılır.
 *
 * @return array<string,string> tablo => etiket
 */
function pdks_sifir_tablolar(): array
{
    return [
        'foreman_entitlement_adjustments' => 'Hakediş düzeltmeleri',
        'foreman_daily_entitlement_lines' => 'Hakediş satırları',
        'foreman_daily_entitlements'      => 'Hakedişler',
        'foreman_payments'                => 'Çavuş ödemeleri',
        'daily_worker_work_periods'       => 'Mesai dönemleri (puantaj)',
        'daily_worker_card_events'        => 'Kart giriş/çıkış taramaları',
        'daily_work_sessions'             => 'Günlük mesai oturumları',
        'foreman_worker_rates'            => 'Çavuş fiyatları',
        'foremen'                         => 'Çavuşlar',
    ];
}

/**
 * Her tablonun satır sayısı ve en büyük id'si. Tablo yoksa null.
 *
 * @return array<string,?array{adet:int,max_id:int}>
 */
function pdks_sifir_sayim(PDO $pdo): array
{
    $r = [];
    foreach (array_keys(pdks_sifir_tablolar()) as $t) {
        try {
            $row = $pdo->query("SELECT COUNT(*) AS adet, COALESCE(MAX(id),0) AS max_id FROM `{$t}`")->fetch(PDO::FETCH_ASSOC);
            $r[$t] = ['adet' => (int)$row['adet'], 'max_id' => (int)$row['max_id']];
        } catch (PDOException $e) {
            $r[$t] = null;
        }
    }
    return $r;
}

/** Sayımın değişmez özeti — önizleme ile POST arasında veri değişti mi? */
function pdks_sifir_parmak_izi(array $sayim): string
{
    ksort($sayim);
    return hash('sha256', json_encode($sayim, JSON_THROW_ON_ERROR));
}

function pdks_sifir_toplam(array $sayim): int
{
    $n = 0;
    foreach ($sayim as $s) $n += $s['adet'] ?? 0;
    return $n;
}

/**
 * Silmeden önce her tabloyu kopyalar ve satır sayısını doğrular.
 * CREATE TABLE MySQL'de örtük COMMIT yapar — bu yüzden transaction'DAN
 * ÖNCE çağrılır. Hata olursa RuntimeException; hiçbir şey silinmemiştir.
 *
 * @return array<string,array{yedek:string,adet:int}>
 */
function pdks_sifir_yedekle(PDO $pdo, string $zaman): array
{
    if (!preg_match('/^\d{8}_\d{6}$/', $zaman)) {
        throw new InvalidArgumentException('Geçersiz yedek zamanı.');
    }
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $sonuc = [];
    foreach (pdks_sifir_sayim($pdo) as $t => $s) {
        if ($s === null) continue; // tablo bu kurulumda yok — silinecek bir şey de yok
        $y = "pdks_yedek_{$zaman}_{$t}";
        if ($sqlite) {
            $pdo->exec("CREATE TABLE `{$y}` AS SELECT * FROM `{$t}`");
        } else {
            // LIKE: kolonlar + indeksler kopyalanır, FK kopyalanMAZ (yedek bağımsız kalır).
            $pdo->exec("CREATE TABLE `{$y}` LIKE `{$t}`");
            $pdo->exec("INSERT INTO `{$y}` SELECT * FROM `{$t}`");
        }
        $canli = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        $yedek = (int)$pdo->query("SELECT COUNT(*) FROM `{$y}`")->fetchColumn();
        if ($canli !== $yedek) {
            throw new RuntimeException("Yedek doğrulanamadı: {$t} (canlı {$canli}, yedek {$yedek}).");
        }
        $sonuc[$t] = ['yedek' => $y, 'adet' => $yedek];
    }
    return $sonuc;
}

/**
 * Tek transaction içinde siler. Her tablodan silinen satır sayısı
 * yedekteki sayıya eşit olmalı; değilse ROLLBACK + RuntimeException.
 *
 * @param array<string,array{yedek:string,adet:int}> $yedek pdks_sifir_yedekle() çıktısı
 * @return array<string,int> tablo => silinen satır
 */
function pdks_sifir_sil(PDO $pdo, array $yedek): array
{
    $silinen = [];
    $pdo->beginTransaction();
    try {
        // Kendine FK (düzeltmenin geri alınışı → asıl düzeltme): önce çöz,
        // yoksa InnoDB satır satır kontrolde RESTRICT'e takılır.
        if (isset($yedek['foreman_entitlement_adjustments'])) {
            $pdo->exec("UPDATE foreman_entitlement_adjustments SET reversal_of_adjustment_id = NULL
                         WHERE reversal_of_adjustment_id IS NOT NULL");
        }
        foreach (array_keys(pdks_sifir_tablolar()) as $t) {
            if (!isset($yedek[$t])) continue; // yedeklenmemiş tablo ASLA silinmez
            $n = $pdo->exec("DELETE FROM `{$t}`");
            $n = $n === false ? -1 : (int)$n;
            $beklenen = $yedek[$t]['adet'] ?? -2;
            if ($n !== $beklenen) {
                throw new RuntimeException("{$t}: silinecek {$n} satır, yedekte {$beklenen} satır — veri arada değişmiş olabilir. İşlem geri alındı.");
            }
            $silinen[$t] = $n;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $silinen;
}
