<?php
// =========================================================
// config/mail_smtp.php — Saf PHP SMTP istemcisi + giden mesaj oluşturucu (M5)
//
// Taşıma: MailStream (config/mail_imap.php) — gerçek ağ MailSocketStream, testte sahte sunucu.
// TLS: ssl:// (465) ya da STARTTLS (587), DOĞRULAMALI; düz metin YOK. STARTTLS sonrası tamponda bayt
// kalmışsa (yanıt enjeksiyonu) TLS'e geçilmez (MailSocketStream::startTls).
//
// GÖNDERİM SONUCU İKİ AYRI SINIFTIR (at-most-once için KRİTİK — docs/MAIL_CENTER_AGENT_BRIDGE.md AD-7):
//   • DATA'DAN ÖNCE hata (bağlantı/TLS/kimlik/RCPT reddi) ya da DATA'ya AÇIK ret (son yanıt 4xx/5xx) →
//     mesaj kabul EDİLMEDİ → güvenle yeniden denenebilir (kind = connect|auth|rcpt|data_reject).
//   • Son "." gönderildikten SONRA yanıt gelmezse / bağlantı koparsa → mesaj kabul edilmiş OLABİLİR →
//     kind = unknown (otomatik yeniden gönderim YOK; insan doğrular).
// Kimlik bilgileri ve AUTH argümanları günlükte maskelidir (mail_redact).
// =========================================================
declare(strict_types=1);

final class MailSmtpException extends RuntimeException
{
    /** connect | auth | rcpt | data_reject | protocol | limit | unknown */
    public function __construct(public readonly string $kind, string $message, public readonly bool $guvenliTekrar = true)
    {
        parent::__construct($message);
    }
}

final class MailSmtpClient
{
    private float $bitis = 0.0;
    private bool $kapali = false;
    /** DATA gövdesi yazılmaya başlandıktan sonra true: bundan sonraki her belirsizlik 'unknown' (kabul edilmiş olabilir). */
    public bool $dataFazi = false;
    /** @var list<string> */
    public array $gunluk = [];
    /** @var list<string> */
    public array $yetenekler = [];
    private array $o;

    public function __construct(private MailStream $s, array $opts = [])
    {
        $this->o = $opts + ['max_satir' => 4096, 'max_satir_sayisi' => 200, 'sure' => 60.0, 'max_boyut' => 25 * 1048576];
    }

    private function log(string $s): void
    {
        $this->gunluk[] = mb_substr(mail_redact($s), 0, 200);
        if (count($this->gunluk) > 60) array_shift($this->gunluk);
    }

    /** @return array{0:int,1:string} [kod, metin] — çok satırlı yanıtların tamamı okunur. */
    private function yanit(): array
    {
        $kod = 0; $metin = []; $n = 0;
        while (true) {
            if (microtime(true) > $this->bitis) { $this->kapat(); throw new MailSmtpException($this->dataFazi ? 'unknown' : 'connect', 'SMTP yanıt süresi doldu.', !$this->dataFazi); }
            $satir = $this->s->readLine($this->o['max_satir']);
            if ($satir === null) throw new MailSmtpException($this->dataFazi ? 'unknown' : 'connect', 'SMTP bağlantısı kesildi ya da yanıt vermedi.', !$this->dataFazi);
            if (++$n > $this->o['max_satir_sayisi']) { $this->kapat(); throw new MailSmtpException('limit', 'SMTP yanıtı çok uzun.'); }
            if (!preg_match('/^(\d{3})([ -])(.*)$/s', $satir, $m)) { $this->kapat(); throw new MailSmtpException('protocol', 'SMTP yanıtı çözülemedi.'); }
            if ($kod !== 0 && (int)$m[1] !== $kod) { $this->kapat(); throw new MailSmtpException('protocol', 'SMTP çok satırlı yanıt tutarsız.'); }
            $kod = (int)$m[1]; $metin[] = $m[3];
            if ($m[2] === ' ') return [$kod, implode("\n", $metin)];
        }
    }

