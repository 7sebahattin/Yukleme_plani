<?php
// =========================================================
// config/mail_imap.php — Saf PHP IMAP istemcisi (M2)
//
// ext-imap'e BAĞIMLILIK YOK (PHP 8.4'te çekirdekten çıktı; paylaşımlı hostta garanti değil).
// Yalnız senkron için gereken alt küme: CAPABILITY, STARTTLS, AUTHENTICATE PLAIN / LOGIN,
// EXAMINE (salt okunur — sunucu durumunu ASLA değiştirmez), UID SEARCH, UID FETCH
// (BODY.PEEK — \Seen bayrağına dokunmaz), LOGOUT.
//
// Güvenlik: TLS her zaman DOĞRULAMALI (verify_peer + verify_peer_name); düz metin
// bağlantı seçeneği YOK. Satır / literal / toplam bayt sınırları ve zaman aşımı
// kötü niyetli ya da bozuk sunucuya karşı bellek/zaman tüketimini sınırlar.
// Komut günlüğü kimlik bilgisini içermez (LOGIN/AUTHENTICATE argümanları maskeli).
//
// Taşıma katmanı MailStream arayüzüdür → testte sahte sunucu (scripts/_mail_fake_imap.php).
// =========================================================
declare(strict_types=1);

interface MailStream
{
    /** CRLF'e kadar bir satır (CRLF'siz). EOF/zaman aşımında null. */
    public function readLine(int $max): ?string;
    /** Tam $n bayt; eksik gelirse (EOF/zaman aşımı) null. */
    public function readBytes(int $n): ?string;
    public function write(string $data): void;
    /** TLS'e yükselt (STARTTLS sonrası). */
    public function startTls(): bool;
    public function close(): void;
}

final class MailImapException extends RuntimeException
{
    /** connect | auth | protocol | timeout | limit | no | bad */
    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }
}

/** Gerçek ağ taşıması. Düz metin YOK: ssl:// (örtük TLS) ya da tcp + STARTTLS. */
final class MailSocketStream implements MailStream
{
    /** @var resource|null */
    private $fp;
    private function __construct($fp, private string $host, private float $timeout)
    {
        $this->fp = $fp;
    }

    public static function baglan(string $host, int $port, string $guvenlik, float $timeout = 20.0): self
    {
        if (!in_array($guvenlik, ['ssl', 'starttls'], true)) {
            throw new MailImapException('connect', 'Yalnız ssl / starttls desteklenir.');
        }
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
            'peer_name' => $host, 'SNI_enabled' => true, 'SNI_server_name' => $host,
            'disable_compression' => true,
        ]]);
        $hedef = ($guvenlik === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client($hedef, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new MailImapException('connect', 'Sunucuya bağlanılamadı (' . $host . ':' . $port . '): ' . mail_redact($errstr !== '' ? $errstr : 'bilinmeyen hata'));
        }
        stream_set_timeout($fp, (int)$timeout);
        return new self($fp, $host, $timeout);
    }

    public function readLine(int $max): ?string
    {
        if (!$this->fp) return null;
        $line = fgets($this->fp, $max + 3);
        if ($line === false) return null;
        if (!str_ends_with($line, "\n")) {
            // $max'tan uzun satır: kalanını AT ve limit hatası ver (üst katman bağlantıyı kapatır).
            throw new MailImapException('limit', 'Sunucu satırı çok uzun.');
        }
        return rtrim($line, "\r\n");
    }

    public function readBytes(int $n): ?string
    {
        if (!$this->fp) return null;
        $buf = '';
        while (strlen($buf) < $n) {
            $parca = fread($this->fp, min(65536, $n - strlen($buf)));
            if ($parca === false || $parca === '') {
                $meta = stream_get_meta_data($this->fp);
                if (!empty($meta['timed_out']) || feof($this->fp)) return null;
                continue;
            }
            $buf .= $parca;
        }
        return $buf;
    }

    public function write(string $data): void
    {
        if (!$this->fp) throw new MailImapException('connect', 'Bağlantı kapalı.');
        $kalan = $data;
        while ($kalan !== '') {
            $n = @fwrite($this->fp, $kalan);
            if ($n === false || $n === 0) throw new MailImapException('connect', 'Sunucuya yazılamadı.');
            $kalan = substr($kalan, $n);
        }
    }

    public function startTls(): bool
    {
        if (!$this->fp) return false;
        // STARTTLS enjeksiyonu: "A002 OK"tan sonra aynı pakette gelen DÜZ METİN baytlar PHP okuma tamponunda kalır ve
        // TLS'ten sonra sanki güvenli sunucudan geliyormuş gibi okunur. Tamponda artık bayt varsa TLS'e GEÇME.
        $meta = stream_get_meta_data($this->fp);
        if ((int)($meta['unread_bytes'] ?? 0) > 0) return false;
        $ok = @stream_socket_enable_crypto($this->fp, true,
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0));
        return $ok === true;
    }

    public function close(): void
    {
        if ($this->fp) { @fclose($this->fp); $this->fp = null; }
    }
}

