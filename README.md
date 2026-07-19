# MyTakii Intranet Platform

PHP ve MariaDB altyapisi uzerinde gelistirilen, cok dilli ve moduler kurumsal
intranet platformu. Sistem; izin, duyuru, mesajlasma, satin alma, butce,
belge, PWA bildirimleri, rapor ve mail sablonlarini tek merkezden yonetmek icin
tasarlanmistir.

Canli ortam: `https://mytakii.com`

Guncel guvenlik tabani ve surum: `v1.00.0`

## Urun Ozeti

- Uluslararasi firmalara beyaz etiketli olarak sunulabilecek intranet urunu
- Turkce, Ingilizce, Almanca ve Japonca arayuz destegi
- Admin tarafindan kisi bazli modul ve surec yetkisi yonetimi
- Departman bazli izin onay semasi: tek yonetici / IK veya iki yonetici / IK onayi
- PWA uyumlulugu, offline sayfasi ve Web Push bildirim altyapisi
- PHP 8.3+ ve MariaDB 10.11+ hedefli, moduler monolith mimarisi

## Guncel Moduller

- **Panel:** Izin takvimi, hava durumu ve kullaniciya ait hizli is ozeti.
- **Izin yonetimi:** Izin talebi, aylik/haftalik/gunluk takvim, bakiye karti, 60 gun icinde yeni izin hakki uyarisi; Taleplerim, Izin islem tarihcem ve Iptal Talebi sekmeleri.
- **Guvenli izin sahipligi:** Izinler ve bakiyeler, ad-soyad veya e-posta degisse de korunan sabit personel kimligiyle eslestirilir.
- **Tatil ve nobet takvimi:** Turkiye resmi tatilleri, yarim gunler, sirket tatilleri ve tarih bazli aylik ozel nobet planlari izin hesabina katilir.
- **Takvim gizliligi:** Takvim olaylari onay tarihcesi, karar veren kisi, red nedeni, talep notu veya onay akisi tasimaz; bu bilgiler yalnizca kisinin kendi tarihcesinde ve yetkili islem ekranlarinda kalir.
- **Lokasyon gorunurlugu:** Antalya ve Bursa personeli yalnizca kendi lokasyonundaki personel, izin, mesaj ve shift kayitlarini gorur. Admin ve IK yoneticisi iki lokasyona erisir; IK Asistani Antalya ve IK Asistani Bursa rolleri yalnizca atandiklari lokasyonu yonetir ve IK asamasina gelen izinleri kendi bolgeleri icin onaylayabilir.
- **Mail ile izin onayi:** Yoneticilere 96 saatlik token linki ile approve/reject akisi.
- **Admin yetki merkezi:** Tum modulleri ve surec yetkilerini kisi bazinda acma/kapama; departman izin semasi tanimlama.
- **Ic mesajlasma:** Konusma thread'i, hizli kisiler, pinleme, okundu takibi, menu bildirimi.
- **Silinen mesaj havuzu:** Mesajlar soft-delete ile admin havuzuna tasinir, admin geri getirebilir.
- **Satin alma:** Yeni satin alma talebi acma, kategori/tutar/tarih/gerekce alanlari.
- **PWA ve Web Push:** VAPID anahtarlari, push aboneligi, test bildirimi, service worker cache yonetimi.
- **Rapor ve mail sablonlari:** GrapesJS ile surukle-birak mail ve rapor sablon editoru.
- **Kullanici adi ve sifre yonetimi:** Personel kullanici adlari benzersiz `isimsoyisim` biciminde uretilir, duzenlenebilir ve giriste kullanilabilir. IK/Admin sifre yenilediginde e-postali personele bilgi maili gider; e-postasiz personelin yeni sifresi yalnizca bir kez ekranda gosterilir.
- **E-postasiz mavi yaka girisi:** E-posta adresi olmayan mavi yaka personel kullanici adi ve sifreyle giris yapabilir, izin talebi olusturabilir.
- **Sifre sifirlama:** Giris ekranindan 2 saatlik token ile sifre sifirlama baglantisi gonderilir.
- **Cok dilli altyapi:** `resources/lang` altinda `tr-TR`, `en-US`, `de-DE`, `ja-JP`.

## GrapesJS Sablon Editoru

`/module/templates` ekraninda rapor ve mail sablonlari GrapesJS ile duzenlenir.
Editor yalnizca sunucuda temizlenmis HTML ve CSS bilgisini merkezi StateStore
icinde saklar. Calistirilabilir GrapesJS project data verisi guvenlik nedeniyle
kalici kayda alinmaz.

Varsayilan seed sablonlar:

- `TPL-MAIL-1001`: Yillik izin onay maili
- `TPL-REPORT-1001`: Aylik IK izin raporu

