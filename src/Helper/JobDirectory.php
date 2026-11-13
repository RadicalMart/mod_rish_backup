<?php

declare(strict_types=1);

namespace Rish\Module\RishBackup\Administrator\Helper;

use Joomla\CMS\Language\Text;
use JsonException;
use RuntimeException;

defined('_JEXEC') or die;

final class JobDirectory
{
    private const TYPES = ['backup', 'export'];
    private const CLOUD_TYPES = ['cloud-list', 'cloud-fetch'];
    private const SITE_DIRECTORY_PATTERN = '~\A/var/www/([a-z_][a-z0-9_-]*)/www/([a-z0-9][a-z0-9._-]*)(?:/|\z)~D';
    private const ARCHIVE_ID_PATTERN = '[0-9]{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])_(?:[01][0-9]|2[0-3])-[0-5][0-9]-[0-5][0-9]-[0-9]{9}';
    private const SNAPSHOT_ID_PATTERN = '~\A[a-f0-9]{64}\z~D';
    private const SNAPSHOT_DATE_PATTERN = '~\A[0-9]{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01]) (?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\z~D';
    private const MAX_SNAPSHOTS = 10;
    private const REGULAR_FILE = 0100000;
    private const DIRECTORY = 0040000;
    private const FILE_TYPE_MASK = 0170000;
    private const MAX_STATUS_BYTES = 16384;

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

    public function status(): array
    {
        $archives = $this->listExports();
        $serverTime = time();
        $result = ['accepts_requests' => $this->acceptsRequests(), 'archives' => $archives, 'server_time' => $serverTime];

        foreach (['backup', 'export', 'delete'] as $type) {
            $result[$type] = $this->readStatus($type);
        }

        if ($result['export']['state'] === 'ready' && $archives === []) {
            $result['export'] = ['state' => 'idle', 'updated_at' => null, 'message' => ''];
        }

        if ($this->hasTrustedStatusDirectory()) {
            $schedule = $this->readSchedule();
            if ($schedule !== null) {
                $result['schedule'] = $schedule;
            }

            if ($this->hasCloudCapability()) {
                try {
                    $catalog = $this->readCloudCatalog();
                    $result['cloud'] = $catalog + [
                        'server_time' => $serverTime,
                        'list' => $this->readStatus('cloud-list'),
                        'fetch' => $this->cloudFetchStatus($catalog['snapshots']),
                    ];
                } catch (RuntimeException $error) {
                    $result['cloud'] = ['error' => $error->getMessage(), 'server_time' => $serverTime];
                }
            }
        }

        return $result;
    }

    public function request(string $type): array
    {
        $this->checkRequestsEnabled();

        $exportBefore = $type === 'export' ? $this->readStatus('export', false) : null;
        $activeType = null;
        $nextAt = 0;

        foreach (self::TYPES as $jobType) {
            $job = $this->readStatus($jobType);
            if (in_array($job['state'], ['queued', 'running'], true)) {
                $activeType = $jobType;
                break;
            }
            if ($jobType === $type) {
                $nextAt = $job['next_at'] ?? 0;
            }
        }

        if ($activeType === null && $nextAt <= time()) {
            $this->queue($this->markerPath($type));
        }

        $status = $this->status();

        if ($exportBefore !== null && $activeType !== 'backup') {
            $status['export_before'] = $exportBefore;
        }

        return $status;
    }

    public function requestDelete(string $id): array
    {
        $this->checkRequestsEnabled();
        $this->requireArchive($id);

        $this->queue($this->deleteMarkerPath($id));

        return $this->status();
    }

    public function requestCloudList(): array
    {
        $this->checkCloudRequestsEnabled();

        try {
            $status = $this->readStatus('cloud-list');
        } catch (RuntimeException) {
            $status = null;
        }

        if (($status['next_at'] ?? 0) > time()) {
            throw new RuntimeException(Text::_('MOD_RISH_BACKUP_CLOUD_LIST_BUSY'), 409);
        }

        if (!in_array($status['state'] ?? null, ['queued', 'running'], true)) {
            $this->queue($this->markerPath('cloud-list'));
        }

        return $this->status();
    }