/** IMAP4rev1 "modified UTF-7" (klasör adları): "Gönderilmiş" ↔ "G&APY-nderilmi&AV8-". */
function mail_imap_utf7_encode(string $s): string
{
    if (!preg_match('/[^\x20-\x7e]|&/', $s)) return $s;
    $out = ''; $buf = '';
    $bosalt = function () use (&$out, &$buf) {
        if ($buf === '') return;
        $b64 = rtrim(base64_encode(mb_convert_encoding($buf, 'UTF-16BE', 'UTF-8')), '=');
        $out .= '&' . str_replace('/', ',', $b64) . '-';
        $buf = '';
    };
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        if ($ch === '&') { $bosalt(); $out .= '&-'; }
        elseif (strlen($ch) === 1 && ord($ch) >= 0x20 && ord($ch) <= 0x7e) { $bosalt(); $out .= $ch; }
        else $buf .= $ch;
    }
    $bosalt();
    return $out;
}

function mail_imap_utf7_decode(string $s): string
{
    return (string)preg_replace_callback('/&([^-]*)-/', static function ($m) {
        if ($m[1] === '') return '&';
        $b64 = str_replace(',', '/', $m[1]);
        $raw = base64_decode($b64 . str_repeat('=', (4 - strlen($b64) % 4) % 4), true);
        return $raw === false ? $m[0] : mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
    }, $s);
}

/** IMAP SINCE tarihi: "05-Oct-2026" (yerelden bağımsız, İngilizce ay kısaltması). */
function mail_imap_tarih(int $ts): string
{
    static $ay = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return gmdate('d', $ts) . '-' . $ay[(int)gmdate('n', $ts) - 1] . '-' . gmdate('Y', $ts);
}

final class MailImapClient
{
    private int $tagSayac = 0;
    private float $bitis = 0.0;
    private int $toplamBayt = 0;
    private bool $kapali = false;
    /** @var list<string> kimlik bilgisi İÇERMEYEN komut günlüğü (son 60) */
    public array $gunluk = [];
    /** @var list<string> */
    public array $yetenekler = [];
    private array $o;

    public function __construct(private MailStream $s, array $opts = [])
    {
        $this->o = $opts + [
            'max_satir'     => 65536,
            'max_literal'   => 26214400,   // 25 MB — tek literal
            'max_toplam'    => 157286400,  // 150 MB — bağlantı başına
            'sure'          => 120.0,      // tek komut için duvar saati bütçesi (sn)
        ];
    }

    private function log(string $s): void
    {
        $this->gunluk[] = mb_substr(mail_redact($s), 0, 200);
        if (count($this->gunluk) > 60) array_shift($this->gunluk);
    }

    // ── Düşük seviye ───────────────────────────────────────────────

    /** @return array{0:string,1:list<string>} mantıksal satır (literaller yer tutucuyla) + literaller */
    private function mantiksalOku(): array
    {
        $satir = $this->s->readLine($this->o['max_satir']);
        if ($satir === null) throw new MailImapException('timeout', 'Sunucu yanıt vermedi ya da bağlantı kesildi.');
        $lits = [];
        while (preg_match('/\{(\d{1,10})\+?\}$/', $satir, $m)) {
            if (count($lits) >= 5000 || microtime(true) > $this->bitis) {   // sonsuz "{0}" akışı: sayı + süre sınırı
                $this->kapat();
                throw new MailImapException('limit', 'Sunucu yanıtı çok fazla parça içeriyor.');
            }
            $n = (int)$m[1];
            if ($n > $this->o['max_literal'] || $this->toplamBayt + $n > $this->o['max_toplam']) {
                $this->kapat();
                throw new MailImapException('limit', 'Sunucu yanıtı izin verilen boyutu aşıyor.');
            }
            $veri = $n === 0 ? '' : $this->s->readBytes($n);
            if ($veri === null) throw new MailImapException('timeout', 'Yanıt yarıda kesildi.');
            $this->toplamBayt += $n;
            $lits[] = $veri;
            $satir = substr($satir, 0, -strlen($m[0])) . "\x01L" . (count($lits) - 1) . "\x01";
            $devam = $this->s->readLine($this->o['max_satir']);
            if ($devam === null) throw new MailImapException('timeout', 'Yanıt yarıda kesildi.');
            $satir .= $devam;
        }
        return [$satir, $lits];
    }

