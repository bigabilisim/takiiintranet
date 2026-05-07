<?php

namespace App\Core;

class ReleaseNoteStore
{
    private const VERSION = 1;
    private const CURRENT_RELEASE = 'v0.30.0';

    public function __construct()
    {
        $this->data();
    }

    public function all(): array
    {
        $entries = $this->data()['entries'];

        usort($entries, fn (array $a, array $b): int => strcmp((string) ($b['released_at'] ?? ''), (string) ($a['released_at'] ?? '')));

        return $entries;
    }

    public function latest(int $count = 6): array
    {
        return array_slice($this->all(), 0, max(1, $count));
    }

    public function mailDigest(int $count = 6): array
    {
        $entries = $this->latest($count);
        $current = $entries[0] ?? null;
        $lines = [];

        if ($current !== null) {
            $lines[] = 'Merhabalar,';
            $lines[] = '';
            $lines[] = 'Yeni intranet surumu tamamlandi: ' . (string) ($current['version'] ?? '') . ' - ' . (string) ($current['title'] ?? '');
            $lines[] = 'Tarih: ' . (string) ($current['released_at'] ?? '');
            $lines[] = '';
            $lines[] = 'Bu surumde yapilanlar:';

            foreach ((array) ($current['changes'] ?? []) as $change) {
                $lines[] = '- ' . (string) $change;
            }

            $previous = array_slice($entries, 1);

            if ($previous !== []) {
                $lines[] = '';
                $lines[] = 'Onceki 5 surum:';

                foreach ($previous as $entry) {
                    $lines[] = '';
                    $lines[] = (string) ($entry['version'] ?? '') . ' - ' . (string) ($entry['title'] ?? '') . ' (' . (string) ($entry['released_at'] ?? '') . ')';

                    foreach ((array) ($entry['changes'] ?? []) as $change) {
                        $lines[] = '- ' . (string) $change;
                    }
                }
            }
        }

        return [
            'subject' => $current !== null
                ? 'Takii Intranet surum notu: ' . (string) ($current['version'] ?? '')
                : 'Takii Intranet surum notu',
            'body' => implode("\n", $lines),
        ];
    }

    private function data(): array
    {
        $data = $this->loadData();
        $dirty = false;

        if (($data['version'] ?? null) !== self::VERSION || !is_array($data['entries'] ?? null)) {
            $data = $this->seedData(false);
            $dirty = true;
        }

        foreach ($this->seedEntries() as $seedEntry) {
            if ($this->hasEntry($data, (string) ($seedEntry['version'] ?? ''))) {
                continue;
            }

            $data['entries'][] = $seedEntry;
            $dirty = true;
        }

        if (($data['current_version'] ?? '') !== self::CURRENT_RELEASE) {
            $data['current_version'] = self::CURRENT_RELEASE;
            $dirty = true;
        }

        if ($dirty) {
            $this->saveData($data);
        }

        return $data;
    }

    private function hasEntry(array $data, string $version): bool
    {
        foreach ($data['entries'] ?? [] as $entry) {
            if ((string) ($entry['version'] ?? '') === $version) {
                return true;
            }
        }

        return false;
    }

    private function seedData(bool $save = true): array
    {
        $data = [
            'version' => self::VERSION,
            'current_version' => self::CURRENT_RELEASE,
            'entries' => $this->seedEntries(),
        ];

        if ($save) {
            $this->saveData($data);
        }

        return $data;
    }

