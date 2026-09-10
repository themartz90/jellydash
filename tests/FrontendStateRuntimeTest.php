<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FrontendStateRuntimeTest extends TestCase
{
    public function testFrontendStateTransitionsExecuteInNode(): void
    {
        $script = ROOT_DIR . '/tests/frontend/frontend-state.test.js';
        $command = sprintf('node %s 2>&1', escapeshellarg($script));
        exec($command, $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertContains('Frontend state tests passed.', $output);
    }

    public function testPushRegistrationStateTransitionsExecuteInNode(): void
    {
        $script = ROOT_DIR . '/tests/frontend/push-state.test.js';
        $command = sprintf('node %s 2>&1', escapeshellarg($script));
        exec($command, $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertContains('Push state tests passed.', $output);
    }
}