    /** Yanıt satırını iç içe diziye çevirir. Atomlar string, NIL → null, literal → ham string. */
    public static function belirtecle(string $satir, array $lits): array
    {
        $i = 0; $n = strlen($satir);
        $oku = function () use (&$oku, &$i, $n, $satir, $lits): array {
            $liste = [];
            while ($i < $n) {
                $c = $satir[$i];
                if ($c === ' ') { $i++; continue; }
                if ($c === ')') { $i++; return $liste; }
                if ($c === '(') { $i++; $liste[] = $oku(); continue; }
                if ($c === '"') {
                    $i++; $b = '';
                    while ($i < $n && $satir[$i] !== '"') {
                        if ($satir[$i] === '\\' && $i + 1 < $n) $i++;
                        $b .= $satir[$i++];
                    }
                    $i++;
                    $liste[] = $b;
                    continue;
                }
                if ($c === "\x01") {
                    $j = strpos($satir, "\x01", $i + 1);
                    if ($j === false) { $i = $n; break; }
                    $liste[] = $lits[(int)substr($satir, $i + 2, $j - $i - 2)] ?? '';
                    $i = $j + 1;
                    continue;
                }
                $b = '';
                while ($i < $n && !in_array($satir[$i], [' ', '(', ')'], true)) {
                    if ($satir[$i] === '[' && preg_match('/^(?:BODY(?:\.PEEK)?|BINARY(?:\.PEEK|\.SIZE)?)$/i', $b)) {   // BODY[HEADER.FIELDS (A B)] tek atom; "foo[" anahtar kelimesi DEĞİL
                        $k = strpos($satir, ']', $i);
                        if ($k === false) { $b .= substr($satir, $i); $i = $n; break; }
                        $b .= substr($satir, $i, $k - $i + 1); $i = $k + 1; continue;
                    }
                    $b .= $satir[$i++];
                }
                $liste[] = (strcasecmp($b, 'NIL') === 0) ? null : $b;
            }
            return $liste;
        };
        return $oku();
    }

    /**
     * Tek komut. Dönüş: untagged yanıtlar [['tip'=>..,'sayi'=>?,'belirtec'=>[..],'ham'=>satir], ...]
     * + son 'tamam' metni. NO/BAD → MailImapException.
     * @param callable|null $devam '+' devam isteğinde çağrılır (string|null döner → gönderilir)
     */
    private function komut(string $cmd, ?callable $devam = null, ?string $gunlukMetni = null): array
    {
        if ($this->kapali) throw new MailImapException('connect', 'Bağlantı kapalı.');
        $this->bitis = microtime(true) + (float)$this->o['sure'];
        $tag = 'A' . str_pad((string)(++$this->tagSayac), 3, '0', STR_PAD_LEFT);
        $this->log($tag . ' ' . ($gunlukMetni ?? $cmd));
        $this->s->write($tag . ' ' . $cmd . "\r\n");
        $untagged = [];
        while (true) {
            if (microtime(true) > $this->bitis) { $this->kapat(); throw new MailImapException('timeout', 'Komut zaman aşımına uğradı.'); }
            [$satir, $lits] = $this->mantiksalOku();
            if (str_starts_with($satir, $tag . ' ')) {
                $rest = substr($satir, strlen($tag) + 1);
                if (preg_match('/^(OK|NO|BAD)\b\s*(.*)$/si', $rest, $m)) {
                    $d = strtoupper($m[1]);
                    if ($d === 'OK') return ['untagged' => $untagged, 'tamam' => $m[2]];
                    throw new MailImapException($d === 'NO' ? 'no' : 'bad', 'Sunucu komutu reddetti: ' . mb_substr(mail_redact($m[2]), 0, 160));
                }
                throw new MailImapException('protocol', 'Beklenmeyen sonuç satırı.');
            }
            if (preg_match('/^A\d{3} /', $satir)) {   // bizim etiketimiz değil: enjekte/bozuk yanıt → güvenilmez
                $this->kapat();
                throw new MailImapException('protocol', 'Beklenmeyen etiketli yanıt (protokol ihlali).');
            }
            if ($satir !== '' && $satir[0] === '+') {
                if ($devam === null) throw new MailImapException('protocol', 'Beklenmeyen devam isteği.');
                $g = $devam();
                if ($g !== null) $this->s->write($g . "\r\n");
                continue;
            }
            if ($satir !== '' && $satir[0] === '*') {
                $u = $this->untaggedAyristir(substr($satir, 2), $lits);
                if ($u['tip'] === 'BYE') { $this->kapat(); throw new MailImapException('protocol', 'Sunucu bağlantıyı kapattı: ' . mb_substr(mail_redact($u['ham']), 0, 120)); }
                $untagged[] = $u;
                if (count($untagged) > 200000) { $this->kapat(); throw new MailImapException('limit', 'Çok fazla yanıt satırı.'); }
                continue;
            }
            // Tanınmayan satır: yok say (bazı sunucular gürültü basar) ama sonsuz döngüye girme (süre bütçesi).
        }
    }

