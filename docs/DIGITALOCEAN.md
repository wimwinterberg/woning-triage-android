# DigitalOcean App Platform

De Symfony-API in `backend/` is klaar voor [App Platform](https://docs.digitalocean.com/products/app-platform/) via de PHP-buildpack (Heroku PHP buildpack v281, document root `public/`).

## Wat er klaarstaat

| Bestand | Rol |
| --- | --- |
| `.do/app.yaml` | App spec: API, PostgreSQL 16, PRE_DEPLOY-migraties, live-gateway worker |
| `backend/Procfile` | `heroku-php-nginx` op `public/`, release- en worker-processen |
| `backend/nginx_app.conf` | Symfony front controller (`try_files` → `index.php`) |
| `backend/composer.json` | PHP `^8.4` plus `ext-pdo_pgsql` / `ext-intl` / `ext-mbstring` / `ext-xml` |
| `php bin/console woningtriage:release` | Migraties, demo-catalogus, boomvalidatie |

Android zit niet in deze deploy. Bouw de APK lokaal met `-PBACKEND_URL=https://<jouw-app>.ondigitalocean.app/`.

## App aanmaken

1. Koppel de GitHub-repo in DigitalOcean (App Platform → **Create App** → GitHub).
2. Kies deze repository en de branch die je wilt deployen (`main` na merge, of de feature-branch tijdens test). Gebruik spec-bestand `.do/app.yaml`.
3. Vervang in het control panel (of in de spec vóór `doctl`) de drie `APP_SECRET`-waarden door dezelfde geheime string:

   ```bash
   openssl rand -hex 32
   ```

4. Controleer dat elk component `source_dir: backend` heeft, zodat de PHP-buildpack `composer.json` en de `Procfile` vindt.

5. Na de eerste deploy secrets invullen in de app-settings:
   - `WCS_ADDRESS_API_KEY` — We Create Solutions Address API (postcode-lookup). Zonder key geven adresopzoekingen `503`.
   - `OPENAI_API_KEY` — GPT-Live. Zonder key blijft tekstintake werken; de worker blijft idle.

6. Maak een activatiecode via **App Platform → jouw app → Console** (component `api`):

   ```bash
   php bin/console woningtriage:create-user --label=pilot
   ```

   De code verschijnt één keer. Bewaar hem; hij wordt niet in plaintext opgeslagen.

## Verplichte runtime-variabelen

De spec zet deze al. Controleer ze in het control panel:

| Variabele | Waarde |
| --- | --- |
| `APP_ENV` | `prod` |
| `APP_DEBUG` | `0` |
| `APP_SECRET` | eigen hex-secret (niet de placeholder) |
| `DATABASE_URL` | `${db.DATABASE_URL}` (managed PostgreSQL, inclusief `sslmode=require`) |
| `DEFAULT_URI` | `${api.PUBLIC_URL}` |
| `CORS_ALLOW_ORIGIN` | `^https://.*$` (native Android gebruikt geen CORS) |
| `WCS_ADDRESS_API_KEY` | Address API-sleutel (secret; leeg = adreslookup `503`) |

Doctrine pinnet PostgreSQL **16** in `config/packages/doctrine.yaml`, zodat de DigitalOcean-URL geen `serverVersion` hoeft te bevatten.

## Health check

`GET /health` en `GET /api/v1/health` zijn publiek en raken de database niet. App Platform gebruikt `/health`.

## Live-gateway worker

Web en worker delen geen schijf. Open voice-sessions worden uit PostgreSQL gelezen (`LiveGatewayCommandQueue`). Zonder stem mag je de `live-gateway` worker in `.do/app.yaml` weglaten om kosten te schelen.

## Kosten (indicatie)

De spec gebruikt `basic-xxs` plus een **dev**-database (`production: false`). Dat is een ontwikkelcluster, niet HA. Voor productie: database `production: true` en eventueel grotere instance sizes.

## Android

```bash
cd android
./gradlew assembleDebug -PBACKEND_URL=https://woningtriage-<hash>.ondigitalocean.app/
```

Gebruik HTTPS, inclusief trailing slash. Eerste start: activatiecode uit `woningtriage:create-user`.
