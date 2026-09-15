# API-contract Android ↔ Symfony

Versie 0.1 · 15 september 2026 · Status: voorgesteld eigen applicatiecontract, nog niet geïmplementeerd.

**Dit zijn geen OpenAI-endpoints.** De backend schermt het providerprotocol af. Exacte GPT-Live-handshake en providerconfiguratie worden in fase 1 vastgesteld. De voorbeelden zijn fictief.

Gerelateerd: [Android](ANDROID_SPEC.md), [backend](BACKEND_SPEC.md), [domein](TRIAGE_SPEC.md).

## 1. Conventies

- Basis: `/api/v1`; JSON met UTF-8, behalve de eventstream.
- Authenticatie: `Authorization: Bearer <app-access-token>`; uitgiftemethode is OPEN-03.
- Iedere dossierroute controleert eigendom. Gebruik `404` voor dossiers buiten toegang om bestaan niet te onthullen.
- Tijdstippen: RFC 3339 in UTC. IDs: opaque strings; clients ontleden ze niet.
- Muterende POST/PATCH/DELETE-verzoeken gebruiken `Idempotency-Key`.
- Inhoudelijke mutaties gebruiken `expected_revision`; creëren en voice-lifecycleacties zijn uitzonderingen.
- Idempotentie wordt vóór revisiecontrole afgehandeld bij herhaling van exact hetzelfde verzoek.
- Responses mogen nieuwe optionele velden krijgen; breaking changes krijgen een nieuwe contractversie.
- Voorgestelde limiet bewonersbericht: 4.000 Unicode-codepoints. Overige payload-, rate- en sessielimieten zijn configuratie en vóór pilot vast te leggen.

Iedere fout heeft een machineleesbare code, gebruikersvriendelijke tekst en `request_id`. Geen stacktrace of providersecret.

## 2. Routes

| Methode | Route | Doel | Succes |
| --- | --- | --- | --- |
| POST | `/intakes` | Nieuw dossier | `201` + intake |
| GET | `/intakes/{id}` | Actuele volledige toestand | `200` + intake |
| POST | `/intakes/{id}/messages` | Getypt antwoord analyseren | `202` + taak |
| PATCH | `/intakes/{id}/fields` | Expliciete veldcorrectie | `200` + intake |
| PATCH | `/intakes/{id}/language` | Automatisch/handmatig taalgedrag | `200` + intake |
| POST | `/intakes/{id}/summaries` | Samenvatting aanvragen | `202` + taak |
| POST | `/intakes/{id}/confirmations` | Exacte samenvatting bevestigen | `200` + intake |
| POST | `/intakes/{id}/cancel` | Dossier annuleren | `200` + intake |
| POST | `/intakes/{id}/voice-sessions` | Spraakverbinding aanvragen | `201` + eigen sessieconfiguratie |
| GET | `/intakes/{id}/voice-sessions/{sessionId}` | Verbindingsstatus ophalen | `200` + voice session |
| POST | `/intakes/{id}/voice-sessions/{sessionId}/stop` | Opnameverbinding beëindigen | `202` + voice session |
| GET | `/intakes/{id}/tasks/{taskId}` | Analyse-/samenvattingstaak opvragen | `200` + taak |
| GET | `/intakes/{id}/events` | Server-events volgen | `200`, `text/event-stream` |

Verwijderen van persoonsgegevens is een afzonderlijke te specificeren beheeractie afhankelijk van OPEN-05. Annuleren is uitdrukkelijk geen verwijderverzoek.

## 3. Nieuw dossier

```http
POST /api/v1/intakes
Authorization: Bearer <app-access-token>
Idempotency-Key: <unique-request-key>
Content-Type: application/json
```

```json
{
  "input_mode": "voice"
}
```

`input_mode`: `voice` of `text`. De server bepaalt de actieve boom en standaardtaal; de client kan geen ongepubliceerde boom kiezen.

Voorbeeldresponse `201`:

```json
{
  "id": "intake_example",
  "revision": 0,
  "status": "collecting",
  "tree_version": "demo-ledo-1",
  "demo": true,
  "conversation_language": "nl-NL",
  "language_mode": "auto",
  "fields": {
    "location": {"value": null, "state": "missing", "source": null, "evidence_ids": []},
    "element": {"value": null, "state": "missing", "source": null, "evidence_ids": []},
    "defect": {"value": null, "state": "missing", "source": null, "evidence_ids": []},
    "cause": {"value": null, "state": "missing", "source": null, "evidence_ids": []}
  },
  "answers": [],
  "hypotheses": [],
  "risk": {"state": "unassessed", "rule_ids": [], "evidence_ids": []},
  "next_question": {"id": "opening", "target": null, "text": "Wat is er aan de hand in uw woning?"},
  "summary": null,
  "active_tasks": [],
  "created_at": "2026-09-15T12:00:00Z",
  "updated_at": "2026-09-15T12:00:00Z",
  "confirmed_at": null
}
```