    public function requestCloudFetch(string $id): array
    {
        $this->checkCloudRequestsEnabled();

        if (preg_match(self::SNAPSHOT_ID_PATTERN, $id) !== 1) {
            throw new RuntimeException(Text::_('MOD_RISH_BACKUP_ERROR_SNAPSHOT_ID'), 400);
        }

        $lock = $this->openCloudFetchLock();

        try {
            $snapshots = $this->readCloudCatalog()['snapshots'];

            if (!in_array($id, array_column($snapshots, 'id'), true)) {
                throw new RuntimeException(Text::_('MOD_RISH_BACKUP_ERROR_SNAPSHOT_MISSING'), 404);
            }

            $fetch = $this->cloudFetchStatus($snapshots);
            if (in_array($fetch['state'], ['queued', 'running'], true)) {
                if (($fetch['snapshot_id'] ?? null) !== $id) {
                    throw new RuntimeException(Text::_('MOD_RISH_BACKUP_CLOUD_FETCH_BUSY'), 409);
                }
            } elseif (($fetch['next_at'] ?? 0) > time()) {
                throw new RuntimeException(Text::_('MOD_RISH_BACKUP_CLOUD_FETCH_BUSY'), 409);
            } else {
                $this->queue($this->cloudFetchMarkerPath($id));
            }
        } finally {
            if ($lock !== null) {
                fclose($lock);
            }
        }

        return $this->status();
    }

    /** @return resource|null */
    private function openCloudFetchLock()
    {
        $path = $this->root . '/cloud-fetch.lock';
        $stat = $this->fileStat($path);

        if ($stat === false) {
            return null;
        }

        $busy = Text::_('MOD_RISH_BACKUP_CLOUD_FETCH_BUSY');
        if (!$this->isTrustedFile($stat)) {
            throw new RuntimeException($busy, 503);
        }

        try {
            $lock = $this->openRegularFile($path, $stat);
        } catch (RuntimeException) {
            throw new RuntimeException($busy, 503);
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException($busy, 409);
        }

        return $lock;
    }

    private function hasTrustedStatusDirectory(): bool
    {
        if (!$this->belongsToSite) {
            return false;
        }

        foreach ([dirname($this->root), $this->root, $this->root . '/status'] as $path) {
            $stat = $this->fileStat($path);

            if ($stat === false || $stat['uid'] !== 0 || ($stat['mode'] & 0177777) !== (self::DIRECTORY | 0750)) {
                return false;
            }
        }

        return true;
    }

    private function hasCloudCapability(): bool
    {
        $capability = $this->fileStat($this->root . '/cloud-enabled');
        return $this->isTrustedFile($capability) && $capability['size'] === 0;
    }

    private function readSchedule(): ?array
    {
        $unknown = ['state' => 'unknown', 'jobs' => []];

        try {
            $schedule = $this->readJson('schedule', Text::_('MOD_RISH_BACKUP_ERROR_STATUS'), true);
        } catch (RuntimeException) {
            return $unknown;
        }

        if ($schedule === null) {
            return null;
        }

        $state = $schedule['state'] ?? null;
        $jobs = $schedule['jobs'] ?? null;
        $checkedAt = $schedule['checked_at'] ?? null;
        $checked = is_int($checkedAt) && $checkedAt > 0 ? ['checked_at' => $checkedAt] : [];

        if ($state === 'not_configured' && $jobs === []) {
            return ['state' => $state, 'jobs' => []] + $checked;
        }

        if ($state !== 'configured' || !is_array($jobs) || !array_is_list($jobs) || $jobs === []) {
            return $unknown + $checked;
        }

        $entries = [];

        foreach ($jobs as $job) {
            $expression = $job['expression'] ?? null;
            $timezone = $job['timezone'] ?? null;

            if (!is_string($expression) || strlen($expression) > 100
                || preg_match('~\A(?:@[a-z]+|[0-9a-z*,/\~\-]+(?: [0-9a-z*,/\~\-]+){4})\z~iD', $expression) !== 1
                || ($timezone !== null && (!is_string($timezone) || strlen($timezone) > 100
                    || preg_match('~\A[A-Za-z0-9_.+\-/]+\z~D', $timezone) !== 1))) {
                return $unknown + $checked;
            }

            $entry = ['expression' => $expression];
            if ($timezone !== null) {
                $entry['timezone'] = $timezone;
            }
            $entries[] = $entry;
        }

        return ['state' => $state, 'jobs' => $entries] + $checked;
    }