GrapesJS, html2canvas ve jsPDF sabitlenmis surumlerle `public/vendor` altindan
yerel olarak yuklenir; editor calisirken ucuncu taraf CDN veya telemetri istegi
yapmaz.

Sablon ekranindaki test mail formu, editorun kaydedilmemis son HTML/CSS halini
ornek degiskenlerle render edip gonderir. Test mail kayitlari StateStore
uzerinden eszamanli yazma korumasiyla tutulur.
PHP `mail()` ile gercek gonderim icin `TEMPLATE_TEST_MAIL_TRANSPORT=native`,
`MAIL_FROM_ADDRESS` ve `MAIL_FROM_NAME` ortam degiskenleri ayarlanabilir.

## Yerel Calistirma

Gereksinimler:

- PHP 8.3+
- Composer
- MariaDB 10.11+

Kurulum:

```bash
composer install
cp .env.example .env
php scripts/migrate-state-to-mariadb.php
php -S 127.0.0.1:8080 -t public
```

Alternatif:

```bash
composer serve
```

Ardindan `http://127.0.0.1:8080` adresini acin.

MariaDB bulunmayan gecici bir yerel test ortaminda kilitli dosya surucusu acikca
secilebilir. Bu ayar canli ortamda kullanilmamalidir:

```bash
STATE_STORE_DRIVER=file php -S 127.0.0.1:8080 -t public
```

## Baslangic Hesaplari

Sistem yoneticisi ve IK hesabi `.env` icindeki `APP_ADMIN_*` ve `APP_HR_*`
alanlariyla tanimlanir. Ornek dosyada parola bulunmaz; ilk calistirmadan once
guclu parolalar belirleyin. Personel hesaplari daha sonra Personeller ekranindan
olusturulur. Kullanici adi varsayilan olarak Turkce karakterlerden arindirilmis
`isimsoyisim` biciminde benzersiz uretilir ve personel kartindan degistirilebilir.
Kullanicilar e-posta, kullanici adi, PDKS ID veya profil anahtariyla giris yapabilir;
parolalar yalnizca guvenli hash olarak durum deposunda tutulur.

## Yetki Notlari

Admin kullanicisi sistem yoneticisidir ve tum yonetilen izinlere otomatik
erisir. Admin panelinde yeni moduller de dahil olmak uzere kullanici bazli
yetkiler guncellenebilir.

Onemli yetkiler:

- `module.leave.access`
- `module.messages.access`
- `module.procurement.access`
- `module.templates.access`
- `messaging.send`
- `leave.request.create`
- `leave.request.approve.department`
- `leave.request.manage.hr`
- `procurement.request.create`
- `templates.manage`
- `admin.company.manage`

## Guvenlik

- Oturum kimligi giriste yenilenir; 30 dakika hareketsizlik ve 12 saat mutlak
  oturum siniri uygulanir. Sifre degisikligi eski oturumlari kapatir.
- Giris ve sifre sifirlama istekleri IP ve kimlik bazinda hiz sinirina tabidir.
- Yeni ve sifirlanan parolalar en az 12 karakterdir. `APP_SESSION_SECRET` canli
  ortamda en az 32 bayt rastgele degerle ayarlanmalidir.
- Izin onay maili baglantilari 96 saatlik, hashli ve tek kullanimliktir. GET
  istegi yalniz teyit ekrani acar; karar CSRF korumali POST ile kaydedilir.
- Kayitli sablonlar sunucu tarafinda allowlist ile temizlenir ve rapor onizlemesi
  sandbox iframe icinde calisir. CSP, HSTS, nosniff ve no-referrer basliklari
  uygulama ve statik dosyalarda etkindir.
- Sifre sifirlama ve izin tokenlari outbox, audit veya API yanitlarinda acik
  metin olarak saklanmaz. Teslim edilmeyen reset tokeni aninda iptal edilir.
- `APP_URL` HTTPS ise HTTP ve farkli host istekleri uygulama baslamadan kanonik
  HTTPS alan adina `308` ile yonlendirilir. `TRUST_PROXY` yalniz guvenilen bir
  ters vekil gercekten kullaniliyorsa acilmalidir.
- MariaDB durum belgeleri XChaCha20-Poly1305 ile uygulama katmaninda sifrelenir.
  Sifreli veri belge anahtarina baglidir; bir satirin baska belge anahtarina
  tasinmasi kimlik dogrulamasini gecemez.
- Web Push abonelikleri yalniz bilinen tarayici push saglayicilarinin HTTPS
  adresleri, gecerli Web Push anahtarlari ve genel IP cozumlemesiyle kabul edilir.
