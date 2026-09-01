=== WooCommerce Advanced Search ===
Contributors: syntexdev
Tags: woocommerce, algolia, search, live search, instant search, product search, synonyms
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.4.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Algolia-powered instant search for WooCommerce — live dropdown, full results page with filters, synonyms manager, and B2BKing-aware live pricing.

== Description ==

Drop `[woo_advanced_search]` anywhere for a fully-featured instant search experience powered directly by Algolia. Add `[woo_advanced_search_results]` to a dedicated page for a full results page with facet filters and pagination.

**Key Features**

= Instant Search Dropdown =
* Algolia CDN — results in ~100ms, no WordPress round-trip for products
* Keyword highlighting on matched search terms
* Live price & SKU enrichment via WooCommerce after cards render (B2BKing tier-aware)
* Skeleton shimmer loading — no blank flash
* Category suggestions in sidebar
* Recent searches, popular searches, manual suggestion list
* Configurable "See All Products" button

= Full Results Page =
* `[woo_advanced_search_results]` shortcode with URL routing (?query=)
* 3 / 4 / 5 / 6 column grid switcher (persisted in localStorage)
* Facet filter sidebar — collapsible, scrollable, "Show more"
* Active filter chips + Reset all filters
* Pagination with smart ellipsis
* Sticky sidebar, responsive layout

= Synonyms Manager =
* Admin tab to add, edit, delete synonym pairs
* Types: Regular (bi-directional) and One-way
* Load NAW defaults — 36 industry abbreviation pairs pre-loaded
* Push to Algolia / Pull from Algolia via server-side API (Admin key never exposed to browser)
* Supports partial-phrase one-way synonyms (e.g. "miniature" → MCB)

= Image Quality =
* getBestImage() tries medium → woocommerce_thumbnail → thumbnail
* Strips WordPress size suffix from thumbnail URL to load full-resolution original
* WooCommerce enrichment fills in images missing from Algolia index (e.g. uploaded after last re-index)

= Admin Settings =
* Algolia: App ID, Search-Only Key, Index, Admin Key, Field Mapping, Connection Test
* Synonyms: Full CRUD table, Push/Pull Algolia, NAW Defaults
* Search Field: Placeholder, Min chars, Debounce, Search Results URL
* Button: Show/hide, label, colour, radius
* Products: Dropdown limit, Results page limit, Image/Price/SKU toggles, See All text
* Styling: Primary colour, Accent colour, Highlight colour, Font size, Dropdown height, Card radius, Card border, Image ratio, SKU/Title/Price colour & size

= Search Quality =
* queryType: prefixAll — partial phrases trigger synonym expansion
* removeWordsIfNoResults: allOptional — natural language recall
* Browser preconnect hints to Algolia CDN for fast cold start

== Installation ==

1. Upload the `woo-advanced-search` folder to `/wp-content/plugins/`
2. Activate under **Plugins → Installed Plugins**
3. Go to **WooCommerce → Advanced Search → Algolia tab**
4. Enter your Algolia Application ID, Search-Only API Key, and Index Name
5. Click **Test Connection** — should show green with record count
6. Place `[woo_advanced_search]` in your site header
7. Create a page, add `[woo_advanced_search_results]`, set its URL in the Algolia tab → Search results page URL

== Shortcodes ==

= Dropdown search bar =
    [woo_advanced_search]

= Full results page =
    [woo_advanced_search_results]
    [woo_advanced_search_results hits_per_page="24" category_facet="taxonomies.product_cat" brand_facet="taxonomies.product_brand"]

== Requirements ==

* WooCommerce 7.0+
* An Algolia account with products indexed (compatible with wp-search-with-algolia plugin)
* PHP 7.4+
* WordPress 5.8+

== Frequently Asked Questions ==

= Which Algolia index should I use? =
If you use the official wp-search-with-algolia plugin, your index is typically `wp_posts_product` or `wp_searchable_posts`. Run the Connection Test in the Algolia tab — it shows the record count and warns if zero records are found.

= Why do I need an Admin API Key? =
Only for the Synonyms Manager (Push/Pull). The Admin key is stored server-side and never sent to the browser. The Search-Only key is used for all front-end searches.

= How do I set up synonyms? =
Go to Advanced Search → Synonyms → Load NAW Defaults (or add your own) → Push to Algolia. Changes are live in Algolia within seconds.

