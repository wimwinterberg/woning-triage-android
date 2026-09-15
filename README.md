# Woningtriage

Een zelfstandige Android-app die bewoners via een gesproken gesprek helpt een probleem in huis te beschrijven. De intake gebruikt **LEDO: Locatie, Element, Defect, Oorzaak** en een vervangbare beslisboom. Het gesprek begint in het Nederlands en past zich aan de taal van de gebruiker aan.

**Projectfase:** specificatie, versie 0.1 — 15 september 2026. Er is nog geen app, backend of werkende GPT-Live-koppeling geïmplementeerd. De definitieve beslisboom wordt later door Wim aangeleverd.

## Documentatie

| Document | Inhoud |
| --- | --- |
| [Projectoverzicht](docs/PROJECT_OVERVIEW.md) | Doel, scope, architectuur, fasering en open besluiten |
| [Android-app](docs/ANDROID_SPEC.md) | Schermen, interactie, spraak, toestandbeheer en acceptatiecriteria |
| [Symfony-backend](docs/BACKEND_SPEC.md) | Diensten, opslag, GPT-Live-integratie, beveiliging en beheer |
| [LEDO en beslisboom](docs/TRIAGE_SPEC.md) | Gegevensmodel, vraagselectie, correcties, taal en afronding |
| [API-contract](docs/API_CONTRACT.md) | Voorgesteld eigen API-contract tussen app en backend |
| [Acceptatie en testplan](docs/ACCEPTANCE.md) | Traceerbare scenario's en criteria voor oplevering |

Lees eerst het projectoverzicht. De twee applicatiespecificaties gebruiken hetzelfde domeinmodel en API-contract.

## Beoogde indeling bij implementatie

```text
android/       Kotlin + Jetpack Compose
backend/       Symfony API + achtergrondverwerking
docs/          Gezamenlijke specificaties
```

Deze applicatiemappen worden bij de implementatie toegevoegd. Documentatie maakt onderscheid tussen bevestigde eisen, ontwerpvoorstellen en open besluiten. Een beschreven functie is geen claim dat die al gebouwd of getest is.

## Uitgangspunten

- De backend beheert het dossier en de beslisregels.
- GPT-Live verzorgt het gesprek; feitelijke conclusies worden gevalideerd.
- Een onbekende oorzaak is een geldige uitkomst. Een vermoeden wordt geen vastgesteld feit.
- De bewoner bevestigt de exacte dossier-versie voordat de intake wordt afgerond.
- OpenAI-sleutels staan uitsluitend op de server.
- Geen echte bewonersgegevens, opnamen of geheimen in deze repository.
