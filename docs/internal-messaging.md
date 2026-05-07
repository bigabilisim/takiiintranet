# Ic Mesajlasma

Kullanicilar platform icinde birbirine mesaj gonderebilir. Mesajlasma modul
erisimi ve mesaj gonderme yetkisi admin panelinden kisi bazinda acilip
kapatilir.

## Yetkiler

- `module.messages.access`: Mesajlar modulunu gorur.
- `messaging.send`: Yeni mesaj gonderebilir.

## MVP Davranisi

- Kullanici diger kullanicilari alici olarak secer.
- Konu ve mesaj metni zorunludur.
- Gelen kutusu okunmamis mesajlari isaretler.
- Kullanici kendi gelen mesajini okundu olarak isaretleyebilir.
- Gonderilen mesajlar ayri listelenir.
- Departman muduru ve son yazisilan 3 kisi hizli mesaj kisisi olarak gorunur.
- Kullanici istedigi konusmayi sabitleyerek listenin ustunde tutabilir.
- Bir kisiyle mesaj baslatildiginda sonraki mesajlar ayni konusma thread'i
  icinde devam eder.

## MariaDB Modeli

Gercek kalici veri icin `internal_messages` ve `internal_message_pins`
tablolari kullanilir. Demo asamasinda veri `storage/messages.json` dosyasinda
tutulur.