- Yonetici personel ekraninda kimlik numarasi, dogum tarihi, adres, kisisel telefon,
  acil durum bilgisi ve IK notlari HTML'e dahil edilmez. Bu alanlar yalniz Admin,
  IK ve bolgesel IK asistani rollerine sunulur.
- Izin talebi, departman yoneticisi ve IK onaycisi eksik veya gecersizse acilmaz;
  mevcut bir talep de bos onayci uzerinden yetki kazanamaz.

Yerel veya canli ortam icin secret uretimi:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

MariaDB belge sifreleme anahtari farkli formatta uretilir:

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

Bu deger `APP_DATA_ENCRYPTION_KEY` olarak kaynak kod disinda saklanir ve guvenli
bir yedegi alinir. Anahtar kaybi MariaDB belgelerinin geri getirilememesine yol
acar. Ayrintili kurulum ve rotasyon adimlari
[`docs/security-baseline.md`](docs/security-baseline.md) belgesindedir.

## Transactional State Store

Izinler, personeller, mesajlar, yetkiler, shift planlari, satin alma talepleri,
parola sifirlama kayitlari, guvenli mail metadatasi, Web Push abonelikleri,
denetim loglari, sablonlar, hiz sinirlari ve izin defteri zamanlayicisi `app_state_documents`
tablosunda saklanir. Her degisiklik InnoDB
transaction'i ve `SELECT ... FOR UPDATE` satir
kilidi altinda yapilir. Ayni anda gelen iki onay veya mesaj birbirinin verisini
ezemez. MariaDB `payload` degeri sifreli tutulur; `checksum` sifreli metni
dogrular ve anahtar rotasyonu onceki anahtarlar tanimli kaldigi surece otomatik
olarak uygulanir.

Eski `storage/*.json` dosyalari ilk migrasyonda veri kaynagi olarak okunur ve
sonrasinda cekirdek kayitlar icin yazma hedefi olmaz. Migrasyon idempotenttir;
mevcut MariaDB satirlarini varsayilan olarak ezmez:

```bash
php scripts/migrate-state-to-mariadb.php
```

Yalnizca bilincli bir geri yukleme sirasinda JSON kopyalarini MariaDB uzerine
yazmak icin `--force` kullanilabilir. Islemden once veritabani yedegi alinmalidir.

Dosya surucusu sadece yerel test ve acil geri donus icindir. Bu modda da her
belge icin `flock` ve atomik gecici-dosya/rename yazimi kullanilir.

Personel e-posta adresi degistiginde profil anahtari, kullanici yetkileri,
departman onaycilari, izinler, mesajlar, sabitlenmis konusmalar, Web Push
abonelikleri ve aylik shift planlari tek transaction icinde yeni kimlige tasinir.
Adimlardan biri basarisiz olursa degisikliklerin tamami geri alinir; eski adrese
gonderilmis aktif parola sifirlama baglantilari guvenlik icin iptal edilir.

IK veya Admin personel kartindaki `Sifreyi yenile` islemini kullandiginda 12
karakterli yeni bir sifre uretilir ve eski sifre sifirlama tokenlari iptal edilir.
E-posta teslimati basariliysa acik sifre arayuze veya outbox'a yazilmaz. E-posta
yoksa ya da teslimat basarisizsa sifre sadece yoneticinin sonraki ekraninda bir
kez gosterilir; bu yanit tarayici onbellegine alinmaz ve audit kaydi acik sifre
icermez.

Her personelin `personnel_id` alani sistem tarafindan bir kez uretilir ve profil
adi ya da e-posta degisikliginde korunur. Yeni izinler bu kimligi dogrudan
saklar. Eski izin kayitlari e-posta/profil anahtariyla, bunlar yoksa yalnizca
tek bir personelle eslesen ad-soyad uzerinden bir kez geriye donuk baglanir;
ayni isimli birden fazla personel varsa sistem tahminde bulunmaz.

Shift modulu, ozel hafta sonu nobetlerini ay icindeki kesin `working_dates`
tarihleriyle saklar. Eski hafta-gunu tabanli aylik planlar, secili ayin ilgili
tarihlerine bir kez genisletilerek kayipsiz donusturulur. Turkiye resmi tatil
takvimi 2025-2035 icin tam gun ve saat 13:00 sonrasi yarim gun ayrimiyla izin
suresine uygulanir; IK yoneticisi ayni ekrandan sirket tatili de tanimlayabilir.

Hava durumu verisi yalnizca yeniden uretilebilir kisa sureli bir cache dosyasidir;
personel veya islem verisi tasimaz.

## MariaDB Migration Taslaklari

Ilk sema taslaklari `database/migrations` altindadir:

