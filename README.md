# VállalkozóOS – backend (Laravel API)

> Ügyfél- és munkakezelés szolgáltató vállalkozóknak. A megjelenített név az `APP_NAME` környezeti változóval állítható.

Verziózott REST API (`/api/v1`) az Angular frontendhez: Laravel 13, Sanctum token-hitelesítés, MySQL (fejlesztéshez SQLite).

## Gyors indítás

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed      # demo: demo@example.com / password, super admin: admin@example.com / password
php artisan serve               # http://localhost:8000
php artisan queue:work          # import feldolgozás, e-mailek
php artisan schedule:work       # emlékeztetők, import-fájlok törlése
```

Tesztek: `php artisan test`

## Mi van benne (V1)

| Terület | Végpontok |
|---|---|
| Regisztráció / belépés | `auth/register` (munkaterület + 14 napos Pro próba, kártya nélkül), `auth/login`, `auth/me`, `auth/profile`, `auth/password` |
| Munkaterületek | `workspaces`, `workspace` (aktuális + csomag, limitek, használat), `workspace/downgrade-preview`, `plans` |
| Csapat | `team/members`, `team/invitations` (e-mailes meghívó, 7 napos lejárat, visszavonás), `invitations/{token}/accept` |
| Ügyfelek | `clients` (keresés, szűrés, rendezés, lapozás, címkék, egyedi mezők), `clients/export?format=csv|xlsx` |
| Érdeklődők | `leads`, `leads/{id}/convert` (naplózott konverzió, előzmények megmaradnak) |
| Értékesítés | `pipelines` (szakaszok), `deals`, `deals/{id}/move` (kanban, időbélyeges szakaszváltás-napló) |
| Teendők / naptár | `tasks` (`?from=&to=` naptárnézethez), e-mail-emlékeztetők (`crm:send-task-reminders`) |
| Kapcsolattörténet | `activities` (hívás, e-mail, találkozó, online megbeszélés, jegyzet + rendszeresemények) |
| Űrlapok | `forms`, `forms/{id}/submissions`, publikus: `public/forms/{slug}` + `/submissions` |
| Import | `imports` (XLSX/XLS/CSV feltöltés, munkalap- és fejlécfelismerés, oszlop-hozzárendelés, előnézet, duplikáció: kihagyás/frissítés, idempotens újrapróbálás, soronkénti napló) |
| Automatizálás | `automations` (pl. új érdeklődő → teendő; Business: körforgásos kiosztás) |
| Riportok | `dashboard`, `reports` (havi bontás, konverzió, pipeline, űrlapok, források, csapat) |
| Super Admin | `admin/stats`, `admin/analytics`, `admin/workspaces?status=trial|expiring|paid|free|suspended`, `admin/subscriptions`, `admin/invoices` (+ újrapróbálás), `admin/plans` (limitek/árak szerkesztése), `admin/support/tickets`, `admin/system` (rendszerállapot, sikertelen jobok), `admin/users`, `admin/audit-logs` – kötelező 2FA (`ADMIN_REQUIRE_2FA`), `php artisan crm:super-admin email@cim` |
| Support Center | `support/tickets` (jegy, válasz, lezárás) – prioritás a csomag szerint: Business magas, Pro normál, Free alacsony |

## Fiókbiztonság és adatvédelem

- **E-mail-megerősítés:** regisztráció után aláírt, 24 órás link; megerősítés nélkül a munkaterületi végpontok `email_unverified` kóddal tiltanak.
- **Elfelejtett jelszó:** e-mailes link (egységes válasz, hogy ne derüljön ki, létezik-e a cím); visszaállításkor minden munkamenet kijelentkezik.
- **E-mail-cím módosítás:** jelszóval, csak az új cím megerősítése után; a régi címre biztonsági értesítés megy.
- **Kétlépcsős azonosítás (TOTP):** QR-kód, 8 egyszer használatos visszaállító kód, visszajátszás elleni védelem, 5 perces bejelentkezési kihívás, frekvenciakorlát.
- **GDPR:** JSON adatexport, fióktörlés jelszóval + megerősítő szóval. A saját munkaterületek törlődnek (az előfizetés azonnal megszűnik); csapattal rendelkező munkaterületnél előbb át kell adni a tulajdonjogot (`POST team/owner`). A kiállított számlák a számviteli megőrzési időre megmaradnak.
- **Értesítések:** határidő-emlékeztető, reggeli összefoglaló (`crm:send-daily-digest`, helyi idő szerint 7:00), új érdeklődő – mind kikapcsolható. Minden levél a felhasználó nyelvén, márkázott sablonnal (`resources/views/vendor/mail`).

## Biztonsági alapelvek

- `tests/Feature/WorkspaceIsolationTest.php` minden munkaterületi végpontot automatikusan végigpróbál egy idegen munkaterület adataival (olvasás, módosítás, törlés, listák, exportok, hivatkozások); új végpont paraméteréhez kötelező izolációs adatot adni.
- Minden munkaterületi rekord `workspace_id`-hoz kötött; a `ResolveWorkspace` middleware minden kérésnél ellenőrzi a tagságot (`X-Workspace-Id` fejléc), a globális scope pedig a route model bindinget is a munkaterületre szűkíti (idegen ID → 404).
- Szerepkörök: Owner, Admin, Member, Viewer (a Viewer csak olvashat). A Super Admin külön jogosultság.
- Csomaglimitek és funkciók szerveroldalon (`config/plans.php`, `PlanService`); a próba lejárata Free-re vált, adat nem törlődik (402-es válasz `plan_limit` / `feature_not_available` kóddal).
- Publikus űrlap: rate limit, szerveroldali validáció, honeypot + időzítési csapda, origin-ellenőrzés, IP csak hash-elve, adatkezelési tájékoztató verziója és külön (nem előre bejelölt) marketing-hozzájárulás naplózva.
- Import: privát tárhely, típus- és méretellenőrzés, queue-alapú feldolgozás, sikeres import után és 24 óra elteltével automatikus fájltörlés.
- Időpontok UTC-ben tárolva; a riportok a munkaterület időzónájában csoportosítanak.

## Előfizetés és számlázás

- **Stripe (Laravel Cashier)**: a fizető ügyfél a munkaterület. Havi és éves díj (`config/plans.php`, éves = 10 havi díj), Stripe Checkout, ügyfélportál (kártyacsere, lemondás), csomag- és ciklusváltás arányosítással, lemondás az időszak végére és visszavonás.
- A próbaidő alatt indított előfizetés megtartja a hátralévő ingyenes napokat; az első terhelés a próba végén történik.
- **Webhook**: `POST /api/stripe/webhook` (Stripe Dashboardban: `customer.subscription.created|updated|deleted`, `invoice.paid`). Minden előfizetés-változás után a munkaterület csomagja szinkronizálódik; lejáratkor Free-re vált, adat nem törlődik.
- **Számlázz.hu e-számla**: minden kifizetett Stripe-számlához automatikusan (queue, újrapróbálással, Stripe-azonosító alapján idempotensen) készül magyar e-számla, PDF-je letölthető. Az ÁFA-kulcs (`BILLING_VAT_KEY`, alapértelmezés 27, alanyi mentességnél `AAM`) és az, hogy az árak bruttók-e (`BILLING_PRICES_INCLUDE_VAT`), konfigurálható.
- Számlázási adatok (név, cím, adószám, EU-adószám) kötelezők az előfizetés indítása előtt.
- `crm:send-trial-reminders`: e-mail a próba lejárta előtt 3 nappal.

Beállítás: `.env` – `STRIPE_*`, `STRIPE_PRICE_*` (4 ár: Pro/Business × havi/éves, HUF), `SZAMLAZZ_*`. Stripe-kulcs nélkül a felület jelzi, hogy az online fizetés még nincs beállítva, és a Super Admin kézzel is állíthat csomagot.
