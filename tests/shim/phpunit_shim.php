<?php

declare(strict_types=1);

/**
 * Minimal PHPUnit-compatible shim.
 *
 * Loaded by tests/bootstrap.php ONLY when a real PHPUnit is not installed
 * (no composer / network in this environment). Provides the TestCase API
 * subset used by this project's tests so `php tools/run_tests.php` produces
 * real assertion-checked results. When `vendor/bin/phpunit` exists (composer
 * install), the shim is skipped and the genuine PHPUnit runs the same tests.
 */

namespace PHPUnit\Framework;

use Throwable;

class AssertionFailedError extends \Exception
{
}

abstract class TestCase
{
    /** @var array<int, string> */
    private array $expectExceptionStack = [];

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    public static function setUpBeforeClass(): void
    {
    }

    public static function tearDownAfterClass(): void
    {
    }

    // ── Assertions ──────────────────────────────────────────────────────

    /** @param mixed $expected @param mixed $actual */
    public static function assertSame($expected, $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailedError(
                ($message !== '' ? $message . ' ' : '')
                . 'Failed asserting that ' . self::describe($actual) . ' is identical to ' . self::describe($expected) . '.'
            );
        }
    }

    /** @param mixed $expected @param mixed $actual */
    public static function assertEquals($expected, $actual, string $message = ''): void
    {
        if ($expected != $actual) {
            throw new AssertionFailedError(
                ($message !== '' ? $message . ' ' : '')
                . 'Failed asserting that ' . self::describe($actual) . ' equals ' . self::describe($expected) . '.'
            );
        }
    }

    public static function assertTrue(bool $condition, string $message = ''): void
    {
        if (!$condition) {
            throw new AssertionFailedError($message !== '' ? $message : 'Failed asserting that condition is true.');
        }
    }

    public static function assertFalse(bool $condition, string $message = ''): void
    {
        if ($condition) {
            throw new AssertionFailedError($message !== '' ? $message : 'Failed asserting that condition is false.');
        }
    }

    public static function assertNull($actual, string $message = ''): void
    {
        self::assertTrue($actual === null, $message !== '' ? $message : 'Failed asserting that value is null.');
    }

    public static function assertNotNull($actual, string $message = ''): void
    {
        self::assertTrue($actual !== null, $message !== '' ? $message : 'Failed asserting that value is not null.');
    }

    /** @param mixed $actual */
    public static function assertNotFalse($actual, string $message = ''): void
    {
        self::assertTrue($actual !== false, $message !== '' ? $message : 'Failed asserting that value is not false.');
    }

    /** @param mixed $actual */
    public static function assertNotSame($expected, $actual, string $message = ''): void
    {
        if ($expected === $actual) {
            throw new AssertionFailedError(
                ($message !== '' ? $message . ' ' : '')
                . 'Failed asserting that ' . self::describe($actual) . ' is NOT identical to ' . self::describe($expected) . '.'
            );
        }
    }

    public static function assertEmpty($actual, string $message = ''): void
    {
        self::assertTrue(empty($actual), $message !== '' ? $message : 'Failed asserting that value is empty.');
    }

    public static function assertNotEmpty($actual, string $message = ''): void
    {
        self::assertFalse(empty($actual), $message !== '' ? $message : 'Failed asserting that value is not empty.');
    }

    /** @param mixed $haystack */
    public static function assertContains($needle, array $haystack, string $message = ''): void
    {
        self::assertTrue(
            in_array($needle, $haystack, true),
            $message !== '' ? $message : 'Failed asserting that ' . self::describe($needle) . ' is contained.'
        );
    }

    /** @param mixed $haystack */
    public static function assertNotContains($needle, array $haystack, string $message = ''): void
    {
        self::assertFalse(
            in_array($needle, $haystack, true),
            $message !== '' ? $message : 'Failed asserting that ' . self::describe($needle) . ' is not contained.'
        );
    }

    /** @param array<int|string, mixed> $haystack */
    public static function assertArrayHasKey($key, array $haystack, string $message = ''): void
    {
        self::assertTrue(
            array_key_exists($key, $haystack),
            $message !== '' ? $message : "Failed asserting that array has key '$key'."
        );
    }

    /** @param array<int|string, mixed> $haystack */
    public static function assertArrayNotHasKey($key, array $haystack, string $message = ''): void
    {
        self::assertFalse(
            array_key_exists($key, $haystack),
            $message !== '' ? $message : "Failed asserting that array has NOT key '$key'."
        );
    }

    public static function assertCount(int $expected, iterable $haystack, string $message = ''): void
    {
        $actual = is_array($haystack) ? count($haystack) : iterator_count($haystack);
        self::assertSame($expected, $actual, $message !== '' ? $message : "Failed asserting that count is $expected.");
    }

    public static function assertGreaterThan($expected, $actual, string $message = ''): void
    {
        self::assertTrue(
            $actual > $expected,
            $message !== '' ? $message : "Failed asserting that " . self::describe($actual) . " is greater than " . self::describe($expected) . "."
        );
    }

    public static function assertGreaterThanOrEqual($expected, $actual, string $message = ''): void
    {
        self::assertTrue(
            $actual >= $expected,
            $message !== '' ? $message : 'Failed asserting that value is greater than or equal.'
        );
    }

    public static function assertStringStartsWith(string $prefix, string $string, string $message = ''): void
    {
        self::assertTrue(
            str_starts_with($string, $prefix),
            $message !== '' ? $message : "Failed asserting that string starts with '$prefix'."
        );
    }

    public static function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        self::assertTrue(
            str_contains($haystack, $needle),
            $message !== '' ? $message : "Failed asserting that string contains '$needle'."
        );
    }

    public static function assertMatchesRegularExpression(string $pattern, string $string, string $message = ''): void
    {
        self::assertTrue(
            (bool)preg_match($pattern, $string),
            $message !== '' ? $message : "Failed asserting that string matches $pattern."
        );
    }

    public static function assertDoesNotMatchRegularExpression(string $pattern, string $string, string $message = ''): void
    {
        self::assertFalse(
            (bool)preg_match($pattern, $string),
            $message !== '' ? $message : "Failed asserting that string does NOT match $pattern."
        );
    }

    /** @param class-string<Throwable> $exception */
    public function expectException(string $exception): void
    {
        $this->expectExceptionStack[] = $exception;
    }

    public function expectExceptionMessage(string $message): void
    {
        $this->expectExceptionStack[] = '@@message@@' . $message;
    }

    public function fail(string $message = ''): never
    {
        throw new AssertionFailedError($message !== '' ? $message : 'Test failed.');
    }    /** @internal */
    public function runBare(): array
    {
        $class = new \ReflectionClass($this);
        $methods = [];
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'test') && $method->getDeclaringClass()->getName() !== TestCase::class) {
                $methods[] = $method;
            }
        }
        usort($methods, static fn (\ReflectionMethod $a, \ReflectionMethod $b): int => strcmp($a->getName(), $b->getName()));

        $results = [];
        foreach ($methods as $method) {
            $name = $method->getName();
            $instance = new static();
            $start = microtime(true);
            try {
                $instance->setUp();
                $method->invoke($instance);
                $instance->tearDown();
                $results[] = ['name' => $name, 'status' => 'pass', 'time' => microtime(true) - $start];
            } catch (Throwable $e) {
                $verdict = $instance->evaluateExpectations($e);
                if ($verdict === null) {
                    $status = $e instanceof AssertionFailedError ? 'fail' : 'error';
                    $results[] = ['name' => $name, 'status' => $status, 'message' => $e->getMessage(), 'time' => microtime(true) - $start];
                } elseif ($verdict === true) {
                    $results[] = ['name' => $name, 'status' => 'pass', 'time' => microtime(true) - $start];
                } else {
                    $results[] = ['name' => $name, 'status' => 'fail', 'message' => $verdict, 'time' => microtime(true) - $start];
                }
            }
        }
        return $results;
    }

    /**
     * Validate a thrown exception against expectException()/expectExceptionMessage().
     * Returns null when no expectations were registered, true when satisfied,
     * or a failure message string when violated.
     *
     * @internal
     */
    private function evaluateExpectations(Throwable $e): null|bool|string
    {
        $expectedClasses = [];
        $expectedMessages = [];
        foreach ($this->expectExceptionStack as $entry) {
            if (str_starts_with($entry, '@@message@@')) {
                $expectedMessages[] = substr($entry, 11);
            } else {
                $expectedClasses[] = $entry;
            }
        }
        if ($expectedClasses === [] && $expectedMessages === []) {
            return null;
        }

        foreach ($expectedClasses as $expectedClass) {
            if (!is_a($e, $expectedClass)) {
                return 'Failed asserting that thrown ' . get_class($e) . " is an instance of $expectedClass. Message: " . $e->getMessage();
            }
        }
        foreach ($expectedMessages as $expectedMessage) {
            if (!str_contains($e->getMessage(), $expectedMessage)) {
                return "Failed asserting that exception message contains '$expectedMessage'. Actual: " . $e->getMessage();
            }
        }
        return true;
    }

    /** @internal used by the shim runner */
    private static function describe($value): string
    {
        if (is_null($value)) return 'null';
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_string($value)) return "'$value'";
        if (is_scalar($value)) return (string)$value;
        if (is_array($value)) return 'array(' . count($value) . ')';
        return get_class($value);
    }
}
