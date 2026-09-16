# Woningtriage

Een zelfstandige Android-app die bewoners via een gesproken of getypt gesprek helpt een probleem in huis te beschrijven. De intake gebruikt **LEDO: Locatie, Element, Defect, Oorzaak**. Het gesprek begint in het Nederlands. Het resultaat is één melding met een Nederlandse werkomschrijving, een door de bewoner gecontroleerd adres en de tekstuele gespreksdetails.

**Projectfase:** verticale demo v1. Symfony-backend en Android-app staan in deze repository. GPT-Live en de productieclassificatieboom zijn aangesloten als contract; live providerproeven vereisen credentials die niet in git staan.

## Documentatie

| Document | Inhoud |
| --- | --- |
| [Projectoverzicht](docs/PROJECT_OVERVIEW.md) | Doel, scope, architectuur |
| [Android-app](docs/ANDROID_SPEC.md) | Schermen en acceptatie |
| [Symfony-backend](docs/BACKEND_SPEC.md) | Diensten en GPT-Live |
| [LEDO en beslisboom](docs/TRIAGE_SPEC.md) | Domeinmodel |
| [API-contract](docs/API_CONTRACT.md) | App ↔ backend |
| [OpenAPI](docs/openapi.yaml) | Machineleesbaar contract |
| [Technische keuzes](docs/IMPLEMENTATION.md) | Vastgelegde v1-keuzes |
| [DigitalOcean](docs/DIGITALOCEAN.md) | App Platform-deploy van de Symfony-API |
| [Acceptatie](docs/ACCEPTANCE.md) | Scenario's |

## Vereisten

- PHP 8.4, Composer, PostgreSQL 16 **of** Docker Compose
- JDK 17, Android SDK (compileSdk 35) voor de app
- Optioneel: `OPENAI_API_KEY` met GPT-Live-toegang

## Backend starten met Docker

```bash
cd backend
cp -n .env.example .env
docker compose up --build
```

De API luistert op http://127.0.0.1:8000 (`GET /health` moet `{"status":"ok"}` teruggeven).

In een tweede terminal:

```bash
cd backend
docker compose exec api php bin/console woningtriage:create-user --label=pilot
```

Spraak (GPT-Live) vereist `OPENAI_API_KEY` in `backend/.env` (Compose leest dat bestand; `.env.local` alleen is niet genoeg). Zonder key blijft **typen** werken. De worker blijft idle en logt geen `Skipping voice_…`. De app past geen fake-SDP toe, zodat WebRTC niet crasht op m-line-volgorde.

Na het zetten of wijzigen van de key containers opnieuw aanmaken:

```bash
cd backend
docker compose --profile live up --force-recreate --build
```

Poort 8000 bezet? `HTTP_PORT=8080 docker compose up --build`.  
Postgres is alleen bereikbaar in het Docker-netwerk (niet op localhost:5432), zodat een lokale PostgreSQL niet botst. Inspecteren: `docker compose exec database psql -U woningtriage`.

Stoppen: `docker compose down`. Data blijft in het volume `database_data`.

## Logs (spraak / “Gegevens verwerken”)

Spraak loopt via OpenAI-WebRTC. **Verwerken** (LEDO bijwerken) gebeurt in de worker `live-gateway`, niet in de HTTP-API. Typen gaat wel via `api`.

In een tweede terminal:

```bash
cd backend
docker compose --profile live logs -f --timestamps live-gateway api
```

Alleen de worker (delegatie, transcript, analysetijd):

```bash
docker compose --profile live logs -f --timestamps live-gateway
```

Alleen HTTP (typen, voice-session starten), met duur in milliseconden:

```bash
docker compose logs -f --timestamps api
```

Na een codewijziging in de gateway de worker herstarten (het PHP-proces houdt anders het oude script):

```bash
docker compose --profile live up -d --force-recreate live-gateway
```

## Telefoon via ngrok

