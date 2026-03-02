document.addEventListener('DOMContentLoaded', function () {
    const flipBookElement = document.getElementById('flipbook');
    const container = document.querySelector('.container');
    const loader = document.getElementById('loader');
    const errorMessage = document.getElementById('error-message');

    const prevBtn = document.getElementById('prev-btn');
    const nextBtn = document.getElementById('next-btn');
    const zoomInBtn = document.getElementById('zoom-in');
    const zoomOutBtn = document.getElementById('zoom-out');
    const zoomResetBtn = document.getElementById('zoom-reset');
    const pageIndicator = document.getElementById('page-indicator');

    const menuBtn = document.getElementById('menu-btn');
    const menuDropdown = document.getElementById('menu-dropdown');
    const firstPageBtn = document.getElementById('first-page-btn');
    const lastPageBtn = document.getElementById('last-page-btn');
    const jumpPageInput = document.getElementById('jump-page-input');
    const jumpPageBtn = document.getElementById('jump-page-btn');
    const fullscreenBtn = document.getElementById('fullscreen-btn');
    const downloadBtn = document.getElementById('download-btn');

    const defaultRenderScale = 1.5;
    const largeFileRenderScale = 1.2;
    const slowNetworkRenderScale = 1.15;
    const largeFileThresholdBytes = 30 * 1024 * 1024; // 30 MB
    const rangeChunkSizeBytes = 512 * 1024; // 512 KB

    let pageFlip = null;
    let pdfDoc = null;
    let pageCount = 0;
    let currentBookId = null;
    let activeRenderScale = defaultRenderScale;
    let hasFirstPagePainted = false;

    let currentZoom = 1;
    let isPanning = false;
    let startX = 0;
    let startY = 0;
    let scrollLeft = 0;
    let scrollTop = 0;

    const renderedPages = new Set();
    const renderPromises = new Map();

    function showLoader(show, text) {
        if (!loader) {
            return;
        }

        if (text) {
            loader.textContent = text;
        }
        loader.style.display = show ? 'block' : 'none';
    }

    function showError(message) {
        if (!errorMessage) {
            return;
        }

        errorMessage.textContent = message;
        errorMessage.classList.add('active');
    }

    function clearError() {
        if (!errorMessage) {
            return;
        }

        errorMessage.textContent = '';
        errorMessage.classList.remove('active');
    }

    function closeMenu() {
        menuDropdown.classList.remove('active');
    }

    function isSlowNetwork() {
        const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (!connection) {
            return false;
        }

        if (connection.saveData) {
            return true;
        }

        const networkType = connection.effectiveType || '';
        return networkType === 'slow-2g' || networkType === '2g' || networkType === '3g';
    }

    function resolveRenderScale(bookSizeBytes) {
        let scale = defaultRenderScale;
        if (Number.isInteger(bookSizeBytes) && bookSizeBytes >= largeFileThresholdBytes) {
            scale = largeFileRenderScale;
        }

        if (isSlowNetwork()) {
            scale = Math.min(scale, slowNetworkRenderScale);
        }

        return scale;
    }

    function formatBytesAsMb(value) {
        return `${(value / (1024 * 1024)).toFixed(1)} MB`;
    }

    function updatePdfLoadingProgress(loadedBytes, totalBytes) {
        if (hasFirstPagePainted) {
            return;
        }

        if (!Number.isFinite(loadedBytes) || loadedBytes <= 0) {
            return;
        }

        if (Number.isFinite(totalBytes) && totalBytes > 0) {
            const safeLoadedBytes = Math.min(loadedBytes, totalBytes);
            const percentage = Math.min(100, Math.round((safeLoadedBytes / totalBytes) * 100));
            showLoader(true, `Loading PDF... ${percentage}% (${formatBytesAsMb(safeLoadedBytes)} / ${formatBytesAsMb(totalBytes)})`);
            return;
        }

        showLoader(true, `Loading PDF... ${formatBytesAsMb(loadedBytes)}`);
    }

    function buildPdfLoadingOptions(fileUrl) {
        return {
            url: fileUrl,
            disableAutoFetch: true,
            disableStream: false,
            rangeChunkSize: rangeChunkSizeBytes,
        };
    }

    function applyZoom() {
        flipBookElement.style.transform = `scale(${currentZoom})`;

        if (currentZoom > 1) {
            container.classList.add('zoomed');
        } else {
            container.classList.remove('zoomed');
            container.scrollTo(0, 0);
        }
    }

    function getCurrentPageIndex() {
        if (!pageFlip || pageCount === 0) {
            return 0;
        }

        const index = pageFlip.getCurrentPageIndex();
        return Number.isInteger(index) ? index : 0;
    }

    function updatePageIndicator(index) {
        if (!pageIndicator) {
            return;
        }

        const safeIndex = Math.min(Math.max(index, 0), Math.max(pageCount - 1, 0));
        const current = pageCount === 0 ? 0 : safeIndex + 1;
        pageIndicator.textContent = `${current} / ${pageCount}`;
    }

    function getStorageKey(bookId) {
        return `flipbook:lastPage:${bookId}`;
    }

    function persistLastPage(index) {
        if (!currentBookId) {
            return;
        }

        localStorage.setItem(getStorageKey(currentBookId), String(index));
    }

    function getStoredPage(bookId) {
        const raw = localStorage.getItem(getStorageKey(bookId));
        const parsed = Number.parseInt(raw, 10);

        if (!Number.isInteger(parsed) || parsed < 0) {
            return 0;
        }

        return Math.min(parsed, Math.max(pageCount - 1, 0));
    }

    function initPageFlip() {
        if (pageFlip) {
            pageFlip.destroy();
            pageFlip = null;
        }

        flipBookElement.innerHTML = '';

        pageFlip = new St.PageFlip(flipBookElement, {
            width: 400,
            height: 600,
            size: 'stretch',
            minWidth: 315,
            maxWidth: 1000,
            minHeight: 420,
            maxHeight: 1350,
            maxShadowOpacity: 0.5,
            showCover: true,
            mobileScrollSupport: false,
        });

        pageFlip.on('flip', function (event) {
            const index = Number.isInteger(event.data) ? event.data : getCurrentPageIndex();
            updatePageIndicator(index);
            renderNearbyPages(index).catch(function (error) {
                console.error(error);
            });
            persistLastPage(index);
        });
    }

    function createPagePlaceholders(total) {
        const fragment = document.createDocumentFragment();

        for (let i = 0; i < total; i += 1) {
            const div = document.createElement('div');
            div.className = 'page';
            div.dataset.pageIndex = String(i);

            const canvas = document.createElement('canvas');
            div.appendChild(canvas);
            fragment.appendChild(div);
        }

        flipBookElement.appendChild(fragment);
    }

    async function renderPage(pageIndex) {
        if (!pdfDoc || pageIndex < 0 || pageIndex >= pageCount) {
            return;
        }

        if (renderedPages.has(pageIndex)) {
            return;
        }

        if (renderPromises.has(pageIndex)) {
            return renderPromises.get(pageIndex);
        }

        const pageElement = flipBookElement.querySelector(`.page[data-page-index="${pageIndex}"]`);
        if (!pageElement) {
            return;
        }

        const canvas = pageElement.querySelector('canvas');
        const context = canvas.getContext('2d');

        const renderPromise = pdfDoc.getPage(pageIndex + 1)
            .then(function (page) {
                const viewport = page.getViewport({ scale: activeRenderScale });
                canvas.width = viewport.width;
                canvas.height = viewport.height;

                return page.render({
                    canvasContext: context,
                    viewport: viewport,
                }).promise;
            })
            .then(function () {
                renderedPages.add(pageIndex);
            })
            .finally(function () {
                renderPromises.delete(pageIndex);
            });

        renderPromises.set(pageIndex, renderPromise);
        return renderPromise;
    }

    async function renderNearbyPages(centerIndex, waitForCompletion) {
        const candidates = [centerIndex, centerIndex - 1, centerIndex + 1, centerIndex - 2, centerIndex + 2];
        const valid = candidates.filter(function (index, position, arr) {
            return index >= 0 && index < pageCount && arr.indexOf(index) === position;
        });

        if (waitForCompletion) {
            for (const index of valid) {
                await renderPage(index);
            }
            return;
        }

        valid.forEach(function (index, queuePosition) {
            window.setTimeout(function () {
                renderPage(index).catch(function (error) {
                    console.error(error);
                });
            }, queuePosition * 60);
        });
    }

    function goToPage(index) {
        if (!pageFlip || pageCount === 0) {
            return;
        }

        const target = Math.min(Math.max(index, 0), pageCount - 1);
        pageFlip.flip(target);
    }

    function updateFullscreenLabel() {
        if (!fullscreenBtn) {
            return;
        }

        fullscreenBtn.textContent = document.fullscreenElement ? 'Exit Fullscreen' : 'Toggle Fullscreen';
    }

    async function toggleFullscreen() {
        try {
            if (!document.fullscreenElement) {
                await document.documentElement.requestFullscreen();
            } else {
                await document.exitFullscreen();
            }
        } catch (error) {
            console.error(error);
            showError('Fullscreen is not available in this browser context.');
        }
    }

    async function loadBookByResolverQuery(queryString) {
        try {
            hasFirstPagePainted = false;
            showLoader(true, 'Loading book...');
            clearError();

            const response = await fetch(`get_book.php?${queryString}`);
            if (!response.ok) {
                throw new Error('Book lookup failed.');
            }

            const payload = await response.json();
            if (!payload.success || !payload.book) {
                throw new Error(payload.message || 'Book is unavailable.');
            }

            const fileUrl = payload.book.fileUrl;
            const parsedFileSize = Number.parseInt(payload.book.fileSizeBytes, 10);
            const fileSizeBytes = Number.isInteger(parsedFileSize) ? parsedFileSize : null;
            currentBookId = payload.book.id;
            activeRenderScale = resolveRenderScale(fileSizeBytes);

            downloadBtn.href = fileUrl;
            if (payload.book.fileName) {
                downloadBtn.setAttribute('download', payload.book.fileName);
            }

            const loadingTask = pdfjsLib.getDocument(buildPdfLoadingOptions(fileUrl));
            loadingTask.onProgress = function (progressData) {
                if (!progressData) {
                    return;
                }

                updatePdfLoadingProgress(progressData.loaded, progressData.total);
            };
            pdfDoc = await loadingTask.promise;
            pageCount = pdfDoc.numPages;

            renderedPages.clear();
            renderPromises.clear();

            initPageFlip();
            createPagePlaceholders(pageCount);

            const pages = flipBookElement.querySelectorAll('.page');
            pageFlip.loadFromHTML(pages);

            const initialPage = getStoredPage(currentBookId);
            if (initialPage > 0) {
                goToPage(initialPage);
            }

            updatePageIndicator(initialPage);
            persistLastPage(initialPage);
            showLoader(true, 'Rendering first page...');
            await renderPage(initialPage);
            hasFirstPagePainted = true;
            showLoader(false);
            renderNearbyPages(initialPage, false).catch(function (error) {
                console.error(error);
            });
        } catch (error) {
            console.error(error);
            showLoader(false);
            showError(error.message || 'Failed to load the selected PDF.');
        }
    }

    prevBtn.addEventListener('click', function () {
        if (pageFlip) {
            pageFlip.flipPrev();
        }
    });

    nextBtn.addEventListener('click', function () {
        if (pageFlip) {
            pageFlip.flipNext();
        }
    });

    menuBtn.addEventListener('click', function (event) {
        event.stopPropagation();
        menuDropdown.classList.toggle('active');
    });

    document.addEventListener('click', function (event) {
        if (!menuDropdown.contains(event.target) && event.target !== menuBtn) {
            closeMenu();
        }
    });

    firstPageBtn.addEventListener('click', function () {
        goToPage(0);
        closeMenu();
    });

    lastPageBtn.addEventListener('click', function () {
        goToPage(pageCount - 1);
        closeMenu();
    });

    jumpPageBtn.addEventListener('click', function () {
        const targetPage = Number.parseInt(jumpPageInput.value, 10);
        if (!Number.isInteger(targetPage) || targetPage < 1 || targetPage > pageCount) {
            showError(`Enter a page between 1 and ${pageCount}.`);
            return;
        }

        clearError();
        goToPage(targetPage - 1);
        closeMenu();
    });

    jumpPageInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            jumpPageBtn.click();
        }
    });

    fullscreenBtn.addEventListener('click', function () {
        toggleFullscreen();
        closeMenu();
    });

    document.addEventListener('fullscreenchange', updateFullscreenLabel);

    zoomInBtn.addEventListener('click', function () {
        if (currentZoom < 3) {
            currentZoom += 0.5;
            applyZoom();
        }
    });

    zoomOutBtn.addEventListener('click', function () {
        if (currentZoom > 1) {
            currentZoom -= 0.5;
            if (currentZoom < 1) {
                currentZoom = 1;
            }
            applyZoom();
        }
    });

    zoomResetBtn.addEventListener('click', function () {
        currentZoom = 1;
        applyZoom();
    });

    container.addEventListener('mousedown', function (event) {
        if (currentZoom <= 1) {
            return;
        }

        isPanning = true;
        startX = event.pageX - container.offsetLeft;
        startY = event.pageY - container.offsetTop;
        scrollLeft = container.scrollLeft;
        scrollTop = container.scrollTop;
    });

    container.addEventListener('mouseleave', function () {
        isPanning = false;
    });

    container.addEventListener('mouseup', function () {
        isPanning = false;
    });

    container.addEventListener('mousemove', function (event) {
        if (!isPanning) {
            return;
        }

        event.preventDefault();
        const x = event.pageX - container.offsetLeft;
        const y = event.pageY - container.offsetTop;
        const walkX = x - startX;
        const walkY = y - startY;
        container.scrollLeft = scrollLeft - walkX;
        container.scrollTop = scrollTop - walkY;
    });

    container.addEventListener('touchstart', function (event) {
        if (currentZoom <= 1 || event.touches.length !== 1) {
            return;
        }

        isPanning = true;
        startX = event.touches[0].pageX - container.offsetLeft;
        startY = event.touches[0].pageY - container.offsetTop;
        scrollLeft = container.scrollLeft;
        scrollTop = container.scrollTop;
    });

    container.addEventListener('touchend', function () {
        isPanning = false;
    });

    container.addEventListener('touchmove', function (event) {
        if (!isPanning) {
            return;
        }

        event.preventDefault();
        const x = event.touches[0].pageX - container.offsetLeft;
        const y = event.touches[0].pageY - container.offsetTop;
        const walkX = x - startX;
        const walkY = y - startY;
        container.scrollLeft = scrollLeft - walkX;
        container.scrollTop = scrollTop - walkY;
    }, { passive: false });

    document.addEventListener('keydown', function (event) {
        const activeTag = document.activeElement && document.activeElement.tagName;
        if (activeTag === 'INPUT' || activeTag === 'TEXTAREA' || activeTag === 'SELECT') {
            return;
        }

        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            prevBtn.click();
            return;
        }

        if (event.key === 'ArrowRight') {
            event.preventDefault();
            nextBtn.click();
            return;
        }

        if (event.key === 'Home') {
            event.preventDefault();
            goToPage(0);
            return;
        }

        if (event.key === 'End') {
            event.preventDefault();
            goToPage(pageCount - 1);
            return;
        }

        if (event.key === '+' || event.key === '=') {
            event.preventDefault();
            zoomInBtn.click();
            return;
        }

        if (event.key === '-') {
            event.preventDefault();
            zoomOutBtn.click();
            return;
        }

        if (event.key === '0') {
            event.preventDefault();
            zoomResetBtn.click();
        }
    });

    applyZoom();
    updatePageIndicator(0);
    updateFullscreenLabel();

    const urlParams = new URLSearchParams(window.location.search);
    const bookId = Number.parseInt(urlParams.get('id'), 10);
    const legacyBookPath = urlParams.get('book');

    if (Number.isInteger(bookId) && bookId > 0) {
        loadBookByResolverQuery(`id=${encodeURIComponent(bookId)}`);
        return;
    }

    if (legacyBookPath && legacyBookPath.trim() !== '') {
        loadBookByResolverQuery(`book=${encodeURIComponent(legacyBookPath.trim())}`);
        return;
    }

    showLoader(false);
    showError('No valid book id was provided.');
});
