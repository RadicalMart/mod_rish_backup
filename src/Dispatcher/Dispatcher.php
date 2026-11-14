<?php

declare(strict_types=1);

namespace Rish\Module\RishBackup\Administrator\Dispatcher;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Rish\Module\RishBackup\Administrator\Service\BackupDirectoryService;
use RuntimeException;

defined('_JEXEC') or die;

class Dispatcher extends AbstractModuleDispatcher
{
    public static function canAccess(CMSApplication $app): bool
    {
        $identity = $app->getIdentity();

        return $app->isClient('administrator')
            && $identity !== null
            && !$identity->guest
            && $identity->authorise('core.admin');
    }

    protected function getLayoutData(): array|false
    {
        $data = parent::getLayoutData();

        if ($data === false || !self::canAccess($data['app'])) {
            return false;
        }

        try {
            $directory = BackupDirectoryService::fromConfiguration(
                (string) $data['params']->get('job_directory', ''),
                $data['app']->getInput()->server->getString('DOCUMENT_ROOT', '')
            );
        } catch (RuntimeException) {
            $data['directoryAvailable'] = false;
            return $data;
        }

        $data['directoryAvailable'] = true;
        $domain = basename($directory->path());

        if ($directory->belongsToSite()) {
            try {
                $host = (string) Uri::getInstance()->getHost();
            } catch (RuntimeException) {
                $host = '';
            }

            if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false) {
                $domain = $host;
            }
        }

        $module = $data['module'];
        $suffix = ' — ' . $domain;
        if (!str_ends_with($module->title, $suffix)) {
            $module->title .= $suffix;
        }

        $assets = $data['app']->getDocument()->getWebAssetManager();
        $assets->registerAndUseStyle(
            'mod_rish_backup.dashboard',
            'mod_rish_backup/dashboard.css',
            ['version' => '1.5.1.4']
        );
        $assets->registerAndUseScript(
            'mod_rish_backup.dashboard',
            'mod_rish_backup/dashboard.js',
            ['version' => '1.5.3.1'],
            ['type' => 'module'],
            ['messages', 'joomla.dialog']
        );
        Text::script('JYES');
        Text::script('JNO');
        Text::script('INFO');

        $labels = [];

        foreach (['idle', 'queued', 'running', 'ready', 'failed'] as $state) {
            $labels[$state] = Text::_('MOD_RISH_BACKUP_STATE_' . strtoupper($state));
        }

        $labels['error'] = Text::_('MOD_RISH_BACKUP_ERROR_NETWORK');
        $labels['confirmDelete'] = Text::_('MOD_RISH_BACKUP_CONFIRM_DELETE');
        $labels['downloadArchive'] = Text::_('MOD_RISH_BACKUP_DOWNLOAD_LABEL');
        $labels['deleteArchive'] = Text::_('MOD_RISH_BACKUP_DELETE_LABEL');
        $labels['noArchives'] = Text::_('MOD_RISH_BACKUP_EXPORT_EMPTY');
        $labels['noSnapshots'] = Text::_('MOD_RISH_BACKUP_CLOUD_EMPTY');
        $labels['cloudLoading'] = Text::_('MOD_RISH_BACKUP_CLOUD_LOADING');
        $labels['cloudNoList'] = Text::_('MOD_RISH_BACKUP_CLOUD_NO_LIST');
        $labels['cloudLoadFailed'] = Text::_('MOD_RISH_BACKUP_CLOUD_LOAD_FAILED');
        $labels['cloudLegacyEmpty'] = Text::_('MOD_RISH_BACKUP_CLOUD_LEGACY_EMPTY');
        $labels['cloudListOperation'] = Text::_('MOD_RISH_BACKUP_CLOUD_LIST_OPERATION');
        $labels['cloudFetchOperation'] = Text::_('MOD_RISH_BACKUP_CLOUD_FETCH_OPERATION');
        $labels['cloudDownloaded'] = Text::_('MOD_RISH_BACKUP_CLOUD_DOWNLOADED');
        $labels['fetchSnapshot'] = Text::_('MOD_RISH_BACKUP_CLOUD_FETCH_LABEL');
        $labels['scheduleDaily'] = Text::_('MOD_RISH_BACKUP_SCHEDULE_DAILY');
        $labels['scheduleCron'] = Text::_('MOD_RISH_BACKUP_SCHEDULE_CRON');
        $labels['scheduleNotConfigured'] = Text::_('MOD_RISH_BACKUP_SCHEDULE_NOT_CONFIGURED');
        $labels['scheduleUnknown'] = Text::_('MOD_RISH_BACKUP_SCHEDULE_UNKNOWN');
        $labels['serverTimezone'] = Text::_('MOD_RISH_BACKUP_SCHEDULE_SERVER_TIMEZONE');
        $labels['retryAt'] = Text::_('MOD_RISH_BACKUP_RETRY_AT');

        $moduleId = (int) $module->id;
        $data['labels'] = $labels;
        $data['ajaxUrl'] = 'index.php?option=com_ajax&module=rish_backup&module_id=' . $moduleId;
        $data['exportListId'] = 'rish-backup-local-' . $moduleId;
        $data['cloudListId'] = 'rish-backup-cloud-' . $moduleId;
        $data['token'] = Session::getFormToken();

        return $data;
    }
}
