# Teknik Mimari

## Teknoloji Karari

- Backend: PHP 8.3+
- Veritabani: MariaDB 10.11+
- Web sunucusu: Nginx veya Apache
- Mimari stil: moduler monolith
- Arayuz: server-rendered PHP sayfalari + kademeli JavaScript
- Kimlik: oturum bazli giris, ileride SSO/SAML/OIDC hazirligi
- Dil altyapisi: veritabani destekli i18n + dosya bazli fallback

Moduler monolith ilk faz icin en dogru baslangictir. Urun tek kurulumla kolay
satilir, deployment basit kalir, ama moduller kendi sinirlariyla ayrildigi icin
ileride servislesmeye acik olur.

## Uygulama Katmanlari

- `public/`: web kok dizini, giris noktasi ve statik dosyalar
- `app/Core/`: router, request, response, auth, session, validation
- `app/Modules/`: is modulleri
- `app/Shared/`: ortak servisler, mail, dosya, bildirim, denetim kaydi
- `resources/views/`: PHP template dosyalari
- `resources/lang/`: varsayilan ceviri dosyalari
- `database/migrations/`: sema surumleri
- `database/seeders/`: demo ve baslangic verileri
- `storage/`: yuklenen dosyalar, loglar, gecici ciktiklar

## Kalicilik ve Es Zamanlilik

Cekirdek operasyonel veriler `app_state_documents` tablosunda tutulur. Bu katman
mevcut domain belge yapisini korurken dosya tabanli kaliciligi devreden cikarir.
Her read-modify-write islemi InnoDB transaction'i icinde ilgili belge satirini
`SELECT ... FOR UPDATE` ile kilitler; boylece paralel isteklerde kayip guncelleme
olmaz. Her kayitta artan `revision` ve SHA-256 `checksum` bulunur.

Eski JSON dosyalari yalnizca idempotent ilk migrasyonun kaynagidir. Dosya
surucusu uretim icin degil, yerel test ve acil geri donus icindir; bu surucu de
`flock` ve atomik rename kullanir.

Kullanici e-posta degisikligi bir kimlik migrasyonu olarak ele alinir. Parola
sifirlama, profil, yetki, izin, izin mail kuyrugu, mesaj, Web Push ve shift
belgeleri sabit lock sirasiyla tek islem kapsamina alinir. MariaDB tum belgeleri
tek transaction ile geri alir; dosya fallback surucusu ise kilit altinda alinan
snapshot'lari geri yukler. Tarihsel audit kayitlari degistirilmez.

## Moduller

### Identity

- Kullanici hesaplari
- Sirket ve sube bilgileri
- Rol ve yetki matrisi
- Oturum ve parola kurallari

### Content

- Haberler
- Duyurular
- Hedef kitleye gore yayinlama
- Okundu bilgisi

### Leave

- Izin turleri
- Yillik izin bakiyeleri
- Izin talep formu
- Onay akisi
- IK kesinlestirme
- Ek dosyalar

### Documents

- Kisiye ozel dosyalar
- Politika ve prosedur dokumanlari
- Yetkiye gore erisim
- Dosya gecmisi

### Budget

- Kisi bazli yillik butceler
- Departman bazli yillik butceler
- Harcama ve kalan limit takibi
- Finans onayi

### Procurement

- Satin alma talebi
- Kalem bazli urun/hizmet girisi
- Butce kontrolu
- Departman ve finans onaylari
- Talep durumu

### Notifications

- Uygulama ici bildirim
- E-posta bildirimi
- Onay bekleyen isler
- Gecikme hatirlatmalari

### Audit

- Kritik islem kayitlari
- Kim, ne zaman, hangi veriyi degistirdi
- Eski/yeni deger ozeti
- Raporlama icin sorgulanabilir log

## Veri Modeli Ilk Taslak

Temel tablolar:

- `companies`
- `branches`
- `departments`
- `users`
- `roles`
- `permissions`
- `role_permissions`
- `user_roles`
- `translations`
- `news_posts`
- `announcements`
- `leave_types`
- `leave_balances`
- `leave_requests`
- `leave_request_files`
- `documents`
- `budgets`
- `budget_allocations`
- `purchase_requests`
- `purchase_request_items`
- `approval_flows`
- `approval_steps`
- `notifications`
- `audit_logs`

## Cok Dillilik

Her kullanici icin tercih edilen dil saklanir. Arayuz metinleri anahtar bazli
ceviri sistemiyle gelir. Icerik modullerinde haber ve duyuru gibi kayitlarin
cok dilli varyantlari tutulur.

Baslangic dilleri:

- `tr-TR`
- `en-US`
- `ja-JP`

## Yetki Modeli

Yetki kontrolu rol + aksiyon + kaynak uzerinden kurulur.

Ornek yetkiler:

- `leave.request.create`
- `leave.request.approve.department`
- `leave.request.manage.hr`
- `budget.view.own`
- `budget.view.department`
- `procurement.request.create`
- `procurement.request.approve.finance`
- `content.announcement.publish`
- `admin.company.manage`

## Guvenlik Ilkeleri

- Parolalar `password_hash` ile saklanir.
- Tum formlarda CSRF korumasi kullanilir.
- Dosya yuklemelerinde uzanti, MIME ve boyut kontrolu yapilir.
- Yetki kontrolu controller seviyesinde degil servis seviyesinde de korunur.
- Kritik islemler `audit_logs` tablosuna yazilir.
- MariaDB sorgularinda hazir ifadeler kullanilir.
