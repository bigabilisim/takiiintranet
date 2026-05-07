# Admin Yetki Merkezi

Admin paneli iki ana karari yonetir:

1. Kisi bazli modul ve surec yetkileri
2. Departman bazli izin onay semasi

## Kisi Bazli Yetki

Her kullanici icin moduller acilip kapatilabilir. Ayni ekranda izin, butce,
satin alma, duyuru ve admin surec yetkileri de yonetilir.

Ornek yetkiler:

- `leave.request.create`
- `leave.request.approve.department`
- `leave.request.manage.hr`
- `budget.view.department`
- `procurement.request.approve.department`
- `admin.company.manage`

## Departman Izin Semasi

Her departman icin su alanlar belirlenir:

- Yonetici onay sayisi: 1 veya 2
- Birinci yonetici
- Ikinci yonetici
- IK sorumlusu

Calisan izin talebi actiginda onay sayisi ve atanmis onaycilar calisanin
departman politikasindan otomatik gelir. Kullanici formdan bu akisi degistiremez.

