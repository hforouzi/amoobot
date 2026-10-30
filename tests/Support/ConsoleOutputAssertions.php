<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Console output checks that do not depend on the terminal: SymfonyStyle wraps its blocks (error, warning, success,
 * note) at the terminal width, capped at 120 columns, and the console application wraps an exception's message at the
 * full width, so a message gets a line break wherever its text, ids included, happens to reach the edge. Each test runs
 * with a fixed wide COLUMNS (also passed to subprocesses: Symfony Process hands a child only the variables that are in
 * $_SERVER), and the text checks compare with every run of whitespace collapsed to one space on both sides.
 * CommandTester output is not decorated unless a test asks for it.
 */
trait ConsoleOutputAssertions
{
    /** @var array{getenv: string|false, server: mixed, env: mixed} */
    private array $columnsBeforeTest = ['getenv' => false, 'server' => null, 'env' => null];

    #[Before]
    protected function fixConsoleWidth(): void
    {
        $this->columnsBeforeTest = ['getenv' => getenv('COLUMNS'), 'server' => $_SERVER['COLUMNS'] ?? null, 'env' => $_ENV['COLUMNS'] ?? null];
        putenv('COLUMNS=200');
        $_SERVER['COLUMNS'] = $_ENV['COLUMNS'] = '200';
    }

    #[After]
    protected function restoreConsoleWidth(): void
    {
        ['getenv' => $getenv, 'server' => $server, 'env' => $env] = $this->columnsBeforeTest;
        putenv(false === $getenv ? 'COLUMNS' : 'COLUMNS='.$getenv);
        if (null === $server) {
            unset($_SERVER['COLUMNS']);
        } else {
            $_SERVER['COLUMNS'] = $server;
        }
        if (null === $env) {
            unset($_ENV['COLUMNS']);
        } else {
            $_ENV['COLUMNS'] = $env;
        }
    }

    protected static function assertConsoleContains(string $needle, string $output, string $message = ''): void
    {
        Assert::assertStringContainsString(self::collapseWhitespace($needle), self::collapseWhitespace($output), $message);
    }

    protected static function assertConsoleNotContains(string $needle, string $output, string $message = ''): void
    {
        Assert::assertStringNotContainsString(self::collapseWhitespace($needle), self::collapseWhitespace($output), $message);
    }

    protected static function consoleCount(string $output, string $needle): int
    {
        return substr_count(self::collapseWhitespace($output), self::collapseWhitespace($needle));
    }

    private static function collapseWhitespace(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
