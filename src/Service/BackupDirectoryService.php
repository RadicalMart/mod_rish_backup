<?php

declare(strict_types=1);

namespace Rish\Module\RishBackup\Administrator\Service;

use Joomla\CMS\Language\Text;
use RuntimeException;

defined('_JEXEC') or die;

class BackupDirectoryService
{
    private const SITE_DIRECTORY_PATTERN = '~\A/var/www/([a-z_][a-z0-9_-]*)/www/([a-z0-9][a-z0-9._-]*)(?:/|\z)~D';

    private readonly string $root;
    private readonly string $archivePrefix;
    private readonly bool $belongsToSite;

    public function __construct(string $configured, string $documentRoot)
    {
        $siteRoot = self::discoverPath($documentRoot);
        $root = trim($configured) === '' ? $siteRoot : rtrim(trim($configured), '/');

        if (
            preg_match('~\A/var/www/[a-z_][a-z0-9_-]*/backups/[a-z0-9][a-z0-9._-]*\z~D', $root) !== 1
            || !$this->isCanonicalDirectory($root)
        ) {
            throw new RuntimeException(Text::_('MOD_RISH_BACKUP_ERROR_DIRECTORY'), 503);
        }

        $webRoots = [JPATH_ROOT, $documentRoot];

        if (defined('JPATH_PUBLIC')) {
            $webRoots[] = JPATH_PUBLIC;
        }

        foreach ($webRoots as $webRoot) {
            if ($webRoot === '') {
                continue;
            }

            $webRoot = @realpath($webRoot);

            if ($webRoot === false
                || $root === $webRoot
                || str_starts_with($root, rtrim($webRoot, '/') . '/')
                || str_starts_with($webRoot, $root . '/')) {
                throw new RuntimeException(Text::_('MOD_RISH_BACKUP_ERROR_DIRECTORY'), 503);
            }
        }

        foreach (['inbox', 'status', 'downloads'] as $child) {
            if (!$this->isCanonicalDirectory($root . '/' . $child)) {
                throw new RuntimeException(Text::_('MOD_RISH_BACKUP_ERROR_DIRECTORY'), 503);
            }
        }

        $this->root = $root;
        $this->archivePrefix = basename($root) . '_';
        $this->belongsToSite = $root === $siteRoot;
    }

    public static function fromConfiguration(string $configured, string $documentRoot): self
    {
        return new self($configured, $documentRoot);
    }

    public static function availablePath(string $configured, string $documentRoot): ?string
    {
        try {
            return self::fromConfiguration($configured, $documentRoot)->root;
        } catch (RuntimeException) {
            return null;
        }
    }

    public function path(): string
    {
        return $this->root;
    }

    public function acceptsRequests(): bool
    {
        $inbox = $this->root . '/inbox';
        clearstatcache(true, $inbox);

        return is_writable($inbox);
    }

    private static function discoverPath(string $documentRoot): string
    {
        $joomlaRoot = @realpath(JPATH_ROOT);

        if ($joomlaRoot === false) {
            return '';
        }

        $publicRoot = $documentRoot !== '' ? @realpath($documentRoot) : false;
        if (preg_match(self::SITE_DIRECTORY_PATTERN, $documentRoot, $logicalSite) === 1 && $publicRoot !== false) {
            $userHome = '/var/www/' . $logicalSite[1];
            $logicalPath = $userHome . '/www/' . $logicalSite[2];
            $release = @realpath($logicalPath);
            $logicalJobs = $userHome . '/backups/' . $logicalSite[2];

            if ($release !== false && $release !== $logicalPath
                && str_starts_with($release, $userHome . '/')
                && !self::isUnder($release, $userHome . '/backups')
                && !self::isUnder($release, $userHome . '/www/backups')
                && self::isUnder($joomlaRoot, $release) && self::isUnder($publicRoot, $release)
                && (self::isUnder($joomlaRoot, $publicRoot) || self::isUnder($publicRoot, $joomlaRoot))) {
                return $logicalJobs;
            }
        }

        if (preg_match(self::SITE_DIRECTORY_PATTERN, $joomlaRoot, $site) !== 1) {
            return '';
        }

        if ($publicRoot !== false
            && preg_match(self::SITE_DIRECTORY_PATTERN, $publicRoot, $documentSite) === 1
            && ($documentSite[1] !== $site[1] || $documentSite[2] !== $site[2])) {
            return '';
        }

        return '/var/www/' . $site[1] . '/backups/' . $site[2];
    }

    private static function isUnder(string $path, string $directory): bool
    {
        return $path === $directory || str_starts_with($path, $directory . '/');
    }

    public function archivePrefix(): string
    {
        return $this->archivePrefix;
    }

    public function belongsToSite(): bool
    {
        return $this->belongsToSite;
    }

    private function isCanonicalDirectory(string $path): bool
    {
        clearstatcache(true, $path);

        return @realpath($path) === $path && is_dir($path);
    }
}
