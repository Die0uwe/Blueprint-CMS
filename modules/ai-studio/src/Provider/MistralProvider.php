<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Provider;

final class MistralProvider extends OpenAiCompatibleProvider
{
    public function slug(): string
    {
        return 'mistral';
    }

    public function label(): string
    {
        return 'Mistral';
    }

    protected function baseUrl(): string
    {
        return 'https://api.mistral.ai/v1';
    }
}
