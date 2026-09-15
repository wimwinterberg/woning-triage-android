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
5. Bij `session.delegation.created` analyseert de backend het dossier en stuurt `session.commentary.append`.

Zonder `OPENAI_API_KEY` blijft tekstintake werken. Een fake SDP is geen live-bewijs.

Gespreksprompt: `App\Live\ConversationPrompt` (versie `conversation-v1`). Analyzer: deterministische heuristic voor CI/demo; geen verzonnen oorzaak.

## Adres (OPEN-10)

Providerinterface met:

- **PDOK Locatieserver v3.1** (`/free`, `fq=type:adres`) — officiële Nederlandse BAG-zoekdienst, geen API-key.
- **FakeAddressProvider** voor tests en lokale demo. Resultaten zijn fictief (`Voorbeeldstraat`) en mogen niet als live BAG worden gepresenteerd.

## Beslisboom

Conversatieboom: `backend/config/trees/demo-ledo-1.json` (LEDO + adres + samenvatting).  
Classificatiecatalogus: importer `woningtriage:import-classification`. Productiebestand `beslisboom-prod.json` (53.115.659 bytes, SHA-256 `4ba8f60d9b2072d05ffa03e7796b0be7fefd2b4a7f9a23d3a0565f99af6d901c`) ontbreekt in deze repository. Er is een kleine fixture `backend/fixtures/classification/demo-catalog.json`. `planning_duration` wordt hoogstens als bronmetadata bewaard en nooit in API, prompt of report gezet.

## Overig

- Revisies + `Idempotency-Key`; één report per intake (`uniq_report_intake`).
- SSE in testdmodus stuurt gemiste events en sluit; productie pollt keepalives.
- Android stopt microfoon bij `onStop` tenzij configuratiewijziging (rotatie).