De emulator gebruikt `http://10.0.2.2:8000/`. Een echte telefoon op 4G/wifi (niet hetzelfde LAN) bereikt je computer niet. Tunnel de API met [ngrok](https://ngrok.com/download).

1. Start de backend (`docker compose up --build` of `php -S 127.0.0.1:8000 -t public`).
2. Account + authtoken: [dashboard.ngrok.com](https://dashboard.ngrok.com/get-started/your-authtoken), daarna `ngrok config add-authtoken <token>`.
3. Tunnel:

```bash
ngrok http 8000
```

Of via Docker (zelfde token in `backend/.env` als `NGROK_AUTHTOKEN=...`):

```bash
cd backend
docker compose --profile ngrok up --build
```

De publieke HTTPS-URL staat in de ngrok-terminal of op http://127.0.0.1:4040 (eindigt op `.ngrok-free.app`).

4. Bouw de app met die URL (slash op het eind mag ontbreken):

```bash
cd android
./gradlew assembleDebug -PBACKEND_URL=https://JOUW-ID.ngrok-free.app/
```

Installeer `android/app/build/outputs/apk/debug/app-debug.apk` op de telefoon. Activatiecode: `docker compose exec api php bin/console woningtriage:create-user --label=pilot`.

De debug-app stuurt `ngrok-skip-browser-warning` mee, anders antwoordt het gratis ngrok-plan met een HTML-waarschuwing in plaats van JSON. De tunnel is publiek zolang ngrok draait; deel de URL niet.

## Backend starten zonder Docker

```bash
cd backend
cp .env.example .env
# pas DATABASE_URL aan indien nodig
composer install
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console woningtriage:import-classification \
  --file=fixtures/classification/demo-catalog.json --version=demo-ledo-1
php bin/console woningtriage:validate-tree
php bin/console woningtriage:create-user --label=pilot
php -S 127.0.0.1:8000 -t public
```

In een tweede terminal, als GPT-Live is geconfigureerd:

```bash
php bin/console woningtriage:live-gateway
```

Tests:

```bash
cd backend
php bin/phpunit
```

### Productieclassificatie

`beslisboom-prod.json` zit **niet** in deze repository (verwacht 53.115.659 bytes, SHA-256 `4ba8f60d9b2072d05ffa03e7796b0be7fefd2b4a7f9a23d3a0565f99af6d901c`). Importeer het buiten git:

```bash
php bin/console woningtriage:import-classification \
  --file=/secure/path/beslisboom-prod.json --version=beslisboom-prod-1
```

Claim niet dat de productieboom is geïmporteerd totdat hash en omvang kloppen. Gebruik nooit een oude `beslisboom.json` als vervanging.

## Android

```bash
cd android
echo "sdk.dir=/path/to/Android/sdk" > local.properties
# emulator / device: 10.0.2.2 wijst naar de host
./gradlew assembleDebug testDebugUnitTest
```

Debug-APK: `android/app/build/outputs/apk/debug/app-debug.apk`.

Standaard backend-URL is `http://10.0.2.2:8000/` (emulator). Override:

```bash
./gradlew assembleDebug -PBACKEND_URL=https://jouw-server.example/
```

Eerste start: voer de activatiecode in. Kies **Probleem melden** (spraak, microfoontoestemming) of **Liever typen**.

## DigitalOcean App Platform

De API is deploybaar op App Platform (PHP-buildpack, document root `public/`, managed PostgreSQL 16). Spec: `.do/app.yaml`. Stappen, secrets en de Android-`BACKEND_URL` staan in [docs/DIGITALOCEAN.md](docs/DIGITALOCEAN.md).

## Wat v1 wel en niet bewijst

| Onderdeel | Status |
| --- | --- |
| LEDO, correcties, onbekende oorzaak, samenvatting, één report | Getest in backend PHPUnit |
| Adreslookup nul/één/meer, storing, verificatie intrekken | Fake provider in CI |
| Idempotentie, eigendom, geen `planning_duration` in API/report | Getest |
| Nederlandse opening / Engelse zin / “okay” | Heuristic analyzer + API-test |
| GPT-Live WebRTC end-to-end | **Niet live bewezen** zonder account |
| PDOK live lookup | Geïmplementeerd; CI gebruikt fake |
| Productieboom 53 MB | Ontbreekt; fixture + importer aanwezig |
| Spoedbeleid / echte medewerker | Open (OPEN-02); demo claimt geen inschakeling |

## Architectuur

Android praat alleen met `/api/v1`. OpenAI-sleutels blijven op de server. GPT-Live gebruikt client delegation; de backend valideert feiten voordat iets “opgeslagen” mag heten.
