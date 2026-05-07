# Izin Akisi

## Surec

1. Calisan yillik izin talebi acar.
2. Talep tek yonetici ya da iki yonetici onayina gonderilir.
3. Yonetici onayi platformdan veya guvenli e-posta linkinden verilebilir.
4. Yonetici asamalari tamamlaninca talep IK onayina duser.
5. IK onayindan sonra izin takvimde kesinlesmis kayit olarak gorunur.

## Takvim Durumlari

- `tentative`: Talep onay surecinde, takvimde gecici gorunur.
- `confirmed`: IK onayladi, takvime kesin islendi.
- `blocked`: Talep reddedildi, takvimde risk/iptal olarak izlenir.

## Onay Adimlari

- `manager_1`
- `manager_2`
- `hr`
- `calendar`

E-posta onaylari icin gercek ortamda token ham hali saklanmaz. Linkteki token
SHA-256 ile hashlenerek `mail_approval_tokens.token_hash` alaninda tutulur,
sure bitimi ve tek kullanim `expires_at` ve `consumed_at` ile kontrol edilir.

