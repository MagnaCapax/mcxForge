<?php

declare(strict_types=1);

namespace mcxForge;

/**
 * Logger
 *
 * Lightweight shared logger for mcxForge CLI tools.
 *
 * Responsibilities:
 *  - Append everything written to STDOUT to a single shared log file
 *    (/tmp/mcxForge.log) while keeping CLI output unchanged.
 *  - Provide helpers to log STDERR messages into the same log.
 *
 * This is implemented via an output buffer callback that "tees" all
 * STDOUT output into the log file, plus explicit helpers for STDERR.
 *
 * @author Aleksi Ursin
 */
final class Logger
{
    private const LOG_FILE = '/tmp/mcxForge.log';

    /** @var resource|null */
    private static $handle = null;

    private static bool $initialized = false;

    /**
     * Enable streaming logging for STDOUT by attaching an output buffer
     * callback that appends everything to LOG_FILE while passing it
     * through to the terminal unchanged.
     */
    public static function initStreamLogging(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        $fh = @fopen(self::LOG_FILE, 'ab');
        if (!is_resource($fh)) {
            return;
        }

        self::$handle = $fh;

        ob_start(
            /**
             * @param string $buffer
             */
            function ($buffer) use ($fh): string {
                if ($buffer !== '') {
                    @fwrite($fh, $buffer);
                    @fflush($fh);
                }

                return $buffer;
            }
        );

        ob_implicit_flush(true);
    }

    /**
     * Append a single line to the shared log file without affecting CLI output.
     * A trailing newline is added if missing.
     */
    public static function logLine(string $line): void
    {
        if ($line === '') {
            return;
        }

        if (!self::$initialized) {
            self::initStreamLogging();
        }

        if (!is_resource(self::$handle)) {
            return;
        }

        if (substr($line, -1) !== "\n") {
            $line .= PHP_EOL;
        }

        @fwrite(self::$handle, $line);
        @fflush(self::$handle);
    }

    /**
     * Write a message to STDERR and mirror it into the shared log file.
     */
    public static function logStderr(string $message): void
    {
        if ($message === '') {
            return;
        }

        fwrite(STDERR, $message);
        self::logLine($message);
    }
}
