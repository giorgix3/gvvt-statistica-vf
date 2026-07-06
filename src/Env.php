<?php

declare(strict_types=1);

namespace Gvvt;

/**
 * Minimal .env loader — no external library needed.
 *
 * Reads KEY=VALUE lines from a .env file and pushes them into the process
 * environment via putenv(). Already-set variables are not overwritten, so
 * real server environment variables (set via the hosting control panel or
 * GitHub Actions secrets) always take precedence over the .env file.
 *
 * Usage:  Env::load(__DIR__ . '/../.env');
 */
class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return; // no .env on the server — env vars must come from the host
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            // Skip comments
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $name  = trim($name);
            $value = trim($value);

            // Strip surrounding quotes if present
            if (strlen($value) >= 2
                && (($value[0] === '"' && str_ends_with($value, '"'))
                 || ($value[0] === "'" && str_ends_with($value, "'")))
            ) {
                $value = substr($value, 1, -1);
            }

            // Don't overwrite values already set in the real environment
            if (getenv($name) === false) {
                putenv("$name=$value");
                $_ENV[$name] = $value;
            }
        }
    }
}
