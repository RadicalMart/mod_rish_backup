(() => {
    'use strict';

    const pollInterval = 5000;
    const cloudCacheLifetime = 300;
    const cloudRefreshCooldown = 30;
    const activeStates = new Set(['queued', 'running']);
    const sizeUnits = ['byte', 'kilobyte', 'megabyte', 'gigabyte', 'terabyte', 'petabyte'];

    const formatSize = (bytes) => {
        const unit = Math.min(Math.floor(Math.log10(bytes) / 3), sizeUnits.length - 1);

        return new Intl.NumberFormat(undefined, {
            style: 'unit',
            unit: sizeUnits[unit],
            maximumFractionDigits: 1,
        }).format(bytes / 1000 ** unit);
    };

    for (const dashboard of document.querySelectorAll('[data-rish-backup]')) {
        const labels = JSON.parse(dashboard.dataset.labels);
        const alert = dashboard.querySelector('[data-rish-error]');
        const disabled = dashboard.querySelector('[data-rish-disabled]');
        const body = dashboard.querySelector('[data-rish-export-rows]');
        const emptyRow = body.querySelector('[data-rish-export-empty]');
        const template = dashboard.querySelector('[data-rish-export-row]');
        const deleteStatus = dashboard.querySelector('[data-rish-delete-status]');
        const backupButton = dashboard.querySelector('[data-rish-backup-start]');
        const backupStatus = dashboard.querySelector('[data-rish-backup-status]');
        const scheduleStatus = dashboard.querySelector('[data-rish-schedule]');
        const exportButton = dashboard.querySelector('[data-rish-export-start]');
        const exportStatus = dashboard.querySelector('[data-rish-export-status]');
        const cloudSection = dashboard.querySelector('[data-rish-cloud]');
        const cloudListStatus = dashboard.querySelector('[data-rish-cloud-list-status]');
        const cloudFetchStatus = dashboard.querySelector('[data-rish-cloud-fetch-status]');
        const cloudError = dashboard.querySelector('[data-rish-cloud-error]');
        const cloudLegacy = dashboard.querySelector('[data-rish-cloud-legacy]');
        const cloudRefresh = dashboard.querySelector('[data-rish-cloud-refresh]');
        const cloudBody = dashboard.querySelector('[data-rish-cloud-rows]');
        const cloudEmptyRow = cloudBody.querySelector('[data-rish-cloud-empty]');
        const cloudTemplate = dashboard.querySelector('[data-rish-cloud-row]');
        const rows = new Map();
        const cloudRows = new Map();
        let timer;
        let pending;
        let posting = false;
        let autoRefreshChecked = false;
        let previousBackup;
        let refreshAfterBackup = 0;

        const request = async (action, method, id, signal) => {
            const url = new URL(dashboard.dataset.endpoint, window.location.href);
            url.searchParams.set('method', action);
            url.searchParams.set('format', 'json');

            const options = {
                method,
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' },
                signal,
            };

            if (method === 'POST') {
                options.body = new URLSearchParams({ [dashboard.dataset.token]: '1' });

                if (id !== undefined) {
                    options.body.set(action === 'fetchCloud' ? 'snapshot_id' : 'archive_id', id);
                }
            }

            const response = await fetch(url, options);
            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || labels.error);
            }

            return result.data;
        };

        const renderStatus = (element, record, operation) => {
            const { state, updated_at: updated, message } = record;
            const date = new Date(updated * 1000);
            const timestamp = !activeStates.has(state) && updated && !Number.isNaN(date.valueOf()) ? date.toLocaleString() : '';
            const detail = state === 'failed' || !message
                ? [operation, labels[state] || labels.error, message]
                : [message];
            const line = [...detail, timestamp].filter(Boolean).join(' · ');

            element.dataset.state = state;
            if (element.textContent !== line) {
                element.textContent = line;
            }
        };

        const renderArchives = (archives, canDelete) => {
            const current = new Set();
            let next = body.firstElementChild;

            for (const archive of archives) {
                const { id, created_at: created, size, delete_queued: queued } = archive;
                let row = rows.get(id);

                if (!row) {
                    const element = template.content.firstElementChild.cloneNode(true);
                    const date = element.querySelector('time');
                    const button = element.querySelector('[data-rish-delete]');
                    const download = element.querySelector('[data-rish-download]');

                    date.dateTime = created.replace(' ', 'T');
                    date.textContent = created;
                    element.querySelector('[data-rish-size]').textContent = formatSize(size);
                    element.querySelector('[name="archive_id"]').value = id;
                    download.setAttribute('aria-label', labels.downloadArchive.replace('%s', created));
                    button.setAttribute('aria-label', labels.deleteArchive.replace('%s', created));

                    button.addEventListener('click', () => {
                        if (window.confirm(labels.confirmDelete.replace('%s', created))) {
                            refresh('delete', 'POST', id);
                        }
                    });

                    row = { element, button, download, queued: element.querySelector('[data-rish-delete-queued]') };
                    rows.set(id, row);
                }

                row.button.disabled = queued || !canDelete;
                row.download.disabled = queued;
                row.queued.hidden = !queued;

                if (row.element !== next) {
                    body.insertBefore(row.element, next);
                }

                next = row.element.nextElementSibling;
                current.add(id);
            }

            for (const [id, row] of rows) {
                if (!current.has(id)) {
                    row.element.remove();
                    rows.delete(id);
                }
            }

            emptyRow.firstElementChild.textContent = labels.noArchives;
            emptyRow.hidden = archives.length !== 0;
        };

        const renderSchedule = (schedule) => {
            if (!schedule) {
                scheduleStatus.hidden = true;
                return;
            }

            let message;
            if (schedule.state === 'configured') {
                message = schedule.jobs.map(({ expression, timezone }) => {
                    const daily = /^(\d{1,2}) (\d{1,2}) \* \* \*$/.exec(expression);
                    const zone = timezone || labels.serverTimezone;

                    if (daily && Number(daily[1]) < 60 && Number(daily[2]) < 24) {
                        const time = daily[2].padStart(2, '0') + ':' + daily[1].padStart(2, '0');
                        return labels.scheduleDaily.replace('%s', time) + ' (' + zone + ')';
                    }

                    return labels.scheduleCron.replace('%s', expression) + ' (' + zone + ')';
                }).join(' · ');
            } else {
                message = schedule.state === 'not_configured' ? labels.scheduleNotConfigured : labels.scheduleUnknown;
            }

            if (scheduleStatus.textContent !== message) {
                scheduleStatus.textContent = message;
            }
            scheduleStatus.hidden = false;
        };

        const renderCloudRows = (snapshots, canFetch, emptyMessage, fetchStatus) => {
            const current = new Set();
            let next = cloudBody.firstElementChild;

            for (const snapshot of snapshots) {
                const { id, created_at: created, size, encrypted } = snapshot;
                let row = cloudRows.get(id);

                if (!row) {
                    const element = cloudTemplate.content.firstElementChild.cloneNode(true);
                    const date = element.querySelector('time');
                    const button = element.querySelector('[data-rish-cloud-fetch]');

                    date.dateTime = created.replace(' ', 'T');
                    date.textContent = created;
                    element.querySelector('[data-rish-encrypted]').hidden = !encrypted;
                    button.setAttribute('aria-label', labels.fetchSnapshot.replace('%s', created));
                    button.addEventListener('click', () => refresh('fetchCloud', 'POST', id));

                    row = {
                        element,
                        button,
                        size: element.querySelector('[data-rish-size]'),
                        status: element.querySelector('[data-rish-cloud-row-status]'),
                    };
                    cloudRows.set(id, row);
                }

                const sizeLabel = formatSize(size);

                if (row.size.textContent !== sizeLabel) {
                    row.size.textContent = sizeLabel;
                }

                row.button.disabled = !canFetch;
                row.status.hidden = fetchStatus?.snapshot_id !== id || fetchStatus.state === 'idle';
                if (!row.status.hidden) {
                    renderStatus(row.status, fetchStatus);
                }

                if (row.element !== next) {
                    cloudBody.insertBefore(row.element, next);
                }

                next = row.element.nextElementSibling;
                current.add(id);
            }

            for (const [id, row] of cloudRows) {
                if (!current.has(id)) {
                    row.element.remove();
                    cloudRows.delete(id);
                }
            }

            cloudEmptyRow.firstElementChild.textContent = emptyMessage;
            cloudEmptyRow.hidden = snapshots.length !== 0;
        };

        const renderCloud = (cloud, acceptsRequests, willRefresh) => {
            cloudSection.hidden = !cloud;
            cloudRefresh.hidden = !cloud;

            if (!cloud) {
                return;
            }

            cloudError.hidden = !cloud.error;
            cloudError.textContent = cloud.error || '';
            cloudLegacy.hidden = !cloud.legacy;
            cloudListStatus.hidden = !cloud.list || ['idle', 'ready'].includes(cloud.list.state);
            cloudFetchStatus.hidden = !cloud.fetch || cloud.fetch.state === 'idle'
                || cloud.snapshots?.some(snapshot => snapshot.id === cloud.fetch.snapshot_id);
            cloudRefresh.disabled = !acceptsRequests || willRefresh || activeStates.has(cloud.list?.state)
                || cloud.list?.next_at > cloud.server_time;

            if (cloud.error) {
                renderCloudRows([], false, labels.cloudLoadFailed);
                return;
            }

            renderStatus(cloudListStatus, cloud.list, labels.cloudListOperation);
            renderStatus(cloudFetchStatus, cloud.fetch, labels.cloudFetchOperation);

            let emptyMessage = labels.cloudNoList;
            if (willRefresh || activeStates.has(cloud.list.state)) {
                emptyMessage = labels.cloudLoading;
            } else if (cloud.list.state === 'failed' && !cloud.updated_at) {
                emptyMessage = labels.cloudLoadFailed;
            } else if (cloud.updated_at) {
                emptyMessage = cloud.legacy ? labels.cloudLegacyEmpty : labels.noSnapshots;
            }

            const canFetch = acceptsRequests && !activeStates.has(cloud.fetch.state)
                && !(cloud.fetch.next_at > cloud.server_time);
            renderCloudRows(cloud.snapshots, canFetch, emptyMessage, cloud.fetch);
        };

        const render = (data, willRefresh) => {
            const creating = activeStates.has(data.backup.state) || activeStates.has(data.export.state);
            backupButton.disabled = creating || !data.accepts_requests;
            exportButton.disabled = creating || !data.accepts_requests;
            backupStatus.hidden = data.backup.state === 'idle';
            renderStatus(backupStatus, data.backup);
            exportStatus.hidden = data.export.state === 'idle';
            renderStatus(exportStatus, data.export);
            disabled.hidden = data.accepts_requests;
            deleteStatus.hidden = data.delete.state === 'idle';
            renderStatus(deleteStatus, data.delete);
            renderArchives(data.archives, data.accepts_requests && data.delete.state !== 'running');
            renderSchedule(data.schedule);
            renderCloud(data.cloud, data.accepts_requests, willRefresh);
        };

        const disableMutations = () => {
            backupButton.disabled = true;
            exportButton.disabled = true;
            cloudRefresh.disabled = true;

            for (const { button } of rows.values()) {
                button.disabled = true;
            }

            for (const { button } of cloudRows.values()) {
                button.disabled = true;
            }
        };

        const refresh = async (action = 'status', method = 'GET', id) => {
            if (pending && (method === 'GET' || posting)) {
                return;
            }

            clearTimeout(timer);
            pending?.abort();
            const controller = new AbortController();
            pending = controller;
            posting = method === 'POST';
            let willRefresh = false;

            if (posting) {
                disableMutations();
            }

            try {
                const data = await request(action, method, id, controller.signal);

                if (controller !== pending) {
                    return;
                }

                if (!autoRefreshChecked && data.cloud && data.accepts_requests
                    && !(data.cloud.list?.next_at > data.cloud.server_time)) {
                    autoRefreshChecked = true;
                    const age = data.cloud.server_time - (data.cloud.updated_at || 0);
                    willRefresh = !activeStates.has(data.cloud.list?.state) && age >= cloudCacheLifetime;
                }

                if (data.backup.state === 'ready' && previousBackup
                    && (activeStates.has(previousBackup.state) || data.backup.updated_at !== previousBackup.updated_at)) {
                    refreshAfterBackup = data.backup.updated_at;
                }
                previousBackup = data.backup;

                if (refreshAfterBackup && data.cloud?.updated_at >= refreshAfterBackup) {
                    refreshAfterBackup = 0;
                }

                if (refreshAfterBackup && data.cloud?.list && data.accepts_requests
                    && !activeStates.has(data.cloud.list.state)
                    && data.cloud.server_time >= (data.cloud.list.next_at
                        ?? (data.cloud.list.updated_at || 0) + cloudRefreshCooldown)) {
                    willRefresh = true;
                }

                render(data, willRefresh);
                alert.hidden = true;
                alert.textContent = '';
            } catch (error) {
                if (controller !== pending) {
                    return;
                }

                disableMutations();
                disabled.hidden = true;
                alert.textContent = error instanceof Error && error.constructor === Error ? error.message : labels.error;
                alert.hidden = false;
            } finally {
                if (controller === pending) {
                    pending = undefined;
                    posting = false;

                    if (willRefresh) {
                        refreshAfterBackup = 0;
                        refresh('refreshCloud', 'POST');
                    } else {
                        timer = window.setTimeout(refresh, pollInterval);
                    }
                }
            }
        };

        backupButton.addEventListener('click', () => refresh('startBackup', 'POST'));
        exportButton.addEventListener('click', () => refresh('startExport', 'POST'));
        cloudRefresh.addEventListener('click', () => refresh('refreshCloud', 'POST'));

        refresh();
    }
})();
