# Asya WhatsApp Web Bridge (Chrome Eklentisi — Aşama 1)

WhatsApp Web (`https://web.whatsapp.com`) üzerinde açık olan beyan gruplarındaki görüntülenen metin mesajlarını okuyup, mevcut Asya yapay zekâ API'si (`Groq` / `Gemini`) üzerinden gerçek **Yeni Beyan** formunun alanlarıyla eşleştiren ve kullanıcı onayıyla taslak oluşturan bağımsız Chrome Manifest V3 eklentisi.

## Güvenlik ve Çalışma Sınırları

- Yalnızca `https://web.whatsapp.com/*` ve `https://asya.scai.tr/*` adresleriyle sınırlıdır.
- WhatsApp'ın gizli API'lerine, React/webpack iç yapılarına, IndexedDB, localStorage, çerez veya WebSocket bağlantılarına **dokunmaz**.
- Otomatik mesaj göndermez, silmez veya kullanıcı adına herhangi bir WhatsApp işlemi yapmaz.
- Mesaj okuma yalnızca siz **BU GRUBU TARA** butonuna bastığınızda başlar.
- Yalnızca ekranda yüklenmiş (DOM'da erişilebilir) mesajlar okunur; daha eski mesajlar için WhatsApp Web'de yukarı kaydırarak mesajları yükleyip tekrar tarayabilirsiniz.
- Kullanıcı onayı olmadan Asya sisteminde hiçbir kayıt oluşturulmaz ve resmî HKS bildirimi gönderilmez.

## Chrome Kurulum Talimatları

1. Depoyu bilgisayarınıza indirin veya `chrome-extension/asya-whatsapp-bridge/` klasörünü bilgisayarınızda sabit bir klasöre kopyalayın.
2. Google Chrome'da adres çubuğuna `chrome://extensions` yazıp açın.
3. Sağ üstteki **Geliştirici modu (Developer mode)** anahtarını açın.
4. Sol üstteki **Paketlenmemiş öğe yükle (Load unpacked)** butonuna tıklayın.
5. `chrome-extension/asya-whatsapp-bridge` klasörünü seçin.
6. Eklenti listeye eklendikten sonra araç çubuğundan sabitleyin (pin).

## Asya Hesabı ile Eşleştirme

1. Asya uygulamasında (`https://asya.scai.tr/whatsapp_beyan_aktar.php`) oturum açın.
2. **Yeni Eşleştirme Kodu Üret** butonuna tıklayın (kod tek kullanımlıktır ve 10 dakika geçerlidir).
3. Chrome'da **Asya WhatsApp Web Bridge** eklentisini açın, kodu yapıştırıp **Bağlan** butonuna basın.
4. İstediğiniz zaman hem eklenti içindeki **Çıkış** butonundan hem de `whatsapp_beyan_aktar.php` ekranındaki **Bağlantıyı Kes** butonundan erişim token'ını iptal edebilirsiniz.

## Kullanım

1. `https://web.whatsapp.com` adresinde WhatsApp hesabınıza normal QR kod ile giriş yapın.
2. Beyan bilgilerinin paylaşıldığı WhatsApp grubunu açın.
3. Eklenti simgesine tıklayın, mesaj limitini (`50`, `100`, `200`) seçip **BU GRUBU TARA** butonuna basın.
4. Tespit edilen beyan adaylarından birinin yanındaki **AI ile Ayrıştır** butonuna tıklayın.
5. Açılan iki bölümlü inceleme ekranında (**A. WhatsApp Kaynak Mesajı** / **B. Beyan Bilgileri**) AI tarafından doldurulan alanları ve sarı vurgulu belirsiz alanları kontrol edin, eksik/hatalı alanları düzenleyin.
6. **Onayla ve Taslak Oluştur** butonuna bastığınızda mevcut Asya Yeni Beyan altyapısında `TASLAK` durumunda beyan kaydı oluşturulur.
