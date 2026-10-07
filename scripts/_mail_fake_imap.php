<?php
// =========================================================
// scripts/_mail_fake_imap.php — Test için bellek içi SAHTE IMAP sunucusu (include-only).
// MailStream arayüzünü uygular; gerçek sunucunun önemli davranışlarını taklit eder:
//  • "UID n:*" tuzağı (n son UID'den büyükse son mesajı yine döndürür)
//  • literal ({n}\r\n…) biçimli FETCH yanıtları, BODY.PEEK[] → BODY[], kısmi <0.N> → <0>
//  • AUTHENTICATE PLAIN devam isteği (+), LOGIN, EXAMINE (UIDVALIDITY/UIDNEXT/EXISTS)
//  • hata enjeksiyonu: yanlış şifre, bağlantı kopması, yarım literal, UIDVALIDITY değişimi
// $received tüm istemci komutlarını tutar → testler UNSEEN/STORE/\Seen DEĞİŞTİRİLMEDİĞİNİ denetler.
// =========================================================
declare(strict_types=1);

final class FakeMailStream implements MailStream
{
    public array $received = [];
    public ?FakeMailStream $kok = null;
    /** APPEND ile alınan mesajlar: [['klasor'=>, 'bayrak'=>, 'ham'=>]] */
    public array $eklenen = [];
    private ?array $appendBekle = null;   // şablon sunucu: her bağlantının komutları buraya da yazılır
    public bool $kapandi = false;
    private string $in = '';
    private string $out = '';
    private ?string $bekleyenTag = null;   // AUTHENTICATE devam bekliyor
    private int $komutSayisi = 0;
    private bool $dusuruldu = false;
    public array $mesajlar;                // uid => ['raw'=>, 'flags'=>[], 'date'=>]

    /** @param array $cfg user,pass,uidvalidity,mesajlar,caps,auth_plain,drop_after,drop_in_fetch,uidnext */
    public function __construct(public array $cfg)
    {
        $this->cfg += ['user' => 'u', 'pass' => 'p', 'uidvalidity' => 1000, 'caps' => ['IMAP4rev1', 'AUTH=PLAIN', 'UIDPLUS'],
            'drop_after' => null, 'drop_in_fetch' => false, 'uidnext' => null, 'bye_on_fetch' => false];
        $this->mesajlar = $cfg['mesajlar'] ?? [];
        $this->out = "* OK [CAPABILITY IMAP4rev1] Fake ready\r\n";
    }

    public function readLine(int $max): ?string
    {
        if ($this->dusuruldu) return null;
        $p = strpos($this->out, "\r\n");
        if ($p === false) return null;
        $l = substr($this->out, 0, $p);
        $this->out = substr($this->out, $p + 2);
        return $l;
    }
    public function readBytes(int $n): ?string
    {
        if ($this->dusuruldu) return null;
        if (strlen($this->out) < $n) { $this->out = ''; return null; }
        $b = substr($this->out, 0, $n);
        $this->out = substr($this->out, $n);
        return $b;
    }
    public function startTls(): bool { return true; }
    public function close(): void { $this->kapandi = true; }

    public function write(string $data): void
    {
        $this->in .= $data;
        if ($this->appendBekle !== null) {   // APPEND literal'i: n bayt + CRLF
            $n = $this->appendBekle['n'];
            if (strlen($this->in) < $n + 2) return;
            $ham = substr($this->in, 0, $n); $this->in = substr($this->in, $n + 2);
            $kayit = ['klasor' => $this->appendBekle['klasor'], 'bayrak' => $this->appendBekle['bayrak'], 'ham' => $ham];
            $this->eklenen[] = $kayit; if ($this->kok) $this->kok->eklenen[] = $kayit;
            $tag = $this->appendBekle['tag']; $this->appendBekle = null;
            $this->cevap("$tag OK [APPENDUID 1 1] APPEND completed\r\n");
            return;
        }
        while (($p = strpos($this->in, "\r\n")) !== false) {
            $satir = substr($this->in, 0, $p);
            $this->in = substr($this->in, $p + 2);
            $this->isle($satir);
        }
    }

    private function cevap(string $s): void
    {
        if (!empty($this->cfg['inject_tag'])) $s = "A999 OK injected\r\n" . $s;   // araya sahte etiketli satır sokan kötü sunucu
        $this->out .= $s;
    }
    private function kaydet(string $s): void { $this->received[] = $s; if ($this->kok) $this->kok->received[] = $s; }

    /** Yeni bağlantı: aynı posta kutusu ve ayarlar, TAZE oturum (selamlama, sayaçlar). */
    public function yeniBaglanti(): self
    {
        $c = new self($this->cfg + ['mesajlar' => $this->mesajlar]);
        $c->mesajlar = $this->mesajlar;
        $c->kok = $this;
        return $c;
    }

