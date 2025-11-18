<?php

declare(strict_types=1);

namespace mcxForge;

/**
 * Cli
 *
 * Small shared helpers for mcxForge CLI entrypoints.
 *
 * Responsibilities:
 *  - Handle common flags consistently:
 *      * --help / -h
 *      * --score-only
 *      * --no-color (and NO_COLOR env)
 *  - Provide a shared color palette for human output.
 *
 * Entry points still own their specific arguments; this helper only
 * centralizes the global behavior and avoids copy-paste.
 *
 * @author Aleksi Ursin
 */
final class Cli
{
    /**
     * Determine default color setting based on NO_COLOR.
     */
    public static function defaultColorEnabled(): bool
    {
        return getenv('NO_COLOR') === false;
    }

    /**
     * Consume common flags from the argument list.
     *
     * Supported flags:
     *  - -h / --help    -> calls $printHelp and exits with EXIT_OK.
     *  - --score-only   -> sets $scoreOnly = true.
     *  - --no-color     -> sets $colorEnabled = false.
     *
     * All recognized flags are removed from $args; remaining arguments
     * are left for tool-specific parsing.
     *
     * @param array<int,string> $args
     * @param callable():void   $printHelp
     * @return array<int,string> remaining arguments
     */
    public static function consumeCommonFlags(
        array $args,
        callable $printHelp,
        bool &$scoreOnly,
        bool &$colorEnabled
    ): array {
        $remaining = [];

        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                $printHelp();
                exit(defined('EXIT_OK') ? EXIT_OK : 0);
            }

            if ($arg === '--score-only') {
                $scoreOnly = true;
                continue;
            }

            if ($arg === '--no-color') {
                $colorEnabled = false;
                continue;
            }

            $remaining[] = $arg;
        }

        return $remaining;
    }

    /**
     * Provide a simple, shared color palette for human output.
     *
     * Keys:
     *  - title  : headings / prefixes
     *  - ok     : success values
     *  - warn   : warnings
     *  - error  : error highlights
     *  - device : device labels
     *  - reset  : reset sequence
     *
     * @return array<string,string>
     */
    public static function colors(bool $enabled): array
    {
        if (!$enabled) {
            return [
                'title' => '',
                'ok' => '',
                'warn' => '',
                'error' => '',
                'device' => '',
                'reset' => '',
            ];
        }

        return [
            'title' => "\033[1;34m",
            'ok' => "\033[1;32m",
            'warn' => "\033[1;33m",
            'error' => "\033[1;31m",
            'device' => "\033[0;36m",
            'reset' => "\033[0m",
        ];
    }
}

