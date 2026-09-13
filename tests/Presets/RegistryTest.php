<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Presets;

use OCA\ContactHub\Presets\Registry;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    public function testHasIcloudInfomaniakMailoGoogleAndGenericPresets(): void
    {
        self::assertSame(['icloud', 'infomaniak', 'mailo', 'google', 'generic'], array_keys(Registry::all()));
    }

    public function testEveryPresetExceptGoogleIsUsable(): void
    {
        foreach (Registry::all() as $key => $preset) {
            self::assertSame($key !== 'google', $preset->isUsable(), "usability of '{$key}'");
        }
    }

    public function testGoogleCarriesItsReasonAndIsNotSilentlyMissing(): void
    {
        // Listing it matters as much as blocking it: someone who wants to
        // sync Google Contacts has to be told why they cannot, or they will
        // assume the preset was simply forgotten and go hunting for a URL.
        $google = Registry::get('google');

        self::assertFalse($google->isUsable());
        self::assertNotNull($google->unsupportedReason);
        self::assertStringContainsString('OAuth', (string) $google->unsupportedReason);
    }

    public function testGoogleDefaultsToCategoriesBecauseItExposesNoGroups(): void
    {
        // Not a cosmetic default. Google's CardDAV interface exposes no group
        // representation at all, so passthrough would send group vCards that
        // are silently discarded.
        self::assertSame('categories', Registry::get('google')->defaultGroupStrategy);
    }

    public function testInfomaniakHintAsksForTheShortLogin(): void
    {
        // The hint used to recommend the full XXXXXXXX@sync.infomaniak.com
        // form, which does not authenticate against this app.
        $hint = Registry::get('infomaniak')->authHint;

        self::assertStringContainsString('short login', $hint);
        self::assertStringNotContainsString('Use your full username', $hint);
    }

    public function testIcloudDefaultUrl(): void
    {
        self::assertSame('https://contacts.icloud.com/', Registry::get('icloud')->defaultBaseUrl);
    }

    public function testInfomaniakDefaultUrl(): void
    {
        self::assertSame('https://sync.infomaniak.com/', Registry::get('infomaniak')->defaultBaseUrl);
    }

    public function testMailoDefaultUrlAndFallbackPattern(): void
    {
        $mailo = Registry::get('mailo');

        self::assertSame('https://carddav.mailo.com', $mailo->defaultBaseUrl);
        self::assertSame(
            'https://carddav.mailo.com/adbook/jane.doe@mailo.com/1',
            $mailo->fallbackUrlFor('jane.doe@mailo.com'),
        );
    }

    public function testFallbackUrlIsNullWhenPresetHasNone(): void
    {
        self::assertNull(Registry::get('icloud')->fallbackUrlFor('someone@example.com'));
    }

    public function testGenericHasNoDefaultUrl(): void
    {
        self::assertSame('', Registry::get('generic')->defaultBaseUrl);
    }

    public function testUnknownPresetThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Registry::get('bogus');
    }
}
