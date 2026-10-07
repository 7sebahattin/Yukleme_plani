<?php
// =========================================================
// scripts/_mail_fake_smtp.php — Test için SAHTE SMTP sunucusu (MailStream; include-only).
// Yapılandırma: user/pass, caps, reject_rcpt[], final ('250 2.0.0 queued' | '550 …' | '451 …' | 'DROP' | 'SILENT'),
// pre_data_drop (DATA'dan önce bağlantı kopar), inject_tag yok (SMTP'de etiket yok).
// $komutlar tüm istemci satırlarını, $mesajlar (nokta-çözülmüş) alınan ham mesajları tutar.
// =========================================================
declare(strict_types=1);

final class FakeSmtpStream implements MailStream
{
    public array $komutlar = [];
    public array $mesajlar = [];
    public bool $kapandi = false;
    public bool $dataAlindi = false;
    private string $in = '';
    private string $out = '';
    private string $durum = 'komut';   // komut | auth_user | auth_pass | data
    private string $dataBuf = '';
    private bool $dusuruldu = false;
    private string $authUser = '';
    public ?FakeSmtpStream $kok = null;

    public function __construct(public array $cfg = [])
    {
        $this->cfg += ['user' => 'u', 'pass' => 'p', 'caps' => ['STARTTLS', 'AUTH PLAIN LOGIN', 'SIZE 35882577', '8BITMIME'], 'reject_rcpt' => [],
            'final' => '250 2.0.0 Ok: queued as ABC123', 'pre_data_drop' => false, 'greeting' => '220 mx.test ESMTP ready'];
        $this->out = $this->cfg['greeting'] . "\r\n";
    }

    public function yeniBaglanti(): self { $c = new self($this->cfg); $c->kok = $this; return $c; }
    private function kaydet(string $s): void { $this->komutlar[] = $s; if ($this->kok) $this->kok->komutlar[] = $s; }

    public function readLine(int $max): ?string
    {
        if ($this->dusuruldu) return null;
        $p = strpos($this->out, "\r\n");
        if ($p === false) return null;
        $l = substr($this->out, 0, $p); $this->out = substr($this->out, $p + 2);
        return $l;
    }
    public function readBytes(int $n): ?string { return null; }
    public function startTls(): bool { return true; }
    public function close(): void { $this->kapandi = true; }

    public function write(string $data): void
    {
        if ($this->dusuruldu) throw new MailImapException('connect', 'Sunucuya yazılamadı.');
        $this->in .= $data;
        if ($this->durum === 'data') {
            $son = strpos($this->in, "\r\n.\r\n");
            if ($son === false) { if (strlen($this->in) > 60_000_000) $this->in = ''; return; }
            $this->dataAlindi = true; if ($this->kok) $this->kok->dataAlindi = true;
            $ham = substr($this->in, 0, $son + 2); $this->in = substr($this->in, $son + 5);
            $ham = preg_replace('/^\.\./m', '.', $ham);
            $this->mesajlar[] = $ham; if ($this->kok) $this->kok->mesajlar[] = $ham;
            $this->durum = 'komut';
            $f = $this->cfg['final'];
            if ($f === 'DROP') { $this->dusuruldu = true; return; }
            if ($f === 'SILENT') { return; }
            $this->out .= $f . "\r\n";
            return;
        }
        while (($p = strpos($this->in, "\r\n")) !== false) {
            $satir = substr($this->in, 0, $p); $this->in = substr($this->in, $p + 2);
            $this->isle($satir);
            if ($this->durum === 'data') { $this->write(''); break; }
        }
    }

    private function isle(string $satir): void
    {
        if ($this->durum === 'auth_user') { $this->kaydet('[auth-user]'); $this->authUser = (string)base64_decode($satir); $this->durum = 'auth_pass'; $this->out .= "334 UGFzc3dvcmQ6\r\n"; return; }
        if ($this->durum === 'auth_pass') {
            $this->kaydet('[auth-pass]'); $this->durum = 'komut';
            $this->out .= ($this->authUser === $this->cfg['user'] && base64_decode($satir) === $this->cfg['pass']) ? "235 2.7.0 Authentication successful\r\n" : "535 5.7.8 Authentication failed\r\n";
            return;
        }
        $this->kaydet($satir);
        $u = strtoupper($satir);
        if (str_starts_with($u, 'EHLO')) {
            $caps = $this->cfg['caps'];
            $this->out .= '250-mx.test greets you' . "\r\n";
            foreach ($caps as $i => $c) $this->out .= '250' . ($i === count($caps) - 1 ? ' ' : '-') . $c . "\r\n";
        } elseif ($u === 'STARTTLS') { $this->out .= "220 2.0.0 Ready to start TLS\r\n"; }
        elseif (str_starts_with($u, 'AUTH PLAIN ')) {
            $p = explode("\0", (string)base64_decode(substr($satir, 11)));
            $this->out .= (($p[1] ?? '') === $this->cfg['user'] && ($p[2] ?? '') === $this->cfg['pass']) ? "235 2.7.0 Authentication successful\r\n" : "535 5.7.8 Authentication failed\r\n";
        } elseif ($u === 'AUTH LOGIN') { $this->durum = 'auth_user'; $this->out .= "334 VXNlcm5hbWU6\r\n"; }
        elseif (str_starts_with($u, 'MAIL FROM:')) { $this->out .= "250 2.1.0 Ok\r\n"; }
        elseif (str_starts_with($u, 'RCPT TO:')) {
            preg_match('/<([^>]*)>/', $satir, $m);
            $this->out .= in_array($m[1] ?? '', $this->cfg['reject_rcpt'], true) ? "550 5.1.1 User unknown\r\n" : "250 2.1.5 Ok\r\n";
        } elseif ($u === 'DATA') {
            if ($this->cfg['pre_data_drop']) { $this->dusuruldu = true; return; }
            $this->durum = 'data'; $this->out .= "354 End data with <CR><LF>.<CR><LF>\r\n";
        } elseif ($u === 'RSET') { $this->out .= "250 2.0.0 Ok\r\n"; }
        elseif ($u === 'QUIT') { $this->out .= "221 2.0.0 Bye\r\n"; }
        else { $this->out .= "502 5.5.2 Error: command not recognized\r\n"; }
    }
}
