<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HistoryTemplateTest extends TestCase
{
    public function testFooterUsesFilteredResultTotal(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/history/index.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString(
            'Showing {{ summary.from }}-{{ summary.to }} of {{ summary.filtered_total }} plays',
            $template
        );
        $this->assertStringContainsString(
            '{{ summary.from }}-{{ summary.to }} <small>of {{ summary.filtered_total }}</small>',
            $template
        );
        $this->assertStringContainsString(
            '0 <small>of {{ summary.filtered_total }}</small>',
            $template
        );
        $this->assertStringContainsString('history/_pager.twig', $template);
    }
}
