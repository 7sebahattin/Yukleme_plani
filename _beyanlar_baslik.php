<?php
// =========================================================
// _beyanlar_baslik.php — Beyanlar sayfası başlığı + sekme çubuğu (v498)
// beyanlar.php'nin iki sekmesinin (Beyanlar | Beyansız Yüklemeler) ORTAK parçası.
//
// Beklenen değişkenler:
//   $bsl_alt   — alt başlık HTML'i (çağıran güvenli sayı/etiketlerle kurar)
//   $sekme     — 'beyanlar' | 'beyansiz'
//   $bszGor    — sekme çubuğu çizilsin mi (Beyansız sekmesini görebilen kullanıcı)
//   $beyanOku  — Beyanlar sekmesi görülebilir mi
//   $bekleyen_total (ops.) — Beyanlar sekmesi sayacı
//
// Fonksiyon TANIMLAMAZ: sayfa testte birden çok kez include edilir.
// =========================================================
if (!isset($bsl_alt)) return;   // doğrudan istek: çıktı yok
?>
<div class="page-head">
    <div>
        <h1>🧾 Beyanlar</h1>
        <p class="muted"><?= $bsl_alt ?></p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <?php if (is_admin()): ?>
        <a href="beyan_bildirim_tani.php" class="btn btn-ghost btn-sm"
           title="Bildirim köprüsü ön kontrolü (salt-okunur)">🩺 Bildirim Ön Kontrol</a>
        <?php endif; ?>
        <?php if (can_yukleme_bildir()): ?>
        <button type="button" class="btn btn-lg bsz-ac-btn" id="bszAc" data-bsz-ac
                title="Beyan olmadan yapılan yükleme için HKS taslağı oluştur">🚚 Yükleme Bildir</button>
        <?php endif; ?>
        <?php if (can_beyan('create')): ?>
        <a href="beyan_create.php" class="btn btn-primary btn-lg">+ Yeni Beyan</a>
        <?php endif; ?>
    </div>
</div>
<?php if (!empty($bszGor)):
    $__bszSay = isset($bsz_say['bekleyen']) ? (int)$bsz_say['bekleyen'] : 0;   // beyansız sekmesi zaten hesapladı
    if (!isset($bsz_say['bekleyen']) && function_exists('beyansiz_tablo_var') && beyansiz_tablo_var()) {
        require_once __DIR__ . '/config/beyansiz_yukleme.php';
        $__bszSay = (int)(bsz_sayilar()['bekleyen'] ?? 0);
    }
?>
<nav class="bsz-sekmeler" aria-label="Beyan bölümleri">
    <?php if (!empty($beyanOku)): ?>
    <a class="bsz-sekme<?= $sekme === 'beyanlar' ? ' bsz-sekme-aktif' : '' ?>" href="beyanlar.php"
       <?= $sekme === 'beyanlar' ? 'aria-current="page"' : '' ?>>Beyanlar<?php if (isset($bekleyen_total) && $bekleyen_total): ?> <span class="bsz-sekme-say"><?= (int)$bekleyen_total ?></span><?php endif; ?></a>
    <?php endif; ?>
    <a class="bsz-sekme<?= $sekme === 'beyansiz' ? ' bsz-sekme-aktif' : '' ?>" href="beyanlar.php?sekme=beyansiz"
       <?= $sekme === 'beyansiz' ? 'aria-current="page"' : '' ?>>Beyansız Yüklemeler<?php if ($__bszSay): ?> <span class="bsz-sekme-say"><?= $__bszSay ?></span><?php endif; ?></a>
</nav>
<?php endif; ?>
