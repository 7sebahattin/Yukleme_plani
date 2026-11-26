<?php
// =========================================================
// config/muhasebe_referans.php — Muhasebe kart silme çapraz modül referans kapısı (B-20)
//
// Alt katman çekirdekler (cari_core, stok_core, kasa_core) üst katman modülleri
// (stok, fatura, ceksenet) doğrudan bilmez; bu yardımcı tek merkezden mevcut
// tablolardaki dış referansları kontrol ederek yetim kayıt oluşmasını engeller.
// =========================================================
declare(strict_types=1);

if (!function_exists('muhasebe_kart_referans_kontrol')) {
    /**
     * Bir kartın ($tur: 'cari'|'stok'|'kasa') başka modüllerde aktif/geçmiş kaydı var mı?
     * Bağlı kayıt varsa Türkçe ret mesajı, yoksa null döner. Kurulu olmayan tablolar atlanır.
     */
    function muhasebe_kart_referans_kontrol(PDO $pdo, string $tur, int $id): ?string
    {
        $kurallar = match ($tur) {
            'cari' => [
                ['fatura_belgeler',     'SELECT COUNT(*) FROM fatura_belgeler WHERE cari_id = ?',                               [$id],      'Fatura/belge kaydı bulunan cari silinemez; pasifleştirin.'],
                ['stok_fisler',         'SELECT COUNT(*) FROM stok_fisler WHERE cari_id = ?',                                   [$id],      'Stok fişi kaydı bulunan cari silinemez; pasifleştirin.'],
                ['ceksenet_kayitlar',   'SELECT COUNT(*) FROM ceksenet_kayitlar WHERE cari_id = ? OR yer_cari_id = ?',          [$id, $id], 'Çek/senet kaydı bulunan cari silinemez; pasifleştirin.'],
                ['ceksenet_hareketler', 'SELECT COUNT(*) FROM ceksenet_hareketler WHERE cari_id = ? OR onceki_yer_cari_id = ?', [$id, $id], 'Çek/senet hareketi bulunan cari silinemez; pasifleştirin.'],
            ],
            'stok' => [
                ['fatura_satirlar',     'SELECT COUNT(*) FROM fatura_satirlar WHERE kart_id = ?',                               [$id],      'Fatura/belge satırında kullanılan stok kartı silinemez; pasifleştirin.'],
            ],
            'kasa' => [
                ['ceksenet_kayitlar',   'SELECT COUNT(*) FROM ceksenet_kayitlar WHERE yer_kasa_id = ?',                         [$id],      'Çek/senet kaydı bulunan kasa silinemez; pasifleştirin.'],
                ['ceksenet_hareketler', 'SELECT COUNT(*) FROM ceksenet_hareketler WHERE kasa_id = ? OR onceki_yer_kasa_id = ?', [$id, $id], 'Çek/senet hareketi bulunan kasa silinemez; pasifleştirin.'],
                ['ceksenet_tanimlar',   'SELECT COUNT(*) FROM ceksenet_tanimlar WHERE kasa_id = ?',                             [$id],      'Çek karnesi bağlı kasa silinemez; pasifleştirin.'],
            ],
            default => [],
        };

        foreach ($kurallar as [$tbl, $sql, $par, $mesaj]) {
            try {
                $pdo->query("SELECT 1 FROM `$tbl` LIMIT 0");
            } catch (PDOException $e) {
                continue;   // ilgili üst modül henüz kurulmamış
            }
            $st = $pdo->prepare($sql);
            $st->execute($par);
            if ((int)$st->fetchColumn() > 0) {
                return $mesaj;
            }
        }
        return null;
    }
}