    private function checkCloudRequestsEnabled(): void
    {
        if (!$this->hasTrustedStatusDirectory() || !$this->hasCloudCapability()) {
            throw new RuntimeException(Text::_('MOD_RISH_BACKUP_CLOUD_UNAVAILABLE'), 403);
        }

        $this->checkRequestsEnabled();
    }

    private function readCloudCatalog(): array
    {
        $error = Text::_('MOD_RISH_BACKUP_ERROR_CLOUD_CATALOG');
        $catalog = $this->readJson('cloud-catalog', $error, true);

        if (($catalog['schema'] ?? null) !== 2) {
            return ['updated_at' => null, 'snapshots' => [], 'legacy' => false];
        }

        $snapshots = $catalog['snapshots'] ?? null;
        $legacy = $catalog['legacy'] ?? false;

        if (!is_int($catalog['updated_at'] ?? null) || $catalog['updated_at'] < 1
            || !is_array($snapshots) || !array_is_list($snapshots) || count($snapshots) > self::MAX_SNAPSHOTS
            || !is_bool($legacy)) {
            throw new RuntimeException($error, 503);
        }

        $entries = [];

        foreach ($snapshots as $snapshot) {
            $id = $snapshot['id'] ?? null;
            $date = $snapshot['created_at'] ?? null;
            $size = $snapshot['size'] ?? null;
            $encrypted = $snapshot['encrypted'] ?? null;

            if (!is_string($id) || preg_match(self::SNAPSHOT_ID_PATTERN, $id) !== 1
                || isset($entries[$id]) || !is_string($date) || preg_match(self::SNAPSHOT_DATE_PATTERN, $date) !== 1
                || !is_int($size) || $size < 1 || !is_bool($encrypted)) {
                throw new RuntimeException($error, 503);
            }

            $entries[$id] = ['id' => $id, 'created_at' => $date, 'size' => $size, 'encrypted' => $encrypted];
        }

        return ['updated_at' => $catalog['updated_at'], 'snapshots' => array_values($entries), 'legacy' => $legacy];
    }

    private function cloudFetchStatus(array $snapshots): array
    {
        $status = $this->readStatus('cloud-fetch', false);

        if ($status['state'] !== 'running') {
            foreach ($snapshots as $snapshot) {
                $queuedAt = $this->queuedMarker($this->cloudFetchMarkerPath($snapshot['id']), false);

                if ($queuedAt !== null) {
                    return [
                        'state' => 'queued',
                        'updated_at' => $queuedAt,
                        'message' => '',
                        'snapshot_id' => $snapshot['id'],
                    ];
                }
            }
        }

        return $status;
    }

    /**
     * Opens a published export and verifies the opened file.
     *
     * @return array{stream: resource, archive: string, size: int}
     */
    public function openExport(string $id): array
    {
        [$name, $path, $stat] = $this->requireArchive($id);

        return [
            'stream' => $this->openRegularFile($path, $stat),
            'archive' => $name,
            'size' => $stat['size'],
        ];
    }