    private function untaggedAyristir(string $satir, array $lits): array
    {
        $ham = $satir;
        if (preg_match('/^(\d+)\s+(EXISTS|RECENT|EXPUNGE|FETCH)\b\s*(.*)$/si', $satir, $m)) {
            $tip = strtoupper($m[2]);
            $b = $tip === 'FETCH' ? self::belirtecle($m[3], $lits) : [];
            return ['tip' => $tip, 'sayi' => (int)$m[1], 'belirtec' => $b, 'ham' => $tip === 'FETCH' ? '' : $ham];
        }
        if (preg_match('/^(OK|NO|BAD|BYE|PREAUTH)\b\s*(.*)$/si', $satir, $m)) {
            $kod = [];
            if (preg_match('/^\[([A-Za-z0-9-]+)(?:\s+([^\]]*))?\]/', $m[2], $k)) $kod = [strtoupper($k[1]), $k[2] ?? ''];
            return ['tip' => strtoupper($m[1]), 'sayi' => null, 'belirtec' => $kod, 'ham' => $m[2]];
        }
        if (preg_match('/^(SEARCH|CAPABILITY|FLAGS|LIST|LSUB|STATUS)\b\s*(.*)$/si', $satir, $m)) {
            return ['tip' => strtoupper($m[1]), 'sayi' => null, 'belirtec' => preg_split('/\s+/', trim($m[2])) ?: [], 'ham' => $m[2]];
        }
        return ['tip' => 'DIGER', 'sayi' => null, 'belirtec' => [], 'ham' => $ham];
    }

    private function kapat(): void
    {
        $this->kapali = true;
        $this->s->close();
    }

    // ── Oturum ─────────────────────────────────────────────────────

    /** Selamlama + (gerekirse STARTTLS) + yetenekler. */
    public function baslat(bool $starttls): void
    {
        $this->bitis = microtime(true) + (float)$this->o['sure'];
        [$satir] = $this->mantiksalOku();
        if (!preg_match('/^\*\s+(OK|PREAUTH)\b/i', $satir)) {
            throw new MailImapException('protocol', 'Sunucu selamlaması beklenmedik: ' . mb_substr(mail_redact($satir), 0, 80));
        }
        $this->yetenekAl();
        if ($starttls) {
            if (!in_array('STARTTLS', $this->yetenekler, true)) {
                $this->kapat();
                throw new MailImapException('connect', 'Sunucu STARTTLS sunmuyor; düz metin bağlantı reddedildi.');
            }
            $this->komut('STARTTLS');
            if (!$this->s->startTls()) { $this->kapat(); throw new MailImapException('connect', 'TLS el sıkışması başarısız (sertifika doğrulanamadı).'); }
            $this->yetenekAl();   // TLS sonrası yetenekler yeniden istenir (önceki liste güvenilmez)
        }
    }

    private function yetenekAl(): void
    {
        $r = $this->komut('CAPABILITY');
        $this->yetenekler = [];
        foreach ($r['untagged'] as $u) {
            if ($u['tip'] === 'CAPABILITY') $this->yetenekler = array_map('strtoupper', array_map('strval', $u['belirtec']));
        }
    }

