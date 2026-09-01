<?php
defined( 'ABSPATH' ) || exit;

class WAS_Shortcode {

    public static function init(): void {
        add_shortcode( 'woo_advanced_search',         [ __CLASS__, 'render' ] );
        add_shortcode( 'woo_advanced_search_results', [ __CLASS__, 'render_results' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
        // Preconnect to Algolia domains so the first search fires faster
        add_action( 'wp_head', [ __CLASS__, 'print_preconnects' ], 2 );
    }

    public static function print_preconnects(): void {
        $app_id = WAS_Settings::get( 'algolia_app_id' );
        if ( ! $app_id ) return;
        // These hints tell the browser to resolve DNS + complete TLS handshake
        // to Algolia before the JS even runs, cutting ~200-300ms off cold searches.
        ?>
        <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
        <link rel="preconnect" href="https://<?php echo esc_attr( $app_id ); ?>.algolia.net" crossorigin>
        <link rel="preconnect" href="https://<?php echo esc_attr( $app_id ); ?>-dsn.algolia.net" crossorigin>
        <link rel="dns-prefetch"  href="https://<?php echo esc_attr( $app_id ); ?>-1.algolianet.com">
        <link rel="dns-prefetch"  href="https://<?php echo esc_attr( $app_id ); ?>-2.algolianet.com">
        <link rel="dns-prefetch"  href="https://<?php echo esc_attr( $app_id ); ?>-3.algolianet.com">
        <?php
    }

    public static function enqueue(): void {
        $css_file = WAS_PLUGIN_DIR . 'assets/css/was-search.css';
        $js_file  = WAS_PLUGIN_DIR . 'assets/js/was-search.js';

        // Algolia JS client — false (last param) = load in <head> not footer
        // so it's ready sooner and doesn't delay the first search.
        wp_register_script(
            'algoliasearch',
            'https://cdn.jsdelivr.net/npm/algoliasearch@4.23.3/dist/algoliasearch.umd.js',
            [],
            '4.23.3',
            false  // <-- head, not footer
        );

        wp_enqueue_style(
            'was-style',
            WAS_PLUGIN_URL . 'assets/css/was-search.css',
            [],
            was_asset_version( $css_file )
        );
        wp_enqueue_script(
            'was-script',
            WAS_PLUGIN_URL . 'assets/js/was-search.js',
            [ 'jquery', 'algoliasearch' ],
            was_asset_version( $js_file ),
            true
        );

        $s = WAS_Settings::get();

        wp_localize_script( 'was-script', 'wasConfig', [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'was_nonce' ),
            'minChars'      => (int) $s['min_chars'],
            'debounceMs'    => (int) $s['debounce_ms'],
            'showProducts'  => (bool) $s['show_products'],
            'showCats'      => (bool) $s['show_categories'],
            'showSugg'      => (bool) $s['show_suggestions'],
            'suggLimit'     => (int) $s['suggestions_limit'],
            'suggSource'    => $s['suggestion_source'],
            'showSeeAll'        => (bool) $s['see_all_button'],
            'seeAllLabel'       => $s['see_all_text'],     // "See All Products" from Products tab
            'resultsPageLimit'  => (int) ( $s['results_page_limit'] ?? 24 ),
            'showNRCats'    => (bool) $s['show_popular_cats_on_noresult'],
            'showInstead'   => (bool) $s['show_search_instead'],
            'nrMessage'     => $s['noresult_message'],
            'primaryColor'  => $s['primary_color'],
            'accentColor'   => $s['accent_color'],
            'productsLimit'     => (int) $s['products_limit'],
            'searchResultsUrl'  => $s['search_results_url'],
            'showPrice'     => (bool) $s['show_price'],
            'showSku'       => (bool) $s['show_sku'],
            'showImage'     => (bool) $s['show_product_image'],
            'popularCats'   => WAS_Ajax::popular_categories( 8 ),

            // Algolia connection + field mapping
            'algolia' => [
                'appId'      => $s['algolia_app_id'],
                'apiKey'     => $s['algolia_api_key'],
                'indexName'  => $s['algolia_index_name'],
                'liveEnrich' => (bool) $s['algolia_live_enrich'],
                'fields'     => [
                    'title' => $s['algolia_field_title'],
                    'image' => $s['algolia_field_image'],
                    'url'   => $s['algolia_field_url'],
                    'id'    => $s['algolia_field_id'],
                    'price' => $s['algolia_field_price'],
                    'sku'   => $s['algolia_field_sku'],
                ],
            ],

            'i18n' => [
                'products'    => __( 'Products', 'woo-advanced-search' ),
                'suggestions' => __( 'Suggestions', 'woo-advanced-search' ),
                'categories'  => __( 'Categories', 'woo-advanced-search' ),
                'instead'     => __( 'Search instead', 'woo-advanced-search' ),
                'tips_title'  => __( 'Try the following:', 'woo-advanced-search' ),
                'tips'        => [
                    __( 'Double check your spelling', 'woo-advanced-search' ),
                    __( 'Use fewer keywords', 'woo-advanced-search' ),
                    __( 'Search to an item that is less specific and refine results', 'woo-advanced-search' ),
                ],
                'popular_cats' => __( 'Popular categories', 'woo-advanced-search' ),
            ],
        ] );
    }

    /* -----------------------------------------------------------------------
       Shortcode output — HTML markup unchanged from v1.0.x
    ----------------------------------------------------------------------- */
    public static function render( $atts ): string {
        $atts = shortcode_atts( [], (array) $atts );
        $s    = WAS_Settings::get();

        $btn_style = sprintf(
            'background:%s;color:%s;border-radius:%dpx;',
            esc_attr( $s['button_bg'] ),
            esc_attr( $s['button_text_color'] ),
            (int) $s['button_border_radius']
        );

        $css_vars = sprintf(
            '--was-primary:%s;--was-accent:%s;--was-highlight:%s;--was-font-size:%dpx;--was-dropdown-max-height:%dpx;',
            esc_attr( $s['primary_color'] ),
            esc_attr( $s['accent_color'] ),
            esc_attr( $s['highlight_color'] ),
            (int) $s['font_size'],
            (int) $s['dropdown_max_height']
        );

        ob_start();
        ?>
        <div class="was-search-wrapper" style="<?php echo esc_attr( $css_vars ); ?>">
            <div class="was-search-bar" role="search">
                <button class="was-clear-btn" type="button" aria-label="<?php esc_attr_e( 'Clear', 'woo-advanced-search' ); ?>" style="display:none;">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M1 1L13 13M13 1L1 13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </button>

                <svg class="was-search-icon" width="18" height="18" viewBox="0 0 20 20" fill="none"><circle cx="9" cy="9" r="7" stroke="#9ca3af" stroke-width="2"/><path d="M15 15L19 19" stroke="#9ca3af" stroke-width="2" stroke-linecap="round"/></svg>

                <input
                    type="text"
                    class="was-input"
                    placeholder="<?php echo esc_attr( $s['placeholder'] ); ?>"
                    autocomplete="off"
                    aria-label="<?php echo esc_attr( $s['placeholder'] ); ?>"
                    aria-autocomplete="list"
                    aria-expanded="false"
                >

                <?php if ( $s['show_button'] ) : ?>
                    <button class="was-search-btn" type="button" style="<?php echo esc_attr( $btn_style ); ?>">
                        <?php echo esc_html( $s['button_text'] ); ?>
                    </button>
                <?php endif; ?>
            </div>

            <div class="was-dropdown" role="listbox" aria-label="<?php esc_attr_e( 'Search results', 'woo-advanced-search' ); ?>" hidden>
                <div class="was-dropdown-inner">
                    <!-- JS fills this -->
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* -----------------------------------------------------------------------
       [woo_advanced_search_results] — full-page search results
       Place this shortcode on any page (e.g. /algolia-search/).
       Reads ?s= and ?page= from the URL, renders product grid with
       category/brand filters and pagination, all powered by Algolia.

       Attributes:
         hits_per_page  — products per page (default: 24)
         category_facet — Algolia attribute for categories (default: taxonomies.product_cat)
         brand_facet    — Algolia attribute for brands (default: taxonomies.product_brand)
    ----------------------------------------------------------------------- */
    public static function render_results( $atts ): string {
        $atts = shortcode_atts( [
            'hits_per_page'  => $s['results_page_limit'] ?? 24,  // overridable via attribute
            'category_facet' => 'taxonomies.product_cat',
            'brand_facet'    => 'taxonomies.product_brand',
            'pa_voltage' => 'taxonomies.pa_voltage',
            'pa_amp-range' => 'taxonomies.pa_amp-range',
            'pa_ip-rating' => 'taxonomies.pa_ip-rating',
            'pa_material' => 'taxonomies.pa_material',            
            'pa_colour' => 'taxonomies.pa_colour',
            'pa_dimensions'    => 'taxonomies.pa_dimensions',            
            
        ], (array) $atts );

        $s             = WAS_Settings::get();
        // Read query from URL. Use ?query= (not ?s=) because WordPress intercepts
        // ?s= as its own search before the page can load, causing a 404.
        // ?s= is kept as a fallback for direct WP search links.
        $initial_query = '';
        if ( isset( $_GET['query'] ) ) {
            $initial_query = sanitize_text_field( wp_unslash( $_GET['query'] ) );
        } elseif ( isset( $_GET['s'] ) ) {
            $initial_query = sanitize_text_field( wp_unslash( $_GET['s'] ) );
        }
        $initial_page  = max( 0, ( isset( $_GET['page'] ) ? (int) $_GET['page'] : 1 ) - 1 );

        $css_vars = sprintf(
            '--was-primary:%s;--was-accent:%s;--was-highlight:%s;--was-font-size:%dpx;--was-card-radius:%dpx;--was-card-border:%s;--was-img-ratio:%d%%;--was-rp-sku-color:%s;--was-rp-sku-size:%dpx;--was-rp-title-color:%s;--was-rp-title-size:%dpx;--was-rp-title-weight:%d;--was-rp-price-color:%s;--was-rp-price-size:%dpx;',
            esc_attr( $s['primary_color'] ),
            esc_attr( $s['accent_color'] ),
            esc_attr( $s['highlight_color'] ),
            (int) $s['font_size'],
            (int) ( $s['card_radius']       ?? 10 ),
            esc_attr( $s['card_border_color'] ?? '#e5e7eb' ),
            (int) ( $s['img_ratio']          ?? 90 ),
            esc_attr( $s['rp_sku_color']     ?? '#6E727B' ),
            (int) ( $s['rp_sku_size']        ?? 10 ),
            esc_attr( $s['rp_title_color']   ?? '#1a1f29' ),
            (int) ( $s['rp_title_size']      ?? 13 ),
            (int) ( $s['rp_title_weight']    ?? 600 ),
            esc_attr( $s['rp_price_color']   ?? '#1a1f29' ),
            (int) ( $s['rp_price_size']      ?? 15 )
        );

        $btn_style = sprintf(
            'background:%s;color:%s;border-radius:%dpx;',
            esc_attr( $s['button_bg'] ),
            esc_attr( $s['button_text_color'] ),
            (int) $s['button_border_radius']
        );

        $rp_config = wp_json_encode( [
            'initialQuery'   => $initial_query,
            'initialPage'    => $initial_page,
            'hitsPerPage'    => (int) $atts['hits_per_page'],
            'facets'         => [
                [ 'attr' => sanitize_text_field( $atts['category_facet'] ), 'label' => __( 'Categories', 'woo-advanced-search' ) ],
                [ 'attr' => sanitize_text_field( $atts['brand_facet'] ),    'label' => __( 'Brands', 'woo-advanced-search' ) ],
                [ 'attr' => sanitize_text_field( $atts['pa_voltage'] ),    'label' => __( 'Voltage', 'woo-advanced-search' ) ],                
                [ 'attr' => sanitize_text_field( $atts['pa_amp-range'] ),    'label' => __( 'Amp Rating', 'woo-advanced-search' ) ],                
                [ 'attr' => sanitize_text_field( $atts['pa_ip-rating'] ),    'label' => __( 'IP Rating', 'woo-advanced-search' ) ],
                [ 'attr' => sanitize_text_field( $atts['pa_material'] ),    'label' => __( 'Material', 'woo-advanced-search' ) ],
                [ 'attr' => sanitize_text_field( $atts['pa_dimensions'] ),    'label' => __( 'Dimension', 'woo-advanced-search' ) ],
                [ 'attr' => sanitize_text_field( $atts['pa_colour'] ),    'label' => __( 'Colour', 'woo-advanced-search' ) ],                
            ],
        ] );

        ob_start();
        ?>
        <div class="was-results-page" style="<?php echo esc_attr( $css_vars ); ?>"
             data-was-rp-config='<?php echo esc_attr( $rp_config ); ?>'>

            <!-- Search bar -->
            <div class="was-rp-topbar">
                <div class="was-rp-search-wrap">
                    <svg class="was-rp-search-icon" width="18" height="18" viewBox="0 0 20 20" fill="none"><circle cx="9" cy="9" r="7" stroke="currentColor" stroke-width="2"/><path d="M15 15L19 19" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    <input type="text" class="was-rp-input"
                           value="<?php echo esc_attr( $initial_query ); ?>"
                           placeholder="<?php echo esc_attr( $s['placeholder'] ); ?>"
                           autocomplete="off">
                    <?php if ( $s['show_button'] ) : ?>
                    <button type="button" class="was-rp-search-btn" style="<?php echo esc_attr( $btn_style ); ?>">
                        <?php echo esc_html( $s['button_text'] ); ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stats + active filter chips -->
            <div class="was-rp-meta">
                <div class="was-rp-stats"></div>
                <div class="was-rp-active-filters"></div>
            </div>

            <!-- Body: sidebar + grid -->
            <div class="was-rp-body">
                <aside class="was-rp-filters" hidden>
                    <div class="was-rp-filter-group" data-facet="<?php echo esc_attr( $atts['category_facet'] ); ?>">
                        <h4 class="was-rp-filter-heading"><?php esc_html_e( 'Categories', 'woo-advanced-search' ); ?></h4>
                        <div class="was-rp-filter-list"></div>
                    </div>
                    <div class="was-rp-filter-group" data-facet="<?php echo esc_attr( $atts['brand_facet'] ); ?>">
                        <h4 class="was-rp-filter-heading"><?php esc_html_e( 'Brands', 'woo-advanced-search' ); ?></h4>
                        <div class="was-rp-filter-list"></div>
                    </div>
                    <div class="was-rp-filter-group" data-facet="<?php echo esc_attr( $atts['pa_voltage'] ); ?>">
                        <h4 class="was-rp-filter-heading"><?php esc_html_e( 'Voltage', 'woo-advanced-search' ); ?></h4>
                        <div class="was-rp-filter-list"></div>
                    </div> 
                    <div class="was-rp-filter-group" data-facet="<?php echo esc_attr( $atts['pa_amp-range'] ); ?>">
                        <h4 class="was-rp-filter-heading"><?php esc_html_e( 'Amp Rating', 'woo-advanced-search' ); ?></h4>
                        <div class="was-rp-filter-list"></div>
                    </div> 
                    <div class="was-rp-filter-group" data-facet="<?php echo esc_attr( $atts['pa_ip-rating'] ); ?>">
                        <h4 class="was-rp-filter-heading"><?php esc_html_e( 'IP Rating', 'woo-advanced-search' ); ?></h4>
                        <div class="was-rp-filter-list"></div>
                    </div> 
                    <div class="was-rp-filter-group" data-facet="<?php echo esc_attr( $atts['pa_material'] ); ?>">
                        <h4 class="was-rp-filter-heading"><?php esc_html_e( 'Material', 'woo-advanced-search' ); ?></h4>
                        <div class="was-rp-filter-list"></div>
                    </div> 
                    <div class="was-rp-filter-group" data-facet="<?php echo esc_attr( $atts['pa_colour'] ); ?>">
                        <h4 class="was-rp-filter-heading"><?php esc_html_e( 'Colour', 'woo-advanced-search' ); ?></h4>
                        <div class="was-rp-filter-list"></div>
                    </div>                     
                    <div class="was-rp-filter-group" data-facet="<?php echo esc_attr( $atts['pa_dimensions'] ); ?>">
                        <h4 class="was-rp-filter-heading"><?php esc_html_e( 'Dimensions', 'woo-advanced-search' ); ?></h4>
                        <div class="was-rp-filter-list"></div>
                    </div>                    
                </aside>

                <main class="was-rp-main">
                    <!-- Column switcher toolbar -->
                    <div class="was-rp-toolbar">
                        <div class="was-rp-col-switcher" aria-label="<?php esc_attr_e( 'Grid columns', 'woo-advanced-search' ); ?>">
                            <button class="was-rp-col-btn" data-cols="3" title="3 columns">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><rect x="0" y="0" width="4" height="7" rx="1"/><rect x="6" y="0" width="4" height="7" rx="1"/><rect x="12" y="0" width="4" height="7" rx="1"/><rect x="0" y="9" width="4" height="7" rx="1"/><rect x="6" y="9" width="4" height="7" rx="1"/><rect x="12" y="9" width="4" height="7" rx="1"/></svg>
                                <span>3</span>
                            </button>
                            <button class="was-rp-col-btn was-rp-col-active" data-cols="4" title="4 columns">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><rect x="0" y="0" width="2.5" height="7" rx="1"/><rect x="4.5" y="0" width="2.5" height="7" rx="1"/><rect x="9" y="0" width="2.5" height="7" rx="1"/><rect x="13.5" y="0" width="2.5" height="7" rx="1"/><rect x="0" y="9" width="2.5" height="7" rx="1"/><rect x="4.5" y="9" width="2.5" height="7" rx="1"/><rect x="9" y="9" width="2.5" height="7" rx="1"/><rect x="13.5" y="9" width="2.5" height="7" rx="1"/></svg>
                                <span>4</span>
                            </button>
                            <button class="was-rp-col-btn" data-cols="5" title="5 columns">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><rect x="0" y="0" width="1.8" height="7" rx="0.5"/><rect x="3.5" y="0" width="1.8" height="7" rx="0.5"/><rect x="7" y="0" width="1.8" height="7" rx="0.5"/><rect x="10.4" y="0" width="1.8" height="7" rx="0.5"/><rect x="13.9" y="0" width="1.8" height="7" rx="0.5"/><rect x="0" y="9" width="1.8" height="7" rx="0.5"/><rect x="3.5" y="9" width="1.8" height="7" rx="0.5"/><rect x="7" y="9" width="1.8" height="7" rx="0.5"/><rect x="10.4" y="9" width="1.8" height="7" rx="0.5"/><rect x="13.9" y="9" width="1.8" height="7" rx="0.5"/></svg>
                                <span>5</span>
                            </button>
                            <button class="was-rp-col-btn" data-cols="6" title="6 columns">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><rect x="0" y="0" width="1.2" height="7" rx="0.5"/><rect x="2.9" y="0" width="1.2" height="7" rx="0.5"/><rect x="5.8" y="0" width="1.2" height="7" rx="0.5"/><rect x="8.7" y="0" width="1.2" height="7" rx="0.5"/><rect x="11.6" y="0" width="1.2" height="7" rx="0.5"/><rect x="14.4" y="0" width="1.2" height="7" rx="0.5"/><rect x="0" y="9" width="1.2" height="7" rx="0.5"/><rect x="2.9" y="9" width="1.2" height="7" rx="0.5"/><rect x="5.8" y="9" width="1.2" height="7" rx="0.5"/><rect x="8.7" y="9" width="1.2" height="7" rx="0.5"/><rect x="11.6" y="9" width="1.2" height="7" rx="0.5"/><rect x="14.4" y="9" width="1.2" height="7" rx="0.5"/></svg>
                                <span>6</span>
                            </button>
                        </div>
                    </div>
                    <div class="was-rp-grid was-rp-cols-4"></div>
                    <div class="was-rp-no-results" hidden>
                        <svg width="48" height="48" viewBox="0 0 48 48" fill="none" style="color:#d1d5db;margin-bottom:12px"><circle cx="22" cy="22" r="16" stroke="currentColor" stroke-width="2"/><path d="M34 34L44 44" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M16 22h12M22 16v12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        <p><?php esc_html_e( 'No products found.', 'woo-advanced-search' ); ?></p>
                        <p class="was-rp-nr-tip"><?php esc_html_e( 'Try fewer keywords or check your spelling.', 'woo-advanced-search' ); ?></p>
                    </div>
                    <div class="was-rp-pagination"></div>
                </main>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