= Prices show wrong or no dollar sign? =
Enable "Live price & SKU" in the Algolia tab. This fetches real-time WooCommerce prices after the card renders, including B2BKing tier pricing for the logged-in customer.

= Some products show a placeholder image =
This means the Algolia record has no image (image was added after the last Algolia re-index). With Live price & SKU enabled, the plugin fetches the image from WooCommerce automatically as a fallback.

== Changelog ==

= 1.4.8 =
* Enhancement: WooCommerce enrichment now also returns featured image — fixes products showing placeholder icon when Algolia record has no image (e.g. uploaded after last re-index)

= 1.4.7 =
* Fix: Reduce image padding from 6px to 2px to recover space for narrow product images

= 1.4.6 =
* Enhancement: Add 8 partial-phrase one-way synonyms to NAW defaults (miniature→MCB, residual→RCD, variable speed→VSD, etc.)
* Fix: images in dropdown use object-fit contain — no cropping

= 1.4.5 =
* Fix: product images in dropdown now use object-fit contain (was cover — caused cropping of tall/narrow images)

= 1.4.4 =
* Enhancement: queryType prefixAll added — partial phrases now trigger Algolia synonyms (miniature circuit → MCB)
* Fix: image box background changed from gray to white

= 1.4.3 =
* Fix: getBestImage() moved to module level — dropdown now uses same high-resolution image logic as results page

= 1.4.2 =
* Fix: Admin API Key field renders with value="" — prevents bullet-character masking from corrupting stored key on re-save
* Fix: One-way synonyms with single target term (e.g. kA) now push correctly (validation was incorrectly requiring ≥2 terms for all types)
* Enhancement: Admin API Key now shows green saved badge with last 6 chars for confirmation

= 1.4.1 =
* Fix: Admin API Key sanitize strips bullet/asterisk chars — prevents stored key corruption when saving unrelated settings

= 1.4.0 =
* Feature: Synonyms Manager tab — full CRUD, Push to Algolia, Pull from Algolia, 28 NAW industry defaults
* Feature: Admin API Key field (server-side only, never localized to JS)
* Enhancement: removeWordsIfNoResults: allOptional added to both search calls

= 1.3.9 =
* Enhancement: SKU, Title, Price colour and size controls added to Styling tab (CSS variables)

= 1.3.8 =
* Fix: Algolia script moved to head for faster initialization
* Enhancement: Browser preconnect hints for Algolia CDN domains
* Fix: Orphaned filter groups (empty data-facet) now hidden correctly
* Feature: Reset all filters button

= 1.3.7 =
* Enhancement: Card border radius, border colour, image height ratio added to Styling tab
* Fix: Skeleton sidebar replaced static filter group HTML — filter groups now preserved so paintFacets works

= 1.3.6 =
* Enhancement: getBestImage() — tries medium/woocommerce_thumbnail first, strips -150x150 suffix for full-res fallback
* Enhancement: Skeleton grid shown on page load (no blank flash)

= 1.3.5 =
* Feature: Column switcher 3/4/5/6 on results page (localStorage persistent)
* Fix: Sidebar width changed to minmax(180px, 22%)

= 1.3.3 =
* Feature: Collapsible filter groups with chevron toggle
* Feature: Show more / Show less for long filter lists
* Feature: Scrollable filter sidebar with styled scrollbar

= 1.3.2 =
* Fix: Search results URL now uses ?query= not ?s= — prevents WordPress search intercept causing 404

= 1.3.1 =
* Feature: [woo_advanced_search_results] shortcode — full results page with facets, pagination, URL sync

= 1.3.0 =
* Feature: Search results page URL configurable in Algolia tab
* Fix: buildSearchUrl now uses WP product search instead of WooCommerce shop URL

= 1.2.9 =
* Feature: Price shimmer placeholder while WooCommerce enrichment is in-flight

= 1.2.8 =
* Fix: Raw Algolia price (no $ symbol, wrong B2BKing tier) no longer shown — WooCommerce enrichment owns price display

= 1.2.7 =
* Feature: Keyword Highlight Color added to Styling tab (--was-highlight CSS variable)
* Cleanup: Full Results Page URL setting removed from Search Field tab

= 1.2.6 =
* Fix: Settings JS wrapped in jQuery document.ready — prevents wpColorPicker crash killing Test Now button

= 1.2.5 =
* Feature: Connection Test button in Algolia tab — auto-runs on page load, checks record count, suggests correct index

= 1.0.0 =
* Initial release — live search dropdown, Algolia integration, category suggestions, no-results state
