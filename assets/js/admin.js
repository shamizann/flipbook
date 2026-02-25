document.addEventListener('DOMContentLoaded', function () {
    const uploadInput = document.getElementById('upload-input');
    const uploadBtn = document.getElementById('upload-btn');
    const bookList = document.getElementById('admin-book-list');
    const messageBox = document.getElementById('admin-message');
    const searchInput = document.getElementById('search-input');
    const statusFilter = document.getElementById('status-filter');
    const perPageSelect = document.getElementById('per-page-select');
    const refreshBtn = document.getElementById('refresh-btn');
    const listSummary = document.getElementById('list-summary');
    const paginationControls = document.getElementById('pagination-controls');
    const auditActionFilter = document.getElementById('audit-action-filter');
    const auditPerPageSelect = document.getElementById('audit-per-page-select');
    const auditRefreshBtn = document.getElementById('audit-refresh-btn');
    const auditSummary = document.getElementById('audit-summary');
    const auditLogList = document.getElementById('audit-log-list');
    const auditPaginationControls = document.getElementById('audit-pagination-controls');
    const editTitleModal = document.getElementById('edit-title-modal');
    const editTitleForm = document.getElementById('edit-title-form');
    const editTitleInput = document.getElementById('edit-title-input');
    const editTitleError = document.getElementById('edit-title-error');
    const editTitleBookLabel = document.getElementById('edit-title-book-label');
    const editTitleCancelBtn = document.getElementById('edit-title-cancel');
    const editTitleSaveBtn = document.getElementById('edit-title-save');
    const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfTokenMeta ? csrfTokenMeta.getAttribute('content') : '';

    const state = {
        page: 1,
        perPage: 10,
        status: 'active',
        q: ''
    };

    const auditState = {
        page: 1,
        perPage: 10,
        action: ''
    };

    let searchDebounceTimer = null;
    let editTitleContext = null;

    function showMessage(text, type = 'info') {
        messageBox.textContent = text;
        messageBox.className = `status-message ${type}`;
    }

    function clearMessage() {
        messageBox.textContent = '';
        messageBox.className = 'status-message';
    }

    function appendCsrfToken(formData) {
        if (!csrfToken) {
            return;
        }
        formData.append('csrf_token', csrfToken);
    }

    function isCsrfTokenError(message) {
        return String(message || '').toLowerCase().includes('csrf');
    }

    function formatBytes(value) {
        const bytes = Number(value);
        if (!Number.isFinite(bytes) || bytes <= 0) {
            return 'Unknown size';
        }

        const units = ['B', 'KB', 'MB', 'GB'];
        let current = bytes;
        let index = 0;
        while (current >= 1024 && index < units.length - 1) {
            current /= 1024;
            index += 1;
        }

        const fixed = current >= 100 || index === 0 ? 0 : 1;
        return `${current.toFixed(fixed)} ${units[index]}`;
    }

    function formatPageCount(value) {
        const pages = Number(value);
        if (!Number.isInteger(pages) || pages <= 0) {
            return 'Pages unknown';
        }
        return `${pages} page${pages > 1 ? 's' : ''}`;
    }

    function formatDateTime(value) {
        if (!value) {
            return 'Unknown time';
        }
        const raw = String(value).trim();
        const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T');
        const date = new Date(normalized);
        if (Number.isNaN(date.getTime())) {
            return raw;
        }
        return date.toLocaleString();
    }

    function updateSummary(pagination) {
        if (!pagination) {
            listSummary.textContent = '';
            return;
        }

        const total = Number(pagination.total) || 0;
        const perPage = Number(pagination.perPage) || state.perPage;
        const page = Number(pagination.page) || state.page;
        const from = total === 0 ? 0 : (page - 1) * perPage + 1;
        const to = Math.min(total, page * perPage);
        listSummary.textContent = `Showing ${from}-${to} of ${total} books`;
    }

    function renderPagination(pagination) {
        paginationControls.innerHTML = '';
        if (!pagination || (pagination.totalPages || 1) <= 1) {
            return;
        }

        const totalPages = Number(pagination.totalPages) || 1;
        const currentPage = Number(pagination.page) || 1;

        function makePageButton(label, targetPage, disabled, isActive) {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = label;
            button.disabled = disabled;
            if (isActive) {
                button.classList.add('active');
            }
            button.addEventListener('click', () => {
                if (targetPage === state.page) {
                    return;
                }
                state.page = targetPage;
                fetchBooks();
            });
            return button;
        }

        paginationControls.appendChild(makePageButton('Prev', Math.max(1, currentPage - 1), currentPage <= 1, false));

        const start = Math.max(1, currentPage - 2);
        const end = Math.min(totalPages, currentPage + 2);
        for (let i = start; i <= end; i += 1) {
            paginationControls.appendChild(makePageButton(String(i), i, false, i === currentPage));
        }

        paginationControls.appendChild(makePageButton('Next', Math.min(totalPages, currentPage + 1), currentPage >= totalPages, false));
    }

    function updateAuditSummary(pagination) {
        if (!auditSummary) {
            return;
        }

        if (!pagination) {
            auditSummary.textContent = '';
            return;
        }

        const total = Number(pagination.total) || 0;
        const perPage = Number(pagination.perPage) || auditState.perPage;
        const page = Number(pagination.page) || auditState.page;
        const from = total === 0 ? 0 : (page - 1) * perPage + 1;
        const to = Math.min(total, page * perPage);
        auditSummary.textContent = `Showing ${from}-${to} of ${total} audit log entries`;
    }

    function renderAuditPagination(pagination) {
        if (!auditPaginationControls) {
            return;
        }

        auditPaginationControls.innerHTML = '';
        if (!pagination || (pagination.totalPages || 1) <= 1) {
            return;
        }

        const totalPages = Number(pagination.totalPages) || 1;
        const currentPage = Number(pagination.page) || 1;

        function makePageButton(label, targetPage, disabled, isActive) {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = label;
            button.disabled = disabled;
            if (isActive) {
                button.classList.add('active');
            }
            button.addEventListener('click', () => {
                if (targetPage === auditState.page) {
                    return;
                }
                auditState.page = targetPage;
                fetchAuditLogs();
            });
            return button;
        }

        auditPaginationControls.appendChild(makePageButton('Prev', Math.max(1, currentPage - 1), currentPage <= 1, false));

        const start = Math.max(1, currentPage - 2);
        const end = Math.min(totalPages, currentPage + 2);
        for (let i = start; i <= end; i += 1) {
            auditPaginationControls.appendChild(makePageButton(String(i), i, false, i === currentPage));
        }

        auditPaginationControls.appendChild(makePageButton('Next', Math.min(totalPages, currentPage + 1), currentPage >= totalPages, false));
    }

    function formatActionLabel(value) {
        const action = String(value || '').trim();
        return action === '' ? 'unknown' : action;
    }

    function formatAuditDetails(detailsRaw) {
        if (detailsRaw === null || detailsRaw === undefined || detailsRaw === '') {
            return '-';
        }

        let details = detailsRaw;
        if (typeof detailsRaw === 'string') {
            try {
                details = JSON.parse(detailsRaw);
            } catch (_error) {
                return detailsRaw.length > 120 ? `${detailsRaw.slice(0, 117)}...` : detailsRaw;
            }
        }

        if (details && typeof details === 'object' && !Array.isArray(details)) {
            const entries = Object.entries(details).slice(0, 4).map(([key, value]) => {
                const normalized = value === null || value === undefined ? 'null' : String(value);
                return `${key}: ${normalized}`;
            });
            return entries.length > 0 ? entries.join(' | ') : '-';
        }

        if (Array.isArray(details)) {
            const serialized = JSON.stringify(details);
            if (!serialized) {
                return '-';
            }
            return serialized.length > 120 ? `${serialized.slice(0, 117)}...` : serialized;
        }

        const text = String(details);
        return text.length > 120 ? `${text.slice(0, 117)}...` : text;
    }

    function renderAuditLogs(logs) {
        if (!auditLogList) {
            return;
        }

        auditLogList.innerHTML = '';

        if (!Array.isArray(logs) || logs.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'audit-empty';
            empty.textContent = 'No audit logs found.';
            auditLogList.appendChild(empty);
            return;
        }

        const table = document.createElement('table');
        table.className = 'audit-table';

        const thead = document.createElement('thead');
        const headRow = document.createElement('tr');
        ['Time', 'Action', 'Admin', 'Book', 'Request', 'Details'].forEach(label => {
            const th = document.createElement('th');
            th.textContent = label;
            headRow.appendChild(th);
        });
        thead.appendChild(headRow);

        const tbody = document.createElement('tbody');
        logs.forEach(log => {
            const row = document.createElement('tr');

            const timeCell = document.createElement('td');
            timeCell.textContent = formatDateTime(log.created_at);

            const actionCell = document.createElement('td');
            const actionPill = document.createElement('span');
            actionPill.className = 'audit-action-pill';
            actionPill.textContent = formatActionLabel(log.action);
            actionCell.appendChild(actionPill);

            const adminCell = document.createElement('td');
            adminCell.textContent = log.admin_username || (log.admin_user_id ? `User #${log.admin_user_id}` : 'System');

            const bookCell = document.createElement('td');
            if (log.book_id) {
                const title = log.book_title ? ` - ${log.book_title}` : '';
                bookCell.textContent = `#${log.book_id}${title}`;
            } else {
                bookCell.textContent = '-';
            }

            const requestCell = document.createElement('td');
            requestCell.textContent = log.ip_address || '-';
            const requestMeta = document.createElement('div');
            requestMeta.className = 'audit-secondary';
            requestMeta.textContent = log.user_agent || '-';
            requestMeta.title = log.user_agent || '';
            requestCell.appendChild(requestMeta);

            const detailsCell = document.createElement('td');
            detailsCell.textContent = formatAuditDetails(log.details_json);

            row.appendChild(timeCell);
            row.appendChild(actionCell);
            row.appendChild(adminCell);
            row.appendChild(bookCell);
            row.appendChild(requestCell);
            row.appendChild(detailsCell);
            tbody.appendChild(row);
        });

        table.appendChild(thead);
        table.appendChild(tbody);
        auditLogList.appendChild(table);
    }

    function fetchAuditLogs() {
        if (!auditLogList) {
            return;
        }

        auditLogList.innerHTML = '<div class="audit-empty">Loading audit logs...</div>';

        const params = new URLSearchParams();
        params.set('page', String(auditState.page));
        params.set('per_page', String(auditState.perPage));
        if (auditState.action) {
            params.set('action', auditState.action);
        }

        fetch(`list_audit_logs.php?${params.toString()}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Failed to load audit logs.');
                }
                return response.json();
            })
            .then(data => {
                if (!data.success) {
                    if (data.message === 'Unauthorized') {
                        window.location.href = 'login.php';
                        return;
                    }
                    throw new Error(data.message || 'Failed to load audit logs.');
                }

                renderAuditLogs(data.logs || []);
                updateAuditSummary(data.pagination);
                renderAuditPagination(data.pagination);
            })
            .catch(error => {
                console.error(error);
                auditLogList.innerHTML = '<div class="audit-empty">Unable to load audit logs.</div>';
                updateAuditSummary(null);
                if (auditPaginationControls) {
                    auditPaginationControls.innerHTML = '';
                }
            });
    }

    function copyLink(bookId) {
        const viewerUrl = new URL('index.html', window.location.href);
        viewerUrl.search = '';
        viewerUrl.searchParams.set('id', String(bookId));

        navigator.clipboard.writeText(viewerUrl.toString()).then(() => {
            showMessage('Link copied to clipboard.', 'success');
        }).catch(() => {
            showMessage('Failed to copy link.', 'error');
        });
    }

    function replaceBook(book, file, triggerButton) {
        if (file.type !== 'application/pdf') {
            showMessage('Only PDF files are allowed.', 'error');
            return Promise.resolve();
        }

        if (triggerButton) {
            triggerButton.disabled = true;
            triggerButton.textContent = 'Replacing...';
        }

        showMessage(`Replacing PDF for "${book.title}"...`, 'info');

        const formData = new FormData();
        formData.append('id', String(book.id));
        formData.append('pdf_file', file);
        appendCsrfToken(formData);

        return fetch('replace_book.php', {
            method: 'POST',
            body: formData
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Replace request failed.');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const versionMessage = data.version ? ` (v${data.version})` : '';
                    showMessage(`PDF replaced successfully${versionMessage}.`, 'success');
                    fetchBooks();
                    fetchAuditLogs();
                } else if (data.message === 'Unauthorized') {
                    window.location.href = 'login.php';
                } else if (isCsrfTokenError(data.message)) {
                    showMessage('Session token expired. Please refresh this page.', 'error');
                } else {
                    showMessage('Replace failed: ' + (data.message || 'Unknown error.'), 'error');
                }
            })
            .catch(error => {
                console.error(error);
                showMessage('Replace failed due to a network or server error.', 'error');
            })
            .finally(() => {
                if (triggerButton) {
                    triggerButton.disabled = false;
                    triggerButton.textContent = 'Replace PDF';
                }
            });
    }

    function setEditTitleError(message) {
        if (!editTitleError) {
            return;
        }
        editTitleError.textContent = message || '';
    }

    function setEditTitleSubmitting(isSubmitting) {
        if (editTitleSaveBtn) {
            editTitleSaveBtn.disabled = isSubmitting;
            editTitleSaveBtn.textContent = isSubmitting ? 'Saving...' : 'Save Title';
        }

        if (editTitleCancelBtn) {
            editTitleCancelBtn.disabled = isSubmitting;
        }

        if (editTitleContext && editTitleContext.triggerButton) {
            editTitleContext.triggerButton.disabled = isSubmitting;
            editTitleContext.triggerButton.textContent = isSubmitting ? 'Saving...' : 'Edit Title';
        }
    }

    function isEditTitleSubmitting() {
        return !!(editTitleSaveBtn && editTitleSaveBtn.disabled);
    }

    function closeEditTitleModal() {
        if (!editTitleModal) {
            return;
        }

        setEditTitleSubmitting(false);
        setEditTitleError('');
        editTitleModal.classList.remove('active');
        editTitleModal.setAttribute('aria-hidden', 'true');

        if (editTitleForm) {
            editTitleForm.reset();
        }

        if (editTitleBookLabel) {
            editTitleBookLabel.textContent = '';
        }

        editTitleContext = null;
    }

    function openEditTitleModal(book, triggerButton) {
        if (!editTitleModal || !editTitleInput) {
            showMessage('Edit title dialog is unavailable.', 'error');
            return;
        }

        const currentTitle = String(book.title || '').trim();
        editTitleContext = {
            bookId: Number(book.id),
            currentTitle,
            triggerButton
        };

        setEditTitleError('');
        setEditTitleSubmitting(false);

        if (editTitleBookLabel) {
            editTitleBookLabel.textContent = `Book #${book.id}`;
        }

        editTitleInput.value = currentTitle;
        editTitleModal.classList.add('active');
        editTitleModal.setAttribute('aria-hidden', 'false');

        window.setTimeout(() => {
            editTitleInput.focus();
            editTitleInput.select();
        }, 0);
    }

    function submitEditTitle() {
        if (!editTitleContext || !editTitleInput) {
            return;
        }

        const currentTitle = editTitleContext.currentTitle;
        const nextTitle = String(editTitleInput.value || '').trim();

        if (nextTitle === '') {
            setEditTitleError('Title cannot be empty.');
            return;
        }

        if (nextTitle.length > 255) {
            setEditTitleError('Title is too long (max 255 characters).');
            return;
        }

        if (nextTitle === currentTitle) {
            closeEditTitleModal();
            showMessage('Title is unchanged.', 'info');
            return;
        }

        setEditTitleError('');
        setEditTitleSubmitting(true);
        showMessage(`Updating title for "${currentTitle}"...`, 'info');

        const formData = new FormData();
        formData.append('id', String(editTitleContext.bookId));
        formData.append('title', nextTitle);
        appendCsrfToken(formData);

        fetch('update_book.php', {
            method: 'POST',
            body: formData
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Update title request failed.');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    closeEditTitleModal();
                    showMessage(data.message || 'Title updated.', 'success');
                    fetchBooks();
                    fetchAuditLogs();
                } else if (data.message === 'Unauthorized') {
                    window.location.href = 'login.php';
                } else if (isCsrfTokenError(data.message)) {
                    setEditTitleError('Session token expired. Refresh this page and try again.');
                    showMessage('Session token expired. Please refresh this page.', 'error');
                } else {
                    const errorText = data.message || 'Unknown error.';
                    setEditTitleError(errorText);
                    showMessage('Update title failed: ' + errorText, 'error');
                }
            })
            .catch(error => {
                console.error(error);
                setEditTitleError('Network or server error while updating title.');
                showMessage('Update title failed due to a network or server error.', 'error');
            })
            .finally(() => {
                if (editTitleContext) {
                    setEditTitleSubmitting(false);
                }
            });
    }

    function renderHistory(panel, versions) {
        panel.innerHTML = '';

        if (!versions.length) {
            panel.innerHTML = '<div class="history-meta">No version history available.</div>';
            return;
        }

        versions.forEach(version => {
            const row = document.createElement('div');
            row.className = 'history-item';

            const left = document.createElement('div');
            left.className = 'history-meta';

            const metaText = [
                `v${version.version}`,
                version.fileName || 'Unknown file',
                formatPageCount(version.pageCount),
                formatBytes(version.fileSizeBytes),
                `By: ${version.uploadedByUsername || 'Unknown'}`,
                formatDateTime(version.createdAt)
            ].join(' - ');

            left.textContent = metaText;

            if (version.isCurrent) {
                const currentBadge = document.createElement('span');
                currentBadge.className = 'history-current';
                currentBadge.textContent = 'Current';
                left.appendChild(currentBadge);
            }

            const right = document.createElement('div');

            if (version.isAvailable && version.downloadUrl) {
                const downloadLink = document.createElement('a');
                downloadLink.href = version.downloadUrl;
                downloadLink.className = 'btn-copy';
                downloadLink.textContent = 'Download';
                downloadLink.setAttribute('download', version.fileName || `book_v${version.version}.pdf`);
                right.appendChild(downloadLink);
            } else {
                const missing = document.createElement('span');
                missing.className = 'history-missing';
                missing.textContent = 'File missing';
                right.appendChild(missing);
            }

            row.appendChild(left);
            row.appendChild(right);
            panel.appendChild(row);
        });
    }

    function toggleHistory(bookId, historyPanel, historyButton) {
        const isOpen = historyPanel.style.display === 'block';
        if (isOpen) {
            historyPanel.style.display = 'none';
            historyButton.textContent = 'History';
            return;
        }

        historyPanel.style.display = 'block';
        historyButton.textContent = 'Hide History';

        if (historyPanel.dataset.loaded === 'true') {
            return;
        }

        historyPanel.innerHTML = '<div class="history-meta">Loading version history...</div>';

        fetch(`list_book_versions.php?id=${encodeURIComponent(bookId)}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Failed to load version history.');
                }
                return response.json();
            })
            .then(data => {
                if (!data.success) {
                    if (data.message === 'Unauthorized') {
                        window.location.href = 'login.php';
                        return;
                    }
                    throw new Error(data.message || 'Failed to load version history.');
                }

                renderHistory(historyPanel, data.versions || []);
                historyPanel.dataset.loaded = 'true';
            })
            .catch(error => {
                console.error(error);
                historyPanel.innerHTML = '<div class="history-missing">Unable to load version history.</div>';
            });
    }

    function moveToTrash(book, triggerButton) {
        const confirmed = window.confirm(`Move "${book.title}" to trash? You can restore it later.`);
        if (!confirmed) {
            return;
        }

        if (triggerButton) {
            triggerButton.disabled = true;
            triggerButton.textContent = 'Moving...';
        }

        showMessage(`Moving "${book.title}" to trash...`, 'info');

        const formData = new FormData();
        formData.append('id', String(book.id));
        appendCsrfToken(formData);

        fetch('delete_book.php', {
            method: 'POST',
            body: formData
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Trash request failed.');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    showMessage(data.message || 'Book moved to trash.', 'success');
                    fetchBooks();
                    fetchAuditLogs();
                } else if (data.message === 'Unauthorized') {
                    window.location.href = 'login.php';
                } else if (isCsrfTokenError(data.message)) {
                    showMessage('Session token expired. Please refresh this page.', 'error');
                } else {
                    showMessage('Failed: ' + (data.message || 'Unknown error.'), 'error');
                }
            })
            .catch(error => {
                console.error(error);
                showMessage('Failed due to a network or server error.', 'error');
            })
            .finally(() => {
                if (triggerButton) {
                    triggerButton.disabled = false;
                    triggerButton.textContent = 'Move to Trash';
                }
            });
    }

    function restoreBook(book, triggerButton) {
        if (triggerButton) {
            triggerButton.disabled = true;
            triggerButton.textContent = 'Restoring...';
        }

        showMessage(`Restoring "${book.title}"...`, 'info');

        const formData = new FormData();
        formData.append('id', String(book.id));
        appendCsrfToken(formData);

        fetch('restore_book.php', {
            method: 'POST',
            body: formData
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Restore request failed.');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    showMessage(data.message || 'Book restored from trash.', 'success');
                    fetchBooks();
                    fetchAuditLogs();
                } else if (data.message === 'Unauthorized') {
                    window.location.href = 'login.php';
                } else if (isCsrfTokenError(data.message)) {
                    showMessage('Session token expired. Please refresh this page.', 'error');
                } else {
                    showMessage('Restore failed: ' + (data.message || 'Unknown error.'), 'error');
                }
            })
            .catch(error => {
                console.error(error);
                showMessage('Restore failed due to a network or server error.', 'error');
            })
            .finally(() => {
                if (triggerButton) {
                    triggerButton.disabled = false;
                    triggerButton.textContent = 'Restore';
                }
            });
    }

    function hardDeleteBook(book, triggerButton) {
        const firstConfirm = window.confirm(
            `Permanently delete "${book.title}" and its version history?\n\nThis action cannot be undone.`
        );
        if (!firstConfirm) {
            return;
        }

        const phrase = window.prompt('Type DELETE to permanently remove this book:', '');
        if (phrase === null) {
            return;
        }

        if (phrase.trim() !== 'DELETE') {
            showMessage('Permanent delete cancelled. Confirmation text did not match.', 'error');
            return;
        }

        if (triggerButton) {
            triggerButton.disabled = true;
            triggerButton.textContent = 'Deleting...';
        }

        showMessage(`Permanently deleting "${book.title}"...`, 'info');

        const formData = new FormData();
        formData.append('id', String(book.id));
        formData.append('confirm_phrase', 'DELETE');
        appendCsrfToken(formData);

        fetch('hard_delete_book.php', {
            method: 'POST',
            body: formData
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Hard delete request failed.');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const summary = `Deleted file(s): ${data.deletedFilesCount || 0}, missing: ${data.missingFilesCount || 0}, skipped: ${data.skippedFilesCount || 0}.`;
                    showMessage(`${data.message || 'Book permanently deleted.'} ${summary}`, 'success');
                    fetchBooks();
                    fetchAuditLogs();
                } else if (data.message === 'Unauthorized') {
                    window.location.href = 'login.php';
                } else if (isCsrfTokenError(data.message)) {
                    showMessage('Session token expired. Please refresh this page.', 'error');
                } else {
                    showMessage('Permanent delete failed: ' + (data.message || 'Unknown error.'), 'error');
                }
            })
            .catch(error => {
                console.error(error);
                showMessage('Permanent delete failed due to a network or server error.', 'error');
            })
            .finally(() => {
                if (triggerButton) {
                    triggerButton.disabled = false;
                    triggerButton.textContent = 'Hard Delete';
                }
            });
    }

    function renderBooks(books) {
        bookList.innerHTML = '';

        if (!books.length) {
            let emptyText = 'No books found.';
            if (state.status === 'active') {
                emptyText = 'No active books found.';
            } else if (state.status === 'trashed') {
                emptyText = 'Trash is empty.';
            }
            bookList.innerHTML = `<div>${emptyText}</div>`;
            return;
        }

        books.forEach(book => {
            const isDeleted = !!book.deleted_at;

            const item = document.createElement('div');
            item.className = isDeleted ? 'book-item trashed' : 'book-item';

            const info = document.createElement('div');
            const titleRow = document.createElement('div');
            titleRow.className = 'book-title-row';

            const title = document.createElement('strong');
            title.textContent = book.title;

            const versionPill = document.createElement('span');
            versionPill.className = 'version-pill';
            versionPill.textContent = `v${book.latest_version || 1}`;

            titleRow.appendChild(title);
            titleRow.appendChild(versionPill);

            if (isDeleted) {
                const trashPill = document.createElement('span');
                trashPill.className = 'trash-pill';
                trashPill.textContent = 'In Trash';
                titleRow.appendChild(trashPill);
            }

            const updatedMeta = document.createElement('div');
            updatedMeta.className = 'book-meta';
            updatedMeta.textContent = `Updated: ${formatDateTime(book.latest_at || book.uploaded_at)} by ${book.latest_uploaded_by_username || 'Unknown'}`;

            const fileMeta = document.createElement('div');
            fileMeta.className = 'book-meta';
            fileMeta.textContent = `Current file: ${book.file_name || 'Unknown'} - ${formatPageCount(book.latest_page_count)} - ${formatBytes(book.latest_file_size_bytes)}`;

            const uploadMeta = document.createElement('div');
            uploadMeta.className = 'book-meta';
            uploadMeta.textContent = `Uploaded: ${formatDateTime(book.uploaded_at)} by ${book.created_by_username || 'Unknown'}`;

            info.appendChild(titleRow);
            info.appendChild(updatedMeta);
            info.appendChild(fileMeta);
            info.appendChild(uploadMeta);

            if (isDeleted) {
                const trashMeta = document.createElement('div');
                trashMeta.className = 'book-meta';
                trashMeta.textContent = `Trashed: ${formatDateTime(book.deleted_at)} by ${book.deleted_by_username || 'Unknown'}`;
                info.appendChild(trashMeta);
            }

            const actions = document.createElement('div');
            actions.className = 'actions';

            const historyButton = document.createElement('button');
            historyButton.type = 'button';
            historyButton.className = 'btn-history';
            historyButton.textContent = 'History';

            const historyPanel = document.createElement('div');
            historyPanel.className = 'history-panel';
            historyPanel.dataset.loaded = 'false';

            historyButton.addEventListener('click', () => {
                toggleHistory(book.id, historyPanel, historyButton);
            });

            if (!isDeleted) {
                const viewLink = document.createElement('a');
                viewLink.href = `index.html?id=${encodeURIComponent(book.id)}`;
                viewLink.target = '_blank';
                viewLink.className = 'btn-view';
                viewLink.textContent = 'View';

                const copyButton = document.createElement('button');
                copyButton.type = 'button';
                copyButton.className = 'btn-copy';
                copyButton.textContent = 'Copy Link';
                copyButton.addEventListener('click', () => copyLink(book.id));

                const replaceButton = document.createElement('button');
                replaceButton.type = 'button';
                replaceButton.className = 'btn-replace';
                replaceButton.textContent = 'Replace PDF';

                const editButton = document.createElement('button');
                editButton.type = 'button';
                editButton.className = 'btn-edit';
                editButton.textContent = 'Edit Title';
                editButton.addEventListener('click', () => openEditTitleModal(book, editButton));

                const replaceInput = document.createElement('input');
                replaceInput.type = 'file';
                replaceInput.accept = '.pdf';
                replaceInput.style.display = 'none';

                replaceButton.addEventListener('click', () => replaceInput.click());
                replaceInput.addEventListener('change', () => {
                    const file = replaceInput.files[0];
                    if (!file) {
                        return;
                    }

                    replaceBook(book, file, replaceButton).finally(() => {
                        replaceInput.value = '';
                    });
                });

                const trashButton = document.createElement('button');
                trashButton.type = 'button';
                trashButton.className = 'btn-delete';
                trashButton.textContent = 'Move to Trash';
                trashButton.addEventListener('click', () => moveToTrash(book, trashButton));

                actions.appendChild(viewLink);
                actions.appendChild(copyButton);
                actions.appendChild(editButton);
                actions.appendChild(replaceButton);
                actions.appendChild(historyButton);
                actions.appendChild(trashButton);
                item.appendChild(replaceInput);
            } else {
                const restoreButton = document.createElement('button');
                restoreButton.type = 'button';
                restoreButton.className = 'btn-restore';
                restoreButton.textContent = 'Restore';
                restoreButton.addEventListener('click', () => restoreBook(book, restoreButton));

                const hardDeleteButton = document.createElement('button');
                hardDeleteButton.type = 'button';
                hardDeleteButton.className = 'btn-hard-delete';
                hardDeleteButton.textContent = 'Hard Delete';
                hardDeleteButton.addEventListener('click', () => hardDeleteBook(book, hardDeleteButton));

                actions.appendChild(historyButton);
                actions.appendChild(restoreButton);
                actions.appendChild(hardDeleteButton);
            }

            item.appendChild(info);
            item.appendChild(actions);
            bookList.appendChild(item);
            bookList.appendChild(historyPanel);
        });
    }

    function fetchBooks() {
        bookList.innerHTML = '<div class="loading">Loading...</div>';

        const params = new URLSearchParams();
        params.set('page', String(state.page));
        params.set('per_page', String(state.perPage));
        params.set('status', state.status);
        if (state.q) {
            params.set('q', state.q);
        }

        fetch(`list_books.php?${params.toString()}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Failed to load books.');
                }
                return response.json();
            })
            .then(data => {
                if (!data.success) {
                    if (data.message === 'Unauthorized') {
                        window.location.href = 'login.php';
                        return;
                    }
                    throw new Error(data.message || 'Failed to load books.');
                }

                renderBooks(data.books || []);
                updateSummary(data.pagination);
                renderPagination(data.pagination);
            })
            .catch(error => {
                console.error(error);
                bookList.innerHTML = '<div>Unable to load books.</div>';
                updateSummary(null);
                paginationControls.innerHTML = '';
                showMessage('Failed to fetch the book list.', 'error');
            });
    }

    uploadBtn.addEventListener('click', () => {
        const file = uploadInput.files[0];
        if (!file) {
            showMessage('Please select a PDF file first.', 'error');
            return;
        }

        if (file.type !== 'application/pdf') {
            showMessage('Only PDF files are allowed.', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('pdf_file', file);
        appendCsrfToken(formData);

        uploadBtn.textContent = 'Uploading...';
        uploadBtn.disabled = true;
        showMessage('Uploading PDF...', 'info');

        fetch('upload.php', {
            method: 'POST',
            body: formData
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Upload request failed.');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    showMessage('Upload successful.', 'success');
                    uploadInput.value = '';
                    state.page = 1;
                    state.status = 'active';
                    statusFilter.value = 'active';
                    fetchBooks();
                    fetchAuditLogs();
                } else if (data.message === 'Unauthorized') {
                    window.location.href = 'login.php';
                } else if (isCsrfTokenError(data.message)) {
                    showMessage('Session token expired. Please refresh this page.', 'error');
                } else {
                    showMessage('Upload failed: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error(error);
                showMessage('Upload failed due to a network or server error.', 'error');
            })
            .finally(() => {
                uploadBtn.textContent = 'Upload New PDF';
                uploadBtn.disabled = false;
            });
    });

    searchInput.addEventListener('input', () => {
        if (searchDebounceTimer) {
            clearTimeout(searchDebounceTimer);
        }

        searchDebounceTimer = setTimeout(() => {
            state.q = searchInput.value.trim();
            state.page = 1;
            fetchBooks();
        }, 350);
    });

    statusFilter.addEventListener('change', () => {
        state.status = statusFilter.value;
        state.page = 1;
        fetchBooks();
    });

    perPageSelect.addEventListener('change', () => {
        state.perPage = Number.parseInt(perPageSelect.value, 10) || 10;
        state.page = 1;
        fetchBooks();
    });

    refreshBtn.addEventListener('click', () => {
        clearMessage();
        fetchBooks();
        fetchAuditLogs();
    });

    if (auditActionFilter) {
        auditActionFilter.addEventListener('change', () => {
            auditState.action = auditActionFilter.value;
            auditState.page = 1;
            fetchAuditLogs();
        });
    }

    if (auditPerPageSelect) {
        auditPerPageSelect.addEventListener('change', () => {
            auditState.perPage = Number.parseInt(auditPerPageSelect.value, 10) || 10;
            auditState.page = 1;
            fetchAuditLogs();
        });
    }

    if (auditRefreshBtn) {
        auditRefreshBtn.addEventListener('click', () => {
            fetchAuditLogs();
        });
    }

    if (editTitleForm) {
        editTitleForm.addEventListener('submit', event => {
            event.preventDefault();
            submitEditTitle();
        });
    }

    if (editTitleCancelBtn) {
        editTitleCancelBtn.addEventListener('click', () => {
            if (isEditTitleSubmitting()) {
                return;
            }
            closeEditTitleModal();
        });
    }

    if (editTitleModal) {
        editTitleModal.addEventListener('click', event => {
            if (event.target !== editTitleModal || isEditTitleSubmitting()) {
                return;
            }
            closeEditTitleModal();
        });
    }

    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') {
            return;
        }
        if (!editTitleModal || !editTitleModal.classList.contains('active') || isEditTitleSubmitting()) {
            return;
        }
        closeEditTitleModal();
    });

    fetchBooks();
    fetchAuditLogs();
});