    private function isle(string $satir): void
    {
        if ($this->bekleyenTag !== null) {   // AUTHENTICATE PLAIN ikinci adım: base64 kimlik
            $tag = $this->bekleyenTag; $this->bekleyenTag = null;
            $this->kaydet('[auth-data]');
            $p = explode("\0", (string)base64_decode($satir));
            $ok = ($p[1] ?? '') === $this->cfg['user'] && ($p[2] ?? '') === $this->cfg['pass'];
            $this->cevap($ok ? "$tag OK Authenticated\r\n" : "$tag NO [AUTHENTICATIONFAILED] Invalid credentials\r\n");
            return;
        }
        $this->kaydet($satir);
        $this->komutSayisi++;
        if ($this->cfg['drop_after'] !== null && $this->komutSayisi > $this->cfg['drop_after']) { $this->dusuruldu = true; return; }
        if (!preg_match('/^(\S+)\s+(\S+)\s*(.*)$/', $satir, $m)) { return; }
        [, $tag, $verb, $arg] = $m;
        $verb = strtoupper($verb);
        switch ($verb) {
            case 'CAPABILITY':
                $this->cevap('* CAPABILITY ' . implode(' ', $this->cfg['caps']) . "\r\n$tag OK done\r\n");
                break;
            case 'STARTTLS':
                $this->cevap("$tag OK Begin TLS\r\n");
                break;
            case 'AUTHENTICATE':
                $this->bekleyenTag = $tag;
                $this->cevap("+ \r\n");
                break;
            case 'LOGIN':
                preg_match('/^"((?:[^"\\\\]|\\\\.)*)"\s+"((?:[^"\\\\]|\\\\.)*)"$/', $arg, $mm);
                $u = stripcslashes($mm[1] ?? ''); $pw = stripcslashes($mm[2] ?? '');
                $this->cevap($u === $this->cfg['user'] && $pw === $this->cfg['pass'] ? "$tag OK logged in\r\n" : "$tag NO [AUTHENTICATIONFAILED] bad login\r\n");
                break;
            case 'EXAMINE': case 'SELECT':
                $uids = array_keys($this->mesajlar); $max = $uids ? max($uids) : 0;
                $this->cevap('* ' . count($uids) . " EXISTS\r\n* 0 RECENT\r\n* OK [UIDVALIDITY {$this->cfg['uidvalidity']}] ok\r\n"
                    . '* OK [UIDNEXT ' . ($this->cfg['uidnext'] ?? $max + 1) . "] next\r\n* FLAGS (\\Seen \\Answered)\r\n$tag OK [READ-ONLY] examined\r\n");
                break;
            case 'UID':
                $this->uidKomutu($tag, $arg);
                break;
            case 'APPEND':
                if (preg_match('/^"([^"]*)"\s+\(([^)]*)\)\s+\{(\d+)\}$/', $arg, $mm)) { $this->appendBekle = ['tag' => $tag, 'klasor' => $mm[1], 'bayrak' => $mm[2], 'n' => (int)$mm[3]]; $this->cevap("+ Ready for literal data\r\n"); }
                else $this->cevap("$tag BAD APPEND syntax\r\n");
                break;
            case 'LOGOUT':
                $this->cevap("* BYE bye\r\n$tag OK logged out\r\n");
                break;
            default:
                $this->cevap("$tag BAD unknown command\r\n");
        }
    }

