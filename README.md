# ÜgyfélFlow CRM – backend (Laravel API)

> Munkanév: **ÜgyfélFlow** (VállalkozóOS). A végleges név az `APP_NAME` környezeti változóval állítható.

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
| Super Admin | `admin/stats`, `admin/workspaces`, `admin/users`, `admin/audit-logs` (`php artisan crm:super-admin email@cim`) |

## Biztonsági alapelvek

- Minden munkaterületi rekord `workspace_id`-hoz kötött; a `ResolveWorkspace` middleware minden kérésnél ellenőrzi a tagságot (`X-Workspace-Id` fejléc), a globális scope pedig a route model bindinget is a munkaterületre szűkíti (idegen ID → 404).
- Szerepkörök: Owner, Admin, Member, Viewer (a Viewer csak olvashat). A Super Admin külön jogosultság.
- Csomaglimitek és funkciók szerveroldalon (`config/plans.php`, `PlanService`); a próba lejárata Free-re vált, adat nem törlődik (402-es válasz `plan_limit` / `feature_not_available` kóddal).
- Publikus űrlap: rate limit, szerveroldali validáció, honeypot + időzítési csapda, origin-ellenőrzés, IP csak hash-elve, adatkezelési tájékoztató verziója és külön (nem előre bejelölt) marketing-hozzájárulás naplózva.
- Import: privát tárhely, típus- és méretellenőrzés, queue-alapú feldolgozás, sikeres import után és 24 óra elteltével automatikus fájltörlés.
- Időpontok UTC-ben tárolva; a riportok a munkaterület időzónájában csoportosítanak.

Stripe-integráció a későbbi mérföldkőben (a Super Admin addig kézzel állítja a csomagot).
