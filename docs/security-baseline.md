# MyTakii v1.00.0 Güvenlik Tabanı

Bu belge üretim kurulumu, anahtar yönetimi ve yayın için zorunlu güvenlik
sözleşmesidir. Bir adım sağlanmıyorsa sürüm canlı giriş noktasına bağlanmaz.

## Kapatılan Yüksek Bulgular

- Gerçek personel organizasyon planı kaynak ağacından çıkarıldı. Senkronizasyon
  yalnız depo dışındaki gizli bir JSON planıyla çalışır.
- HTTP ve farklı host istekleri `APP_URL` içindeki kanonik HTTPS alan adına
  yönlendirilir. HSTS ve mevcut güvenlik başlıkları korunur.
- Web Push uçları sağlayıcı allowlist'i, HTTPS/443, geçerli Web Push anahtarları
  ve genel IP çözümlemesiyle doğrulanır; kayıt ve gönderim öncesi tekrar denetlenir.
- MariaDB `app_state_documents.payload` değerleri XChaCha20-Poly1305 ile
  şifrelenir. Anahtar olmadan MariaDB sürücüsü başlamaz.
- Hassas personel alanları yetkisiz yönetici görünümünden sunucu tarafında
  çıkarılır ve HTML'e yazılmaz.
- İzin talebi ve onayı, atanmış geçerli departman yöneticisi ve İK onaycısı
  olmadan kapalı davranır.

## Üretim Değişkenleri

Üretimde en az aşağıdaki değerler kaynak kod dışında tanımlanır:

```dotenv
APP_ENV=production
APP_URL=https://mytakii.com
APP_SESSION_SECRET=<en-az-32-bayt-rastgele-deger>
APP_DATA_ENCRYPTION_KEY=<base64-kodlu-32-bayt-anahtar>
APP_DATA_ENCRYPTION_PREVIOUS_KEYS=
STATE_STORE_DRIVER=mariadb
STATE_STORE_AUTO_MIGRATE=false
TRUST_PROXY=false
```

Anahtar üretimi:

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

`APP_DATA_ENCRYPTION_KEY`, veritabanı yedeğinden ayrı ve erişimi denetlenen bir
secret kasasında yedeklenir. Anahtar olmadan şifreli veriler kurtarılamaz.

## İlk Şifreleme Geçişi

1. Uygulama ve veritabanının tutarlı yedeğini alın.
2. Üretim PHP'sinde `sodium` uzantısının açık olduğunu doğrulayın.
3. Yeni `APP_DATA_ENCRYPTION_KEY` değerini üretim ortamına ekleyin.
4. Sürümü bakım penceresinde başlatın. StateStore, düz metin veya eski anahtarlı
   satırları transaction içinde mevcut anahtarla yeniden şifreler.
5. `app_state_documents.payload` değerlerinin `enc:v1:` ile başladığını ve düz
   metin profil/izin alanı taşımadığını yalnız metadata sorgusuyla doğrulayın.
6. Login, personel, izin, mesaj, push ve audit smoke testlerini çalıştırın.

Üretim veritabanı regresyon testlerinin hedefi değildir. Entegrasyon testleri
yalnız adı `_test` ile biten veya `test_` ile başlayan ayrı bir veritabanında
çalıştırılır.

## Anahtar Rotasyonu

1. Yeni anahtarı `APP_DATA_ENCRYPTION_KEY` yapın.
2. Eski anahtarı `APP_DATA_ENCRYPTION_PREVIOUS_KEYS` içine ekleyin. Birden fazla
   eski anahtar virgülle ayrılır.
3. Uygulamayı başlatın; eski anahtarlı satırlar transaction içinde yeni anahtara
   çevrilir.
4. Tüm satırların yeni key ID önekini kullandığını ve smoke testlerini doğrulayın.
5. Önceki yedeklerin saklama süresi dolmadan eski anahtarı kasadan silmeyin.
6. Eski anahtarlı satır kalmadıktan sonra environment listesinden çıkarın.

## Kaynak ve Personel Verisi

- GitHub deposu `private` görünürlükte tutulur.
- `.env`, veritabanı dump'ı, `storage` çalışma verileri ve personel Excel/JSON
  dosyaları commit edilmez.
- `PERSONNEL_ORGANIZATION_PLAN` mutlak bir depo dışı JSON yoludur.
- Bir secret daha önce mesaj, FTP veya Git geçmişinde paylaşıldıysa yalnız dosyayı
  silmek yeterli değildir; secret döndürülür. Geçmiş temizliği ayrı ve onaylı bir
  bakım işlemi olarak yapılır.

## Güvenli Yayın

1. `composer verify:release` başarılı olmalıdır.
2. Sürüm notu, temiz commit ve eşleşen tag oluşturulur.
3. `composer verify:release:record` ve `composer release:assert` çalıştırılır.
4. Veritabanı ve secret yedeği alınır.
5. Paket yalnız Git tarafından izlenen uygulama dosyalarından hazırlanır; canlı
   `.env` ve `storage` üzerine yazılmaz.
6. Aktarım SFTP, FTPS veya HTTPS tabanlı hosting paneliyle yapılır. Düz FTP
   kullanılmaz.
7. Yeni immutable sürüm dizini tamamlandıktan sonra public giriş noktası değiştirilir.
8. HTTP yönlendirmesi, TLS zinciri, login, dashboard, değişen modül, PWA dosyaları,
   güvenlik başlıkları, yanıt süresi ve hata logları doğrulanır.

## Sürümleme

Güvenlik tabanı `v1.00.0` ile başlar. Bundan sonra:

- Hata ve güvenlik düzeltmesi: `v1.00.1`
- Geriye uyumlu özellik: `v1.01.0`
- Kırıcı sözleşme veya veri modeli değişikliği: `v2.00.0`

Sürüm bildirim e-postası gönderilmez; değişiklikler uygulama içindeki Sürümler
ekranı ve Git etiketi üzerinden takip edilir.
