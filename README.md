# WooCommerce Advanced Search

> Algolia-powered instant search plugin for WooCommerce — built for NAW Controls (naw.com.au)

![Version](https://img.shields.io/badge/version-1.4.8-blue)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue)
![WooCommerce](https://img.shields.io/badge/WooCommerce-7.0%2B-96588a)
![License](https://img.shields.io/badge/license-GPLv2-green)

---

## Overview

A fully-featured Algolia instant search plugin for WooCommerce with two shortcodes:

| Shortcode | Purpose |
|---|---|
| `[woo_advanced_search]` | Live search dropdown — place in header |
| `[woo_advanced_search_results]` | Full results page with facet filters + pagination |

Products are fetched **directly from Algolia in the visitor's browser** (no WordPress round-trip). Prices and SKUs are enriched live from WooCommerce after render, making it fully compatible with **B2BKing** customer tier pricing.

---

## Features

### Live Search Dropdown
- Instant Algolia results on every keystroke (~100ms)
- Keyword highlighting on matched terms
- Skeleton shimmer loading (no blank flash)
- Category suggestions sidebar
- Recent / popular / manual suggestions
- Live price + SKU from WooCommerce (B2BKing tier-aware)
- "See All Products (N)" button → full results page

### Full Results Page
- 3 / 4 / 5 / 6 column grid switcher (localStorage persistent)
- Facet filter sidebar — collapsible groups, scrollable lists, Show more
- Active filter chips + Reset all filters
- Pagination with smart ellipsis
- URL sync (`?query=` + `?page=`) — bookmarkable and shareable
- Skeleton loading on page load

### Synonyms Manager
- Admin tab with full CRUD table
- Types: Regular (bi-directional) and One-way
- **36 industry abbreviation pairs pre-loaded** for electrical/industrial catalogue:
  - RCD ↔ Residual Current Device
  - MCB ↔ Miniature Circuit Breaker
  - VSD/VFD ↔ Variable Speed/Frequency Drive
  - miniature → MCB (partial phrase)
  - residual current → RCD (partial phrase)
  - _...and 30 more_
- Push to Algolia / Pull from Algolia via server-side PHP
- Admin API key stored server-side — **never sent to browser**

### Image Quality
- Tries `images.medium` → `images.woocommerce_thumbnail` → strips size suffix from thumbnail URL
- WooCommerce enrichment fills in images missing from Algolia index (added after last re-index)

### Admin Settings Panel
**WooCommerce → Advanced Search** with 9 tabs:

| Tab | Controls |
|---|---|
| Algolia | App ID, Search-Only Key, Index, Admin Key, Field Mapping, Connection Test |
| Synonyms | CRUD table, Push/Pull Algolia, Load NAW Defaults |
| Search Field | Placeholder, Min chars, Debounce, Results URL |
| Button | Show/hide, label, colour, border radius |
| Products | Dropdown limit, Results page limit, Image/Price/SKU toggles |
| Categories | Show/hide, limit, whitelist |
| Suggestions | Source (Recent / Popular / Manual), limit |
| No Results | Custom message, popular categories grid |
| Styling | Primary colour, Accent, Highlight, Font size, Card radius, Card border, Image ratio, SKU/Title/Price colour & size |

---

## Requirements

- WordPress 5.8+
- WooCommerce 7.0+
- PHP 7.4+
- Algolia account with products indexed
- Compatible with [wp-search-with-algolia](https://wordpress.org/plugins/wp-search-with-algolia/) plugin

---

## Installation

1. Clone or download this repo
2. Upload the `woo-advanced-search` folder to `/wp-content/plugins/`
3. Activate under **Plugins → Installed Plugins**
4. Go to **WooCommerce → Advanced Search → Algolia tab**
5. Enter your **Application ID**, **Search-Only API Key**, and **Index Name**
6. Click **▶ Test Now** — confirm green ✓ with record count
7. Place `[woo_advanced_search]` in your site header
8. Create a WordPress page, add `[woo_advanced_search_results]`
9. In Algolia tab → **Search results page URL** → set to `/your-page-slug/?query=%s`

### Setting Up Synonyms

1. Advanced Search → **Synonyms tab**
2. Paste your **Admin API Key** in the Algolia tab (one-time setup)
3. Click **↓ Load NAW defaults (36 pairs)**
4. Click **▲ Push to Algolia**
5. Done — changes are live within seconds

---

## Shortcode Reference

### Dropdown
```
[woo_advanced_search]
```
All configuration via WP Admin — no shortcode attributes needed.

### Results Page
```
[woo_advanced_search_results]
[woo_advanced_search_results hits_per_page="24"]
[woo_advanced_search_results category_facet="taxonomies.product_cat" brand_facet="taxonomies.product_brand"]
```

---

## Architecture

```
Browser → Algolia CDN          (products, ~100ms)
Browser → WordPress AJAX       (categories, suggestions, enrichment)
PHP     → Algolia REST API     (synonyms push/pull — Admin key server-side only)
PHP     → WooCommerce          (price, SKU, image enrichment)
```

**Security:** The Algolia Admin API key is stored in WordPress options (server-side only). It is never localized to JavaScript or sent to visitors' browsers. All Synonyms API calls are made PHP → Algolia.

---

## File Structure

```
woo-advanced-search/
├── woo-advanced-search.php          # Plugin bootstrap
├── includes/
│   ├── class-was-settings.php       # Admin settings panel + Synonyms Manager UI
│   ├── class-was-ajax.php           # WP AJAX handlers (search meta, enrich, synonyms API)
│   └── class-was-shortcode.php      # Shortcode render + asset enqueue
├── assets/
│   ├── js/was-search.js             # All frontend JS (dropdown + results page)
│   └── css/was-search.css           # All styles
└── readme.txt                       # WordPress.org readme
```

---

## Changelog

See [readme.txt](readme.txt) for full version history.

**Latest: v1.4.8**
- WooCommerce enrichment now returns featured image — fixes placeholder icons when Algolia record has no image (e.g. uploaded after last re-index)
- queryType: prefixAll — partial phrases trigger synonym expansion ("miniature" → MCB)
- Synonyms Manager with 36 NAW industry defaults
- Admin API key stored server-side only

---

## Credits

Built by [SyntexDev](https://syntexdev.com) for NAW Controls.

- Algolia JavaScript Client v4
- jQuery (WordPress bundled)
- WooCommerce REST API (price/SKU enrichment)
- Algolia Synonyms REST API (server-side)