    private function komut(string $cmd, ?string $gunluk = null, float $sure = 60.0): array
    {
        if ($this->kapali) throw new MailSmtpException('connect', 'SMTP bağlantısı kapalı.');
        if (preg_match('/[\r\n\0]/', $cmd)) throw new MailSmtpException('protocol', 'Komutta geçersiz karakter.');
        $this->bitis = microtime(true) + $sure;
        $this->log('> ' . ($gunluk ?? $cmd));
        $this->s->write($cmd . "\r\n");
        $r = $this->yanit();
        $this->log('< ' . $r[0] . ' ' . mb_substr($r[1], 0, 80));
        return $r;
    }

    private function kapat(): void { $this->kapali = true; $this->s->close(); }

    public function baslat(string $istemciAdi, bool $starttls): void
    {
        $this->bitis = microtime(true) + 30.0;
        [$kod] = $this->yanit();
        if ($kod !== 220) { $this->kapat(); throw new MailSmtpException('connect', 'SMTP selamlaması beklenmedik (' . $kod . ').'); }
        $this->ehlo($istemciAdi);
        if ($starttls) {
            if (!in_array('STARTTLS', $this->yetenekler, true)) { $this->kapat(); throw new MailSmtpException('connect', 'Sunucu STARTTLS sunmuyor; düz metin bağlantı reddedildi.'); }
            [$k] = $this->komut('STARTTLS');
            if ($k !== 220) { $this->kapat(); throw new MailSmtpException('connect', 'STARTTLS reddedildi (' . $k . ').'); }
            if (!$this->s->startTls()) { $this->kapat(); throw new MailSmtpException('connect', 'TLS el sıkışması başarısız (sertifika doğrulanamadı ya da güvensiz tampon).'); }
            $this->ehlo($istemciAdi);   // TLS sonrası yetenekler yeniden
        }
    }

    private function ehlo(string $ad): void
    {
        $ad = preg_match('/^[A-Za-z0-9.-]{1,200}$/', $ad) ? $ad : 'localhost';
        [$kod, $metin] = $this->komut('EHLO ' . $ad);
        if ($kod !== 250) throw new MailSmtpException('connect', 'EHLO reddedildi (' . $kod . ').');
        $this->yetenekler = [];
        foreach (explode("\n", $metin) as $i => $l) if ($i > 0) $this->yetenekler[] = strtoupper(trim($l));
        // AUTH PLAIN LOGIN → "AUTH" + mekanizmalar ayrı tutulur
        foreach ($this->yetenekler as $y) if (str_starts_with($y, 'AUTH ')) foreach (explode(' ', substr($y, 5)) as $mek) $this->yetenekler[] = 'AUTH=' . $mek;
    }

    public function girisYap(string $kullanici, string $sifre): void
    {
        foreach ([$kullanici, $sifre] as $v) if (preg_match('/[\r\n\0]/', $v)) throw new MailSmtpException('auth', 'Kimlik bilgisinde geçersiz karakter.');
        mail_redact_sirlar($sifre);
        if (in_array('AUTH=PLAIN', $this->yetenekler, true)) {
            [$k] = $this->komut('AUTH PLAIN ' . base64_encode("\0" . $kullanici . "\0" . $sifre), 'AUTH PLAIN ***');
        } elseif (in_array('AUTH=LOGIN', $this->yetenekler, true)) {
            [$k] = $this->komut('AUTH LOGIN', 'AUTH LOGIN');
            if ($k !== 334) throw new MailSmtpException('auth', 'SMTP AUTH LOGIN başlatılamadı.');
            [$k] = $this->komut(base64_encode($kullanici), '***');
            if ($k !== 334) throw new MailSmtpException('auth', 'SMTP kimlik doğrulama başarısız.');
            [$k] = $this->komut(base64_encode($sifre), '***');
        } else {
            $this->kapat();
            throw new MailSmtpException('auth', 'Sunucu AUTH PLAIN/LOGIN sunmuyor (TLS gerekli olabilir).');
        }
        if ($k !== 235) throw new MailSmtpException('auth', 'SMTP kimlik doğrulama başarısız (kullanıcı adı/şifre veya uygulama şifresi gerekli olabilir).');
    }

