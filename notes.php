<?php
// =========================================================
// notes.php - Geliştirici notları tam liste
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$rows = db()->query(
    "SELECT id, page_url, page_name, note, done, created_at
     FROM dev_notes ORDER BY done ASC, id DESC"
)->fetchAll();
$done_count = count(array_filter($rows, fn($r) => (int)$r['done'] === 1));
$open_count = count($rows) - $done_count;

render_header('Notlar');
render_flash();
?>

<div class="page-head">
    <div>
        <h1>📝 Notlar</h1>
        <p class="muted"><?= $open_count ?> açık<?= $done_count > 0 ? ' · ' . $done_count . ' tamamlanan' : '' ?></p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($done_count > 0): ?>
        <button id="toggleDoneBtn" class="btn btn-ghost btn-sm">Tamamlananları Göster (<?= $done_count ?>)</button>
        <?php endif; ?>
        <button id="notesCopyAllBtn" class="btn btn-primary">📋 Claude için Kopyala</button>
    </div>
</div>

<?php if (empty($rows)): ?>
<div class="empty">
    <p>Henüz not yok.</p>
    <p class="muted">Sayfaları gezerken alt bardaki 📝 Not butonuna bas.</p>
</div>
<?php else: ?>
<div id="notesFullList" class="notes-full-list">
<?php foreach ($rows as $n): ?>
    <div class="notes-item<?= $n['done'] ? ' notes-done' : '' ?>" data-note-id="<?= (int)$n['id'] ?>">
        <div class="notes-item-page">
            <span class="notes-page-chip"><?= h($n['page_name'] ?: $n['page_url'] ?: '—') ?></span>
            <span class="notes-item-date muted"><?= h(fmt_datetime($n['created_at'])) ?></span>
        </div>
        <div class="notes-item-body">
            <span class="notes-item-text"><?= nl2br(h($n['note'])) ?></span>
        </div>
        <div class="notes-item-actions">
            <button class="btn btn-sm notes-toggle-btn" data-note-id="<?= (int)$n['id'] ?>">
                <?= $n['done'] ? '↩ Geri Al' : '✓ Tamamlandı' ?>
            </button>
            <button class="btn btn-sm btn-ghost notes-del-btn" data-note-id="<?= (int)$n['id'] ?>">✕ Sil</button>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<script>
(function () {
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    // Tamamlananları başlangıçta gizle
    var showDone = false;
    document.querySelectorAll('.notes-done').forEach(function (el) { el.hidden = true; });

    var toggleDoneBtn = document.getElementById('toggleDoneBtn');
    if (toggleDoneBtn) {
        toggleDoneBtn.addEventListener('click', function () {
            showDone = !showDone;
            document.querySelectorAll('.notes-done').forEach(function (el) { el.hidden = !showDone; });
            var cnt = document.querySelectorAll('.notes-done').length;
            toggleDoneBtn.textContent = showDone
                ? 'Tamamlananları Gizle (' + cnt + ')'
                : 'Tamamlananları Göster (' + cnt + ')';
        });
    }

    function postJson(url, body) {
        body.csrf = csrf;
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    document.addEventListener('click', function (e) {
        var toggleBtn = e.target.closest('.notes-toggle-btn');
        var delBtn    = e.target.closest('.notes-del-btn');

        if (toggleBtn) {
            var id   = parseInt(toggleBtn.dataset.noteId, 10);
            var item = document.querySelector('.notes-item[data-note-id="' + id + '"]');
            toggleBtn.disabled = true;
            postJson('note_update.php', { action: 'toggle', id: id })
                .then(function (d) {
                    toggleBtn.disabled = false;
                    if (!d.ok) { alert(d.msg || 'Hata'); return; }
                    if (d.done) {
                        item.classList.add('notes-done');
                        toggleBtn.textContent = '↩ Geri Al';
                    } else {
                        item.classList.remove('notes-done');
                        toggleBtn.textContent = '✓ Tamamlandı';
                    }
                });
        }

        if (delBtn) {
            if (!confirm('Bu not silinsin mi?')) return;
            var id   = parseInt(delBtn.dataset.noteId, 10);
            var item = document.querySelector('.notes-item[data-note-id="' + id + '"]');
            delBtn.disabled = true;
            postJson('note_update.php', { action: 'delete', id: id })
                .then(function (d) {
                    if (!d.ok) { delBtn.disabled = false; alert(d.msg || 'Hata'); return; }
                    item.remove();
                });
        }
    });

    document.getElementById('notesCopyAllBtn').addEventListener('click', function () {
        var items = document.querySelectorAll('.notes-item');
        if (!items.length) { alert('Kopyalanacak not yok.'); return; }

        // Group by page
        var groups = {};
        items.forEach(function (item) {
            var chip = item.querySelector('.notes-page-chip');
            var page = chip ? chip.textContent.trim() : '—';
            if (!groups[page]) groups[page] = [];
            var text = item.querySelector('.notes-item-text');
            var note = text ? text.innerText.trim() : '';
            var done = item.classList.contains('notes-done');
            groups[page].push({ note: note, done: done });
        });

        var md = '## Geliştirme Notları\n\n';
        Object.keys(groups).forEach(function (page) {
            md += '### ' + page + '\n';
            groups[page].forEach(function (n) {
                md += (n.done ? '- [x] ' : '- [ ] ') + n.note + '\n';
            });
            md += '\n';
        });

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(md).then(function () {
                alert('Kopyalandı! Claude\'a yapıştırabilirsin.');
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = md; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            alert('Kopyalandı! Claude\'a yapıştırabilirsin.');
        }
    });
})();
</script>

<?php render_footer(); ?>
