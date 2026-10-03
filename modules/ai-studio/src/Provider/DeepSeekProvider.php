<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Provider;

final class DeepSeekProvider extends OpenAiCompatibleProvider
{
    public function slug(): string
    {
        return 'deepseek';
    }

    public function label(): string
    {
        return 'DeepSeek';
    }

    protected function baseUrl(): string
    {
        return 'https://api.deepseek.com';
    }
}