    /**
     * Mesajı gönderir. DATA öncesi hata ya da açık ret → güvenli (guvenliTekrar=true); son "." sonrası belirsizlik → unknown (false).
     * @param list<string> $alicilar
     * @return array{kabul:list<string>,red:list<string>,yanit:string}
     */
    public function gonder(string $zarfGonderen, array $alicilar, string $ham): array
    {
        if (!filter_var($zarfGonderen, FILTER_VALIDATE_EMAIL)) throw new MailSmtpException('rcpt', 'Zarf gönderen adresi geçersiz.');
        if (strlen($ham) > $this->o['max_boyut']) throw new MailSmtpException('limit', 'Mesaj boyut sınırını aşıyor.');
        [$k, $m] = $this->komut('MAIL FROM:<' . $zarfGonderen . '>');
        if ($k !== 250) throw new MailSmtpException('rcpt', 'Gönderen reddedildi (' . $k . '): ' . mb_substr(mail_redact($m), 0, 100));
        $kabul = []; $red = [];
        foreach ($alicilar as $a) {
            if (!filter_var($a, FILTER_VALIDATE_EMAIL)) { $red[] = $a; continue; }
            [$k] = $this->komut('RCPT TO:<' . $a . '>');
            if ($k === 250 || $k === 251) $kabul[] = $a; else $red[] = $a;
        }
        if (!$kabul) { try { $this->komut('RSET'); } catch (Throwable $e) {} throw new MailSmtpException('rcpt', 'Hiçbir alıcı kabul edilmedi.'); }
        [$k, $m] = $this->komut('DATA');
        if ($k !== 354) throw new MailSmtpException('data_reject', 'DATA reddedildi (' . $k . ').');

        // Nokta doldurma + CRLF normalizasyonu; sonda "\r\n.\r\n". Son noktadan sonrası = BELİRSİZLİK BÖLGESİ.
        $govde = preg_replace('/\r\n|\r|\n/', "\r\n", $ham) ?? $ham;
        $govde = preg_replace('/^\./m', '..', $govde) ?? $govde;
        if (!str_ends_with($govde, "\r\n")) $govde .= "\r\n";
        $this->bitis = microtime(true) + 120.0;
        $this->dataFazi = true;   // bu satırdan sonra HER belirsizlik "kabul edilmiş olabilir" demektir
        $this->log('> [DATA ' . strlen($govde) . ' bayt]');
        try {
            $this->s->write($govde . ".\r\n");
        } catch (Throwable $e) {
            // Yazma sırasında kopma: sunucu eksik veriyi atar (nokta gelmedi) — ama emin olamayız → unknown.
            throw new MailSmtpException('unknown', 'DATA gönderimi sırasında bağlantı koptu.', false);
        }
        try {
            [$k, $m] = $this->yanit();
        } catch (MailSmtpException $e) {
            throw new MailSmtpException('unknown', 'Mesaj gönderildi ancak sunucunun son yanıtı alınamadı (kabul edilmiş olabilir).', false);
        }
        $this->log('< ' . $k . ' ' . mb_substr($m, 0, 80));
        if ($k >= 200 && $k < 300) return ['kabul' => $kabul, 'red' => $red, 'yanit' => mb_substr($m, 0, 200)];
        throw new MailSmtpException('data_reject', 'Sunucu mesajı reddetti (' . $k . '): ' . mb_substr(mail_redact($m), 0, 120));   // açık 4xx/5xx = kabul EDİLMEDİ
    }

    public function cikis(): void
    {
        if ($this->kapali) return;
        try { $this->komut('QUIT', null, 5.0); } catch (Throwable $e) { /* sessiz */ }
        $this->kapat();
    }
    public function kapatZorla(): void { $this->kapat(); }
}

