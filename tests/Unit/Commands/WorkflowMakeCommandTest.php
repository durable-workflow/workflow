<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class WorkflowMakeCommandTest extends TestCase
{
    private const WORKFLOW = 'TestWorkflow';

    private const FOLDER = 'Workflows';

    public function testMakeCommandCreatesLegacyWorkflowByDefault(): void
    {
        $file = self::FOLDER . '/' . self::WORKFLOW . '.php';

        $filesystem = Storage::build([
            'driver' => 'local',
            'root' => app_path(),
        ]);

        $this->assertFalse($filesystem->exists(self::FOLDER));
        $this->assertFalse($filesystem->exists($file));

        $this->artisan('make:workflow ' . self::WORKFLOW)->assertSuccessful();

        $this->assertTrue($filesystem->exists($file));
        $this->assertStringContainsString('use Workflow\\Workflow;', $filesystem->get($file));
        $this->assertStringNotContainsString('Workflow\\V2\\Workflow', $filesystem->get($file));

        $filesystem->delete($file);
        $filesystem->deleteDirectory(self::FOLDER);
    }

    public function testMakeCommandCanCreateV2WorkflowScaffold(): void
    {
        $file = self::FOLDER . '/' . self::WORKFLOW . '.php';

        $filesystem = Storage::build([
            'driver' => 'local',
            'root' => app_path(),
        ]);

        $this->assertFalse($filesystem->exists(self::FOLDER));
        $this->assertFalse($filesystem->exists($file));

        $this->artisan('make:workflow ' . self::WORKFLOW . ' --v2')->assertSuccessful();

        $contents = $filesystem->get($file);

        $this->assertTrue($filesystem->exists($file));
        $this->assertStringContainsString('use Workflow\\V2\\Attributes\\Type;', $contents);
        $this->assertStringContainsString('use Workflow\\V2\\Workflow;', $contents);
        $this->assertStringContainsString("#[Type('test-workflow')]", $contents);
        $this->assertStringContainsString('public function handle(): mixed', $contents);
        $this->assertStringContainsString('return null;', $contents);
        $this->assertStringContainsString('use function Workflow\\V2\\activity;', $contents);
        $this->assertStringContainsString('use function Workflow\\V2\\now;', $contents);
        $this->assertStringContainsString('use function Workflow\\V2\\timer;', $contents);
        $this->assertStringContainsString('$result = activity(MyActivity::class, $input);', $contents);
        $this->assertStringContainsString('timer(\'5 seconds\');', $contents);
        $this->assertStringNotContainsString('Generator', $contents);
        $this->assertStringNotContainsString('yield', $contents);
        $this->assertStringNotContainsString('use Workflow\\Workflow;', $contents);

        $filesystem->delete($file);
        $filesystem->deleteDirectory(self::FOLDER);
    }

    public function testMakeCommandCanUseExplicitV2WorkflowType(): void
    {
        $file = self::FOLDER . '/' . self::WORKFLOW . '.php';

        $filesystem = Storage::build([
            'driver' => 'local',
            'root' => app_path(),
        ]);

        $this->artisan('make:workflow ' . self::WORKFLOW . ' --v2 --type=orders.approval')->assertSuccessful();

        $this->assertStringContainsString("#[Type('orders.approval')]", $filesystem->get($file));

        $filesystem->delete($file);
        $filesystem->deleteDirectory(self::FOLDER);
    }
}
