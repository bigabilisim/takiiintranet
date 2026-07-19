# Teknik Mimari

MyTakii'nin güncel ve ayrıntılı mimari ağacı
[`docs/architecture-tree.md`](architecture-tree.md) dosyasındadır. Makine
tarafından doğrulanan modül, rota, dosya, bağımlılık ve durum belgesi envanteri
ise `config/architecture.php` içinde tutulur.

## Teknoloji ve Stil

- PHP 8.3+
- MariaDB 10.11+ veya MySQL 8 uyumlu InnoDB
- Apache veya Nginx
- Sunucu taraflı PHP view + kademeli JavaScript
- Session tabanlı kimlik doğrulama
- `tr-TR`, `en-US`, `de-DE`, `ja-JP` dosya tabanlı çeviri
- Modüler monolit

Tek composition root `bootstrap/app.php`, tek HTTP giriş noktası
`public/index.php` dosyasıdır. Controller katmanı HTTP/yetki akışını, store ve
service sınıfları iş kurallarını, view dosyaları yalnızca sunumu yönetir.

## Kalıcılık

Operasyonel durum, `app_state_documents` tablosunda domain belgesi olarak
saklanır. Her read-modify-write işlemi InnoDB transaction'ı ve
`SELECT ... FOR UPDATE` kilidi kullanır. Belge kayıtlarında artan `revision` ve
SHA-256 `checksum` bulunur. `payload` alanı XChaCha20-Poly1305 ile şifrelenir;
associated data belge anahtarını da doğruladığı için şifreli satırlar farklı
belge anahtarları arasında taşınamaz. Üretim sürücüsü `mariadb`, dosya sürücüsü
yalnız yerel test ve kontrollü geri dönüş içindir.

Bu belge modeli bugünkü hacim için güvenli ve hızlıdır. Yüksek mesaj trafiği,
binlerce eş zamanlı kullanıcı veya çok şirketli SaaS aşamasından önce yüksek
yazmalı domainler normalleştirilmiş, indeksli tablolara taşınmalıdır. Sıralama ve
geçiş eşikleri mimari ağaçtaki "Kontrollü Büyüme Yolu" bölümündedir.

## Güvenlik Sınırları

- Tüm durum mutasyonları store/service katmanından geçer.
- Kimlik değişikliği ilgili belgelerde tek transaction olarak uygulanır.
- CSRF, yetki ve lokasyon kapsamı controller/domain sınırında doğrulanır.
- Parolalar hashlenir; açık token veya parola audit/outbox içine yazılmaz.
- CSP, HSTS, `nosniff`, referrer ve frame politikaları web root'ta uygulanır.
- Üretim `.env` ve `storage` dizini immutable sürüm paketinden ayrıdır.
- Kanonik HTTPS yönlendirmesi host header yerine yalnız `APP_URL` hedefini kullanır.
- Web Push uçları sağlayıcı allowlist'i, genel IP ve anahtar biçimiyle doğrulanır.
- Hassas personel alanları yetkisiz view modelinden sunucu tarafında çıkarılır.
- Kaynak deposu özeldir; personel organizasyon planı depo dışında JSON olarak tutulur.

## Zorunlu Doğrulama

```bash
composer verify:release
```

Bu komut mimari sahipliği, bağımlılık sınırlarını, PHP ve JavaScript sözdizimini
ve tüm zorunlu regresyon testlerini çalıştırır. MariaDB entegrasyon paketi sadece
ayrı test veritabanında etkinleştirilir:

```bash
RELEASE_VERIFY_MARIADB=1 composer verify:release
```

Canlı yayın öncesi temiz ve etiketli commit için doğrulama kaydı oluşturulur:

```bash
composer verify:release:record
composer release:assert
```

Herhangi bir kontrol başarısızsa canlı giriş noktası değiştirilmez.
