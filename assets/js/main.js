document.addEventListener('DOMContentLoaded', function () {
    const flipBookElement = document.getElementById('flipbook');
    // Library elements removed


    // --- PageFlip Initialization ---
    // We initialize it once, but we might need to recreate it on new book load depending on behavior
    let pageFlip = null;

    function initPageFlip() {
        if (pageFlip) {
            pageFlip.destroy();
            // Clear container
            flipBookElement.innerHTML = '';
        }

        pageFlip = new St.PageFlip(flipBookElement, {
            width: 400, // base page width
            height: 600, // base page height
            size: "stretch",
            minWidth: 315,
            maxWidth: 1000,
            minHeight: 420,
            maxHeight: 1350,
            maxShadowOpacity: 0.5,
            showCover: true,
            mobileScrollSupport: false
        });
    }

    // --- UI Elements ---
    const prevBtn = document.getElementById('prev-btn');
    const nextBtn = document.getElementById('next-btn');
    const zoomInBtn = document.getElementById('zoom-in');
    const zoomOutBtn = document.getElementById('zoom-out');
    const zoomResetBtn = document.getElementById('zoom-reset');

    // Menu Elements
    const menuBtn = document.getElementById('menu-btn');
    const menuDropdown = document.getElementById('menu-dropdown');
    const firstPageBtn = document.getElementById('first-page-btn');
    const lastPageBtn = document.getElementById('last-page-btn');
    const downloadBtn = document.getElementById('download-btn');

    // --- Navigation Logic ---
    prevBtn.addEventListener('click', () => {
        if (pageFlip) pageFlip.flipPrev();
    });

    nextBtn.addEventListener('click', () => {
        if (pageFlip) pageFlip.flipNext();
    });

    // --- Menu Logic ---
    menuBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        menuDropdown.classList.toggle('active');
    });

    // Close menu when clicking outside
    document.addEventListener('click', (e) => {
        if (!menuDropdown.contains(e.target) && e.target !== menuBtn) {
            menuDropdown.classList.remove('active');
        }
    });

    firstPageBtn.addEventListener('click', () => {
        if (pageFlip) pageFlip.flip(0);
        menuDropdown.classList.remove('active');
    });

    lastPageBtn.addEventListener('click', () => {
        if (pageFlip) {
            pageFlip.flip(pageFlip.getPageCount() - 1);
        }
        menuDropdown.classList.remove('active');
    });

    // --- Zoom Logic ---

    // --- Zoom Logic ---
    let currentZoom = 1;
    let isPanning = false;
    let startX = 0;
    let startY = 0;
    let scrollLeft = 0;
    let scrollTop = 0;
    const container = document.querySelector('.container');

    function applyZoom() {
        flipBookElement.style.transform = `scale(${currentZoom})`;

        // Update container class for cursor
        if (currentZoom > 1) {
            container.classList.add('zoomed');
        } else {
            container.classList.remove('zoomed');
            // Reset scroll when zooming out completely
            container.scrollTo(0, 0);
        }
    }

    // --- Pan / Drag Logic ---
    container.addEventListener('mousedown', (e) => {
        if (currentZoom <= 1) return;
        isPanning = true;
        container.classList.add('active'); // Optional: for grabbing cursor
        startX = e.pageX - container.offsetLeft;
        startY = e.pageY - container.offsetTop;
        scrollLeft = container.scrollLeft;
        scrollTop = container.scrollTop;
    });

    container.addEventListener('mouseleave', () => {
        isPanning = false;
    });

    container.addEventListener('mouseup', () => {
        isPanning = false;
    });

    container.addEventListener('mousemove', (e) => {
        if (!isPanning) return;
        e.preventDefault();
        const x = e.pageX - container.offsetLeft;
        const y = e.pageY - container.offsetTop;
        const walkX = (x - startX) * 1; // Scroll speed multiplier
        const walkY = (y - startY) * 1;
        container.scrollLeft = scrollLeft - walkX;
        container.scrollTop = scrollTop - walkY;
    });

    // Touch events for mobile
    container.addEventListener('touchstart', (e) => {
        if (currentZoom <= 1) return;
        isPanning = true;
        startX = e.touches[0].pageX - container.offsetLeft;
        startY = e.touches[0].pageY - container.offsetTop;
        scrollLeft = container.scrollLeft;
        scrollTop = container.scrollTop;
    });

    container.addEventListener('touchend', () => {
        isPanning = false;
    });

    container.addEventListener('touchmove', (e) => {
        if (!isPanning) return;
        // Don't prevent default on touch unless necessary, can interfere with native behavior
        // But here we want custom pan
        // e.preventDefault(); 
        const x = e.touches[0].pageX - container.offsetLeft;
        const y = e.touches[0].pageY - container.offsetTop;
        const walkX = (x - startX) * 1;
        const walkY = (y - startY) * 1;
        container.scrollLeft = scrollLeft - walkX;
        container.scrollTop = scrollTop - walkY;
    });

    zoomInBtn.addEventListener('click', () => {
        if (currentZoom < 3) { // Max zoom limit
            currentZoom += 0.5; // Increased step for better UX
            applyZoom();
        }
    });

    zoomOutBtn.addEventListener('click', () => {
        if (currentZoom > 1) { // Min zoom limit clamped to 1
            currentZoom -= 0.5;
            if (currentZoom < 1) currentZoom = 1;
            applyZoom();
        }
    });

    zoomResetBtn.addEventListener('click', () => {
        currentZoom = 1;
        applyZoom();
    });

    // --- URL Parsing & Initial Load ---
    const urlParams = new URLSearchParams(window.location.search);
    const bookPath = urlParams.get('book');
    const downloadUrl = bookPath; // Use the path relative to web root

    if (bookPath) {
        // Build absolute path for download link just in case
        downloadBtn.href = downloadUrl;

        // Simple security check (optional, but good practice to ensure it's a relative path)
        // In a real app, backend validation would be better.
        loadBook(bookPath);
    } else {
        const coverMessage = document.querySelector('.cover-message');
        if (coverMessage) {
            coverMessage.textContent = 'No book specified. Please use the link provided by the administrator.';
        }
    }

    // --- PDF Loading & Rendering ---
    // --- PDF Loading & Rendering ---
    function loadBook(url) {
        console.log("Loading book:", url);
        // Show loading state
        const loader = document.getElementById('loader');
        if (loader) loader.style.display = 'block';

        // Ensure flipbook container is clear for new book
        flipBookElement.innerHTML = '';

        pdfjsLib.getDocument(url).promise.then(pdf => {
            console.log('PDF loaded, pages:', pdf.numPages);

            // Re-initialize PageFlip for new content
            initPageFlip();

            const numPages = pdf.numPages;
            const renderPromises = [];

            // We render pages to canvases
            for (let i = 1; i <= numPages; i++) {
                renderPromises.push(
                    pdf.getPage(i).then(page => {
                        const viewport = page.getViewport({ scale: 1.5 });
                        const div = document.createElement('div');
                        div.className = 'page';

                        const canvas = document.createElement('canvas');
                        const context = canvas.getContext('2d');
                        canvas.width = viewport.width;
                        canvas.height = viewport.height;

                        div.appendChild(canvas);
                        return { index: i, element: div, page: page, viewport: viewport, context: context };
                    })
                );
            }

            Promise.all(renderPromises).then(results => {
                results.sort((a, b) => a.index - b.index);

                results.forEach(item => {
                    flipBookElement.appendChild(item.element);
                    item.page.render({
                        canvasContext: item.context,
                        viewport: item.viewport
                    });
                });

                pageFlip.loadFromHTML(document.querySelectorAll('.page'));

                // Hide loader
                if (loader) loader.style.display = 'none';
            });

        }, reason => {
            console.error(reason);
            alert('Error loading PDF: ' + reason);
            if (loader) loader.style.display = 'none';
        });
    }

    // Initial fetch
    // fetchBooks(); 
    // Don't auto-fetch, let user explore.
    console.log("App initialized");
});
