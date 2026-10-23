/**
 * CMSbyPHP Clean Theme — Vanilla JavaScript
 * Lightweight, zero dependencies, high performance.
 */

document.addEventListener('DOMContentLoaded', function () {
    // 1. Dark/Light Mode Switcher
    const themeToggleBtn = document.getElementById('theme-toggle');
    const moonIcon = document.getElementById('icon-moon');
    const sunIcon = document.getElementById('icon-sun');

    function updateThemeIcons(isDark) {
        if (moonIcon && sunIcon) {
            moonIcon.style.display = isDark ? 'none' : 'block';
            sunIcon.style.display = isDark ? 'block' : 'none';
        }
    }

    const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
    updateThemeIcons(currentTheme === 'dark');

    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', function () {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            const nextTheme = isDark ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', nextTheme);
            localStorage.setItem('cmsbyphp_theme', nextTheme);
            updateThemeIcons(nextTheme === 'dark');
        });
    }

    // 2. Reading Progress Bar
    const progressBar = document.getElementById('reading-progress');
    const article = document.querySelector('.post-prose');

    if (progressBar && article) {
        window.addEventListener('scroll', function () {
            const articleRect = article.getBoundingClientRect();
            const articleTop = articleRect.top + window.scrollY;
            const articleHeight = article.offsetHeight;
            const windowHeight = window.innerHeight;
            const scrollPos = window.scrollY;

            if (scrollPos < articleTop) {
                progressBar.style.width = '0%';
            } else if (scrollPos > articleTop + articleHeight - windowHeight) {
                progressBar.style.width = '100%';
            } else {
                const progress = ((scrollPos - articleTop) / (articleHeight - windowHeight)) * 100;
                progressBar.style.width = Math.min(100, Math.max(0, progress)) + '%';
            }
        }, { passive: true });
    }

    // 3. Live Client-Side Post Filter (on Archive page)
    const searchInput = document.getElementById('blog-search-input');
    const postCards = document.querySelectorAll('.post-card');
    const noResultsMsg = document.getElementById('no-search-results');
    const postsGrid = document.getElementById('posts-grid-container');

    if (searchInput && postCards.length > 0) {
        searchInput.addEventListener('input', function (e) {
            const query = e.target.value.toLowerCase().trim();
            let visibleCount = 0;

            postCards.forEach(function (card) {
                const title = (card.querySelector('.post-card-title')?.textContent || '').toLowerCase();
                const excerpt = (card.querySelector('.post-card-excerpt')?.textContent || '').toLowerCase();
                const category = (card.querySelector('.badge-category')?.textContent || '').toLowerCase();

                const matches = title.includes(query) || excerpt.includes(query) || category.includes(query);
                if (matches) {
                    card.style.display = 'flex';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            if (noResultsMsg) {
                noResultsMsg.style.display = visibleCount === 0 ? 'block' : 'none';
            }
        });
    }

    // 4. Toast Notification Helper
    function showToast(message) {
        let toast = document.getElementById('toast-notice');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'toast-notice';
            toast.className = 'toast-notice';
            document.body.appendChild(toast);
        }
        toast.textContent = message;
        toast.classList.add('show');
        setTimeout(function () {
            toast.classList.remove('show');
        }, 2500);
    }

    // 5. Copy Link to Clipboard
    const copyBtns = document.querySelectorAll('[data-copy-url]');
    copyBtns.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            const urlToCopy = btn.getAttribute('data-copy-url') || window.location.href;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(urlToCopy).then(function () {
                    showToast('Link do wpisu został skopiowany!');
                }).catch(function () {
                    fallbackCopy(urlToCopy);
                });
            } else {
                fallbackCopy(urlToCopy);
            }
        });
    });

    function fallbackCopy(text) {
        const dummy = document.createElement('input');
        document.body.appendChild(dummy);
        dummy.value = text;
        dummy.select();
        document.execCommand('copy');
        document.body.removeChild(dummy);
        showToast('Link skopiowany do schowka!');
    }

    // 6. Inject "Copy" button into code blocks
    const codeBlocks = document.querySelectorAll('.post-prose pre');
    codeBlocks.forEach(function (block) {
        const copyBtn = document.createElement('button');
        copyBtn.className = 'btn btn-secondary btn-sm';
        copyBtn.style.cssText = 'position:absolute; top:8px; right:8px; font-size:11px; padding:3px 8px; z-index:10; opacity:0.8;';
        copyBtn.innerHTML = 'Kopiuj';
        copyBtn.addEventListener('click', function () {
            const code = block.querySelector('code')?.innerText || block.innerText;
            if (navigator.clipboard) {
                navigator.clipboard.writeText(code).then(function () {
                    copyBtn.innerHTML = '✓ Skopiowano';
                    setTimeout(function () { copyBtn.innerHTML = 'Kopiuj'; }, 2000);
                });
            }
        });
        block.appendChild(copyBtn);
    });

    // 7. Back to Top Button
    const backToTopBtn = document.getElementById('backToTop');
    if (backToTopBtn) {
        window.addEventListener('scroll', function () {
            if (window.scrollY > 400) {
                backToTopBtn.classList.add('show');
            } else {
                backToTopBtn.classList.remove('show');
            }
        }, { passive: true });

        backToTopBtn.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    // 8. Mobile Menu Toggle
    const mobileToggle = document.getElementById('mobile-menu-toggle');
    const header = document.querySelector('.site-header');
    if (mobileToggle && header) {
        mobileToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            header.classList.toggle('mobile-menu-active');
        });

        // Close drawer when any nav link is tapped
        document.querySelectorAll('.nav-links a').forEach(function (link) {
            link.addEventListener('click', function () {
                header.classList.remove('mobile-menu-active');
            });
        });

        // Close drawer when tapping outside
        document.addEventListener('click', function (e) {
            if (!header.contains(e.target)) {
                header.classList.remove('mobile-menu-active');
            }
        });
    }
});
