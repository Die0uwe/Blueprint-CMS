<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Provider;

final class OpenAiProvider extends OpenAiCompatibleProvider
{
    public function slug(): string
    {
        return 'openai';
    }

    public function label(): string
    {
        return 'OpenAI';
    }

    protected function baseUrl(): string
    {
        return 'https://api.openai.com/v1';
    }

    protected function tokenParam(): string
    {
        return 'max_completion_tokens';
    }
}
