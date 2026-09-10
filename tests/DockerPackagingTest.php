<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DockerPackagingTest extends TestCase
{
    private string $contextDirectory;

    protected function setUp(): void
    {
        $this->contextDirectory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'jellydash-docker-context-' . bin2hex(random_bytes(8));

        self::assertTrue(mkdir($this->contextDirectory, 0777, true));
        self::assertTrue(copy(ROOT_DIR . '/.dockerignore', $this->contextDirectory . '/.gitignore'));
        $this->runGit(['init', '--quiet']);
    }

    protected function tearDown(): void
    {
        if (!isset($this->contextDirectory) || !is_dir($this->contextDirectory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->contextDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->contextDirectory);
    }

    #[DataProvider('privateBuildContextFiles')]
    public function testPrivateAndRuntimeFilesAreExcludedFromTheBuildContext(string $path): void
    {
        $this->createMarker($path);

        self::assertTrue($this->isIgnored($path), sprintf('%s must stay out of local Docker builds.', $path));
    }

    /** @return iterable<string, array{string}> */
    public static function privateBuildContextFiles(): iterable
    {
        foreach ([
            'internal/PROJECT.md',
            'Assets/source-design.psd',
            'modules/downloads/module.php',
            'dev-router.php',
            'sqlite-data/jellydash.sqlite',
            'var/data/jellydash.sqlite',
            'var/data/jellydash.sqlite.bak',
            'var/sessions/php-session',
            'cache/compiled-template.php',
            'var/cache/libraries.json',
            'var/log/app.log',
            'public/uploads/private-import.tsv',
            'public/uploads/images/private-poster.jpg',
            'PlaybackReportingBackup-private.tsv',
            'tests/PrivateMarkerTest.php',
            'vendor/package/source.php',
            'node_modules/package/source.js',
            '.env',
            '.env.local',
            '.env.production',
            '.env.production.local',
            '.envrc',
            '.idea/workspace.xml',
            '.vscode/settings.json',
            '.github/workflows/private.yml',
            '.phpunit.cache/test-results',
            '.php-cs-fixer.cache',
            '.DS_Store',
            'Thumbs.db',
            'project.iml',
            'docker-compose.override.yml',
            'composer.phar',
            'phpunit.xml',
            'phpstan.neon',
            '.php-cs-fixer.dist.php',
            'AGENTS.md',
        ] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('runtimeBuildContextFiles')]
    public function testRuntimeAndPublicModuleScaffoldingRemainInTheBuildContext(string $path): void
    {
        $this->createMarker($path);

        self::assertFalse($this->isIgnored($path), sprintf('%s is required in the source image.', $path));
    }

    /** @return iterable<string, array{string}> */
    public static function runtimeBuildContextFiles(): iterable
    {
        foreach ([
            'src/Config.php',
            'public/index.php',
            'docker/php/app.ini',
            'composer.json',
            '.env.example',
            'docs/MODULES.md',
            'modules/.gitkeep',
            'cache/.gitkeep',
            'cache/.htaccess',
            'var/cache/.gitkeep',
            'var/log/.gitkeep',
            'var/sessions/.gitkeep',
            'public/uploads/.gitkeep',
            'public/uploads/.htaccess',
            'public/uploads/images/.gitkeep',
        ] as $path) {
            yield $path => [$path];
        }
    }

    public function testContainerPhpConfigurationOmitsExceptionArguments(): void
    {
        $configuration = parse_ini_file(ROOT_DIR . '/docker/php/app.ini', false, INI_SCANNER_TYPED);

        self::assertIsArray($configuration);
        self::assertArrayHasKey('zend.exception_ignore_args', $configuration);
        self::assertTrue($configuration['zend.exception_ignore_args']);
    }

    private function createMarker(string $path): void
    {
        $absolutePath = $this->contextDirectory . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $directory = dirname($absolutePath);
        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0777, true));
        }

        self::assertNotFalse(file_put_contents($absolutePath, 'synthetic packaging marker'));
    }

    private function isIgnored(string $path): bool
    {
        return 0 === $this->runGit(['check-ignore', '--quiet', '--no-index', '--', $path], [0, 1]);
    }

    /**
     * Git's ignore matcher provides deterministic coverage for the directory,
     * wildcard and negation rules used by this Docker ignore file. Docker image
     * inspection remains a separate container acceptance check.
     *
     * @param list<string> $arguments
     * @param list<int>    $allowedExitCodes
     */
    private function runGit(array $arguments, array $allowedExitCodes = [0]): int
    {
        $pipes = [];
        $process = proc_open(
            ['git', ...$arguments],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->contextDirectory,
        );
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertContains(
            $exitCode,
            $allowedExitCodes,
            sprintf("git %s failed.\n%s\n%s", implode(' ', $arguments), $stdout, $stderr),
        );

        return $exitCode;
    }
}