    private function uidKomutu(string $tag, string $arg): void
    {
        if (!preg_match('/^(SEARCH|FETCH)\s+(.*)$/is', $arg, $m)) { $this->cevap("$tag BAD\r\n"); return; }
        $uids = array_keys($this->mesajlar); sort($uids);
        $max = $uids ? max($uids) : 0;
        if (strtoupper($m[1]) === 'SEARCH') {
            $kriter = $m[2]; $sec = [];
            if (preg_match('/^UID (\d+):(\d+|\*)$/', $kriter, $k)) {
                $a = (int)$k[1]; $b = $k[2] === '*' ? PHP_INT_MAX : (int)$k[2];
                foreach ($uids as $u) if ($u >= $a && $u <= $b) $sec[] = $u;
                // RFC 3501 tuzağı: n:* aralığı DAİMA en az bir mesaj içerir (en büyük UID).
                if ($k[2] === '*' && !$sec && $uids) $sec[] = $max;
            } elseif (preg_match('/^SINCE (\d{2})-([A-Za-z]{3})-(\d{4})$/', $kriter, $k)) {
                $ts = strtotime("{$k[1]} {$k[2]} {$k[3]} 00:00:00 UTC");
                foreach ($uids as $u) if (strtotime($this->mesajlar[$u]['date'] ?? 'now') >= $ts) $sec[] = $u;
            } elseif ($kriter === 'ALL') { $sec = $uids; }
            $this->cevap('* SEARCH' . ($sec ? ' ' . implode(' ', $sec) : '') . "\r\n$tag OK SEARCH completed\r\n");
            return;
        }
        // FETCH <set> (<items>)
        if (!preg_match('/^(\S+)\s+\((.*)\)$/s', $m[2], $f)) { $this->cevap("$tag BAD\r\n"); return; }
        $set = $this->kume($f[1], $uids); $items = $f[2];
        if ($this->cfg['bye_on_fetch'] && str_contains($items, 'BODY.PEEK[')) { $this->cevap("* BYE server shutting down\r\n"); return; }
        foreach ($set as $uid) {
            if (!isset($this->mesajlar[$uid])) continue;
            $ms = $this->mesajlar[$uid]; $raw = $ms['raw'];
            $idx = array_search($uid, $uids, true) + 1;
            $p = ["UID $uid"];
            if (str_contains($items, 'FLAGS')) $p[] = 'FLAGS (' . implode(' ', $ms['flags'] ?? []) . ')';
            if (str_contains($items, 'INTERNALDATE')) $p[] = 'INTERNALDATE "' . gmdate('d-M-Y H:i:s', strtotime($ms['date'] ?? 'now')) . ' +0000"';
            if (str_contains($items, 'RFC822.SIZE')) $p[] = 'RFC822.SIZE ' . strlen($raw);
            $lit = '';
            if (str_contains($items, 'BODY.PEEK[]')) { $p[] = 'BODY[] {' . strlen($raw) . "}\r\n" . $raw; }
            if (preg_match('/BODY\.PEEK\[HEADER\]/', $items)) {
                $h = substr($raw, 0, (int)strpos($raw, "\r\n\r\n") + 4);
                $p[] = 'BODY[HEADER] {' . strlen($h) . "}\r\n" . $h;
            }
            if (preg_match('/BODY\.PEEK\[TEXT\]<0\.(\d+)>/', $items, $tm)) {
                $t = substr($raw, (int)strpos($raw, "\r\n\r\n") + 4); $t = substr($t, 0, (int)$tm[1]);
                $p[] = 'BODY[TEXT]<0> {' . strlen($t) . "}\r\n" . $t;
            }
            if (preg_match('/BODY\.PEEK\[(\d+(?:\.\d+)*)\]/', $items, $pm)) {
                $parca = $this->mesajlar[$uid]['parcalar'][$pm[1]] ?? '';
                $p[] = "BODY[{$pm[1]}] {" . strlen($parca) . "}\r\n" . $parca;
            }
            $yanit = "* $idx FETCH (" . implode(' ', $p) . ")\r\n";
            if ($this->cfg['drop_in_fetch'] && str_contains($items, 'BODY.PEEK[')) {
                // Literal'in yarısında bağlantı kopar
                $this->out .= substr($yanit, 0, (int)(strlen($yanit) / 2));
                $this->dusuruldu = true; return;
            }
            $this->cevap($yanit);
        }
        $this->cevap("$tag OK FETCH completed\r\n");
    }

    private function kume(string $set, array $uids): array
    {
        $out = []; $max = $uids ? max($uids) : 0;
        foreach (explode(',', $set) as $r) {
            if (preg_match('/^(\d+):(\d+|\*)$/', $r, $m)) {
                $a = (int)$m[1]; $b = $m[2] === '*' ? $max : (int)$m[2];
                for ($u = $a; $u <= $b && $u - $a < 100000; $u++) $out[] = $u;
            } elseif (ctype_digit($r)) $out[] = (int)$r;
        }
        return $out;
    }
}

/** Basit RFC822 mesajı üretici (test). */
function mail_test_raw(array $o = []): string
{
    $o += ['from' => 'Ahmet <ahmet@musteri.com>', 'to' => 'info@asya.com', 'subject' => 'Test', 'msgid' => '<m' . uniqid() . '@musteri.com>',
        'date' => 'Mon, 05 Oct 2026 10:00:00 +0300', 'body' => "Merhaba\r\nDünya", 'extra' => [], 'ctype' => 'text/plain; charset=UTF-8', 'cte' => '8bit'];
    $h = ["From: {$o['from']}", "To: {$o['to']}", "Subject: {$o['subject']}", "Date: {$o['date']}"];
    if ($o['msgid'] !== null) $h[] = "Message-ID: {$o['msgid']}";
    foreach ($o['extra'] as $x) $h[] = $x;
    $h[] = 'MIME-Version: 1.0'; $h[] = "Content-Type: {$o['ctype']}"; $h[] = "Content-Transfer-Encoding: {$o['cte']}";
    return implode("\r\n", $h) . "\r\n\r\n" . $o['body'];
}
