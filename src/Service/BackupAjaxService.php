<?php

declare(strict_types=1);

namespace Rish\Module\RishBackup\Administrator\Service;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\Registry\Registry;
use Rish\Module\RishBackup\Administrator\Dispatcher\Dispatcher;
use RuntimeException;

defined('_JEXEC') or die;

class BackupAjaxService
{
    public function __construct(private readonly CMSApplication $app)
    {
    }

    public function statusAjax(): array|RuntimeException
    {
        try {
            return $this->getDirectory('GET', 'json')->status();
        } catch (RuntimeException $error) {
            return $error;
        }
    }

    public function startBackupAjax(): array|RuntimeException
    {
        return $this->start('backup');
    }

    public function startExportAjax(): array|RuntimeException
    {
        return $this->start('export');
    }

    public function refreshCloudAjax(): array|RuntimeException
    {
        try {
            return $this->getDirectory('POST', 'json')->requestCloudList();
        } catch (RuntimeException $error) {
            return $error;
        }
    }

    public function fetchCloudAjax(): array|RuntimeException
    {
        try {
            $directory = $this->getDirectory('POST', 'json');
            $id = $this->app->getInput()->post->get('snapshot_id', '', 'raw');

            if (!is_string($id)) {
                throw new RuntimeException(Text::_('MOD_RISH_BACKUP_ERROR_SNAPSHOT_ID'), 400);
            }

            return $directory->requestCloudFetch($id);
        } catch (RuntimeException $error) {
            return $error;
        }
    }

    public function deleteAjax(): array|RuntimeException
    {
        try {
            $directory = $this->getDirectory('POST', 'json');

            return $directory->requestDelete($this->getArchiveId());
        } catch (RuntimeException $error) {
            return $error;
        }
    }

    public function downloadAjax(): RuntimeException
    {
        try {
            $directory = $this->getDirectory('POST', 'raw');
            $export = $directory->openExport($this->getArchiveId());
        } catch (RuntimeException $error) {
            return $error;
        }

        $app = $this->app;
        $app->getSession()->close();
        @ini_set('zlib.output_compression', '0');

        while (ob_get_level() > 0 && @ob_end_clean()) {
            // Send the archive without accumulated output or a PHP output buffer.
        }

        if (headers_sent() || ob_get_level() > 0) {
            fclose($export['stream']);

            return new RuntimeException(Text::_('MOD_RISH_BACKUP_ERROR_DOWNLOAD'), 503);
        }

        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $export['archive'] . '"');
        header('Content-Length: ' . $export['size']);
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        fpassthru($export['stream']);
        fclose($export['stream']);
        $app->close();
    }

    private function start(string $type): array|RuntimeException
    {
        try {
            return $this->getDirectory('POST', 'json')->request($type);
        } catch (RuntimeException $error) {
            return $error;
        }
    }

    private function getArchiveId(): string
    {
        $id = $this->app->getInput()->post->get('archive_id', '', 'raw');

        if (!is_string($id)) {
            throw new RuntimeException(Text::_('MOD_RISH_BACKUP_ERROR_ARCHIVE_ID'), 400);
        }

        return $id;
    }

    private function getDirectory(string $method, string $format): JobDirectoryService
    {
        $app = $this->app;

        if (!Dispatcher::canAccess($app)) {
            throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $app->getLanguage()->load('mod_rish_backup', JPATH_ADMINISTRATOR);

        $input = $app->getInput();

        if ($input->getMethod() !== $method || strtolower($input->getWord('format', '')) !== $format) {
            throw new RuntimeException(Text::_('MOD_RISH_BACKUP_ERROR_METHOD'), 405);
        }

        if ($method === 'POST' && !Session::checkToken('post')) {
            throw new RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        $module = ModuleHelper::getModuleById((string) $input->get->getInt('module_id', 0));

        if ($module->module !== 'mod_rish_backup') {
            throw new RuntimeException(Text::_('MOD_RISH_BACKUP_ERROR_MODULE'), 404);
        }

        $params = new Registry($module->params);

        return new JobDirectoryService(
            new BackupDirectoryService(
                (string) $params->get('job_directory', ''),
                $input->server->getString('DOCUMENT_ROOT', '')
            )
        );
    }
}
