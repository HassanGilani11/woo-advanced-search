/**
 * WooCommerce Advanced Search – Frontend JS  v1.3.9
 *
 * Key changes from v1.1.x (Algolia hybrid) to v1.2.0 (instant search):
 *
 *  BEFORE:  one debounce (300ms) → spinner → Promise.all([Algolia, WP AJAX]) → render
 *           Products couldn't show until the slower WP AJAX call also finished.
 *
 *  NOW:     Algolia fires immediately on every keystroke (no debounce on products).
 *           WP AJAX for categories fires with a separate short debounce (150ms).
 *           Each source updates its own persistent column the moment it returns.
 *           No spinner — the previous result stays visible while new results load.
 *           WP AJAX responses are cached so repeated/similar searches feel instant.
 *           No-results state only shows once BOTH sources have reported empty.
 *
 * Depends: jQuery (WP bundled), algoliasearch UMD (loaded by WAS_Shortcode::enqueue)
 */
(function ($) {
    'use strict';

    const cfg = window.wasConfig || {};
    const alg = cfg.algolia || {};

    /* -----------------------------------------------------------------------
       Initialise Algolia client once — shared across all widget instances
    ----------------------------------------------------------------------- */
    let algoliaIndex = null;

    if (typeof algoliasearch !== 'undefined' && alg.appId && alg.apiKey && alg.indexName) {
        try {
            algoliaIndex = algoliasearch(alg.appId, alg.apiKey).initIndex(alg.indexName);
        } catch (e) {
            console.error('[WAS] Algolia client init failed:', e);
        }
    } else if (!alg.appId || !alg.apiKey) {
        console.warn('[WAS] Algolia credentials missing — fill in WooCommerce → Advanced Search → Algolia tab.');
    }

    /* -----------------------------------------------------------------------
       Dot-path field accessor  e.g. "images.thumbnail.url"
    ----------------------------------------------------------------------- */
    function getField(obj, path) {
        if (!path || !obj) return undefined;
        return path.split('.').reduce((acc, k) => (acc != null ? acc[k] : undefined), obj);
    }

    /**
     * getHighlight — reads Algolia's _highlightResult for a given field path
     * and returns the value string with <mark>…</mark> around matched terms.
     *
     * Algolia HTML-encodes all user content in _highlightResult; only the
     * highlight wrapper tags (configured below as <mark>) are literal HTML,
     * so using the returned value as innerHTML is safe.
     */
    function getHighlight(hit, fieldPath) {
        const result = getField(hit._highlightResult || {}, fieldPath);
        // result is {value, matchLevel, matchedWords, fullyHighlighted}
        return (result && typeof result.value === 'string') ? result.value : null;
    }

    function hitToProduct(hit) {
        const f = alg.fields || {};
        const plainTitle = getField(hit, f.title) || '';
        // Use Algolia's highlighted title (matched chars wrapped in <mark>).
        // Fall back to escaped plain text if highlight data isn't present.
        const nameHtml = getHighlight(hit, f.title) || esc(plainTitle);
        return {
            id:       getField(hit, f.id)    || hit.objectID || '',
            name:     plainTitle,   // plain text — used for img alt / aria labels
            nameHtml,               // HTML — used for visible product name display
            img:      cfg.showImage ? getBestImage(hit) : '',
            url:      getField(hit, f.url)   || '#',
            // When live enrichment is enabled, ignore the Algolia price field entirely.
            // Algolia stores the price at index time (raw number, no $ symbol) and may
            // be stale or the wrong B2BKing tier for this customer.
            // enrichProducts() will fill in the correct WooCommerce price ~50ms later.
            price: (cfg.showPrice && !alg.liveEnrich) ? String(getField(hit, f.price) || '') : '',
            sku:      cfg.showSku   ? String(getField(hit, f.sku)   || '') : '',
        };
    }

    /* -----------------------------------------------------------------------
       Utilities
    ----------------------------------------------------------------------- */
    function debounce(fn, wait) {
        let t;
        return function (...args) {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, args), wait);
        };
    }

    function esc(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    /* -----------------------------------------------------------------------
       getBestImage — module-level, shared by both the dropdown and results page.
       Tries medium/woocommerce_thumbnail/large sizes first. If only a thumbnail
       is available, strips the WordPress size suffix (-150x150) to load the
       full-resolution original instead.
       e.g. product_1-150x150.jpg → product_1.jpg
    ----------------------------------------------------------------------- */
    function getBestImage(hit) {
        const preferred = ['medium', 'woocommerce_thumbnail', 'large'];
        for (const size of preferred) {
            const url = getField(hit, 'images.' + size + '.url');
            if (url) return url;
        }
        const thumb = getField(hit, 'images.thumbnail.url') || getField(hit, (alg.fields || {}).image) || '';
        if (thumb) {
            const full = thumb.replace(/-\d+x\d+(\.[a-zA-Z]+)$/, '$1');
            return full !== thumb ? full : thumb;
        }
        return '';
    }

    function decodeHtml(str) {
        const txt = document.createElement('textarea');
        txt.innerHTML = str;
        return txt.value;
    }

    function safeHtml(str) { return esc(decodeHtml(str)); }

    /* -----------------------------------------------------------------------
       Recent searches (localStorage)
    ----------------------------------------------------------------------- */
    const RECENT_KEY = 'was_recent_searches';
    const MAX_RECENT = 20;

    function getRecent() {
        try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); }
        catch (e) { return []; }
    }
    function addRecent(term) {
        let arr = getRecent().filter(t => t.toLowerCase() !== term.toLowerCase());
        arr.unshift(term);
        if (arr.length > MAX_RECENT) arr = arr.slice(0, MAX_RECENT);
        try { localStorage.setItem(RECENT_KEY, JSON.stringify(arr)); } catch (e) {}
    }
    function getRecentFiltered(q, limit) {
        return getRecent().filter(t => t.toLowerCase().includes(q.toLowerCase())).slice(0, limit);
    }

    /* -----------------------------------------------------------------------
       SVG icons
    ----------------------------------------------------------------------- */
    const ICON = {
        search:  `<svg width="14" height="14" viewBox="0 0 20 20" fill="none"><circle cx="9" cy="9" r="7" stroke="#9ca3af" stroke-width="2"/><path d="M15 15L19 19" stroke="#9ca3af" stroke-width="2" stroke-linecap="round"/></svg>`,
        arrow:   `<svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 9.5L9 2.5M9 2.5H4M9 2.5V7.5" stroke="#9ca3af" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
        chevron: `<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M5 2L10 7L5 12" stroke="#9ca3af" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
        tag:     `<svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M3 3h6l8 8-6 6-8-8V3z" stroke="#9ca3af" stroke-width="2"/><circle cx="8" cy="8" r="1.5" fill="#9ca3af"/></svg>`,
        img_ph:  `<svg width="28" height="28" viewBox="0 0 28 28" fill="none"><rect x="2" y="2" width="24" height="24" rx="4" stroke="#d1d5db" stroke-width="2"/><circle cx="10" cy="10" r="2.5" stroke="#d1d5db" stroke-width="1.5"/><path d="M2 18l7-5 5 4 3-3 9 8" stroke="#d1d5db" stroke-width="1.5" stroke-linejoin="round"/></svg>`,
    };

    /* -----------------------------------------------------------------------
       URL builder — constructs the full-results navigation URL.
       Uses the admin-configured search_results_url pattern from Settings →
       Algolia → "Search results page URL". Defaults to /algolia-search/?s=…
    ----------------------------------------------------------------------- */
    function buildSearchUrl(q) {
        const pattern = (cfg.searchResultsUrl || '/algolia-search/?s=%s').trim();
        const encoded = encodeURIComponent(q);
        const path    = pattern.replace('%s', encoded);
        return path.startsWith('http') ? path : window.location.origin + '/' + path.replace(/^\//, '');
    }

    /* =======================================================================
       Per-instance init
    ======================================================================= */
    function initInstance(wrapper) {
        const $wrapper   = $(wrapper);
        const $input     = $wrapper.find('.was-input');
        const $dropdown  = $wrapper.find('.was-dropdown');
        const $inner     = $wrapper.find('.was-dropdown-inner');
        const $clearBtn  = $wrapper.find('.was-clear-btn');
        const $searchBtn = $wrapper.find('.was-search-btn');

        // Monotonically increasing request counter — any response older than
        // the current counter is stale and must be discarded.
        let searchReqId = 0;
        let focusedIdx  = -1;
        let allFocusable = [];

        // Per-instance WP AJAX response cache (query → data).
        // Category taxonomy data doesn't change during a user's session.
        const metaCache = new Map();

        // Persistent column elements — created once, their content is replaced
        // on each search rather than rebuilding the whole dropdown.
        let $sidebarPane = null;
        let $productPane = null;

        // No-results coordination: show "no results" only when BOTH Algolia
        // AND the WP AJAX category call have returned empty.
        // Keyed by reqId so stale no-result signals are ignored.
        const nrTracker = {};

        function getNr(reqId) {
            if (!nrTracker[reqId]) {
                nrTracker[reqId] = { algoliaChecked: false, algoliaEmpty: false, sidebarChecked: false, sidebarEmpty: false };
            }
            return nrTracker[reqId];
        }

        /* -- dropdown helpers ------------------------------------------- */
        function showDropdown() {
            $dropdown.removeAttr('hidden');
            $input.attr('aria-expanded', 'true');
        }
        function hideDropdown() {
            $dropdown.attr('hidden', '');
            $input.attr('aria-expanded', 'false');
            focusedIdx = -1;
            // Clear state so a fresh search gets a clean slate
            $sidebarPane = null;
            $productPane = null;
        }

        // Creates persistent left/right panes in the dropdown.
        // Called at the START of every search — no spinner, no content wipe.
        // If the dropdown was previously closed the panes are recreated.
        function prepareColumns() {
            if (!$sidebarPane || !$.contains($inner[0], $sidebarPane[0])) {
                $sidebarPane = $('<div class="was-left-col  was-sidebar-pane"></div>');
                $productPane = $('<div class="was-right-col was-product-pane"></div>');
                $inner.empty().removeClass('was-single-col').append($sidebarPane).append($productPane);
            }

            // Show skeleton shimmer cards in the product column immediately.
            // Algolia responds in ~100ms and replaces these with real results.
            // This prevents the jarring blank-columns flash on every keystroke.
            if (!$productPane.find('.was-skeleton-card').length && !$productPane.find('.was-product-item').length) {
                const n = Math.min(cfg.productsLimit || 5, 5);
                let skHtml = `<div class="was-section-label was-skel-label">&nbsp;</div><div class="was-products-grid">`;
                for (let i = 0; i < n; i++) {
                    skHtml += `<div class="was-skeleton-card">
                        <div class="was-skel-img"></div>
                        <div class="was-skel-line was-skel-wide"></div>
                        <div class="was-skel-line was-skel-mid"></div>
                        <div class="was-skel-line was-skel-short"></div>
                    </div>`;
                }
                skHtml += `</div>`;
                $productPane.html(skHtml);
                $inner.addClass('was-single-col'); // full width while loading (no empty sidebar gap)
            }

            showDropdown();
        }

        // Adjusts two-column vs single-column layout depending on which panes
        // have content, and rebuilds the keyboard-focusable element list.
        function refreshLayout() {
            const leftHasContent  = $sidebarPane && $sidebarPane.children().length > 0;
            const rightHasContent = $productPane && $productPane.children().length > 0;

            if (leftHasContent || rightHasContent) {
                $inner.removeClass('was-single-col');
                if (!leftHasContent) $inner.addClass('was-single-col');
            }

            buildFocusable();
        }

        /* -- keyboard nav ----------------------------------------------- */
        function buildFocusable() {
            allFocusable = $inner.find(
                '.was-suggestion-item, .was-cat-item, .was-product-item, .was-see-all-btn, .was-chip, .was-pop-cat-item'
            ).toArray();
        }
        function moveFocus(dir) {
            buildFocusable();
            if (!allFocusable.length) return;
            focusedIdx = Math.max(0, Math.min(allFocusable.length - 1, focusedIdx + dir));
            $(allFocusable).removeClass('was-focused');
            $(allFocusable[focusedIdx]).addClass('was-focused');
            allFocusable[focusedIdx].scrollIntoView({ block: 'nearest' });
        }
        function selectFocused() {
            if (focusedIdx >= 0 && allFocusable[focusedIdx]) {
                const el = allFocusable[focusedIdx];
                const href = el.href || $(el).data('href');
                if (href) window.location.href = href;
                else $(el).trigger('click');
            }
        }

        /* ---------------------------------------------------------------
           paintProductPane — called as soon as Algolia returns.
           Updates ONLY the right column without touching the sidebar.
        --------------------------------------------------------------- */
        function paintProductPane(products, total, q, reqId) {
            if (reqId !== searchReqId) return;
            if (!$productPane) return; // dropdown was closed while search was in-flight

            const nr = getNr(reqId);
            nr.algoliaChecked = true;
            nr.algoliaEmpty   = products.length === 0;

            if (!products.length) {
                $productPane.empty();
                refreshLayout();
                maybeShowNoResults(q, reqId);
                return;
            }

            const seeAllUrl = buildSearchUrl(q);
            let html = `<div class="was-section-label">${esc(cfg.i18n.products)}</div>`;
            html += `<div class="was-products-grid">`;

            products.forEach(p => {
                const imgHtml   = p.img ? `<img src="${esc(p.img)}" alt="${esc(p.name)}" loading="lazy">` : ICON.img_ph;
                const skuHtml   = `<div class="was-product-sku">${p.sku ? 'STOCK CODE: ' + esc(p.sku) : ''}</div>`;
                // When enrichment is active, show a shimmer placeholder in the price slot
                // so the user sees loading feedback rather than an empty gap.
                // Shimmer is removed when enrichProducts() fills in the real price.
                const needsShimmer = cfg.showPrice && alg.liveEnrich && !p.price;
                const priceHtml = cfg.showPrice
                    ? `<div class="was-product-price${needsShimmer ? ' was-price-shimmer' : ''}" data-was-price>${p.price ? esc(p.price) : ''}</div>`
                    : '';

                html += `<a href="${esc(p.url)}" class="was-product-item" data-id="${esc(String(p.id))}">
                            <div class="was-product-img-wrap">${imgHtml}</div>
                            <div class="was-product-meta">
                                ${skuHtml}
                                <div class="was-product-name">${p.nameHtml}</div>
                                ${priceHtml}
                            </div>
                         </a>`;
            });

            html += `</div>`;

            if (cfg.showSeeAll) {
                const btnStyle  = cfg.primaryColor ? `background:${cfg.primaryColor};` : '';
                const rawLabel  = cfg.seeAllLabel || 'See All Products';
                // If label contains {count}, replace it; otherwise append count in brackets
                const btnLabel  = rawLabel.includes('{count}')
                    ? rawLabel.replace('{count}', total)
                    : rawLabel + ' (' + total + ')';
                html += `<div class="was-see-all-wrap">
                            <a href="${esc(seeAllUrl)}" class="was-see-all-btn" style="${btnStyle}">${esc(btnLabel)}</a>
                         </div>`;
            }

            $productPane.html(html);
            refreshLayout();

            // Live price/SKU enrichment (optional, silent background call)
            if (alg.liveEnrich && products.length) enrichProducts(products, reqId);
        }

        /* ---------------------------------------------------------------
           paintSidebarPane — called when WP AJAX returns (may be cached).
           Updates ONLY the left column without touching the product grid.
        --------------------------------------------------------------- */
        function paintSidebarPane(data, q, reqId) {
            if (reqId !== searchReqId) return;
            if (!$sidebarPane) return; // dropdown was closed while meta call was in-flight

            const categories  = data.categories  || [];
            // 'recent' suggestions are client-side only; others come from WP
            const suggestions = cfg.suggSource === 'recent'
                ? getRecentFiltered(q, cfg.suggLimit)
                : (data.suggestions || []);

            const hasSugg = cfg.showSugg && suggestions.length > 0;
            const hasCats = cfg.showCats  && categories.length  > 0;

            const nr = getNr(reqId);
            nr.sidebarChecked = true;
            nr.sidebarEmpty   = !hasSugg && !hasCats;

            if (!hasSugg && !hasCats) {
                $sidebarPane.empty();
                refreshLayout();
                maybeShowNoResults(q, reqId);
                return;
            }

            const seeAllBase = data.see_all_url || buildSearchUrl(q);
            let html = '';

            if (hasSugg) {
                html += `<div class="was-section-label">${esc(cfg.i18n.suggestions)}</div>`;
                suggestions.forEach(s => {
                    const sUrl = seeAllBase.replace(/s=[^&]+/, 's=' + encodeURIComponent(s));
                    html += `<a href="${esc(sUrl)}" class="was-suggestion-item" data-href="#">
                                <span class="was-sugg-icon">${ICON.search}</span>
                                <span>${esc(s)}</span>
                                <span class="was-sugg-fill-arrow">${ICON.arrow}</span>
                             </a>`;
                });
            }

            if (hasCats) {
                if (hasSugg) html += `<div class="was-cat-separator"></div>`;
                html += `<div class="was-section-label">${esc(cfg.i18n.categories)}</div>`;
                categories.forEach(cat => {
                    const catImg = cat.img
                        ? `<img class="was-cat-icon" src="${esc(cat.img)}" alt="">`
                        : `<span class="was-cat-icon">${ICON.tag}</span>`;
                    html += `<a href="${esc(cat.url)}" class="was-cat-item">
                                ${catImg}
                                <span>${safeHtml(cat.name)}</span>
                                <span style="margin-left:auto">${ICON.chevron}</span>
                             </a>`;
                });
            }

            $sidebarPane.html(html);
            refreshLayout();
        }

        /* ---------------------------------------------------------------
           maybeShowNoResults — shows the no-results panel only after BOTH
           Algolia and WP AJAX have reported empty for the same request.
        --------------------------------------------------------------- */
        function maybeShowNoResults(q, reqId) {
            if (reqId !== searchReqId) return;

            const nr = getNr(reqId);
            if (!nr.algoliaChecked || !nr.sidebarChecked) return; // wait for both
            if (!nr.algoliaEmpty   || !nr.sidebarEmpty)   return; // at least one has results

            // Replace the persistent columns with the no-results panel
            $inner.empty().addClass('was-single-col').html(buildNoResultsHtml(q));
            $sidebarPane = null;
            $productPane = null;
            buildFocusable();
        }

        /* ---------------------------------------------------------------
           buildNoResultsHtml — same content as the original renderNoResults
           but returns HTML string instead of writing to $inner directly.
        --------------------------------------------------------------- */
        function buildNoResultsHtml(q) {
            const rawMsg = (cfg.nrMessage || '').replace('{query}', q);
            const msgParts = rawMsg.split('"' + q + '"');
            const titleHtml = msgParts.length === 2
                ? `${esc(msgParts[0])}<strong style="white-space:nowrap">&ldquo;${esc(q)}&rdquo;</strong>${esc(msgParts[1])}`
                : esc(rawMsg);

            let html = `<div class="was-no-results"><div class="was-nr-left">`;
            html += `<p class="was-nr-title">${titleHtml}</p>`;
            html += `<div class="was-nr-tips">
                        <div class="was-nr-tips-label">${esc(cfg.i18n.tips_title)}</div>
                        <ul>${cfg.i18n.tips.map(t => `<li>${esc(t)}</li>`).join('')}</ul>
                     </div>`;

            if (cfg.showInstead) {
                const recent = getRecent().slice(0, 5);
                if (recent.length) {
                    html += `<div class="was-nr-instead-label">${esc(cfg.i18n.instead)}</div>
                             <div class="was-nr-chips">`;
                    recent.forEach(r => {
                        const sUrl = buildSearchUrl(r);
                        html += `<a href="${esc(sUrl)}" class="was-chip">${safeHtml(r)}</a>`;
                    });
                    html += `</div>`;
                }
            }

            html += `</div>`; // end nr-left

            if (cfg.showNRCats && cfg.popularCats && cfg.popularCats.length) {
                html += `<div class="was-nr-right">
                            <div class="was-pop-cats-label">${esc(cfg.i18n.popular_cats)}</div>
                            <div class="was-pop-cats-grid">`;
                cfg.popularCats.forEach(cat => {
                    const catImg = cat.img ? `<img src="${esc(cat.img)}" alt="${safeHtml(cat.name)}">` : '';
                    html += `<a href="${esc(cat.url)}" class="was-pop-cat-item">
                                ${catImg}
                                <span>${safeHtml(cat.name)}</span>
                                <span class="was-pop-cat-arrow">${ICON.chevron}</span>
                             </a>`;
                });
                html += `</div></div>`;
            }

            html += `</div>`; // end was-no-results
            return html;
        }

        /* ---------------------------------------------------------------
           enrichProducts — silent background call to WooCommerce for live
           price + SKU after Algolia cards are already visible in the DOM.
        --------------------------------------------------------------- */
        function enrichProducts(products, reqId) {
            const ids = products.filter(p => p.id).map(p => p.id);
            if (!ids.length) return;

            $.ajax({
                url:    cfg.ajaxUrl,
                method: 'POST',
                data:   { action: 'was_enrich', nonce: cfg.nonce, ids },
                success(resp) {
                    if (reqId !== searchReqId || !resp?.success || !resp.data) return;
                    Object.entries(resp.data).forEach(([id, info]) => {
                        const card = $inner[0]?.querySelector(`.was-product-item[data-id="${id}"]`);
                        if (!card) return;
                        if (info.price) {
                            // Use data-was-price marker for reliable targeting
                            // (class-only selectors can race if card re-renders)
                            const el = card.querySelector('[data-was-price]') || card.querySelector('.was-product-price');
                            if (el) {
                                el.innerHTML = info.price;
                                el.classList.remove('was-price-shimmer'); // swap shimmer for real price
                            } else {
                                card.querySelector('.was-product-meta')?.insertAdjacentHTML('beforeend', `<div class="was-product-price">${info.price}</div>`);
                            }
                        }
                        if (info.sku) {
                            const el = card.querySelector('.was-product-sku');
                            if (el && !el.textContent.trim()) el.textContent = 'STOCK CODE: ' + info.sku;
                            else if (!el) card.querySelector('.was-product-meta')?.insertAdjacentHTML('afterbegin', `<div class="was-product-sku">STOCK CODE: ${esc(info.sku)}</div>`);
                        }
                        // Fill in image from WooCommerce when Algolia record had no image
                        // (e.g. image uploaded after last Algolia re-index)
                        if (info.image) {
                            const wrap = card.querySelector('.was-product-img-wrap');
                            if (wrap && !wrap.querySelector('img')) {
                                wrap.innerHTML = `<img src="${esc(info.image)}" alt="${esc(card.querySelector('.was-product-name')?.textContent || '')}" loading="lazy">`;
                            }
                        }
                    });
                }
            });
        }

        /* ===============================================================
           doSearch — the instant-search orchestrator.

           Algolia fires IMMEDIATELY on every keystroke (no debounce).
           The Algolia v4 client handles concurrent in-flight requests;
           stale responses are discarded via reqId comparison.

           WP AJAX for categories fires with a 150ms debounce (separate
           from Algolia) and results are cached — so typing the same or
           similar queries again returns the sidebar instantly.
        =============================================================== */
        function doSearch(q) {
            if (q.length < cfg.minChars) { hideDropdown(); return; }

            const reqId = ++searchReqId;

            // Ensure persistent column panes exist — no spinner, no content wipe.
            // The user sees the previous result until new content arrives.
            prepareColumns();

            /* ── 1. Algolia product search (fires immediately) ─────────────── */
            if (cfg.showProducts && algoliaIndex) {
                algoliaIndex
                    .search(q, {
                        hitsPerPage:            cfg.productsLimit || 5,
                        highlightPreTag:        '<mark>',
                        highlightPostTag:       '</mark>',
                        queryType:              'prefixAll',    // every word prefix-matched so partial phrases
                        removeWordsIfNoResults: 'allOptional', // like "miniature" trigger the MCB synonym
                    })
                    .then(res => {
                        paintProductPane(res.hits.map(hitToProduct), res.nbHits, q, reqId);
                    })
                    .catch(err => {
                        console.error('[WAS] Algolia search error:', err);
                        if (reqId !== searchReqId) return;
                        const nr = getNr(reqId);
                        nr.algoliaChecked = true; nr.algoliaEmpty = true;
                        maybeShowNoResults(q, reqId);
                    });
            } else {
                // Products disabled — mark Algolia as "done empty" immediately
                const nr = getNr(reqId);
                nr.algoliaChecked = true; nr.algoliaEmpty = true;
                maybeShowNoResults(q, reqId);
            }

            /* ── 2. Categories + suggestions via WP AJAX (debounced + cached) ─ */
            debouncedMetaSearch(q);
        }

        /* WP AJAX meta call with its own debounce (150ms).
           Separate from Algolia so products never have to wait for it. */
        const debouncedMetaSearch = debounce(function (q) {
            // Capture the CURRENT reqId at the moment the debounce fires
            // (may be higher than when doSearch was called, if user typed more)
            const reqId = searchReqId;

            if (q !== $input.val().trim()) return; // query changed during debounce wait

            const cacheKey = q.trim().toLowerCase();
            const cached   = metaCache.get(cacheKey);

            if (cached) {
                paintSidebarPane(cached, q, reqId);
                return;
            }

            $.ajax({
                url:  cfg.ajaxUrl,
                data: { action: 'was_search', nonce: cfg.nonce, q },
                success(resp) {
                    if (searchReqId !== reqId) return;
                    const data = resp.success ? resp.data : {};
                    metaCache.set(cacheKey, data);
                    paintSidebarPane(data, q, reqId);
                },
                error() {
                    if (searchReqId !== reqId) return;
                    const nr = getNr(reqId);
                    nr.sidebarChecked = true; nr.sidebarEmpty = true;
                    maybeShowNoResults(q, reqId);
                }
            });
        }, 150);

        /* ---------------------------------------------------------------
           Full-page search (Enter / Search button)

           navigator.sendBeacon is specifically designed for analytics/
           tracking calls made at navigation time: it queues the POST
           reliably in the background WITHOUT blocking the page transition,
           which is exactly what was causing the 1-2 second Enter delay
           (the old $.post was resolving before the browser committed to
           navigation). Falls back to fire-and-forget $.post on old browsers.
        --------------------------------------------------------------- */
        function doFullSearch() {
            const q = $input.val().trim();
            if (!q) return;
            addRecent(q);

            // Instant visual feedback — user knows Enter was registered
            $searchBtn.addClass('was-btn-loading').prop('disabled', true);
            $input.prop('disabled', true);

            // Reliable non-blocking tracking (won't delay navigation)
            try {
                const fd = new FormData();
                fd.append('action', 'was_track_search');
                fd.append('nonce',  cfg.nonce);
                fd.append('q',      q);
                if (!navigator.sendBeacon(cfg.ajaxUrl, fd)) throw new Error('beacon');
            } catch (_) {
                $.post(cfg.ajaxUrl, { action: 'was_track_search', nonce: cfg.nonce, q });
            }

            window.location.href = buildSearchUrl(q);
        }

        /* ---------------------------------------------------------------
           Event binding
        --------------------------------------------------------------- */
        $input.on('input', function () {
            const q = this.value.trim();
            $clearBtn.toggle(q.length > 0);
            if (!q) { hideDropdown(); return; }
            doSearch(q);
        });

        // Single keydown handler — consolidated (was split across 2 handlers,
        // causing tracking to fire twice on Enter)
        $input.on('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); if ($dropdown.is(':visible')) moveFocus(1);  return; }
            if (e.key === 'ArrowUp')   { e.preventDefault(); if ($dropdown.is(':visible')) moveFocus(-1); return; }
            if (e.key === 'Escape')    { hideDropdown(); return; }
            if (e.key === 'Enter') {
                e.preventDefault();
                if ($dropdown.is(':visible')) selectFocused();
                doFullSearch();
            }
        });

        $searchBtn.on('click', doFullSearch);

        $clearBtn.on('click', function () {
            $input.val('').focus();
            $clearBtn.hide();
            hideDropdown();
        });

        $(document).on('click.was', function (e) {
            if (!$(e.target).closest('.was-search-wrapper').length) hideDropdown();
        });
    }

    /* -----------------------------------------------------------------------
       Init all instances on page
    ----------------------------------------------------------------------- */
    $(function () {
        $('.was-search-wrapper').each(function () { initInstance(this); });
        $('.was-results-page').each(function () { initResultsPage(this); });
    });

    /* =======================================================================
       Full Search Results Page  [woo_advanced_search_results]
       Reads ?s= and ?page= from URL. Renders grid + sidebar filters + pager.
    ======================================================================= */
    function initResultsPage(container) {
        if (!algoliaIndex) {
            container.querySelector('.was-rp-main').innerHTML =
                '<p style="color:#b91c1c;padding:30px 0">Algolia not configured — fill in credentials under WooCommerce → Advanced Search → Algolia.</p>';
            return;
        }

        const rpCfg         = JSON.parse(container.getAttribute('data-was-rp-config') || '{}');
        const $container    = $(container);
        const $input        = $container.find('.was-rp-input');
        const $searchBtn    = $container.find('.was-rp-search-btn');
        const $stats        = $container.find('.was-rp-stats');
        const $grid         = $container.find('.was-rp-grid');
        const $noResults    = $container.find('.was-rp-no-results');
        const $pagination   = $container.find('.was-rp-pagination');
        const $filtersAside = $container.find('.was-rp-filters');
        const $activeFils   = $container.find('.was-rp-active-filters');
        const facetDefs     = rpCfg.facets || [];

        // Column switcher — restore saved preference (defaults to 4)
        const COL_KEY = 'was_rp_cols';
        let currentCols = parseInt(localStorage.getItem(COL_KEY) || '4', 10);
        if (![3,4,5,6].includes(currentCols)) currentCols = 4;

        function applyColumns(n) {
            currentCols = n;
            $grid.removeClass('was-rp-cols-3 was-rp-cols-4 was-rp-cols-5 was-rp-cols-6')
                 .addClass('was-rp-cols-' + n);
            $container.find('.was-rp-col-btn').removeClass('was-rp-col-active');
            $container.find('.was-rp-col-btn[data-cols="' + n + '"]').addClass('was-rp-col-active');
            try { localStorage.setItem(COL_KEY, n); } catch(e) {}
        }
        applyColumns(currentCols); // apply on init

        $container.on('click', '.was-rp-col-btn', function() {
            applyColumns(parseInt($(this).data('cols'), 10));
        });

        // State
        let currentQuery  = rpCfg.initialQuery || '';
        let currentPage   = rpCfg.initialPage  || 0;
        let selectedFacets = {}; // { attribute: Set<value> }
        let searchReqId   = 0;

        /* -- search -------------------------------------------------------- */
        function doSearch(resetPage) {
            if (resetPage) currentPage = 0;
            const reqId = ++searchReqId;

            const facetFilters = [];
            facetDefs.forEach(function (fd) {
                const sel = selectedFacets[fd.attr];
                if (sel && sel.size > 0) facetFilters.push([...sel].map(v => fd.attr + ':' + v));
            });

            algoliaIndex.search(currentQuery, {
                hitsPerPage:            rpCfg.hitsPerPage || 24,
                page:                   currentPage,
                facets:                 facetDefs.map(fd => fd.attr),
                highlightPreTag:        '<mark>',
                highlightPostTag:       '</mark>',
                facetFilters:           facetFilters.length ? facetFilters : undefined,
                queryType:              'prefixAll',
                removeWordsIfNoResults: 'allOptional',
            }).then(function (res) {
                if (reqId !== searchReqId) return;
                paintStats(res.query, res.nbHits);
                paintFacets(res.facets || {});
                paintGrid(res.hits);
                paintPagination(res.nbPages, res.page);
                syncURL();
                $noResults.prop('hidden', res.hits.length > 0);
            }).catch(function (err) {
                console.error('[WAS Results]', err);
            });
        }

        /* -- render stats -------------------------------------------------- */
        function paintStats(q, n) {
            $stats.text( q ? n.toLocaleString() + ' result' + (n === 1 ? '' : 's') + ' for "' + q + '"' : '' );
        }

        // Tracks which filter groups are collapsed and which are expanded for show-more
        const collapsedGroups = new Set();
        const expandedShowMore = new Set();
        const SHOW_MORE_THRESHOLD = 8; // show "N more" button after this many items

        /* -- render filter sidebar ----------------------------------------- */
        function paintFacets(facets) {
            let anyVisible = false;

            // First, hide any filter groups not in our facetDefs
            // (groups with empty/unknown data-facet attrs won't have data)
            $filtersAside.find('.was-rp-filter-group').each(function() {
                const attr = $(this).data('facet');
                if (!attr || !facetDefs.find(fd => fd.attr === String(attr))) {
                    $(this).prop('hidden', true);
                }
            });

            facetDefs.forEach(function (fd) {
                const $group = $container.find('.was-rp-filter-group[data-facet="' + fd.attr + '"]');
                const data   = facets[fd.attr];
                if (!data || !Object.keys(data).length) { $group.prop('hidden', true); return; }

                anyVisible = true;
                $group.prop('hidden', false);

                // Add collapse chevron to heading once
                const $heading = $group.find('.was-rp-filter-heading');
                if (!$heading.find('.was-rp-chevron').length) {
                    $heading.append('<span class="was-rp-chevron">▼</span>');
                }
                // Restore collapsed state
                $group.toggleClass('is-collapsed', collapsedGroups.has(fd.attr));

                const sel = selectedFacets[fd.attr] || new Set();
                const isExpanded = expandedShowMore.has(fd.attr);
                const sorted = Object.entries(data).sort((a, b) => {
                    if (sel.has(a[0]) !== sel.has(b[0])) return sel.has(b[0]) ? 1 : -1;
                    return b[1] - a[1];
                });

                let html = sorted.map(([value, count], idx) => {
                    const extra   = (!isExpanded && idx >= SHOW_MORE_THRESHOLD) ? ' was-rp-extra-item' : '';
                    const checked = sel.has(value) ? ' checked' : '';
                    return '<label class="was-rp-facet-item' + extra + '">' +
                        '<input type="checkbox" data-facet="' + esc(fd.attr) + '" data-value="' + esc(value) + '"' + checked + '>' +
                        '<span class="was-rp-facet-label">' + esc(value) + '</span>' +
                        '<span class="was-rp-facet-count">' + count + '</span>' +
                        '</label>';
                }).join('');

                if (sorted.length > SHOW_MORE_THRESHOLD) {
                    const remaining = sorted.length - SHOW_MORE_THRESHOLD;
                    html += '<button class="was-rp-show-more" data-facet="' + esc(fd.attr) + '">' +
                        (isExpanded ? 'Show less' : 'Show ' + remaining + ' more') + '</button>';
                }

                $group.find('.was-rp-filter-list').html(html);
            });
            $filtersAside.prop('hidden', !anyVisible);
            paintActiveFilters();
        }

        function paintActiveFilters() {
            const chips = [];
            let totalActive = 0;

            facetDefs.forEach(function (fd) {
                const sel = selectedFacets[fd.attr];
                if (sel) sel.forEach(v => {
                    totalActive++;
                    chips.push(
                        '<button class="was-rp-chip" data-facet="' + esc(fd.attr) + '" data-value="' + esc(v) + '">' +
                        esc(v) + ' ✕</button>'
                    );
                });
            });

            // "Reset all filters" button — only shown when ≥1 filter is active
            if (totalActive > 0) {
                chips.push('<button class="was-rp-reset-all">✕ Reset all filters</button>');
            }

            $activeFils.html(chips.join(''));
        }

        /* -- render product grid ------------------------------------------- */
        function paintGrid(hits) {
            if (!hits.length) { $grid.html(''); return; }

            $grid.html(hits.map(function (hit) {
                const id       = getField(hit, alg.fields.id) || hit.objectID || '';
                const name     = getField(hit, alg.fields.title) || '';
                const nameHtml = getHighlight(hit, alg.fields.title) || esc(name);
                const img      = cfg.showImage ? getBestImage(hit) : '';
                const url      = getField(hit, alg.fields.url) || '#';
                const sku      = cfg.showSku ? String(getField(hit, alg.fields.sku) || '') : '';
                const imgHtml  = img ? '<img src="' + esc(img) + '" alt="' + esc(name) + '" loading="lazy">' : ICON.img_ph;
                const priceHtml = cfg.showPrice
                    ? '<div class="was-rp-product-price' + (alg.liveEnrich ? ' was-price-shimmer' : '') + '" data-was-price></div>'
                    : '';
                return '<a href="' + esc(url) + '" class="was-rp-product-card" data-id="' + esc(String(id)) + '">' +
                    '<div class="was-rp-product-img">' + imgHtml + '</div>' +
                    '<div class="was-rp-product-info">' +
                        '<div class="was-rp-product-sku">' + (sku ? 'STOCK CODE: ' + esc(sku) : '') + '</div>' +
                        '<div class="was-rp-product-name">' + nameHtml + '</div>' +
                        priceHtml +
                    '</div>' +
                '</a>';
            }).join(''));

            // Live price/SKU enrichment
            if (alg.liveEnrich) {
                const ids = hits.map(h => getField(h, alg.fields.id) || h.objectID || '').filter(Boolean);
                if (ids.length) $.ajax({
                    url: cfg.ajaxUrl, method: 'POST',
                    data: { action: 'was_enrich', nonce: cfg.nonce, ids },
                    success: function (resp) {
                        if (!resp?.success || !resp.data) return;
                        Object.entries(resp.data).forEach(([id, info]) => {
                            const card = $grid[0].querySelector('.was-rp-product-card[data-id="' + id + '"]');
                            if (!card) return;
                            if (info.price) {
                                const el = card.querySelector('[data-was-price]');
                                if (el) { el.innerHTML = info.price; el.classList.remove('was-price-shimmer'); }
                            }
                            if (info.sku) {
                                const el = card.querySelector('.was-rp-product-sku');
                                if (el && !el.textContent.trim()) el.textContent = 'STOCK CODE: ' + info.sku;
                            }
                            // Fill in image from WooCommerce when Algolia record had no image
                            if (info.image) {
                                const imgDiv = card.querySelector('.was-rp-product-img');
                                if (imgDiv && !imgDiv.querySelector('img')) {
                                    imgDiv.innerHTML = '<img src="' + esc(info.image) + '" alt="' + esc(card.querySelector('.was-rp-product-name')?.textContent || '') + '" loading="lazy">';
                                }
                            }
                        });
                    }
                });
            }
        }

        /* -- render pagination --------------------------------------------- */
        function paintPagination(nbPages, page) {
            if (nbPages <= 1) { $pagination.html(''); return; }
            const parts = [];
            const start = Math.max(0, page - 2);
            const end   = Math.min(nbPages - 1, page + 2);
            if (start > 0) {
                parts.push('<button class="was-rp-page-btn" data-page="0">1</button>');
                if (start > 1) parts.push('<span class="was-rp-ellipsis">…</span>');
            }
            for (let i = start; i <= end; i++) {
                parts.push('<button class="was-rp-page-btn' + (i === page ? ' was-rp-page-active' : '') + '" data-page="' + i + '">' + (i + 1) + '</button>');
            }
            if (end < nbPages - 1) {
                if (end < nbPages - 2) parts.push('<span class="was-rp-ellipsis">…</span>');
                parts.push('<button class="was-rp-page-btn" data-page="' + (nbPages - 1) + '">' + nbPages + '</button>');
            }
            $pagination.html('<nav class="was-rp-pages" aria-label="Pagination">' + parts.join('') + '</nav>');
        }

        /* -- URL sync ------------------------------------------------------ */
        function syncURL() {
            try {
                const url = new URL(window.location.href);
                // Use ?query= not ?s= — WordPress intercepts ?s= before the page
                // loads, causing a 404. ?query= is a plain custom parameter.
                if (currentQuery) url.searchParams.set('query', currentQuery); else url.searchParams.delete('query');
                url.searchParams.delete('s'); // clean up any legacy ?s= if present
                if (currentPage > 0) url.searchParams.set('page', currentPage + 1); else url.searchParams.delete('page');
                history.replaceState({}, '', url.toString());
            } catch (e) {}
        }

        /* -- events --------------------------------------------------------- */
        const debouncedRpSearch = debounce(function () {
            currentQuery = $input.val().trim();
            selectedFacets = {};
            doSearch(true);
        }, cfg.debounceMs || 300);

        $input.on('input', debouncedRpSearch);
        $input.on('keydown', function (e) {
            if (e.key === 'Enter') { currentQuery = this.value.trim(); selectedFacets = {}; doSearch(true); }
        });
        $searchBtn.on('click', function () { currentQuery = $input.val().trim(); selectedFacets = {}; doSearch(true); });

        $container.on('change', '.was-rp-facet-item input[type="checkbox"]', function () {
            const attr  = $(this).data('facet');
            const value = String($(this).data('value'));
            if (!selectedFacets[attr]) selectedFacets[attr] = new Set();
            if (this.checked) selectedFacets[attr].add(value); else selectedFacets[attr].delete(value);
            if (!selectedFacets[attr].size) delete selectedFacets[attr];
            doSearch(true);
        });

        $container.on('click', '.was-rp-chip', function () {
            const attr  = $(this).data('facet');
            const value = String($(this).data('value'));
            if (selectedFacets[attr]) {
                selectedFacets[attr].delete(value);
                if (!selectedFacets[attr].size) delete selectedFacets[attr];
            }
            doSearch(true);
        });

        // Reset all active filters at once
        $container.on('click', '.was-rp-reset-all', function () {
            selectedFacets = {};
            doSearch(true);
        });

        // Collapse / expand a filter group when heading is clicked
        $container.on('click', '.was-rp-filter-heading', function () {
            const $group = $(this).closest('.was-rp-filter-group');
            const attr   = $group.data('facet');
            $group.toggleClass('is-collapsed');
            if ($group.hasClass('is-collapsed')) collapsedGroups.add(attr);
            else collapsedGroups.delete(attr);
        });

        // Show more / show less inside a filter list
        $container.on('click', '.was-rp-show-more', function (e) {
            e.stopPropagation(); // don't trigger heading collapse
            const attr   = $(this).data('facet');
            const $list  = $(this).closest('.was-rp-filter-list');
            const isOpen = expandedShowMore.has(attr);
            if (isOpen) {
                expandedShowMore.delete(attr);
                $list.find('.was-rp-extra-item').hide();
                $(this).text('Show ' + $list.find('.was-rp-extra-item').length + ' more');
            } else {
                expandedShowMore.add(attr);
                $list.find('.was-rp-extra-item').show();
                $(this).text('Show less');
            }
        });

        $container.on('click', '.was-rp-page-btn', function () {
            currentPage = parseInt($(this).data('page'));
            doSearch(false);
            container.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        // Show grid skeleton immediately on page load — replaces blank flash
        // before Algolia responds (~100-200ms).
        // NOTE: we do NOT touch $filtersAside here — paintFacets() needs the
        // original .was-rp-filter-group elements to be present inside it.
        // The sidebar will appear naturally once the first search completes.
        (function showInitialSkeleton() {
            const n = Math.min(rpCfg.hitsPerPage || 24, 12);
            let gridHtml = '';
            for (let i = 0; i < n; i++) {
                gridHtml += '<div class="was-rp-skeleton-card">' +
                    '<div class="was-rp-skel-img"></div>' +
                    '<div style="padding:12px">' +
                        '<div class="was-skel-line was-skel-wide" style="margin-bottom:8px"></div>' +
                        '<div class="was-skel-line was-skel-mid" style="margin-bottom:6px"></div>' +
                        '<div class="was-skel-line was-skel-short"></div>' +
                    '</div>' +
                '</div>';
            }
            $grid.html(gridHtml);
            // Sidebar stays hidden ($filtersAside is hidden by default in PHP)
            // paintFacets() will reveal it once Algolia returns facet data.
        })();

        // Initial search on page load
        doSearch(false);
    }

}(jQuery));
