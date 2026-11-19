<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\ImportFailure;
use Mk\Framework\Jellyfin\ImportValidationException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class ImportFailureTest extends TestCase
{
    public function testOnlyExplicitImportMessagesAreDisplayedAndOtherErrorsAreLogged(): void
    {
        $previous = Container::logger();
        $logs = new TestHandler();
        Container::set('logger', new Logger('import-fixture', [$logs]));
        try {
            self::assertSame('CSV row 2 has an invalid timezone.',
                ImportFailure::message(new ImportValidationException('CSV row 2 has an invalid timezone.')));
            self::assertFalse($logs->hasErrorRecords());
            foreach ([new RuntimeException('Synthetic storage detail.'), new InvalidArgumentException('Synthetic service detail.')] as $error) {
                self::assertSame('History import failed. Check the application log for details.', ImportFailure::message($error));
                self::assertTrue($logs->hasError($error->getMessage()));
            }
        } finally {
            Container::set('logger', $previous);
        }
    }
}
