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

    const state = {
        page: 1,
        perPage: 10,
        status: 'active',
        q: ''
    };

    let searchDebounceTimer = null;

    function showMessage(text, type = 'info') {
        messageBox.textContent = text;
        messageBox.className = `status-message ${type}`;
    }

    function clearMessage() {
        messageBox.textContent = '';
        messageBox.className = 'status-message';
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
                } else if (data.message === 'Unauthorized') {
                    window.location.href = 'login.php';
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
                } else if (data.message === 'Unauthorized') {
                    window.location.href = 'login.php';
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
                } else if (data.message === 'Unauthorized') {
                    window.location.href = 'login.php';
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

                actions.appendChild(historyButton);
                actions.appendChild(restoreButton);
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
                } else if (data.message === 'Unauthorized') {
                    window.location.href = 'login.php';
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
    });

    fetchBooks();
});