GET retourneert hetzelfde model. `source` is waar van toepassing `user_message` of `user_correction`; `reported` betekent door de bewoner opgegeven. Mogelijke verklaringen horen in `hypotheses`, niet als door gebruiker gemelde oorzaak.

## 4. Getypt antwoord

```json
{
  "expected_revision": 0,
  "client_message_id": "message_example",
  "text": "De keukenkraan druppelt sinds gisteren."
}
```

Response `202`:

```json
{
  "task_id": "task_example",
  "status": "pending",
  "base_revision": 0
}
```

Een geaccepteerde taak is nog geen gevalideerd dossierantwoord. Bij afronden verschijnt `intake.updated` en eventueel `assistant.message`. Dezelfde bericht-ID met andere tekst is een conflict. Lege of te lange input geeft `422`.

Voorstel MVP: maximaal één gewone analyse-/samenvattingstaak per intake tegelijk. Een tweede nieuw tekstbericht krijgt `409 intake_busy`; de app laat het concept staan. Een expliciete correctie mag de lopende taak supersederen. GPT-Live kan ondertussen blijven luisteren; delegaties worden door de gateway samengevoegd of opeenvolgend verwerkt.

Taakstatussen: `pending`, `running`, `succeeded`, `failed`, `superseded`, `cancelled`. GET op een taak geeft bij succes ook `result_revision`; bij een oude bronrevisie volgt `superseded`, geen stille overschrijving.

## 5. Veldcorrectie

PATCH `/fields`:

```json
{
  "expected_revision": 3,
  "changes": [
    {"field": "location", "action": "set", "value": "Badkamer, bij de wastafel"},
    {"field": "cause", "action": "mark_unknown"}
  ]
}
```

Toegestane velden: `location`, `element`, `defect`, `cause`. Acties: `set` met niet-lege `value`, `mark_unknown` zonder value en `clear` zonder value. `clear` maakt een veld `missing`; `mark_unknown` maakt het `unknown` met `value: null`.

De server valideert lengte en acties, voegt gebruikersherkomst toe, past alle wijzigingen atomair toe en markeert afhankelijkheden. De volledige nieuwe intake wordt teruggegeven. De client kan `risk`, `status`, bronverwijzingen of een technisch bewezen oorzaak niet via deze route instellen.

Lopende taken op de vorige revisie worden `superseded`. Een bestaande samenvatting wordt ongeldig. Een bevestigd of geannuleerd dossier geeft `409 intake_locked`.

## 6. Taal

PATCH `/language` voor handmatige keuze:

```json
{
  "expected_revision": 4,
  "mode": "manual",
  "language": "en-GB"
}
```

Terug naar automatisch gebruikt `mode: "auto"` zonder `language`. Dat reset de bestaande gesprekstaal niet blind naar Nederlands; de volgende bewuste bewonersspraak stuurt de taal. Alleen een nieuw gesprek begint opnieuw Nederlands.

De backend valideert de tag, maakt een nieuwe revisie en werkt de voicecontext bij. Automatische taalvoorstellen van de analyzer doorlopen dezelfde regels. Een taalwijziging maakt een oude samenvatting ongeldig omdat de bevestigingstekst kan wijzigen.

## 7. Samenvatting en bevestiging

POST `/summaries` met `expected_revision` start een taak. Alleen toegestaan als de boom compleetheid toestaat en er geen blokkerende analyse of beoordeling loopt. Bij onvoldoende informatie: `422 intake_incomplete` met ontbrekende veld-/vraag-ID's.

Een geslaagde samenvatting maakt binnen één transactie een nieuwe revisie en zet `ready_for_confirmation`. `summary.source_revision` verwijst naar die resulterende dossier-versie. Het schrijven van een samenvatting mag geen inhoudelijke wijziging die intussen plaatsvond overschrijven.

Voorbeeld van het summary-object in de resulterende GET-response:

```json
{
  "id": "summary_example",
  "source_revision": 7,
  "language": "en-GB",
  "resident_text": "The kitchen tap has been dripping from the spout since yesterday, even when closed. The cause is unknown.",
  "work_description_nl": "Keukenkraan druppelt sinds gisteren uit de uitloop terwijl deze dichtgedraaid is. Oorzaak onbekend."
}
```

POST `/confirmations`:

```json
{
  "expected_revision": 7,
  "summary_id": "summary_example"
}
```

Bij succes wordt het dossier `confirmed` met revisie 8 en `confirmed_at`. De bevestigingsregistratie bewaart bronrevisie 7 en summary-ID. Response `200` betekent opgeslagen intake; er is geen automatische werkbonverzending in dit contract.

`409 summary_stale` of `revision_conflict` vereist een nieuwe beoordeling. Een retry van hetzelfde succesvolle verzoek met dezelfde idempotentiesleutel retourneert het originele succesvolle resultaat, ook al is de actuele revisie inmiddels verhoogd.

## 8. Voice session

POST `/voice-sessions`:

```json
{
  "sdp_offer": "<SDP generated by the Android WebRTC client>"
}
```

Voorgestelde response `201`:

