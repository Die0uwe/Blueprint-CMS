# Blueprint AI Studio

Links een chat met zes providers (OpenAI, Anthropic, Google, DeepSeek, Mistral, Ollama) met eigen API-keys,
rechts een eigen editor met syntax highlighting (HTML, PHP, Twig, JS, CSS). De AI leest de editorinhoud als
context en stelt wijzigingen voor als diff; de editor verandert pas na een expliciete klik op "Toepassen".

## Installeren

```
php cli/console.php ai-studio:migrate
```

Draait de idempotente migratie (ook op bestaande installs), maakt de permissions `aistudio.use` en
`aistudio.admin` aan (rol `admin` krijgt ze) en activeert de module. Daarna: `/admin/ai-studio/settings`
om keys in te voeren. Ollama gebruikt de instellingen van de Ollama-module.

## Tests en kwaliteit

```
phpunit --testsuite AiStudio
composer stan:ai-studio
composer cs:ai-studio
```

Alle providertests gebruiken een nep-transport; er is geen netwerk nodig.

## Ontwerp en beveiliging

Zie [`docs/security-notes.md`](../../docs/security-notes.md).
