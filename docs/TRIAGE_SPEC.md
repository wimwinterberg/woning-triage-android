# LEDO, gesprek en beslisboom

Versie 0.2 · 15 september 2026 · Status: domeinontwerp. De productieclassificatieboom is aangeleverd; expliciete vervolgvragen en spoedregels blijven aanvullend.

## 1. Begrippen

| Begrip | Definitie |
| --- | --- |
| Locatie | Ruimte en specifieke plek van het probleem; niet automatisch het woonadres |
| Element | Betrokken bouwdeel, installatie of onderdeel |
| Defect | Waarneembare afwijking, inclusief omstandigheden en gevolgen |
| Oorzaak | Door gebruiker gemelde aanleiding of verklaring; kan onbekend of vermoed zijn |
| Intake | Eén dossier over één primair probleem |
| Gesprek | Interactie in één of meer technische spraaksessies binnen een intake |
| Beslisboom | Versiegebonden regels voor ontbrekende gegevens, vragen en uitkomsten |
| Samenvatting | Presentatie van een specifieke gevalideerde dossier-versie |
| Bevestiging | Expliciete gebruikersactie waarmee die samenvatting wordt geaccepteerd |

De bron van informatie bepaalt wat we mogen zeggen. De bewoner kan een oorzaak melden, maar dat maakt deze niet onafhankelijk technisch vastgesteld. Een modelvermoeden blijft apart van bewonersinformatie.

## 2. Canonieke gegevens

Voorstel voor het dossiermodel:

| Veld | Type / betekenis |
| --- | --- |
| `id` | Opaque intake-ID |
| `revision` | Oplopend geheel getal; iedere duurzame wijziging verhoogt dit |
| `status` | `collecting`, `review_required`, `ready_for_confirmation`, `confirmed`, `cancelled` |
| `tree_version` | Vastgezette versie van gepubliceerde beslisboom |
| `conversation_language` | BCP-47-tag, begint als `nl-NL` |
| `language_mode` | `auto` of `manual` |
| `location`, `element`, `defect`, `cause` | LEDO-veldobjecten |
| `answers` | Aanvullende boomantwoorden met vaste vraag-/slot-ID's |
| `hypotheses` | Eventuele verklaringen die niet als feit mogen gelden |
| `risk` | Beoordelingsstatus, regel-ID's en herkomst; standaard `unassessed` |
| `next_question` | Vraag-ID, doelveld en geformuleerde vraag of `null` |
| `summary` | ID, bronrevisie, gesprekstaaltekst en Nederlandse werkomschrijving |
| `created_at`, `updated_at`, `confirmed_at` | UTC-tijden; laatste kan `null` zijn |

Ieder LEDO-veld heeft `value` (string of `null`), `state`, `source` en `evidence_ids`. Voorstel voor `state`: `missing`, `reported`, `unknown`, `needs_review`. Bewijs verwijst naar een bewonersantwoord of correctie; niet naar een zelfbedachte gedachte van het model.

`missing` betekent nog niet uitgevraagd. `unknown` betekent dat de bewoner expliciet aangeeft het niet te weten. `reported` betekent opgegeven, niet technisch bewezen. `needs_review` betekent dat een wijziging of tegenspraak herbeoordeling vereist.

Een lege string wordt niet gebruikt als verborgen equivalent van onbekend. De oorzaak mag `unknown` zijn zonder afronding te blokkeren. Bij onbekende locatie of element bepaalt de boom of menselijke beoordeling nodig is.

## 3. Gespreksregels

1. Begin een nieuw gesprek in het Nederlands met een korte uitleg en open vraag.
2. Laat de bewoner eerst beschrijven wat er gebeurt.
3. Haal alle bruikbare LEDO-feiten uit één antwoord; dwing geen vier vaste formulierturns af.
4. Stel één korte vervolgvraag gericht op ontbrekende of tegenstrijdige informatie.
5. Vraag niet opnieuw naar informatie die al duidelijk en nog geldig is.
6. Vraag bij dubbelzinnigheid door; verzin geen ruimte, element, hoeveelheid of oorzaak.
7. Neem correcties over en herbeoordeel afhankelijkheden.
8. Houd veiligheids-/spoedsignalen tijdens alle stappen in het oog.
9. Geef geen risicovolle reparatie-instructies. De functie is intake, geen zelfstandige reparatiebegeleiding.
10. Vat samen en laat de bewoner corrigeren voordat de app bevestiging accepteert.

Voorbeeld: “De keukenkraan druppelt sinds gisteren.” Dit levert locatie keuken, element kraan, defect druppelen en tijdsinformatie op. Het levert geen bewezen oorzaak op. Een vervolgvraag kan gaan over druppelen uit de uitloop of lekken bij de aansluiting, als die vraag in de boom staat.

## 4. Vraagselectie en beslisboom

De backend selecteert de route; het taalmodel mag de vraag natuurlijk formuleren zonder de betekenis te veranderen of meerdere extra vragen toe te voegen.

