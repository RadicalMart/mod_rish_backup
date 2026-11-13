<?php

declare(strict_types=1);

namespace Rish\Module\RishBackup\Administrator\Dispatcher;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Rish\Module\RishBackup\Administrator\Helper\RishBackupHelper;

defined('_JEXEC') or die;

final class Dispatcher extends AbstractModuleDispatcher
{
    protected function getLayoutData(): array|false
    {
        $data = parent::getLayoutData();

        if ($data === false || !RishBackupHelper::canAccess($data['app'])) {
            return false;
        }

        return $data;
    }
}