/** Hesap kimlik bilgileriyle bağlanır + giriş yapar. @param array $hesap mail_hesap_cred_oku() çıktısı */
function mail_smtp_baglan(array $hesap, ?float $timeout = null): MailSmtpClient
{
    $stream = MailSocketStream::baglan((string)$hesap['smtp_host'], (int)$hesap['smtp_port'], (string)$hesap['smtp_security'], $timeout ?? 20.0);
    $c = new MailSmtpClient($stream);
    try {
        $alan = (string)(substr(strrchr((string)$hesap['email'], '@') ?: '@localhost', 1) ?: 'localhost');
        $c->baslat($alan, $hesap['smtp_security'] === 'starttls');
        $c->girisYap((string)$hesap['smtp_user'], (string)$hesap['smtp_pass']);
    } catch (Throwable $e) {
        $c->kapatZorla();
        throw $e;
    }
    return $c;
}

// ── Giden mesaj oluşturucu ──────────────────────────────────────────────

/** Başlık değeri: CR/LF/NUL içeremez (başlık enjeksiyonu). */
function mail_baslik_guvenli(string $v): string
{
    if (preg_match('/[\r\n\0]/', $v)) throw new InvalidArgumentException('Başlık değerinde satır sonu karakteri olamaz.');
    return $v;
}

/** RFC 2047: ASCII değilse =?UTF-8?B?…?= (75 karakter sınırına uygun parçalarla). */
function mail_baslik_kodla(string $v): string
{
    $v = mail_baslik_guvenli($v);
    if (!preg_match('/[^\x20-\x7e]/', $v)) return $v;
    $parcalar = []; $cur = '';
    foreach (preg_split('//u', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        if (strlen($cur . $ch) > 42) { $parcalar[] = $cur; $cur = ''; }   // 42 bayt → ~56 base64 + sarmalayıcı < 75
        $cur .= $ch;
    }
    if ($cur !== '') $parcalar[] = $cur;
    return implode("\r\n ", array_map(static fn($p) => '=?UTF-8?B?' . base64_encode($p) . '?=', $parcalar));
}

/** "Ad" <adres> — adı güvenli kodlar. */
function mail_adres_baslik(string $ad, string $email): string
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Geçersiz e-posta adresi.');
    $ad = trim(mail_baslik_guvenli($ad));
    if ($ad === '') return $email;
    $kodlu = mail_baslik_kodla($ad);
    if ($kodlu === $ad) $kodlu = '"' . addcslashes($ad, "\\\"") . '"';
    return $kodlu . ' <' . $email . '>';
}

/** "Re: " + normalize konu (Re/AW/SV/Ответ… önekleri tekrarlanmaz). */
function mail_yanit_konusu(string $konu): string
{
    $n = function_exists('mail_konu_norm') ? mail_konu_norm($konu) : trim($konu);
    return 'Re: ' . ($n !== '' ? $n : '(konu yok)');
}

/** Uzun başlığı 76 sütuna katlar (sözcük sınırında). */
function mail_baslik_kat(string $ad, string $deger): string
{
    $satir = $ad . ': ' . $deger;
    if (strlen($satir) <= 76 || str_contains($deger, "\r\n")) return $satir;
    $out = ''; $cur = $ad . ':';
    foreach (explode(' ', $deger) as $w) {
        if (strlen($cur) + 1 + strlen($w) > 76 && $cur !== $ad . ':') { $out .= $cur . "\r\n"; $cur = ' ' . $w; }
        else $cur .= ($cur === $ad . ':' ? ' ' : ' ') . $w;
    }
    return $out . $cur;
}

/** Cevap için References zinciri: ebeveynin zinciri + ebeveyn id; en çok 20 (kök + son 19). @return list<string> <id> biçiminde */
function mail_references_zinciri(array $ebeveynRefs, string $ebeveynId): array
{
    $z = [];
    foreach (array_merge($ebeveynRefs, [$ebeveynId]) as $id) {
        $id = trim($id, '<> ');
        if ($id !== '' && !in_array($id, $z, true)) $z[] = $id;
    }
    if (count($z) > 20) $z = array_merge([$z[0]], array_slice($z, -19));
    return array_map(static fn($id) => '<' . $id . '>', $z);
}

