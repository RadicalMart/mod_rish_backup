<?php

declare(strict_types=1);

namespace Rish\Module\RishBackup\Administrator\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\TextField;
use Joomla\CMS\Language\Text;
use Rish\Module\RishBackup\Administrator\Helper\JobDirectory;
use RuntimeException;

defined('_JEXEC') or die;

final class RishJobDirectoryField extends TextField
{
    protected $type = 'RishJobDirectory';

    public function renderField($options = []): string
    {
        if ($this->collectLayoutData()['directoryWarning']) {
            $options['descClass'] = 'alert alert-warning mt-2 mb-0';
        }

        return parent::renderField($options);
    }

    protected function getLayoutData(): array
    {
        $data = parent::getLayoutData();
        $configured = (string) ($data['value'] ?? '');
        $documentRoot = Factory::getApplication()->getInput()->server->getString('DOCUMENT_ROOT', '');

        try {
            $directory = JobDirectory::fromConfiguration($configured, $documentRoot);
        } catch (RuntimeException) {
            $directory = null;
        }

        if (trim($configured) === '') {
            $data['value'] = $directory?->path() ?? '';
        }

        $warning = match (true) {
            $directory === null => 'MOD_RISH_BACKUP_ERROR_DIRECTORY',
            !$directory->acceptsRequests() => 'MOD_RISH_BACKUP_SETTINGS_DISABLED',
            default => null,
        };
        $data['directoryWarning'] = $warning !== null;

        if ($warning !== null) {
            $data['description'] = htmlspecialchars(
                Text::_($warning),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
        }

        return $data;
    }
}
