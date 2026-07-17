<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tools;

/**
 * Single source of truth for what the code tools (code_search / code_read)
 * may show to the LLM.
 *
 * Policy — Claude-Code-like "everything is code" default, with rails:
 *  - The WHOLE project is readable by default, EXCEPT the configured
 *    excludes (default: var, node_modules — runtime state, caches, logs).
 *    Excludes are directory names matched at any depth and are configurable
 *    via OROAI_CODE_EXCLUDE (comma-separated).
 *  - vendor/ is special: only the configured vendor namespaces are readable
 *    (default: oro — the business logic the agent should understand), NOT
 *    low-level framework internals (symfony, doctrine, ...). Configurable
 *    via OROAI_CODE_VENDOR_ALLOWED.
 *  - Hard denies that no configuration can lift: dotfiles/dot-directories
 *    (covers .env*, .git), composer auth.json, and key material
 *    (*.pem, *.key, *.p12, *.pfx).
 *  - Secret-looking lines are redacted from every string leaving toward
 *    the LLM.
 */
final class CodeAccessPolicy
{
    private const array DEFAULT_EXCLUDED_DIRS = ['var', 'node_modules'];
    private const array DEFAULT_VENDOR_ALLOWED = ['oro'];
    private const array DENIED_FILE_NAMES = ['auth.json'];
    private const array DENIED_EXTENSIONS = ['pem', 'key', 'p12', 'pfx'];

    public function __construct(
        private readonly string $projectDir,
        private readonly ?string $excludedDirsCsv = null,
        private readonly ?string $vendorAllowedCsv = null,
    ) {
    }

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    /**
     * Resolve a user-supplied project-relative path (file OR directory) to a
     * real path, or null when the policy denies it (".."/symlinks resolved
     * before any check).
     */
    public function resolve(string $relativePath): ?string
    {
        $real = realpath($this->projectDir . '/' . ltrim($relativePath, '/'));
        $projectReal = rtrim((string) realpath($this->projectDir), '/');

        if ($real === false || !str_starts_with($real . '/', $projectReal . '/')) {
            return null;
        }

        $relative = ltrim(substr($real, strlen($projectReal)), '/');

        return $this->isRelativePathReadable($relative, is_file($real)) ? $real : null;
    }

    /** Policy check on a project-relative path. */
    public function isRelativePathReadable(string $relative, bool $isFile): bool
    {
        $segments = $relative === '' ? [] : explode('/', $relative);

        foreach ($segments as $segment) {
            if ($segment !== '' && $segment[0] === '.') {
                return false; // dotfiles and dot-directories, .env* included
            }
            if (in_array($segment, $this->excludedDirs(), true)) {
                return false;
            }
        }

        if (($segments[0] ?? '') === 'vendor') {
            // vendor root itself is browsable only through the allowed namespaces
            $namespace = $segments[1] ?? null;
            if ($namespace === null || !in_array($namespace, $this->vendorAllowed(), true)) {
                return false;
            }
        }

        if ($isFile && $segments !== []) {
            $basename = strtolower((string) end($segments));
            if (in_array($basename, self::DENIED_FILE_NAMES, true)) {
                return false;
            }
            $extension = pathinfo($basename, PATHINFO_EXTENSION);
            if (in_array($extension, self::DENIED_EXTENSIONS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The roots a project-wide search must cover to honor the vendor rule
     * with a directory-walking or grep-based search: the project root with
     * vendor excluded entirely, plus each allowed vendor namespace.
     *
     * @return list<string> absolute paths
     */
    public function getSearchRoots(): array
    {
        $roots = [$this->projectDir];
        foreach ($this->vendorAllowed() as $namespace) {
            $dir = $this->projectDir . '/vendor/' . $namespace;
            if (is_dir($dir)) {
                $roots[] = $dir;
            }
        }

        return $roots;
    }

    /**
     * Directory names a recursive search must skip (used as grep
     * --exclude-dir): configured excludes + all of vendor (its allowed
     * namespaces are separate search roots) + dot directories.
     *
     * @return list<string>
     */
    public function getSearchExcludedDirNames(): array
    {
        return [...$this->excludedDirs(), 'vendor', '.*'];
    }

    /**
     * Mask the value part of secret-looking lines (passwords, api keys,
     * tokens, DSNs with credentials) before the text reaches the LLM.
     */
    public function redactSecrets(string $line): string
    {
        if (preg_match('/(password|secret|api[_-]?key|token|authorization)\s*[:=]/i', $line)) {
            return preg_replace('/([:=])\s*\S.*$/', '$1 ***REDACTED***', $line) ?? '***REDACTED***';
        }

        // user:password@ in DSN-like strings
        return preg_replace('#://([^:/\s]+):([^@/\s]+)@#', '://$1:***@', $line) ?? $line;
    }

    public function relativePath(string $absolute): string
    {
        return ltrim(str_replace(rtrim($this->projectDir, '/'), '', $absolute), '/');
    }

    /** @return list<string> */
    private function excludedDirs(): array
    {
        $configured = array_filter(array_map('trim', explode(',', (string) $this->excludedDirsCsv)));

        return $configured !== [] ? array_values($configured) : self::DEFAULT_EXCLUDED_DIRS;
    }

    /** @return list<string> */
    private function vendorAllowed(): array
    {
        $configured = array_filter(array_map('trim', explode(',', (string) $this->vendorAllowedCsv)));

        return $configured !== [] ? array_values($configured) : self::DEFAULT_VENDOR_ALLOWED;
    }
}