Een boomversie bevat minimaal:

- Unieke versie, status `draft`/`published`, en inhoudseigenaar.
- Een beginpunt en benoemde knopen met stabiele ID's.
- Per vraag: doelveld, semantische vraag, toegestane antwoordtypen en validatie.
- Expliciete overgangen voor bekende antwoorden, `unknown` en onduidelijkheid.
- Regels voor toepasbaarheid, noodzakelijke voorgangers en ongeldig worden bij correcties.
- Terminale uitkomsten: samenvatting mogelijk, menselijke beoordeling of buiten bereik.
- Een maximum voor herhaalde verduidelijkingen en detectie van onbedoelde lussen.

Voorstel voor conditieoperatoren: `exists`, `equals`, `in`, `all`, `any`. Geen PHP, JavaScript, SQL of `eval` in aangeleverde boomdata. Een publicatievalidator controleert knoopverwijzingen, bereikbaarheid, terminale routes, antwoordtypen en ontbrekende onbekend-paden.

Er is nog geen definitief JSON-schema voor de aangeleverde boom: dat wordt afgestemd op Wims bronformaat. Het uitvoeringsmodel hierboven is het ontwerpcontract, geen aanname over zijn bestaande vragenlijst.

### Tijdelijke demonstratieroute

Een demo kan de vier LEDO-velden uitvragen, oorzaak onbekend toestaan, één correctie verwerken en een samenvatting maken. Eventuele lekkagevragen zijn expliciet demo-inhoud. De demo stelt geen definitieve urgentie vast en mag niet voor een echte bewonerspilot worden vrijgegeven zolang OPEN-01 en OPEN-02 openstaan.

## 5. Correcties en afhankelijkheden

Ieder analysevoorstel is gebonden aan de dossier-revisie waarop het gebaseerd is. Als de gebruiker ondertussen corrigeert, wordt een later binnenkomend voorstel eerst opnieuw gevalideerd of verworpen.

Voorbeeld: keuken → badkamer. De locatie wordt aangepast. Informatie over een keukenkraan kan daardoor niet meer zonder controle worden gebruikt. Een algemeen feit zoals “sinds gisteren” hoeft niet te verdwijnen als het nog op hetzelfde probleem slaat.

Gebruik een expliciet afhankelijkheidsmodel: afgeleide waarden die afhangen van een gewijzigd antwoord krijgen `needs_review`; onafhankelijk gemelde waarnemingen blijven bewaard. De backend wist niet blind alle velden na ieder eerder veld.

Correcties bewaren een auditverwijzing. Een geldige correctie maakt een bestaande samenvatting ongeldig en verwijdert de mogelijkheid om die oude versie te bevestigen. Een bevestigd dossier blijft in versie 1 alleen-lezen; latere aanvullende informatie vraagt een nieuwe intake of toekomstige revisiefunctie.

## 6. Taalregels

| Situatie | Gedrag |
| --- | --- |
| Nieuw gesprek, gebruiker heeft nog niets gezegd | Nederlands beginnen |
| Gebruiker spreekt een duidelijke zin in een andere taal | Volgende reactie in die taal |
| Los leenwoord, merknaam of “okay” | Huidige gesprekstaal behouden |
| Gebruiker vraagt expliciet om andere taal | Meteen overschakelen en voorkeur vastleggen |
| Onzekere herkenning of gemengde taal | Kort verduidelijken; geen herhaald heen-en-weer wisselen |
| Achtergrondtelevisie / andere spreker | Niet als bewuste taalkeuze behandelen |
| Herstel van hetzelfde gesprek | Laatste gevalideerde taal behouden |
| Nieuwe intake | Opnieuw Nederlands als opening |

Taalherkenning mag niet gebaseerd zijn op naam, adres, nationaliteit of toestelregio. Vastleggen van de taal gebeurt via een gevalideerd voorstel; er wordt geen niet-bestaande numerieke zekerheid van de provider aangenomen.

De Nederlandse werkomschrijving moet de betekenis behouden. Merknamen, foutcodes, hoeveelheden en ontkenningen worden niet vrij herschreven. De originele bewonersformulering blijft als bron beschikbaar conform bewaarbeleid. Bij onduidelijke vertaling volgt een gerichte vraag of menselijke beoordeling.

Gesprekskwaliteit per taal is een testresultaat, geen generieke garantie van “alle talen ondersteund”.

## 7. Gevaar en menselijke beoordeling

Voorstel: risicostaten `unassessed`, `no_signal_detected`, `review_required`, `urgent_review`. “Geen signaal gedetecteerd” betekent niet dat de woning veilig is verklaard.

Definitieve detectiecriteria, urgentieklassen, teksten en telefoonnummers moeten inhoudelijk worden aangeleverd en beoordeeld. Bij een signaal kan de backend normale vraagselectie blokkeren, de status `review_required` zetten en een toepasselijke contacttekst teruggeven.