```json
{
  "id": "voice_example",
  "intake_id": "intake_example",
  "status": "connecting",
  "transport": "webrtc",
  "sdp_answer": "<SDP returned through the backend>",
  "expires_at": "2026-09-15T12:15:00Z"
}
```

Het tijdstip is illustratief, geen OpenAI-sessielimiet. De backend stelt het in volgens de feitelijke provider- en projectconfiguratie. Een verlopen verbinding vraagt een nieuwe sessie; een nieuwe SDP-offer krijgt een nieuwe idempotentiesleutel.

Statussen: `connecting`, `active`, `closing`, `closed`, `failed`. Maximaal één niet-afgesloten voice session per intake. Bij een nieuwe verbinding worden oude sessies expliciet beëindigd; niet stilzwijgend naast elkaar gehouden. De backend leest bij start de actuele dossiercontext, dus er is geen `expected_revision` nodig.

GET van een sessie bevat status, afsluitreden en `usage_finalized`, maar geen SDP, geheimen of ruwe providerpayload. POST `/stop` heeft een lege JSON-body en sluit alleen de voice session. De app stopt de microfoon zelf onmiddellijk; serverfinalisatie is asynchroon. Een al gesloten sessie stoppen is veilig herhaalbaar.

**Te bewijzen in technische proef:** exacte OpenAI-sessie-aanmaak, koppeling van servergateway, eventkanaal en WebRTC-library. Bij afwijkingen moet dit eigen transportcontract worden bijgewerkt vóór Android daarop wordt gebouwd.

## 9. Annuleren

POST `/cancel` gebruikt `expected_revision`. De server zet `cancelled`, blokkeert inhoudelijke taken en sluit actieve voice sessions. Laat binnenkomende oude resultaten geen wijzigingen meer toepassen.

Gewoon een gesprek stoppen gebruikt de voice-stoproute, niet `/cancel`. Zo blijft hervatten mogelijk. Bevestigde dossiers kunnen niet via `/cancel` teruggedraaid worden.

## 10. Eventstream

GET `/events` gebruikt Server-Sent Events met bearer-authenticatie. Android gebruikt een HTTP-client die headers en herverbinden ondersteunt. Events hebben een per intake oplopende `sequence` en een dossier-`revision` waar relevant.

Voorbeeld:

```text
id: 42
event: intake.updated
data: {"sequence":42,"intake_id":"intake_example","revision":5}
```

| Event | Payload / effect |
| --- | --- |
| `intake.updated` | Revisie gewijzigd; app haalt canonieke intake op |
| `assistant.message` | Eigen message-ID, tekst, taal; dedupliceren op message-ID |
| `task.updated` | Taak-ID en status; app kan resultaat ophalen |
| `voice_session.updated` | Eigen sessie-ID en status |
| `session.reset_required` | Eventhistorie niet meer beschikbaar; volledige GET en nieuwe stream |

Gebruik `Last-Event-ID` voor herstel. Onbekende/te oude cursor leidt tot expliciete reset, niet tot stil missen van updates. Duplicaten zijn toegestaan en worden op sequence/ID verwerkt. Geen aanname dat transcripttekst gelijkstaat aan afgespeelde audio.

Android ontvangt voorlopige voice-transcriptfragmenten via de voice-adapter. Een canoniek backendbericht en een voorlopige weergave mogen niet dubbel getoond worden. Correlatie wordt in fase 1 uitgewerkt zonder te veronderstellen dat providerfragmenten altijd stabiele bericht-ID's bezitten.

## 11. Fouten

```json
{
  "error": {
    "code": "revision_conflict",
    "message": "De gegevens zijn ondertussen gewijzigd. Laad de actuele intake opnieuw.",
    "request_id": "request_example",
    "current_revision": 6
  }
}
```

| HTTP | Voorbeelden |
| --- | --- |
| `400` | Ongeldig JSON of ontbrekende verplichte header |
| `401` | Toegangstoken ontbreekt/verlopen |
| `404` | Intake, taak of sessie niet aanwezig of niet toegankelijk |
| `409` | Revisieconflict, idempotentieconflict, stale summary, intake busy/locked |
| `413` | Verzoek groter dan toegestane payload |
| `422` | Ongeldige waarde, incomplete intake of ongeldige statusovergang |
| `429` | Gebruiks-/snelheidslimiet; eventueel `Retry-After` |
| `502` / `503` | Provider- of backendafhankelijkheid niet beschikbaar |

Android vertaalt machinecodes naar ondersteunde UI-talen. Retry uitsluitend wanneer de bewerking veilig herhaalbaar is; behoud dezelfde sleutel na een onbekende uitkomst.

## 12. Contractverificatie bij implementatie

Dit leesbare contract wordt omgezet naar OpenAPI en gedeelde fixtures vóór het schrijven van productieverzoeken. Valideer minimaal enums, nullability, foutcodes, objecteigendom, idempotentie, datumformaat en samenvattingrevisies. Providerpayloads horen niet in die publieke fixtures.
