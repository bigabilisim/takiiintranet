# PWA ve Web Push

Uygulama PWA uyumlu hale getirildi.

## Eklenen Parcalar

- `public/manifest.webmanifest`
- `public/service-worker.js`
- `public/offline.html`
- `public/assets/pwa.js`
- Web push abonelik endpointleri
- VAPID anahtar yonetimi
- MariaDB icin `web_push_subscriptions` tablo taslagi

## Endpointler

- `GET /push/config`: tarayiciya VAPID public key doner.
- `POST /push/subscribe`: tarayici push aboneligini kaydeder.
- `POST /push/unsubscribe`: aboneligi kapatir.
- `POST /push/test`: aktif kullaniciya test bildirimi yollar.

## Anahtarlar

Canli ortamda `.env` icinde su degerler kalici olarak tanimlanmalidir:

- `VAPID_SUBJECT`
- `VAPID_PUBLIC_KEY`
- `VAPID_PRIVATE_KEY`

Yerel demo ortaminda anahtar yoksa sistem `storage/vapid.json` dosyasinda bir
gelistirme anahtari uretir.

## Not

Web Push, destekleyen tarayicilarda HTTPS uzerinden calisir. `localhost` ve
`127.0.0.1` gelistirme icin guvenli kaynak kabul edilir.