- `001_create_core_tables.sql`
- `002_create_workflow_tables.sql`
- `003_create_leave_approval_flow_tables.sql`
- `004_create_admin_access_policy_tables.sql`
- `005_create_internal_messages_tables.sql`
- `006_create_internal_message_pins_table.sql`
- `007_create_web_push_subscriptions_table.sql`
- `008_create_state_documents_table.sql`

Veritabani ayarlari `config/database.php` icinden okunur ve ortam degiskenleriyle
degistirilebilir:

- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `STATE_STORE_DRIVER` (`mariadb` olmali)
- `STATE_STORE_AUTO_MIGRATE`
- `STATE_STORE_LOCK_TIMEOUT`
- `APP_DATA_ENCRYPTION_KEY` (zorunlu, base64 kodlu 32 bayt)
- `APP_DATA_ENCRYPTION_PREVIOUS_KEYS` (yalniz anahtar rotasyonu sirasinda)

İlk veri aktarımı `php scripts/migrate-state-to-mariadb.php` ile yapılır. Şema
oluşturulduktan sonra üretimde `STATE_STORE_AUTO_MIGRATE=false` kullanılmalıdır;
böylece her web isteğinde gereksiz DDL ve metadata kilidi çalıştırılmaz.

## PWA ve Web Push

Eklenen ana dosyalar:

- `public/manifest.webmanifest`
- `public/service-worker.js`
- `public/offline.html`
- `public/assets/pwa.js`

Endpointler:

- `GET /push/config`
- `POST /push/subscribe`
- `POST /push/unsubscribe`
- `POST /push/test`

Canli ortamda VAPID degerleri `.env` veya sunucu ortam degiskenlerinde kalici
tanimlanmalidir:

- `VAPID_SUBJECT`
- `VAPID_PUBLIC_KEY`
- `VAPID_PRIVATE_KEY`

## Proje Yapisi

```text
app/Core/                 Router, auth, session, view, translator, access control
app/Controllers/          HTTP controller katmani
app/Modules/              Leave, Messaging, Procurement, Templates, Push, Weather
config/architecture.php   Makine tarafindan dogrulanan mimari sahiplik haritasi
config/                   Uygulama, veritabani ve modul konfigurasyonu
database/migrations/      MariaDB sema taslaklari
public/                   Web root, PWA, CSS ve JS assetleri
resources/lang/           Cok dilli metinler
resources/views/          PHP view dosyalari
storage/                  Demo verileri, cache, push anahtarlari ve loglar
docs/                     Urun, mimari ve modul dokumantasyonu
```

## Dogrulama ve Yayin Kapisi

Her degisiklikten sonra mimari sahiplik, katman bagimliliklari, PHP/JavaScript
sozdizimi ve tum zorunlu regresyon testleri tek komutla calistirilir:

```bash
composer verify:release
```

MariaDB entegrasyon testleri uretimden tamamen ayri ve adinda `test` bulunan bir
veritabaninda calistirilir:

```bash
DB_DATABASE=mytakii_test RELEASE_VERIFY_MARIADB=1 composer verify:release
```

Canli paket yalniz temiz ve etiketli commit icin kaydedilmis dogrulamadan sonra
hazirlanabilir:

```bash
composer verify:release:record
composer release:assert
```

Surumleme `v1.00.0` tabanindan SemVer mantigiyla devam eder: guvenli hata
duzeltmeleri `v1.00.1`, geriye uyumlu ozellikler `v1.01.0`, kirici degisiklikler
`v2.00.0` olur. Canli paket yalniz SFTP, FTPS veya HTTPS tabanli hosting paneli
gibi sifreli bir kanaldan aktarilir; duz FTP yayin kanali olarak kullanilmaz.

Detayli sistem agaci, buyume sinirlari ve asamali kapasite plani
[`docs/architecture-tree.md`](docs/architecture-tree.md) belgesindedir. Temel
canli smoke testinde su ekranlar kontrol edilir:

- `/`
- `/admin/access`
- `/module/leave`
- `/module/shift`
- `/module/messages`
- `/module/procurement`
- `/module/templates`

## Baslangic Belgeleri

- [Urun vizyonu](docs/product-vision.md)
- [Mimari agac ve buyume plani](docs/architecture-tree.md)
- [Teknik mimari](docs/technical-architecture.md)
- [MVP yol haritasi](docs/mvp-roadmap.md)
- [Izin akisi](docs/leave-workflow.md)
- [Admin yetki merkezi](docs/admin-access-control.md)
- [Ic mesajlasma](docs/internal-messaging.md)
- [PWA ve Web Push](docs/pwa-web-push.md)
- [Guvenlik tabani ve canliya gecis](docs/security-baseline.md)
