<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio;

use CommunityFusion\Modules\AiStudio\Http\HttpTransportInterface;
use CommunityFusion\Modules\AiStudio\Http\SsrfGuard;
use CommunityFusion\Modules\AiStudio\Provider\AnthropicProvider;
use CommunityFusion\Modules\AiStudio\Provider\DeepSeekProvider;
use CommunityFusion\Modules\AiStudio\Provider\GoogleProvider;
use CommunityFusion\Modules\AiStudio\Provider\MistralProvider;
use CommunityFusion\Modules\AiStudio\Provider\OllamaProvider;
use CommunityFusion\Modules\AiStudio\Provider\OpenAiProvider;
use CommunityFusion\Modules\AiStudio\Provider\ProviderException;
use CommunityFusion\Modules\AiStudio\Provider\ProviderInterface;

/**
 * slug -> provider. Leest keys uit cf_settings (groep "aistudio", sleutel
 * "provider.{slug}.api_key", type encrypted). Een key verlaat deze klasse
 * uitsluitend richting een provider-object; er is bewust GEEN methode die een
 * key teruggeeft aan een controller of view.
 */
final class ProviderRegistry
{
    /** @var array<string, string> slug => label */
    public const PROVIDERS = [
        'openai' => 'OpenAI',
        'anthropic' => 'Anthropic',
        'google' => 'Google',
        'deepseek' => 'DeepSeek',
        'mistral' => 'Mistral',
        'ollama' => 'Ollama / Open WebUI',
    ];

    /** @var array<string, string> */
    private const DEFAULT_MODELS = [
        'openai' => 'gpt-4o-mini',
        'anthropic' => 'claude-sonnet-4-5',
        'google' => 'gemini-2.0-flash',
        'deepseek' => 'deepseek-chat',
        'mistral' => 'mistral-small-latest',
    ];

    public function __construct(
        private readonly SettingsStore $settings,
        private readonly HttpTransportInterface $http,
        private readonly SsrfGuard $guard,
    ) {
    }

    public static function keySetting(string $slug): string
    {
        return 'provider.' . $slug . '.api_key';
    }

    /**
     * @return list<string>
     */
    public function slugs(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public function exists(string $slug): bool
    {
        return isset(self::PROVIDERS[$slug]);
    }

    public function label(string $slug): string
    {
        return self::PROVIDERS[$slug] ?? $slug;
    }

    public function hasKey(string $slug): bool
    {
        return $this->exists($slug) && $this->settings->has(SettingsStore::GROUP, self::keySetting($slug));
    }

    /**
     * Ollama draait zonder key (host uit de Ollama-module); de rest heeft een key nodig.
     */
    public function isConfigured(string $slug): bool
    {
        if (!$this->exists($slug)) {
            return false;
        }
        if ($slug === 'ollama') {
            return true;
        }
        return $this->hasKey($slug);
    }

    /**
     * @return list<string>
     */
    public function configuredSlugs(): array
    {
        return array_values(array_filter($this->slugs(), fn (string $s): bool => $this->isConfigured($s)));
    }

    public function defaultModel(string $slug): string
    {
        if ($slug === 'ollama') {
            $model = $this->settings->get('ollama', 'default_model', '');
            return $model !== '' ? $model : 'llama3.2';
        }
        $custom = $this->settings->get(SettingsStore::GROUP, 'provider.' . $slug . '.model', '');
        return $custom !== '' ? $custom : (self::DEFAULT_MODELS[$slug] ?? '');
    }

    /**
     * @throws ProviderException
     */
    public function get(string $slug): ProviderInterface
    {
        if (!$this->exists($slug)) {
            throw new ProviderException('Onbekende provider.');
        }
        if (!$this->isConfigured($slug)) {
            throw new ProviderException(self::PROVIDERS[$slug] . ': geen API key ingesteld.');
        }
        return $this->build($slug, $this->settings->get(SettingsStore::GROUP, self::keySetting($slug)));
    }

    /**
     * Valideer een nog niet opgeslagen key bij de provider zelf.
     */
    public function validateKey(string $slug, string $key): bool
    {
        if (!$this->exists($slug)) {
            return false;
        }
        return $this->build($slug, '')->validateKey($key);
    }

    public function storeKey(string $slug, string $key): void
    {
        if (!$this->exists($slug)) {
            throw new ProviderException('Onbekende provider.');
        }
        $this->settings->set(SettingsStore::GROUP, self::keySetting($slug), $key, 'encrypted');
    }

    public function clearKey(string $slug): void
    {
        if ($this->exists($slug)) {
            $this->settings->set(SettingsStore::GROUP, self::keySetting($slug), '', 'encrypted');
        }
    }

    private function build(string $slug, string $key): ProviderInterface
    {
        $model = $this->defaultModel($slug);
        return match ($slug) {
            'openai' => new OpenAiProvider($this->http, $key, $model),
            'anthropic' => new AnthropicProvider($this->http, $key, $model),
            'google' => new GoogleProvider($this->http, $key, $model),
            'deepseek' => new DeepSeekProvider($this->http, $key, $model),
            'mistral' => new MistralProvider($this->http, $key, $model),
            'ollama' => new OllamaProvider($this->http, $this->guard, $this->ollamaConfig(), $key !== '' ? $key : $this->settings->get('ollama', 'open_webui_key')),
            default => throw new ProviderException('Onbekende provider.'),
        };
    }

    /**
     * @return array{host: string, default_model: string, timeout: int, open_webui_url: string}
     */
    private function ollamaConfig(): array
    {
        $timeout = (int) $this->settings->get('ollama', 'timeout', '60');
        return [
            'host' => $this->settings->get('ollama', 'host', 'http://localhost:11434'),
            'default_model' => $this->defaultModel('ollama'),
            'timeout' => $timeout > 0 ? $timeout : 60,
            'open_webui_url' => $this->settings->get('ollama', 'open_webui_url'),
        ];
    }
}
