<?php

declare(strict_types=1);

namespace mcxForge\Tests;

/**
 * Minimal assertion framework. Extend and implement methods prefixed with `test`.
 */
abstract class testCase
{
    /** @var array<int,array{bool,string,?string}> */
    private array $results = [];

    /**
     * Discover and run all `test*` methods.
     *
     * @return array<int,array{bool,string,?string}>
     */
    public function run(): array
    {
        $methods = array_filter(get_class_methods($this) ?: [], static fn ($m) => str_starts_with($m, 'test'));
        foreach ($methods as $method) {
            try {
                $this->{$method}();
                $this->results[] = [true, $method, null];
            } catch (\AssertionError $e) {
                $this->results[] = [false, $method, $e->getMessage()];
            } catch (\Throwable $e) {
                $this->results[] = [false, $method, $e->getMessage()];
            }
        }

        return $this->results;
    }

    protected function assertTrue(bool $condition, string $message = ''): void
    {
        if (!$condition) {
            throw new \AssertionError($message !== '' ? $message : 'Assertion failed: expected true');
        }
    }

    /**
     * @param mixed $expected
     * @param mixed $actual
     */
    protected function assertEquals($expected, $actual, string $message = ''): void
    {
        if ($expected != $actual) {
            $msg = $message !== '' ? $message : sprintf(
                'Expected %s, got %s',
                var_export($expected, true),
                var_export($actual, true)
            );
            throw new \AssertionError($msg);
        }
    }

    protected function assertMatches(string $pattern, string $value, string $message = ''): void
    {
        if (@preg_match($pattern, $value) !== 1) {
            $msg = $message !== '' ? $message : sprintf(
                'Value %s does not match pattern %s',
                $value,
                $pattern
            );
            throw new \AssertionError($msg);
        }
    }
}

