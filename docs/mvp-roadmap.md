# MVP Yol Haritasi

## Faz 1: Cekirdek Platform

Hedef: Sistemin giris, yetki, sirket ve dil temelini kurmak.

- PHP proje iskeleti
- MariaDB baglanti katmani
- Migration sistemi
- Oturum acma/kapatma
- Kullanici, rol, yetki tablolari
- Cok dilli arayuz temeli
- Yonetim paneli ana navigasyonu

Basari kriteri: Bir firma, bir yonetici ve bir calisanla sisteme girilip rol bazli ekran gorulebilir.

## Faz 2: Haber ve Duyuru Merkezi

Hedef: Sirket ic iletisim modulunu kullanilir hale getirmek.

- Haber listeleme ve detay
- Duyuru olusturma
- Hedef kitle secimi
- Cok dilli baslik ve icerik
- Okundu bilgisi

Basari kriteri: Yonetici duyuru yayimlar, calisan kendi dilinde gorur ve okundu kaydi olusur.

## Faz 3: Izin Yonetimi

Hedef: Yillik izin talebi ve onay akisini tamamlamak.

- Izin turleri
- Calisan izin bakiyesi
- Izin talep formu
- Dosya ekleme
- Departman amiri onayi
- IK kesinlestirme
- Takvim gorunumu

Basari kriteri: Calisan izin talebi acar, amir onaylar, IK kapatir ve bakiye guncellenir.

## Faz 4: Butce ve Satin Alma

Hedef: Kisi/departman butceleri ile satin alma taleplerini baglamak.

- Yillik butce tanimlari
- Kisi ve departman limitleri
- Satin alma talebi
- Talep kalemleri
- Butce uygunluk kontrolu
- Departman ve finans onaylari

Basari kriteri: Talep acildiginda ilgili butce etkilenir ve onay sureci izlenebilir.

## Faz 5: Kurumsal Hazirlik

Hedef: Urunu uluslararasi firmalara sunulabilir hale getirmek.

- Tema ve marka ayarlari
- Sistem ayarlari
- Denetim raporlari
- E-posta sablonlari
- Demo veri seti
- Kurulum dokumani
- Temel test senaryolari

Basari kriteri: Demo ortaminda farkli dil ve rollerle musteri sunumu yapilabilir.

## Ilk Sprint Onerisi

Ilk sprintte kod tarafinda su parcalari acilmali:

1. Minimal PHP uygulama iskeleti
2. PDO tabanli MariaDB baglantisi
3. Router ve controller yapisi
4. Login/logout akisi
5. Dil secimi ve ceviri yardimcisi
6. Dashboard ekraninin ilk versiyonu

Bu sprint urunun kalbini atmaya baslatir; henuz tum moduller bitmez ama mimari
kararlar yerli yerine oturur.