    private function seedEntries(): array
    {
        return [
            [
                'version' => 'v0.30.0',
                'title' => 'GitHub yayin paketi guvenli hale getirildi',
                'released_at' => '2026-05-07 06:51',
                'status' => 'completed',
                'changes' => [
                    'GitHub reposu icin canli personel, izin, mesaj, bildirim ve audit storage verileri takip disina alindi.',
                    'Gecici test ve canli kontrol ciktisi ureten tmp klasoru repo disinda birakildi.',
                    'Varsayilan admin ve HR giris bilgileri ortam degiskenleriyle degistirilebilir hale getirildi.',
                    'Kod, dokuman, migration, PWA dosyalari, surum notlari ve varsayilan sablonlar yayinlanabilir paket olarak ayrildi.',
                ],
            ],
            [
                'version' => 'v0.29.0',
                'title' => 'HR girisi yeni e-postaya tasindi',
                'released_at' => '2026-05-06 19:09',
                'status' => 'completed',
                'changes' => [
                    'Eski HR ornek e-postasiyla tanimli HR User profil kaydi sistemden kaldirildi.',
                    'HR cekirdek kullanicisi ve izin onay politikasi hr@example.com e-posta adresine tasindi.',
                    'Bekleyen izin onaylari, mesaj alicilari ve yetki kayitlari yeni HR e-posta adresiyle esitlendi.',
                ],
            ],
            [
                'version' => 'v0.28.0',
                'title' => 'Personel dogum tarihleri guncellendi',
                'released_at' => '2026-05-06 18:54',
                'status' => 'completed',
                'changes' => [
                    'Outlook uzerinden gelen dogumgunu tablosu Excel eki sisteme alindi.',
                    'Exceldeki 78 dogum tarihi satiri personel profilleriyle eslestirildi ve 79 profil kaydina dogum tarihi islendi.',
                    'Yaklasik isim farklari ve HR User icin bulunan cift profil anahtari kontrollu sekilde eslestirildi.',
                ],
            ],
            [
                'version' => 'v0.27.0',
                'title' => 'Personel ekleme formu eklendi',
                'released_at' => '2026-05-06 17:49',
                'status' => 'completed',
                'changes' => [
                    'Personel ekranina yetkili kullanicilar icin yeni personel ekleme formu eklendi.',
                    'Yeni kayitta kimlik, departman, PDKS, izin bakiyesi, IK detaylari, egitim bilgileri ve giris sifresi alinacak hale getirildi.',
                    'Personel olusturma islemi audit log kaydi uretiyor ve e-posta olmayan mavi yaka kayitlari icin otomatik profil anahtari olusturuyor.',
                ],
            ],
            [
                'version' => 'v0.26.0',
                'title' => 'HR personel tam yetkisi sabitlendi',
                'released_at' => '2026-05-06 17:37',
                'status' => 'completed',
                'changes' => [
                    'HR User HR profiline personel okuma, duzenleme, silme ve export yetkileri kalici olarak sabitlendi.',
                    'Yetki ekrani kaydinda HR profilinden personel tam yetkileri yanlislikla dusurulse bile sistem bu yetkileri otomatik geri ekleyecek hale getirildi.',
                    'Canli yetki storage dosyasi yeni AccessControl surumune tasindi.',
                ],
            ],
            [
                'version' => 'v0.25.0',
                'title' => 'Demo personel verileri temizlendi',
                'released_at' => '2026-05-06 17:29',
                'status' => 'completed',
                'changes' => [
                    'Eski demo yonetici kullanicisi config, personel ve yetki kayitlarindan kaldirildi.',
                    'Eski demo izin ve mesaj kayitlari temizlendi.',
                    'Izin, mesajlasma ve satin alma seed verileri bosaltildi; Product onay yoneticisi aktif admin hesabina tasindi.',
                ],
            ],
            [
                'version' => 'v0.24.0',
                'title' => 'Personel arama filtresi eklendi',
                'released_at' => '2026-05-06 17:20',
                'status' => 'completed',
                'changes' => [
                    'Personel ekranina ad, e-posta, departman, unvan ve PDKS alanlarinda calisan arama kutusu eklendi.',
                    'Arama eslesmeyen personel satirlarini kademeli olarak soluklastirip gizleyecek sekilde tasarlandi.',
                    'PWA cache versiyonu guncellenerek yeni arama JS ve CSS dosyalarinin istemcilere alinmasi saglandi.',
                ],
            ],
            [
                'version' => 'v0.23.0',
                'title' => 'Personel Excel export eklendi',
                'released_at' => '2026-05-06 16:58',
                'status' => 'completed',
                'changes' => [
                    'Personel modulune normal Excel dosyasi icin .xlsx export endpointi eklendi.',
                    'Personel ekranina Excel indir butonu eklendi; CSV indir yedek format olarak korundu.',
                    'Excel dosyasinda baslik satiri sabitlendi ve personel kolonlari CSV ile ayni tutuldu.',
                ],
            ],
            [
                'version' => 'v0.22.0',
                'title' => 'Tum personel e-postalari ilkharf.soyad standardina alindi',
                'released_at' => '2026-05-06 10:17',
                'status' => 'completed',
                'changes' => [
                    'Mavi yaka disindaki personel e-postalari ilkharf.soyad@company.example formatina toplu olarak tasindi.',
                    'Ayni soyad/ad kombinasyonlarinda cakisma olmamasi icin tekrar eden adreslere sirali ek kullanildi.',
                    'Personel profil anahtarlari ve yetki kayitlari yeni e-posta standardiyla esitlendi.',
                    'Personel listesinde artik bulunmayan eski yetki e-posta anahtarlari otomatik temizlenecek hale getirildi.',
                ],
            ],
            [
                'version' => 'v0.21.0',
                'title' => 'Aytug Devren e-posta adresi guncellendi',
                'released_at' => '2026-05-06 10:16',
                'status' => 'completed',
                'changes' => [
                    'Ornek personel e-posta adresi yeni standart formata tasindi.',
                    'Personel profilindeki gorunen e-posta alani yeni standart adrese guncellendi.',
                    'Yetki kaydi yeni e-posta anahtariyla esitlendi.',
                ],
            ],
            [
                'version' => 'v0.20.0',
                'title' => 'Personel e-posta duzenleme ve mavi yaka temizligi',
                'released_at' => '2026-05-06 09:15',
                'status' => 'completed',
                'changes' => [
                    'Personel kartlarina duzenlenebilir e-posta alani eklendi.',
                    'Mavi yaka olarak degerlendirilen BC departmanlarindaki personellerin e-posta alanlari bosaltildi.',
                    'Beyaz yaka personel e-postalari ad.soyad@company.example standardina gore yeniden duzenlendi.',
                    'Sistem kullanicilari eski giris adresleri korunarak standart personel e-postalariyla da gorunur hale getirildi.',
                ],
            ],
            [
                'version' => 'v0.19.0',
                'title' => 'Personel modulune HR export eklendi',
                'released_at' => '2026-05-06 08:33',
                'status' => 'completed',
                'changes' => [
                    'Personel ekranina yetkili HR kullanicilarinin CSV indirebilecegi export butonu eklendi.',
                    'Yeni personnel.export yetkisi tanimlandi ve HR User HR profiline verildi.',
                    'Export islemi audit log tarafinda personnel.exported aksiyonu ile kayda alinmaya baslandi.',
                ],
            ],
            [
                'version' => 'v0.18.0',
                'title' => 'Demo Employee personel kaydi silindi',
                'released_at' => '2026-05-06 08:26',
                'status' => 'completed',
                'changes' => [
                    'Demo Employee / employee@example.com demo kullanicisi ve personel kaydi sistemden kaldirildi.',
                    'Demo Employee referansli izin, mesaj ve mail outbox kayitlari temizlendi.',
                    'Config demo kullanicilarindan employee@example.com cikarilarak kaydin yeniden olusmasi engellendi.',
                ],
            ],
            [
                'version' => 'v0.17.0',
                'title' => 'Cok dilli metinler kontrol edildi',
                'released_at' => '2026-05-06 07:55',
                'status' => 'completed',
                'changes' => [
                    'Turkce, Ingilizce, Almanca ve Japonca dil dosyalari 496 ortak anahtar uzerinden tekrar duzenlendi.',
                    'Placeholder uyumu korunarak :count, :name ve sablon degiskenlerinin tum dillerde ayni kalmasi saglandi.',
                    'Statik PWA offline sayfasindaki Turkce metinler duzeltildi.',
                ],
            ],
            [
                'version' => 'v0.16.0',
                'title' => 'HR personel yonetim yetkileri',
                'released_at' => '2026-05-05 19:25',
                'status' => 'completed',
                'changes' => [
                    'HR User profiline Personel modulu erisimi sabitlendi.',
                    'Personel duzenleme yetkisi personnel.write aktif hale getirildi.',
                    'Personel silme yetkisi personnel.delete aktif hale getirildi.',
                ],
            ],
            [
                'version' => 'v0.15.0',
                'title' => 'NM personel kayitlari silindi',
                'released_at' => '2026-05-05 19:15',
                'status' => 'completed',
                'changes' => [
                    'NM departmanindaki 4 personel kaydi personel listesinden kaldirildi.',
                    'Silinen NM personellerine ait yetki kayitlari temizlendi.',
                    'NM departman onay politikasi aktif departman listesinden kaldirildi.',
                ],
            ],
            [
                'version' => 'v0.14.0',
                'title' => 'Excel personel listesi yeniden yuklendi',
                'released_at' => '2026-05-05 19:05',
                'status' => 'completed',
                'changes' => [
                    'Mevcut Excel kaynakli personel kayitlari silindi ve yeni parolali Excel dosyasindan 91 personel yeniden olusturuldu.',
                    'Cekirdek sistem kullanicilari korunarak toplam profil sayisi 95 olarak guncellendi.',
                    'Yeni personel e-postalari @company.example domainiyle uretildi ve yetki kayitlari yeni listeye gore temizlendi.',
                ],
            ],
            [
                'version' => 'v0.13.0',
                'title' => 'Demo yonetici kullanicisi silindi',
                'released_at' => '2026-05-05 18:55',
                'status' => 'completed',
                'changes' => [
                    'Eski demo yonetici kullanicisi sistemden kaldirildi.',
                    'Product departmani onay yoneticisi aktif yonetici hesabina guncellendi.',
                    'Eski demo referansli storage kayitlari temizlendi.',
                ],
            ],
            [
                'version' => 'v0.12.0',
                'title' => 'Takii personel e-posta domainleri guncellendi',
                'released_at' => '2026-05-05 18:45',
                'status' => 'completed',
                'changes' => [
                    '125 personel e-posta adresindeki eski internal domain @company.example olarak degistirildi.',
                    'Personel storage anahtarlari ve email alanlari yeni domainle esitlendi.',
                    'Yetki storage kayitlari yeni e-posta adresleriyle uyumlu hale getirildi.',
                ],
            ],
            [
                'version' => 'v0.11.0',
                'title' => 'Ofis departmani Beyaz Yaka yapildi',
                'released_at' => '2026-05-05 18:35',
                'status' => 'completed',
                'changes' => [
                    'Ofis departmanindaki 57 personel Beyaz Yaka departmanina tasindi.',
                    'Personel acilis kaynak alanlarinda Ofis degeri Beyaz Yaka olarak guncellendi.',
                    'Departman izin/yetki politikasindaki Ofis kaydi Beyaz Yaka adina tasindi.',
                ],
            ],
            [
                'version' => 'v0.10.0',
                'title' => 'Takii Gaziler departmani Mavi Yaka yapildi',
                'released_at' => '2026-05-05 18:25',
                'status' => 'completed',
                'changes' => [
                    'Takii Gaziler departmanindaki 33 personel Mavi Yaka departmanina tasindi.',
                    'Personel acilis kaynak alanlarinda Takii Gaziler degeri Mavi Yaka olarak guncellendi.',
                    'Departman izin/yetki politikasindaki Takii Gaziler kaydi Mavi Yaka adina tasindi.',
                ],
            ],
            [
                'version' => 'v0.9.0',
                'title' => 'Isten cikis personel kayitlari temizlendi',
                'released_at' => '2026-05-05 18:15',
                'status' => 'completed',
                'changes' => [
                    'Personel listesinde departmani Isten Cikislar olan 48 kayit silindi.',
                    'Silinen personellere ait yetki storage kalintilari temizlendi.',
                    'Personel toplam kaydi 178den 130a dusuruldu.',
                ],
            ],
            [
                'version' => 'v0.8.0',
                'title' => 'Akis semalari sistemden kaldirildi',
                'released_at' => '2026-05-05 18:05',
                'status' => 'completed',
                'changes' => [
                    'Akis semalari modulu, menusu ve route kayitlari sistemden kaldirildi.',
                    'Akis semasi olusturma ve paylasma yetkileri Admin > Yetkiler ekranindan cikarildi.',
                    'Akis semasi ekranina ait kullanilmayan CSS, JavaScript ve view dosyalari temizlendi.',
                ],
            ],
            [
                'version' => 'v0.7.0',
                'title' => 'Personel tablosu ve R/W/D yetkileri',
                'released_at' => '2026-05-05 17:50',
                'status' => 'completed',
                'changes' => [
                    'Yetkiler ekrani korunarak ayri Personel modulu eklendi.',
                    'Personel modulu icin okuma, yazma ve silme yetkileri kisi bazinda yonetilebilir hale getirildi.',
                    'HR ve yoneticiler personel bilgilerini yetkilere dokunmadan tablo uzerinden yonetebilir hale getirildi.',
                ],
            ],
            [
                'version' => 'v0.6.0',
                'title' => 'Izin takvimi renkli onay akislari ve surum takibi',
                'released_at' => '2026-05-05 17:35',
                'status' => 'completed',
                'changes' => [
                    'Izin takviminde onay asamalari renklere ayrildi.',
                    'Takvim pop-up aciklamasindan not ve detayli onay akisi kaldirildi.',
                    'Admin paneline surum notlari ve mail ozeti gorunumu eklendi.',
                ],
            ],
            [
                'version' => 'v0.5.0',
                'title' => 'Excel personel aktarimi ve yillik izin hak edis numaratoru',
                'released_at' => '2026-05-05 17:20',
                'status' => 'completed',
                'changes' => [
                    'Parolali Excel dosyasindan 173 personel ve acilis izin bakiyesi aktarildi.',
                    'Yillik izin hak edisi 1-5 yil, 5-15 yil ve 15+ yil kurallarina gore hesaplanir hale getirildi.',
                    'Hak edis numaratoru ve ilgili flowchart sisteme eklendi.',
                ],
            ],
            [
                'version' => 'v0.4.0',
                'title' => 'Flowchart modulu',
                'released_at' => '2026-05-05 16:55',
                'status' => 'completed',
                'changes' => [
                    'Sirket ici akis semasi olusturma ve paylasma modulu eklendi.',
                    'Departman/sirket/kisi bazli gorunurluk mantigi kuruldu.',
                    'Ornek satin alma ve izin akislari sisteme yerlestirildi.',
                ],
            ],
            [
                'version' => 'v0.3.0',
                'title' => 'Rapor ve mail sablonlari',
                'released_at' => '2026-05-05 15:45',
                'status' => 'completed',
                'changes' => [
                    'GrapesJS ile rapor ve mail sablon editoru eklendi.',
                    'Test mail ve son gonderilen test mailini sabit tutma ozelligi eklendi.',
                    'Raporlar icin PDF cikti destegi eklendi.',
                ],
            ],
            [
                'version' => 'v0.2.0',
                'title' => 'Mesajlasma deneyimi',
                'released_at' => '2026-05-05 14:30',
                'status' => 'completed',
                'changes' => [
                    'Mesaj yazim alani ust tarafa alindi ve konusma akisi sadeleştirildi.',
                    'Son gorusulen kisiler, hizli mesaj kisileri ve pinlenen konusmalar eklendi.',
                    'Silinen mesajlar admin havuzundan geri getirilebilir hale getirildi.',
                ],
            ],
            [
                'version' => 'v0.1.0',
                'title' => 'Cok dilli PWA intranet temeli',
                'released_at' => '2026-05-05 13:30',
                'status' => 'completed',
                'changes' => [
                    'PWA ve web push altyapisi kuruldu.',
                    'Turkce, Ingilizce, Almanca ve Japonca dil destegi eklendi.',
                    'Panelde saat, hava durumu, izin takvimi, yetki ve departman yonetimi temelleri tamamlandi.',
                ],
            ],
        ];
    }

    private function loadData(): array
    {
        $path = $this->dataPath();

        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function saveData(array $data): void
    {
        $path = $this->dataPath();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function dataPath(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/storage/release-notes.json';
    }
}