    private function listExports(): array
    {
        $files = @scandir($this->root . '/downloads', SCANDIR_SORT_DESCENDING);

        if ($files === false) {
            throw new RuntimeException(Text::_('MOD_RISH_BACKUP_ERROR_LIST'), 503);
        }

        $pattern = $this->exportNamePattern();
        $archives = [];

        foreach ($files as $file) {
            if (preg_match($pattern, $file, $match) !== 1) {
                continue;
            }

            $stat = $this->exportStat($this->archivePath($file));

            if ($stat === null) {
                continue;
            }

            $id = $match[1];
            $deleteQueuedAt = $this->queuedMarker($this->deleteMarkerPath($id), false);
            $archives[] = [
                'id' => $id,
                'created_at' => substr($id, 0, 10) . ' ' . str_replace('-', ':', substr($id, 11, 8)),
                'size' => $stat['size'],
                'delete_queued' => $deleteQueuedAt !== null,
                'delete_queued_at' => $deleteQueuedAt,
            ];
        }

        return $archives;
    }

    private function exportNamePattern(): string
    {
        return '~\A' . preg_quote($this->archivePrefix, '~') . '(' . self::ARCHIVE_ID_PATTERN . ')\.tar\.gz\z~';
    }

    private function requireArchive(string $id): array
    {
        if (preg_match('~\A' . self::ARCHIVE_ID_PATTERN . '\z~', $id) !== 1) {
            throw new RuntimeException(Text::_('MOD_RISH_BACKUP_ERROR_ARCHIVE_ID'), 400);
        }

        $name = $this->archivePrefix . $id . '.tar.gz';
        $path = $this->archivePath($name);
        $stat = $this->exportStat($path);

        if ($stat === null) {
            throw new RuntimeException(Text::_('MOD_RISH_BACKUP_ERROR_DOWNLOAD'), 404);
        }

        return [$name, $path, $stat];
    }

    private function exportStat(string $path): ?array
    {
        $stat = $this->fileStat($path);

        return $this->isRegularFile($stat) && $stat['uid'] === 0 && $stat['size'] > 0 && is_readable($path)
            ? $stat
            : null;
    }

    private function readStatus(string $type, bool $includeQueued = true): array
    {
        $stored = $this->readStoredStatus($type);

        if ($includeQueued && !in_array($type, ['delete', 'cloud-fetch'], true) && ($stored['state'] ?? null) !== 'running') {
            $queuedAt = $this->queuedMarker($this->markerPath($type));

            if ($queuedAt !== null) {
                return ['state' => 'queued', 'updated_at' => $queuedAt, 'message' => ''];
            }
        }

        $status = [
            'state' => $stored['state'] ?? 'idle',
            'updated_at' => $stored['updated_at'] ?? null,
            'message' => $stored['message'] ?? '',
        ];

        if (in_array($type, ['export', 'cloud-fetch'], true) && $status['state'] === 'ready'
            && is_string($stored['archive'] ?? null)
            && preg_match($this->exportNamePattern(), $stored['archive'], $match) === 1) {
            $status['archive_id'] = $match[1];
        }

        if ($type === 'cloud-fetch' && is_string($stored['snapshot_id'] ?? null)
            && preg_match(self::SNAPSHOT_ID_PATTERN, $stored['snapshot_id']) === 1) {
            $status['snapshot_id'] = $stored['snapshot_id'];
        }

        if (is_int($stored['next_at'] ?? null) && $stored['next_at'] > 0) {
            $status['next_at'] = $stored['next_at'];
        }

        return $status;
    }

    private function readStoredStatus(string $type): ?array
    {
        $statusError = Text::_('MOD_RISH_BACKUP_ERROR_STATUS');
        $record = $this->readJson($type, $statusError, in_array($type, self::CLOUD_TYPES, true));
        if ($record === null) {
            return null;
        }

        if (
            !in_array($record['state'] ?? null, ['running', 'ready', 'failed'], true)
            || !is_int($record['updated_at'] ?? null)
            || $record['updated_at'] < 1
            || !is_string($record['message'] ?? null)
        ) {
            throw new RuntimeException($statusError, 503);
        }

        return $record;
    }

