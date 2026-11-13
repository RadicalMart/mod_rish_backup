<?php

declare(strict_types=1);

namespace Rish\Module\RishBackup\Administrator\Helper;

use Joomla\CMS\Factory;
use Rish\Module\RishBackup\Administrator\Service\BackupAjaxService;
use RuntimeException;

defined('_JEXEC') or die;

/** Joomla com_ajax entry point. */
class RishBackupHelper
{
    public function statusAjax(): array|RuntimeException
    {
        return $this->service()->statusAjax();
    }

    public function startBackupAjax(): array|RuntimeException
    {
        return $this->service()->startBackupAjax();
    }

    public function startExportAjax(): array|RuntimeException
    {
        return $this->service()->startExportAjax();
    }

    public function refreshCloudAjax(): array|RuntimeException
    {
        return $this->service()->refreshCloudAjax();
    }

    public function fetchCloudAjax(): array|RuntimeException
    {
        return $this->service()->fetchCloudAjax();
    }

    public function deleteAjax(): array|RuntimeException
    {
        return $this->service()->deleteAjax();
    }

    public function downloadAjax(): RuntimeException
    {
        return $this->service()->downloadAjax();
    }

    private function service(): BackupAjaxService
    {
        return new BackupAjaxService(Factory::getApplication());
    }
}