    public function girisYap(string $kullanici, string $sifre): void
    {
        foreach ([$kullanici, $sifre] as $v) {
            if (preg_match('/[\r\n\0]/', $v)) throw new MailImapException('auth', 'Kimlik bilgisinde geçersiz karakter.');
        }
        mail_redact_sirlar($sifre);
        if (in_array('LOGINDISABLED', $this->yetenekler, true)) {
            throw new MailImapException('auth', 'Sunucu bu bağlantıda girişe izin vermiyor (TLS gerekli olabilir).');
        }
        try {
            if (in_array('AUTH=PLAIN', $this->yetenekler, true)) {
                $b64 = base64_encode("\0" . $kullanici . "\0" . $sifre);
                $this->komut('AUTHENTICATE PLAIN', static fn() => $b64, 'AUTHENTICATE PLAIN ***');
            } else {
                $this->komut('LOGIN ' . $this->astring($kullanici) . ' ' . $this->astring($sifre), null, 'LOGIN *** ***');
            }
        } catch (MailImapException $e) {
            if ($e->kind === 'no' || $e->kind === 'bad') throw new MailImapException('auth', 'Kimlik doğrulama başarısız (kullanıcı adı/şifre veya uygulama şifresi gerekli olabilir).');
            throw $e;
        }
        $this->yetenekAl();
    }

    /** ASCII güvenliyse quoted, değilse UTF-8 baytlarıyla quoted (UTF8=ACCEPT'siz sunucular için AUTH PLAIN tercih edilir). */
    private function astring(string $s): string
    {
        return '"' . addcslashes($s, "\\\"") . '"';
    }

    /** @return array{uidvalidity:int,uidnext:int,exists:int} */
    public function klasorAc(string $klasor): array
    {
        if ($klasor === '' || preg_match('/[\r\n\0]/', $klasor)) throw new MailImapException('bad', 'Geçersiz klasör adı.');
        $r = $this->komut('EXAMINE ' . $this->astring(mail_imap_utf7_encode($klasor)));
        $out = ['uidvalidity' => 0, 'uidnext' => 0, 'exists' => 0];
        foreach ($r['untagged'] as $u) {
            if ($u['tip'] === 'EXISTS') $out['exists'] = (int)$u['sayi'];
            if ($u['tip'] === 'OK' && ($u['belirtec'][0] ?? '') === 'UIDVALIDITY') $out['uidvalidity'] = (int)$u['belirtec'][1];
            if ($u['tip'] === 'OK' && ($u['belirtec'][0] ?? '') === 'UIDNEXT') $out['uidnext'] = (int)$u['belirtec'][1];
        }
        if ($out['uidvalidity'] <= 0) throw new MailImapException('protocol', 'Sunucu UIDVALIDITY bildirmedi; güvenli senkron yapılamaz.');
        return $out;
    }

    /** @return list<int> artan sırada, tekrarsız UID listesi */
    public function uidAra(string $kriter): array
    {
        if (!preg_match('/^[A-Za-z0-9 :*,\-]+$/', $kriter)) throw new MailImapException('bad', 'Geçersiz arama ölçütü.');
        $r = $this->komut('UID SEARCH ' . $kriter);
        $ids = [];
        foreach ($r['untagged'] as $u) {
            if ($u['tip'] !== 'SEARCH') continue;
            foreach ($u['belirtec'] as $x) if (ctype_digit((string)$x) && (int)$x > 0) $ids[(int)$x] = true;
        }
        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** UID listesini IMAP sequence-set'e sıkıştırır: [1,2,3,7] → "1:3,7". */
    public static function uidKumesi(array $uids): string
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        sort($uids, SORT_NUMERIC);
        $parts = []; $i = 0; $n = count($uids);
        while ($i < $n) {
            $j = $i;
            while ($j + 1 < $n && $uids[$j + 1] === $uids[$j] + 1) $j++;
            $parts[] = $i === $j ? (string)$uids[$i] : $uids[$i] . ':' . $uids[$j];
            $i = $j + 1;
        }
        return implode(',', $parts);
    }

    /**
     * Boyut / bayrak / iç tarih (hafif). @return array<int,array{size:int,flags:list<string>,date:?string}>
     */
    public function uidMeta(array $uids): array
    {
        if (!$uids) return [];
        $r = $this->komut('UID FETCH ' . self::uidKumesi($uids) . ' (UID RFC822.SIZE FLAGS INTERNALDATE)');
        $out = [];
        foreach ($r['untagged'] as $u) {
            if ($u['tip'] !== 'FETCH') continue;
            $f = self::fetchAlanlari($u['belirtec'][0] ?? []);
            $uid = (int)($f['UID'] ?? 0);
            if ($uid <= 0) continue;
            $out[$uid] = ['size' => (int)($f['RFC822.SIZE'] ?? 0), 'flags' => array_map('strval', (array)($f['FLAGS'] ?? [])), 'date' => $f['INTERNALDATE'] ?? null];
        }
        return $out;
    }

