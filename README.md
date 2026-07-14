# Kanso Intranet Platform

PHP ve MariaDB altyapisi uzerinde gelistirilen, cok dilli ve moduler kurumsal
intranet platformu. Sistem; izin, duyuru, mesajlasma, satin alma, butce,
belge, PWA bildirimleri, rapor ve mail sablonlarini tek merkezden yonetmek icin
tasarlanmistir.

## Urun Ozeti

- Uluslararasi firmalara beyaz etiketli olarak sunulabilecek intranet urunu
- Turkce, Ingilizce ve Japonca arayuz destegi
- Admin tarafindan kisi bazli modul ve surec yetkisi yonetimi
- Departman bazli izin onay semasi: tek yonetici, iki yonetici ve IK onayi
- PWA uyumlulugu, offline sayfasi ve Web Push bildirim altyapisi
- PHP 8.3+ ve MariaDB 10.11+ hedefli, moduler monolith mimarisi

## Guncel Moduller

- **Operasyon paneli:** Haberler, duyurular, izin takvimi, hava durumu ve is kuyrugu.
- **Izin yonetimi:** Izin talebi, aylik/haftalik/gunluk takvim, bakiye karti, 60 gun icinde yeni izin hakki uyarisi.
- **Guvenli izin sahipligi:** Izinler ve bakiyeler, ad-soyad veya e-posta degisse de korunan sabit personel kimligiyle eslestirilir.
- **Tatil ve nobet takvimi:** Turkiye resmi tatilleri, yarim gunler, sirket tatilleri ve tarih bazli aylik ozel nobet planlari izin hesabina katilir.
- **Takvim gizliligi:** Takvim olaylari onay tarihcesi, karar veren kisi, red nedeni, talep notu veya onay akisi tasimaz; bu bilgiler yalnizca kisinin kendi tarihcesinde ve yetkili islem ekranlarinda kalir.
- **Lokasyon gorunurlugu:** Antalya ve Bursa personeli yalnizca kendi lokasyonundaki personel, izin, mesaj ve shift kayitlarini gorur; Admin, IK yoneticisi ve IK asistanlari iki lokasyona da erisir.
- **Mail ile izin onayi:** Yoneticilere 96 saatlik token linki ile approve/reject akisi.
- **Admin yetki merkezi:** Tum modulleri ve surec yetkilerini kisi bazinda acma/kapama; departman izin semasi tanimlama.
- **Ic mesajlasma:** Konusma thread'i, hizli kisiler, pinleme, okundu takibi, menu bildirimi.
- **Silinen mesaj havuzu:** Mesajlar soft-delete ile admin havuzuna tasinir, admin geri getirebilir.
- **Satin alma:** Yeni satin alma talebi acma, kategori/tutar/tarih/gerekce alanlari.
- **PWA ve Web Push:** VAPID anahtarlari, push aboneligi, test bildirimi, service worker cache yonetimi.
- **Rapor ve mail sablonlari:** GrapesJS ile surukle-birak mail ve rapor sablon editoru.
- **Sifre sifirlama:** Giris ekranindan 2 saatlik token ile sifre sifirlama baglantisi gonderilir.
- **Cok dilli altyapi:** `resources/lang` altinda `tr-TR`, `en-US`, `de-DE`, `ja-JP`.

## GrapesJS Sablon Editoru

`/module/templates` ekraninda rapor ve mail sablonlari GrapesJS ile duzenlenir.
Editor HTML, CSS ve GrapesJS project data bilgisini `storage/templates.json`
dosyasinda saklar.

Varsayilan seed sablonlar:

- `TPL-MAIL-1001`: Yillik izin onay maili
- `TPL-REPORT-1001`: Aylik IK izin raporu

GrapesJS CDN uzerinden yuklenir. Canli ortamda CDN politikasi kapaliysa
GrapesJS dosyalari `public/assets/vendor` gibi lokal bir klasore alinabilir.

Sablon ekranindaki test mail formu, editorun kaydedilmemis son HTML/CSS halini
ornek degiskenlerle render edip gonderir. Varsayilan demo davranisi
`storage/template-test-mail-outbox.json` dosyasina kuyruk kaydi eklemektir.
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
olusturulur ve parolalari sifrelenmis olarak durum deposunda tutulur.

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

## Transactional State Store

Izinler, personeller, mesajlar, yetkiler, shift planlari, parola sifirlama kayitlari,
Web Push abonelikleri, VAPID anahtarlari, izin mail kuyrugu ve izin defteri
zamanlayicisi `app_state_documents` tablosunda saklanir. Her degisiklik InnoDB
transaction'i ve `SELECT ... FOR UPDATE` satir
kilidi altinda yapilir. Ayni anda gelen iki onay veya mesaj birbirinin verisini
ezemez.

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

Sablonlar, sifre sifirlama outbox'i ve hava durumu cache'i henuz cekirdek state
store kapsami disindadir; bunlar ayri modul migrasyonlariyla ele alinacaktir.

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
config/                   Uygulama, veritabani ve modul konfigurasyonu
database/migrations/      MariaDB sema taslaklari
public/                   Web root, PWA, CSS ve JS assetleri
resources/lang/           Cok dilli metinler
resources/views/          PHP view dosyalari
storage/                  Demo verileri, cache, push anahtarlari ve loglar
docs/                     Urun, mimari ve modul dokumantasyonu
```

## Dogrulama

PHP syntax kontrolu:

```bash
find app bootstrap config public resources -name '*.php' -print0 | xargs -0 -n1 php -l
```

JavaScript syntax kontrolu:

```bash
node --check public/assets/app.js
node --check public/assets/pwa.js
node --check public/assets/templates-editor.js
node --check public/service-worker.js
```

Es zamanli yazma testi:

```bash
php tests/state-store-concurrency.php
php tests/core-store-smoke.php
php tests/user-identity-migration.php
php tests/leave-schedule-integrity.php
DB_DATABASE=kanso_intranet php tests/state-store-mariadb-concurrency.php
DB_DATABASE=kanso_intranet composer test:stores:mariadb
DB_DATABASE=kanso_intranet composer test:identity:mariadb
DB_DATABASE=kanso_intranet composer test:leave-schedule:mariadb
```

Temel smoke test icin admin oturumuyla su ekranlar kontrol edilebilir:

- `/`
- `/admin/access`
- `/module/leave`
- `/module/shift`
- `/module/messages`
- `/module/procurement`
- `/module/templates`

## Baslangic Belgeleri

- [Urun vizyonu](docs/product-vision.md)
- [Teknik mimari](docs/technical-architecture.md)
- [MVP yol haritasi](docs/mvp-roadmap.md)
- [Izin akisi](docs/leave-workflow.md)
- [Admin yetki merkezi](docs/admin-access-control.md)
- [Ic mesajlasma](docs/internal-messaging.md)
- [PWA ve Web Push](docs/pwa-web-push.md)
