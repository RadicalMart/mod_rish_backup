<?php

declare(strict_types=1);

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

/** @var bool $directoryAvailable */
/** @var array $labels */
/** @var string $ajaxUrl */
/** @var string $exportListId */
/** @var string $cloudListId */
/** @var string $token */

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

if (!$directoryAvailable) :
    ?>
    <div class="p-3">
        <p class="alert alert-warning mb-0"><?= $escape(Text::_('MOD_RISH_BACKUP_ERROR_DIRECTORY')) ?></p>
    </div>
    <?php
    return;
endif;
?>
<div class="p-3" data-rish-backup
    data-endpoint="<?= $escape($ajaxUrl) ?>"
    data-token="<?= $escape($token) ?>"
    data-labels="<?= $escape(json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">
    <p class="alert alert-danger mb-3" data-rish-error role="alert" hidden></p>
    <p class="alert alert-warning mb-3" data-rish-disabled hidden><?= $escape(Text::_('MOD_RISH_BACKUP_DISABLED')) ?></p>
    <p class="alert alert-warning mb-3" data-rish-queue-warning role="status" hidden><?= $escape(Text::_('MOD_RISH_BACKUP_QUEUE_WARNING')) ?></p>
    <section class="rish-backup-section" aria-labelledby="<?= $escape($cloudListId) ?>">
        <header class="rish-backup-header">
            <h3 class="h5 mb-0" id="<?= $escape($cloudListId) ?>"><?= $escape(Text::_('MOD_RISH_BACKUP_CLOUD_LIST')) ?></h3>
            <div class="rish-backup-actions">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-rish-cloud-refresh disabled hidden>
                    <?= $escape(Text::_('MOD_RISH_BACKUP_CLOUD_REFRESH')) ?>
                </button>
                <button type="button" class="btn btn-sm btn-primary" data-rish-backup-start disabled>
                    <?= $escape(Text::_('MOD_RISH_BACKUP_START_BACKUP')) ?>
                </button>
            </div>
        </header>
        <p class="rish-backup-status" data-rish-schedule hidden></p>
        <p class="rish-backup-status" data-rish-backup-status role="status" hidden></p>
        <div data-rish-cloud hidden>
            <p class="rish-backup-status" data-rish-cloud-list-status role="status" hidden></p>
            <p class="rish-backup-status" data-rish-cloud-fetch-status role="status" hidden></p>
            <p class="alert alert-warning mt-2 mb-0" data-rish-cloud-error role="alert" hidden></p>
            <p class="rish-backup-status" data-rish-cloud-legacy hidden><?= $escape(Text::_('MOD_RISH_BACKUP_CLOUD_LEGACY')) ?></p>
            <div class="table-responsive rish-backup-table-wrap" tabindex="0" role="group" aria-labelledby="<?= $escape($cloudListId) ?>">
                <table class="table table-sm align-middle mb-0 rish-backup-table">
                    <thead>
                        <tr>
                            <th scope="col"><?= $escape(Text::_('MOD_RISH_BACKUP_EXPORT_DATE')) ?></th>
                            <th scope="col"><?= $escape(Text::_('MOD_RISH_BACKUP_EXPORT_SIZE')) ?></th>
                            <th scope="col"><?= $escape(Text::_('MOD_RISH_BACKUP_EXPORT_ACTIONS')) ?></th>
                            <th scope="col"><?= $escape(Text::_('MOD_RISH_BACKUP_CLOUD_STATUS')) ?></th>
                        </tr>
                    </thead>
                    <tbody data-rish-cloud-rows>
                        <tr data-rish-cloud-empty>
                            <td colspan="4"><?= $escape(Text::_('MOD_RISH_BACKUP_CLOUD_LOADING')) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <template data-rish-cloud-row>
        <tr>
            <td>
                <time></time>
                <div class="small text-body-secondary" data-rish-encrypted hidden><?= $escape(Text::_('MOD_RISH_BACKUP_CLOUD_ENCRYPTED')) ?></div>
            </td>
            <td data-rish-size></td>
            <td>
                <button type="button" class="btn btn-sm btn-outline-primary" data-rish-cloud-fetch disabled>
                    <?= $escape(Text::_('MOD_RISH_BACKUP_CLOUD_FETCH')) ?>
                </button>
            </td>
            <td>
                <span class="rish-backup-status" data-rish-cloud-row-status role="status" hidden></span>
            </td>
        </tr>
    </template>
    <section class="rish-backup-section" aria-labelledby="<?= $escape($exportListId) ?>">
        <header class="rish-backup-header">
            <h3 class="h5 mb-0" id="<?= $escape($exportListId) ?>"><?= $escape(Text::_('MOD_RISH_BACKUP_EXPORT_LIST')) ?></h3>
            <button type="button" class="btn btn-sm btn-outline-primary" data-rish-export-start disabled>
                <?= $escape(Text::_('MOD_RISH_BACKUP_START_EXPORT')) ?>
            </button>
        </header>
        <p class="rish-backup-status" data-rish-export-status role="status" hidden></p>
        <p class="rish-backup-status" data-rish-delete-status role="status" hidden></p>
        <div class="table-responsive rish-backup-table-wrap" tabindex="0" role="group" aria-labelledby="<?= $escape($exportListId) ?>">
            <table class="table table-sm align-middle mb-0 rish-backup-table">
                <thead>
                    <tr>
                        <th scope="col"><?= $escape(Text::_('MOD_RISH_BACKUP_EXPORT_DATE')) ?></th>
                        <th scope="col"><?= $escape(Text::_('MOD_RISH_BACKUP_EXPORT_SIZE')) ?></th>
                        <th scope="col"><?= $escape(Text::_('MOD_RISH_BACKUP_EXPORT_ACTIONS')) ?></th>
                    </tr>
                </thead>
                <tbody data-rish-export-rows>
                    <tr data-rish-export-empty>
                        <td colspan="3"><?= $escape(Text::_('MOD_RISH_BACKUP_LOADING')) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
    <template data-rish-export-row>
        <tr>
            <td><time></time></td>
            <td data-rish-size></td>
            <td>
                <div class="rish-backup-actions">
                    <form action="<?= $escape($ajaxUrl . '&method=download&format=raw') ?>" method="post" class="m-0">
                        <?= HTMLHelper::_('form.token') ?>
                        <input type="hidden" name="archive_id">
                        <button type="submit" class="btn btn-sm btn-outline-primary" data-rish-download>
                            <?= $escape(Text::_('MOD_RISH_BACKUP_DOWNLOAD')) ?>
                        </button>
                    </form>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-rish-delete>
                        <?= $escape(Text::_('MOD_RISH_BACKUP_DELETE')) ?>
                    </button>
                </div>
                <span class="small" data-rish-delete-queued hidden><?= $escape(Text::_('MOD_RISH_BACKUP_DELETE_QUEUED')) ?></span>
            </td>
        </tr>
    </template>
    <noscript><p class="small mb-0 mt-2"><?= $escape(Text::_('MOD_RISH_BACKUP_REQUIRES_JAVASCRIPT')) ?></p></noscript>
</div>