    /**
     * Tek mesajın ham baytları. BODY.PEEK → sunucuda \Seen DEĞİŞMEZ.
     * $maxBayt aşılırsa yalnız başlık + gövdenin ilk $kismiBayt baytı alınır.
     * @return array{raw:string,truncated:bool}|null  mesaj sunucuda yoksa null
     */
    public function uidMesaj(int $uid, int $boyut, int $maxBayt, int $kismiBayt = 262144): ?array
    {
        if ($uid <= 0) throw new MailImapException('bad', 'Geçersiz UID.');
        if ($boyut > 0 && $boyut > $maxBayt) {
            $r = $this->komut("UID FETCH {$uid} (UID BODY.PEEK[HEADER] BODY.PEEK[TEXT]<0.{$kismiBayt}>)");
            $kes = true;
        } else {
            $r = $this->komut("UID FETCH {$uid} (UID BODY.PEEK[])");
            $kes = false;
        }
        foreach ($r['untagged'] as $u) {
            if ($u['tip'] !== 'FETCH') continue;
            $f = self::fetchAlanlari($u['belirtec'][0] ?? []);
            if ((int)($f['UID'] ?? 0) !== $uid) continue;
            if (!$kes) {
                $raw = $f['BODY[]'] ?? null;
                if (is_string($raw)) return ['raw' => $raw, 'truncated' => false];
            } else {
                $h = $f['BODY[HEADER]'] ?? null; $t = $f['BODY[TEXT]'] ?? '';
                if (is_string($h)) return ['raw' => $h . (is_string($t) ? $t : ''), 'truncated' => true];
            }
        }
        return null;
    }

    /** Ek indirme için tek MIME parçası (ham, transfer-encoding'li). */
    public function uidParca(int $uid, string $parcaNo, int $maxBayt): ?string
    {
        if (!preg_match('/^\d+(\.\d+)*$/', $parcaNo)) throw new MailImapException('bad', 'Geçersiz parça numarası.');
        $r = $this->komut("UID FETCH {$uid} (UID BODY.PEEK[{$parcaNo}])");
        foreach ($r['untagged'] as $u) {
            if ($u['tip'] !== 'FETCH') continue;
            $f = self::fetchAlanlari($u['belirtec'][0] ?? []);
            if ((int)($f['UID'] ?? 0) !== $uid) continue;
            $v = $f["BODY[{$parcaNo}]"] ?? null;
            if (is_string($v)) {
                if (strlen($v) > $maxBayt) throw new MailImapException('limit', 'Ek izin verilen boyutu aşıyor.');
                return $v;
            }
        }
        return null;
    }

    /** FETCH yanıt listesini [ANAHTAR => değer] haritasına çevirir; BODY[..]<origin> → BODY[..]. */
    public static function fetchAlanlari(array $liste): array
    {
        $f = [];
        for ($i = 0; $i + 1 < count($liste); $i += 2) {
            $k = strtoupper((string)$liste[$i]);
            $k = (string)preg_replace('/<\d+>$/', '', $k);
            $f[$k] = $liste[$i + 1];
        }
        return $f;
    }

    public function cikis(): void
    {
        if ($this->kapali) return;
        try { $this->komut('LOGOUT'); } catch (Throwable $e) { /* sessizce kapat */ }
        $this->kapat();
    }

    public function kapatZorla(): void { $this->kapat(); }
}

/**
 * Hesap kimlik bilgileriyle gerçek bağlantı kurar ve oturum açar.
 * @param array $hesap mail_hesap_cred_oku() çıktısı (şifreler çözülmüş)
 */
function mail_imap_baglan(array $hesap, ?float $timeout = null): MailImapClient
{
    $timeout = $timeout ?? 20.0;
    $stream = MailSocketStream::baglan((string)$hesap['imap_host'], (int)$hesap['imap_port'], (string)$hesap['imap_security'], $timeout);
    $c = new MailImapClient($stream);
    try {
        $c->baslat($hesap['imap_security'] === 'starttls');
        $c->girisYap((string)$hesap['imap_user'], (string)$hesap['imap_pass']);
    } catch (Throwable $e) {
        $c->kapatZorla();
        throw $e;
    }
    return $c;
}
