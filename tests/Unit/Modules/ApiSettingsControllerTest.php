<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Modules;

use CommunityFusion\Modules\Settings\ApiSettingsController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Schema van de voorbereide API-instellingen (Steam + lege Custom API-slots). */
final class ApiSettingsControllerTest extends TestCase
{
    #[Test]
    public function hasSteamAndThreeCustomSlots(): void
    {
        $ids = array_column(ApiSettingsController::sections(), 'id');
        $this->assertSame(['steam', 'custom1', 'custom2', 'custom3'], $ids);
    }

    #[Test]
    public function keysAreUniqueAndSecretsAreEncrypted(): void
    {
        $keys = [];
        foreach (ApiSettingsController::sections() as $sec) {
            foreach ($sec['fields'] as $f) {
                $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $f['key']);
                $keys[] = $f['key'];
                if (str_ends_with($f['key'], 'api_key')) {
                    $this->assertSame('encrypted', $f['type'], $f['key'] . ' moet versleuteld worden opgeslagen');
                }
            }
        }
        $this->assertSame(count($keys), count(array_unique($keys)));
        $this->assertContains('steam_api_key', $keys);
        $this->assertContains('custom3_title', $keys);
    }
}