    private function readJson(string $name, string $error, bool $requireTrusted): ?array
    {
        $path = $this->root . '/status/' . $name . '.json';
        $stream = null;

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $stat = $this->fileStat($path);

            if ($stat === false) {
                return null;
            }

            if (!$this->isRegularFile($stat) || $stat['size'] > self::MAX_STATUS_BYTES
                || ($requireTrusted && !$this->isTrustedFile($stat))) {
                throw new RuntimeException($error, 503);
            }

            try {
                $stream = $this->openRegularFile($path, $stat);
                break;
            } catch (RuntimeException) {
                // The worker may have published a new inode between lstat and fopen.
            }
        }

        if ($stream === null) {
            throw new RuntimeException($error, 503);
        }

        try {
            try {
                $data = stream_get_contents($stream, self::MAX_STATUS_BYTES + 1);
            } finally {
                fclose($stream);
            }

            if ($data === false || strlen($data) > self::MAX_STATUS_BYTES) {
                throw new RuntimeException($error, 503);
            }

            $record = json_decode($data, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException | RuntimeException) {
            throw new RuntimeException($error, 503);
        }

        if (!is_array($record)) {
            throw new RuntimeException($error, 503);
        }

        return $record;
    }

    private function queue(string $path): void
    {
        $request = @fopen($path, 'xb');

        if ($request !== false) {
            fclose($request);
        } elseif ($this->queuedMarker($path) === null) {
            throw new RuntimeException(Text::_('MOD_RISH_BACKUP_ERROR_REQUEST'), 503);
        }
    }

    private function queuedMarker(string $path, bool $strict = true): ?int
    {
        $stat = $this->fileStat($path);

        if ($stat === false) {
            return null;
        }

        if (!$this->isRegularFile($stat) || $stat['size'] !== 0) {
            if ($strict) {
                throw new RuntimeException(Text::_('MOD_RISH_BACKUP_ERROR_REQUEST'), 503);
            }

            return null;
        }

        return $stat['mtime'];
    }

    private function markerPath(string $type): string
    {
        if (!in_array($type, [...self::TYPES, 'cloud-list'], true)) {
            throw new \InvalidArgumentException('Invalid RISH job type');
        }

        return $this->root . '/inbox/' . $type . '.request';
    }

    private function deleteMarkerPath(string $id): string
    {
        return $this->root . '/inbox/delete.' . $id . '.request';
    }

    private function cloudFetchMarkerPath(string $id): string
    {
        return $this->root . '/inbox/cloud-fetch.' . $id . '.request';
    }

    private function archivePath(string $archive): string
    {
        return $this->root . '/downloads/' . $archive;
    }

    private function checkRequestsEnabled(): void
    {
        if (!$this->acceptsRequests()) {
            throw new RuntimeException(Text::_('MOD_RISH_BACKUP_DISABLED'), 403);
        }
    }

    private function isCanonicalDirectory(string $path): bool
    {
        clearstatcache(true, $path);

        return @realpath($path) === $path && is_dir($path);
    }

    private function fileStat(string $path): array|false
    {
        clearstatcache(true, $path);

        return @lstat($path);
    }

    private function isRegularFile(array|false $stat): bool
    {
        return $stat !== false && ($stat['mode'] & self::FILE_TYPE_MASK) === self::REGULAR_FILE;
    }

    private function isTrustedFile(array|false $stat): bool
    {
        return $this->isRegularFile($stat) && $stat['uid'] === 0 && ($stat['mode'] & 07777) === 0640;
    }

    /**
     * @return resource
     */
    private function openRegularFile(string $path, array $before)
    {
        $stream = @fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException(Text::_('MOD_RISH_BACKUP_ERROR_DOWNLOAD'), 404);
        }

        $after = fstat($stream);

        if (
            !$this->isRegularFile($after)
            || $before['dev'] !== $after['dev']
            || $before['ino'] !== $after['ino']
            || $before['uid'] !== $after['uid']
            || $before['size'] !== $after['size']
        ) {
            fclose($stream);
            throw new RuntimeException(Text::_('MOD_RISH_BACKUP_ERROR_DOWNLOAD'), 404);
        }

        return $stream;
    }
}