/** Message-ID üretir: <rastgele.zaman@hesap-alan-adı>. */
function mail_yeni_message_id(string $hesapEmail): string
{
    $alan = strtolower((string)substr(strrchr($hesapEmail, '@') ?: '@localhost', 1));
    if (!preg_match('/^[a-z0-9.-]{1,190}$/', $alan)) $alan = 'localhost';
    return '<' . bin2hex(random_bytes(16)) . '.' . time() . '@' . $alan . '>';
}

/**
 * Giden mesajı RFC 5322 olarak kurar. Tüm başlık değerleri CR/LF'e karşı doğrulanır; gövde quoted-printable UTF-8.
 * @param array $p from_email, from_name, to (list<{name,email}>), reply_to?, subject, body, quote?, message_id, in_reply_to?, references?(list), date? (ts)
 */
function mail_giden_mesaj(array $p): string
{
    $h = [];
    $h[] = 'Date: ' . date('r', (int)($p['date'] ?? time()));
    $h[] = mail_baslik_kat('From', mail_adres_baslik((string)($p['from_name'] ?? ''), (string)$p['from_email']));
    $to = [];
    foreach ((array)$p['to'] as $a) $to[] = mail_adres_baslik((string)($a['name'] ?? ''), (string)$a['email']);
    if (!$to) throw new InvalidArgumentException('Alıcı yok.');
    $h[] = mail_baslik_kat('To', implode(', ', $to));
    if (!empty($p['reply_to'])) $h[] = 'Reply-To: ' . mail_adres_baslik('', (string)$p['reply_to']);
    $h[] = 'Subject: ' . mail_baslik_kodla((string)$p['subject']);
    $mid = mail_baslik_guvenli((string)$p['message_id']);
    if (!preg_match('/^<[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+>$/', $mid)) throw new InvalidArgumentException('Geçersiz Message-ID.');
    $h[] = 'Message-ID: ' . $mid;
    if (!empty($p['in_reply_to'])) {
        $ir = '<' . trim(mail_baslik_guvenli((string)$p['in_reply_to']), '<> ') . '>';
        if (!preg_match('/^<[^<>\s]+>$/', $ir)) throw new InvalidArgumentException('Geçersiz In-Reply-To.');
        $h[] = 'In-Reply-To: ' . $ir;
    }
    if (!empty($p['references'])) {
        $refs = array_map(static function ($r) { $r = '<' . trim(mail_baslik_guvenli((string)$r), '<> ') . '>'; if (!preg_match('/^<[^<>\s]+>$/', $r)) throw new InvalidArgumentException('Geçersiz References.'); return $r; }, (array)$p['references']);
        $h[] = mail_baslik_kat('References', implode(' ', $refs));
    }
    $h[] = 'MIME-Version: 1.0';
    $h[] = 'Content-Type: text/plain; charset=UTF-8';
    $h[] = 'Content-Transfer-Encoding: quoted-printable';
    $govde = str_replace(["\r\n", "\r"], "\n", (string)$p['body']);
    if (!empty($p['quote'])) $govde .= "\n\n" . str_replace(["\r\n", "\r"], "\n", (string)$p['quote']);
    $govde = mail_mime_temiz($govde);
    return implode("\r\n", $h) . "\r\n\r\n" . quoted_printable_encode(str_replace("\n", "\r\n", $govde)) . "\r\n";
}

/** Alıntı bloğu: "On <tarih>, <ad> <adres> wrote:" + "> " ile alıntılanan orijinal (en çok 40 satır / 4000 karakter). */
function mail_alinti_olustur(array $m): string
{
    $metin = trim((string)$m['body_text']);
    $metin = mb_substr($metin, 0, 4000);
    $satirlar = array_slice(preg_split('/\r?\n/', $metin) ?: [], 0, 40);
    $ad = trim((string)($m['from_name'] ?? ''));
    $kim = ($ad !== '' ? $ad . ' ' : '') . '<' . ($m['from_addr'] ?? '') . '>';
    $t = strtotime((string)$m['received_at']);
    $bas = 'On ' . ($t ? date('D, d M Y H:i', $t) : '') . ', ' . preg_replace('/[\r\n]+/', ' ', $kim) . ' wrote:';
    return $bas . "\n" . implode("\n", array_map(static fn($l) => '> ' . $l, $satirlar));
}
