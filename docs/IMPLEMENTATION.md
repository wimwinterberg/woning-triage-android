# Technische keuzes v1

Vastgelegd tijdens implementatie. Producteisen blijven in de specs.

## Stack

- PHP 8.4, Symfony 8.1, Doctrine ORM 3, PostgreSQL 16
- Android 10+ (minSdk 29), Kotlin 2.1, Jetpack Compose, Material 3, compileSdk 35
- Gradle 8.11.1, Android Gradle Plugin 8.8.2

## Authenticatie (OPEN-03)

Pilottoegang via een eenmalige activatiecode (`php bin/console woningtriage:create-user`). De app wisselt die in voor een opaque bearer-token, opgeslagen in EncryptedSharedPreferences. Geen gedeeld geheim in de APK.

## GPT-Live

Handshake volgens de officiële docs (geen Realtime `/v1/realtime/calls`):

1. Android maakt een WebRTC-offer en datachannel `oai-events`.
2. Backend `POST https://api.openai.com/v1/live/sessions` met `delegation.type=client` en `transport.type=webrtc`.
3. Android past `transport.sdp` toe als answer.
4. Worker `woningtriage:live-gateway` koppelt een sideband op `wss://api.openai.com/v1/live/sessions/{id}/attach`.
5. Na attach (de sessie loopt al) stuurt de worker een begroeting: `session.instructions.append`, wacht op `session.instructions.appended`, daarna `session.commentary.append` met de gesproken welkomsttekst. De app stuurt dezelfde groet op `oai-events` zodra het datachannel open is; de worker slaat een dubbele groet over als er al output is.
6. Bij `session.delegation.created` analyseert de backend het dossier en stuurt `session.commentary.append` met de volgende vraag om hardop te zeggen.
   Sideband-frames worden gelezen via `Message::getContent()` (niet `(string)$message`; dat is de classnaam).

Zonder `OPENAI_API_KEY` blijft tekstintake werken. Een fake SDP is geen live-bewijs; de API zet `live: false` en de app past het antwoord niet toe. `APP_ENV=dev` (Docker) forceert de fake **niet** als de key gezet is. De live-gateway slaat `prov_fake_*`-sessies over en blijft idle zonder skip-spam. Na een nieuwe key: `docker compose --profile live up --force-recreate`.

Gespreksprompt: `App\Live\ConversationPrompt` (versie `conversation-v6`), Nederlands, alleen huurwoningen, begroet meteen. Analyzer: deterministische heuristic voor CI/demo; geen verzonnen oorzaak. Gesproken "klopt"/"ja" bevestigt adres of samenvatting; daarna zegt de backend de echte samenvatting en na de tweede bevestiging "De melding is vastgelegd."

## Adres (OPEN-10)

Providerinterface met:

- **We Create Solutions Address API** (`GET https://address-api.createsolutions.dev/v1/postcode/{postalCode}/{houseNumber}`, Bearer `WCS_ADDRESS_API_KEY`). Alleen **Nederland** (`country=nl`); BE/DE-resultaten en kandidaten met een andere postcode of huisnummer worden genegeerd. 200 geeft kandidaten; 404 is een lege lijst; 401/429/503/500 is `address_lookup_unavailable`. Meerdere units komen via `houseLetter` / `houseNumberAddition`. Fake-resultaten (`Voorbeeldstraat`) mogen niet als live BAG worden gepresenteerd. Address-lookup logs gaan naar STDERR (`docker compose logs -f api live-gateway`) zonder postcode, huisnummer of straat. Bij “nee” / verkeerde postcode tijdens bevestigen wordt de lookup gewist en opnieuw gevraagd.
- **FakeAddressProvider** alleen in tests (`when@test`). Live/dev gebruikt altijd WCS. Zet `WCS_ADDRESS_API_KEY` in `backend/.env` (niet alleen `.env.local`) en recreate: `docker compose --profile live up --force-recreate`. De live-gateway logt bij start `Address lookup provider=App\Address\WcsAddressProvider`.

## Beslisboom

Conversatieboom: `backend/config/trees/demo-ledo-1.json` (LEDO + adres + samenvatting).  
Classificatiecatalogus: importer `woningtriage:import-classification`. Productiebestand `beslisboom-prod.json` (53.115.659 bytes, SHA-256 `4ba8f60d9b2072d05ffa03e7796b0be7fefd2b4a7f9a23d3a0565f99af6d901c`) ontbreekt in deze repository. Er is een kleine fixture `backend/fixtures/classification/demo-catalog.json`. `planning_duration` wordt hoogstens als bronmetadata bewaard en nooit in API, prompt of report gezet.

## Docker (lokaal)

- `backend/compose.yaml` start `api` (PHP 8.4 built-in server op poort 8000) en PostgreSQL 16.
- Eerste start: `cd backend && docker compose up --build`. Entrypoint wist Symfony-cache, warmt hem opnieuw en draait `woningtriage:release`. `api` en `live-gateway` hebben elk een eigen cache-volume, zodat een oude `var/cache/prod` op de host de worker niet laat crashen.
- Lokale Compose pinnet `APP_ENV=dev` en start de API via `docker/router.php` (bind-mount), zodat `php -S` geen tweede JSON-body achter `POST /intakes` plakt. In de logs moet `WONINGTRIAGE_ENTRYPOINT=2` staan.
- Live-gateway: `OPENAI_API_KEY` in `backend/.env`, daarna `docker compose --profile live up --force-recreate`.
- Logs: `docker compose --profile live logs -f live-gateway api`. Spraakdelegatie staat in `live-gateway`; HTTP-timing in `api`.
- Telefoon: `ngrok http 8000`, daarna APK met `-PBACKEND_URL=https://….ngrok-free.app/`. De app zet `ngrok-skip-browser-warning` op die hosts.

## DigitalOcean App Platform

- PHP-buildpack (heroku-buildpack-php): `backend/Procfile` start `heroku-php-nginx -C nginx_app.conf public/`.
- Monorepo: `.do/app.yaml` zet `source_dir: backend`, regio `ams`, PostgreSQL 16, PRE_DEPLOY `woningtriage:release`.
- `ext-pdo_pgsql`, `ext-intl`, `ext-mbstring` en `ext-xml` staan in `composer.json` zodat de buildpack ze inschakelt.
- Trusted proxies in prod: `REMOTE_ADDR` + `PRIVATE_SUBNETS` (load balancer).
- Live-gateway-queue leest open `VoiceSession`-rijen uit PostgreSQL; web en worker delen geen schijf.

## Overig

- Revisies + `Idempotency-Key`; één report per intake (`uniq_report_intake`).
- SSE in testdmodus stuurt gemiste events en sluit; productie pollt keepalives.
- Android stopt microfoon bij `onStop` tenzij configuratiewijziging (rotatie).
