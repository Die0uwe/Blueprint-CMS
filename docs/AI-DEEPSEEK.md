# AI in Blueprint: Ollama, Open WebUI en DeepSeek

Blueprint praat via de **Ollama-module** met jouw eigen AI. Dat werkt met elk model dat Ollama draait, dus ook met
**DeepSeek-R1**. Het antwoord wordt door de PHP-server opgehaald; de browser praat nooit rechtstreeks met je AI en
de sleutel komt nooit in de browser.

```
Bezoeker → jouwsite.nl (webhost) → https://ai.jouwdomein.nl (Cloudflare Tunnel) → jouw pc (Docker: Open WebUI + Ollama)
                                  └─ (reserve) https://api.deepseek.com  — alleen als je die zelf aanzet
```

## 1. DeepSeek op je eigen pc

```
ollama pull deepseek-r1:8b
```

| Tag | Ongeveer | Opmerking |
|---|---|---|
| `deepseek-r1:1.5b` | 1 GB videogeheugen | snel, simpel |
| `deepseek-r1:7b` / `:8b` | 5 GB | goede balans |
| `deepseek-r1:14b` | 9 GB | |
| `deepseek-r1:32b` | 20 GB | |

Past een model niet in je videogeheugen, dan rekent Ollama deels op de processor: dat werkt maar is traag. Het "denkwerk"
van R1 (`<think>…</think>`) wordt door Blueprint automatisch uit het antwoord gehaald, ook tijdens streamen.

## 2. Instellen in Blueprint

Beheer → **Ollama AI** (`/admin/ollama`):

* **Open WebUI URL + API-sleutel** (aanbevolen als je webhost jouw pc niet rechtstreeks kan bereiken, wat altijd zo is bij
  gewone webhosting): `https://ai.jouwdomein.nl` en de sleutel uit Open WebUI (Instellingen → Account → API-sleutels, begint met `sk-`).
  Het veld Ollama Host mag dan leeg of op de standaard blijven; Ollama wordt alleen als vangnet gebruikt als die echt bereikbaar is.
* **Standaard model**: `deepseek-r1:8b` (de lijst toont je geïnstalleerde modellen).
* **Verbinding testen**: stap voor stap met uitleg in gewone taal (adres, sleutel, modellen, een echte testvraag).

## 3. Reserve-AI: DeepSeek in de cloud (optioneel)

Vul bij "Reserve-AI" het API-adres (`https://api.deepseek.com`), het model (`deepseek-chat`) en je API-sleutel in.
Valt je eigen AI uit (pc uit, tunnel weg, time-out), dan beantwoordt DeepSeek de vraag. **Let op:** de vragen van bezoekers gaan
dan naar de cloud-dienst van DeepSeek en je betaalt per gebruik. Leeg laten = uit.

## 4. De chatbox als blok

Beheer → Blokken → **AI Chatbox**. Titel, welkomstbericht, snelle vragen (knoppen), hoogte. Je kunt er meerdere plaatsen; ze werken
onafhankelijk. Het blok verschijnt alleen als de Ollama-module aan staat (Beheer → Modules). Bezoekers mogen vragen stellen
zonder in te loggen, begrensd door de rate-limit.

## Als het niet werkt

| Melding | Oorzaak en oplossing |
|---|---|
| "Niet bereikbaar" | Adres klopt niet of server/tunnel staat uit. `localhost` van jouw pc is voor je webhost onbereikbaar: gebruik de https-tunnel. |
| 401 / 403 | Sleutel fout of verlopen; of Cloudflare (Bot Fight Mode / WAF) blokkeert je webhost. Maak een Skip-regel voor je AI-hostnaam. |
| 404 | Adres: Open WebUI = hoofdadres zonder `/api`; Ollama = `http://host:11434`. |
| 502 / 52x | Tunnel of Docker staat uit. |
| 524 | Cloudflare wacht max. 100 s op het eerste teken. Kleiner model, of model geladen houden (`OLLAMA_KEEP_ALIVE=24h`, of "Model geladen houden" in de instellingen). |
| Leeg antwoord | Redeneermodel kwam niet klaar met denken: hogere timeout (max. 300 s) of kleiner/sneller model. |

## Veiligheid

* De sleutels (Open WebUI en reserve-AI) staan versleuteld (AES-256-GCM) in `cf_settings` en worden nergens teruggetoond.
* Het publieke chat-eindpunt geeft bezoekers alleen een vaste foutmelding; details staan in het serverlog en in de admin-test.
* Alleen http(s)-adressen worden opgeslagen; cloud-metadata-adressen (169.254.x.x) worden geweigerd. Zet Open WebUI achter https.
* `/api/ollama/summarize` en `/api/ollama/models` zijn publiek (met rate-limit). Wil je dat niet, zet dan de Ollama-module uit of beperk ze in je Cloudflare-regels.