Een model kan signalen voorstellen, maar alleen vastgestelde regels bepalen de formele classificatie. Bij twijfel kan het systeem naar menselijke beoordeling gaan. De bewoner krijgt geen opdracht om elektrische installaties te openen, gascomponenten te manipuleren of constructies te onderzoeken.

Deze regels maken AI-detectie niet volledig of onfeilbaar. Voor een pilot is een beoordeelde testset met gevaarsignalen en fout-negatieven noodzakelijk. Concrete noodinstructies worden pas toegevoegd op basis van vastgesteld inhoudelijk beleid.

## 8. Compleetheid en bevestiging

`ready_for_confirmation` is alleen toegestaan als:

- De boom een geschikte terminale uitkomst heeft bereikt.
- Verplichte informatie een toegestane status heeft.
- Er geen blokkerende `needs_review`-waarden of lopende mutaties zijn.
- De oorzaak is opgegeven of expliciet onbekend, indien de route die vraagt.
- Geen spoed-/beoordelingsroute normale afronding blokkeert.
- Het volledige adres via de backend is opgezocht en de bewoner de actuele adresversie heeft gecontroleerd.
- De samenvatting overeenkomt met de huidige revisie.

De bewoner bevestigt `summary_id` en `expected_revision`. De backend controleert die binnen één transactie. Bij conflict volgt geen bevestiging maar een nieuwe samenvatting. Een netwerkretry met dezelfde idempotentiesleutel levert dezelfde uitkomst, zonder een tweede melding.

Een `review_required`-dossier mag als open dossier bewaard worden maar kan niet via de gewone bevestigingsroute als complete intake worden afgehandeld. Voor menselijke behandeling is een latere overdrachtskoppeling nodig.

## 9. Resultaat

Voorbeeld, uitsluitend fictief:

| Onderdeel | Waarde |
| --- | --- |
| Locatie | Keuken, bij spoelbak |
| Element | Keukenkraan |
| Defect | Druppelt uit de uitloop wanneer dichtgedraaid, sinds gisteren |
| Oorzaak | Onbekend; niet technisch vastgesteld |
| Nederlandse werkomschrijving | Keukenkraan bij spoelbak druppelt sinds gisteren uit de uitloop terwijl deze dichtgedraaid is. Oorzaak onbekend. |

De output bevat geen fictieve monteur, reparatiemethode, afspraak of gegarandeerde urgentie. Bewonersbevestiging toont alleen dat de bewoner de omschrijving accepteert.

## 10. Testbare invarianten

- Een onbekende oorzaak blokkeert de demo-afronding niet.
- Een modelvermoeden kan nooit ongemerkt `reported` worden.
- Geen bevestiging van een verouderde samenvatting.
- Geen resultaten van een vorige spraaksessie toepassen zonder revisiecontrole.
- Geen gewijzigde boomversie midden in een bestaande intake.
- Geen onbegrensde verduidelijkingslus.
- Geen terminale spoedroute die daarna automatisch gewone vragen hervat.
- Geen opdracht uit bewonersspraak die systeemregels of toegangsrechten vervangt.

## 11. Adres en definitieve melding

Adres staat los van LEDO-locatie. Het adresobject bevat `postcode`, `house_number`, `addition`, `street`, `city`, `country_code`, `lookup_id`, `candidate_id`, `address_revision`, `verification_status` en `verified_at`. Voorstel eerste scope: Nederlandse adressen (`NL`); uitbreidingen naar andere landen vragen een expliciet providercontract. De gesprekstaal bepaalt het land niet.

Verificatiestaten: `missing`, `unverified`, `verified`. Zoekresultaat en bewonerscontrole zijn afzonderlijke stappen. Verificatie is gebonden aan één kandidaat en adresversie. Wijziging trekt die verificatie in. Ambiguïteit wordt niet met modelkennis opgelost.

Het eindrecord bevat: eigen melding-ID, intake-ID en bronrevisie, geverifieerde adressnapshot, Nederlandse werkomschrijving, samenvatting in gesprekstaal, LEDO-velden en oorspronkelijke classificatiepad-ID's met boomversie, onbekenden/vermoedens, competentie-ID indien relevant, bewoners- en assistentberichten met taal/volgorde/tijd, correcties, verificatie- en afrondingsbewijs. Bewaar transcriptfragmenten herkenbaar als voorlopig wanneer hun definitieve reconstructie onzeker is; verzin ontbrekende tekst niet. Details zijn tekstuele gespreksinhoud, geen chain-of-thought of audio-opname.

`planning_duration` is bevestigd als uren, maar is niet nodig voor dit product en wordt niet opgenomen in het meldingsrecord. Classificatie-ID's zijn niet zonder niveau en boomversie globaal uniek; bewaar het volledige bronpad. Een definitief record wordt slechts eenmaal per intake aangemaakt. Een conceptintake of afgebroken gesprek is geen voltooide melding.
