<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ActivityMakeCommandTest extends TestCase
{
    private const ACTIVITY = 'TestActivity';

    private const FOLDER = 'Workflows';

    public function testMakeCommandCreatesLegacyActivityByDefault(): void
    {
        $file = self::FOLDER . '/' . self::ACTIVITY . '.php';

        $filesystem = Storage::build([
            'driver' => 'local',
            'root' => app_path(),
        ]);

        $this->assertFalse($filesystem->exists(self::FOLDER));
        $this->assertFalse($filesystem->exists($file));

        $this->artisan('make:activity ' . self::ACTIVITY)->assertSuccessful();

        $this->assertTrue($filesystem->exists($file));
        $this->assertStringContainsString('use Workflow\\Activity;', $filesystem->get($file));
        $this->assertStringNotContainsString('Workflow\\V2\\Activity', $filesystem->get($file));

        $filesystem->delete($file);
        $filesystem->deleteDirectory(self::FOLDER);
    }

    public function testMakeCommandCanCreateV2ActivityScaffold(): void
    {
        $file = self::FOLDER . '/' . self::ACTIVITY . '.php';

        $filesystem = Storage::build([
            'driver' => 'local',
            'root' => app_path(),
        ]);

        $this->assertFalse($filesystem->exists(self::FOLDER));
        $this->assertFalse($filesystem->exists($file));

        $this->artisan('make:activity ' . self::ACTIVITY . ' --v2')->assertSuccessful();

        $contents = $filesystem->get($file);

        $this->assertTrue($filesystem->exists($file));
        $this->assertStringContainsString('use Workflow\\V2\\Activity;', $contents);
        $this->assertStringContainsString('use Workflow\\V2\\Attributes\\Type;', $contents);
        $this->assertStringContainsString("#[Type('test-activity')]", $contents);
        $this->assertStringContainsString('public function execute(): mixed', $contents);
        $this->assertStringContainsString('return null;', $contents);
        $this->assertStringNotContainsString('use Workflow\\Activity;', $contents);

        $filesystem->delete($file);
        $filesystem->deleteDirectory(self::FOLDER);
    }

    public function testMakeCommandCanUseExplicitV2ActivityType(): void
    {
        $file = self::FOLDER . '/' . self::ACTIVITY . '.php';

        $filesystem = Storage::build([
            'driver' => 'local',
            'root' => app_path(),
        ]);

        $this->artisan('make:activity ' . self::ACTIVITY . ' --v2 --type=activities.greeting')->assertSuccessful();

        $this->assertStringContainsString(
            "#[Type('activities.greeting')]",
            $filesystem->get($file),
        );

        $filesystem->delete($file);
        $filesystem->deleteDirectory(self::FOLDER);
    }
}
