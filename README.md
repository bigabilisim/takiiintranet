# Kanso Intranet Platform

PHP ve MariaDB altyapisi uzerinde gelistirilen, cok dilli ve moduler kurumsal
intranet platformu. Sistem; izin, duyuru, mesajlasma, satin alma, butce,
belge, PWA bildirimleri, rapor ve mail sablonlarini tek merkezden yonetmek icin
tasarlanmistir.

## Urun Ozeti

- Uluslararasi firmalara beyaz etiketli olarak sunulabilecek intranet urunu
- Turkce, Ingilizce, Almanca ve Japonca arayuz destegi
- Admin tarafindan kisi bazli modul ve surec yetkisi yonetimi
- Departman bazli izin onay semasi: tek yonetici, iki yonetici ve IK onayi
- PWA uyumlulugu, offline sayfasi ve Web Push bildirim altyapisi
- PHP 8.3+ ve MariaDB 10.11+ hedefli, moduler monolith mimarisi

## Guncel Moduller

- **Operasyon paneli:** Haberler, duyurular, izin takvimi, hava durumu ve is kuyrugu.
- **Izin yonetimi:** Izin talebi, aylik/haftalik/gunluk takvim, bakiye karti, 60 gun icinde yeni izin hakki uyarisi.
- **Mail ile izin onayi:** Yoneticilere 96 saatlik token linki ile approve/reject akisi.
- **Admin yetki merkezi:** Tum modulleri ve surec yetkilerini kisi bazinda acma/kapama; departman izin semasi tanimlama.
- **Ic mesajlasma:** Konusma thread'i, hizli kisiler, pinleme, okundu takibi, menu bildirimi.
- **Silinen mesaj havuzu:** Mesajlar soft-delete ile admin havuzuna tasinir, admin geri getirebilir.
- **Satin alma:** Yeni satin alma talebi acma, kategori/tutar/tarih/gerekce alanlari.
- **PWA ve Web Push:** VAPID anahtarlari, push aboneligi, test bildirimi, service worker cache yonetimi.
- **Rapor ve mail sablonlari:** GrapesJS ile surukle-birak mail ve rapor sablon editoru.
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
- MariaDB 10.11+ hedeflenir; demo akislarinin bir bolumu JSON storage ile calisir

Kurulum:

```bash
composer install
php -S 127.0.0.1:8080 -t public
```

Alternatif:

```bash
composer serve
```

Ardindan `http://127.0.0.1:8080` adresini acin.

## Demo Hesaplari

- `admin@example.com` / `admin123`
- `hr@example.com` / `hr123`

Bu hesaplar `.env` icindeki `APP_ADMIN_EMAIL`, `APP_ADMIN_PASSWORD`,
`APP_HR_EMAIL` ve `APP_HR_PASSWORD` degerleriyle degistirilebilir. Canli
ortamda varsayilan demo parolalariyla yayin yapmayin.

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

## Storage Dosyalari

Demo ve prototip ortaminda bazi moduller JSON dosyalari ile kalici hale gelir:

- `storage/access-control.json`
- `storage/user-profiles.json`
- `storage/leave-requests.json`
- `storage/leave-mail-outbox.json`
- `storage/messages.json`
- `storage/templates.json`
- `storage/template-test-mail-outbox.json`
- `storage/push-subscriptions.json`
- `storage/vapid.json`
- `storage/weather-cache.json`

Canli personel, izin, mesaj, push, VAPID, audit ve hava durumu storage
dosyalari `.gitignore` ile repo disinda tutulur. Canli MariaDB gecisinde bu
dosyalardaki davranislar ilgili tablolara tasinmalidir.

## MariaDB Migration Taslaklari

Ilk sema taslaklari `database/migrations` altindadir:

- `001_create_core_tables.sql`
- `002_create_workflow_tables.sql`
- `003_create_leave_approval_flow_tables.sql`
- `004_create_admin_access_policy_tables.sql`
- `005_create_internal_messages_tables.sql`
- `006_create_internal_message_pins_table.sql`
- `007_create_web_push_subscriptions_table.sql`

Veritabani ayarlari `config/database.php` icinden okunur ve ortam degiskenleriyle
degistirilebilir:

- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

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

Temel smoke test icin admin oturumuyla su ekranlar kontrol edilebilir:

- `/`
- `/admin/access`
- `/module/leave`
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
