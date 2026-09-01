<?php
defined( 'ABSPATH' ) || exit;

class WAS_Settings {

    const OPTION_KEY = 'was_settings';

    /* -----------------------------------------------------------------------
       Defaults
    ----------------------------------------------------------------------- */
    public static function defaults(): array {
        return [
            /* Search field */
            'placeholder'          => 'Search Categories & Products',
            'min_chars'            => 2,
            'debounce_ms'          => 300,
            'search_in_sku'        => 1,
            'search_in_desc'       => 0,

            /* Button */
            'button_text'          => 'Search',
            'button_bg'            => '#0d1b3e',
            'button_text_color'    => '#ffffff',
            'button_border_radius' => 50,
            'show_button'          => 1,

            /* Products panel */
            'show_products'        => 1,
            'products_limit'       => 5,
            'results_page_limit'   => 24,   // products per page on [woo_advanced_search_results]
            'show_price'           => 1,
            'show_sku'             => 1,
            'show_product_image'   => 1,
            'see_all_button'       => 1,
            'see_all_text'         => 'See All Products',

            /* Categories */
            'show_categories'      => 1,
            'categories_limit'     => 6,
            'allowed_categories'   => [],   // empty = all

            /* Suggestions */
            'show_suggestions'     => 1,
            'suggestions_limit'    => 4,
            'suggestion_source'    => 'recent', // recent | popular | manual
            'manual_suggestions'   => '',        // newline-separated

            /* No-results */
            'show_popular_cats_on_noresult' => 1,
            'show_search_instead'           => 1,
            'noresult_message'              => 'Sorry, we didn\'t find any matches for "{query}"',

            /* Styling */
            'dropdown_max_height'  => 520,
            'primary_color'        => '#0d1b3e',
            'accent_color'         => '#2563eb',
            'highlight_color'      => '#002E5E',   // keyword mark colour in search results
            'font_size'            => 15,

            /* Results page card + image controls */
            'card_radius'          => 10,
            'card_border_color'    => '#e5e7eb',
            'img_ratio'            => 90,

            /* Results page text styling */
            'rp_sku_color'         => '#6E727B',
            'rp_sku_size'          => 10,
            'rp_title_color'       => '#1a1f29',
            'rp_title_size'        => 13,
            'rp_title_weight'      => 600,
            'rp_price_color'       => '#1a1f29',
            'rp_price_size'        => 15,

            /* ── Algolia ──────────────────────────────────────────────── */
            'algolia_app_id'       => 'X2PDNQPACG',
            'algolia_api_key'      => '',            // Search-Only key — fill in the admin tab
            'algolia_index_name'   => 'wp_searchable_posts',

            // Field mapping — dot-paths into each Algolia hit record.
            // Defaults match the wp-search-with-algolia / wp_searchable_posts record shape.
            'algolia_field_title'  => 'post_title',
            'algolia_field_image'  => 'images.thumbnail.url',
            'algolia_field_url'    => 'permalink',
            'algolia_field_id'     => 'post_id',
            'algolia_field_price'  => 'price',
            'algolia_field_sku'    => 'sku',

            // Fetch live price + SKU from WooCommerce after Algolia results render.
            // Recommended: Algolia records rarely include current price.
            'algolia_live_enrich'  => 1,
            'search_results_url'   => '/algolia-search/?query=%s',
            'algolia_admin_key'    => '',   // Write/Admin key — stored server-side only, NEVER sent to JS
        ];
    }

    public static function get( string $key = '' ) {
        $opts = wp_parse_args(
            (array) get_option( self::OPTION_KEY, [] ),
            self::defaults()
        );
        return $key ? ( $opts[ $key ] ?? null ) : $opts;
    }

    /* -----------------------------------------------------------------------
       Boot
    ----------------------------------------------------------------------- */
    public static function init(): void {
        add_action( 'admin_menu',    [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init',    [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_init',    [ __CLASS__, 'handle_purge' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
    }

    /* -----------------------------------------------------------------------
       Handle manual cache-purge button
    ----------------------------------------------------------------------- */
    public static function handle_purge(): void {
        if (
            isset( $_GET['was_purge_cache'] ) &&
            isset( $_GET['_wpnonce'] ) &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'was_purge_cache' ) &&
            current_user_can( 'manage_woocommerce' )
        ) {
            $counter = (int) get_option( 'was_cache_counter', 0 ) + 1;
            update_option( 'was_cache_counter', $counter, true );

            $css = WAS_PLUGIN_DIR . 'assets/css/was-search.css';
            $js  = WAS_PLUGIN_DIR . 'assets/js/was-search.js';
            if ( file_exists( $css ) ) @touch( $css );
            if ( file_exists( $js  ) ) @touch( $js  );

            $back_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'search';

            wp_redirect( add_query_arg( [
                'page'   => 'was-settings',
                'tab'    => $back_tab,
                'purged' => '1',
            ], admin_url( 'admin.php' ) ) );
            exit;
        }
    }

    public static function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Advanced Search', 'woo-advanced-search' ),
            __( 'Advanced Search', 'woo-advanced-search' ),
            'manage_woocommerce',
            'was-settings',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function register_settings(): void {
        register_setting( 'was_settings_group', self::OPTION_KEY, [
            'sanitize_callback' => [ __CLASS__, 'sanitize' ],
        ] );
    }

    public static function sanitize( $input ): array {
        $defaults = self::defaults();
        $clean    = [];

        $clean['placeholder']          = sanitize_text_field( $input['placeholder'] ?? $defaults['placeholder'] );
        $clean['min_chars']            = absint( $input['min_chars'] ?? $defaults['min_chars'] );
        $clean['debounce_ms']          = absint( $input['debounce_ms'] ?? $defaults['debounce_ms'] );
        $clean['search_in_sku']        = ! empty( $input['search_in_sku'] ) ? 1 : 0;
        $clean['search_in_desc']       = ! empty( $input['search_in_desc'] ) ? 1 : 0;

        $clean['dropdown_max_height']  = absint( $input['dropdown_max_height'] ?? $defaults['dropdown_max_height'] );
        $clean['primary_color']        = sanitize_hex_color( $input['primary_color'] ?? $defaults['primary_color'] ) ?: $defaults['primary_color'];
        $clean['accent_color']         = sanitize_hex_color( $input['accent_color']  ?? $defaults['accent_color']  ) ?: $defaults['accent_color'];
        $clean['highlight_color']      = sanitize_hex_color( $input['highlight_color'] ?? $defaults['highlight_color'] ) ?: $defaults['highlight_color'];
        $clean['font_size']            = absint( $input['font_size'] ?? $defaults['font_size'] );
        $clean['card_radius']          = min( 30, absint( $input['card_radius']       ?? $defaults['card_radius'] ) );
        $clean['card_border_color']    = sanitize_hex_color( $input['card_border_color'] ?? $defaults['card_border_color'] ) ?: $defaults['card_border_color'];
        $clean['img_ratio']            = min( 150, max( 50, absint( $input['img_ratio'] ?? $defaults['img_ratio'] ) ) );

        $clean['rp_sku_color']         = sanitize_hex_color( $input['rp_sku_color']   ?? $defaults['rp_sku_color'] )   ?: $defaults['rp_sku_color'];
        $clean['rp_sku_size']          = min( 16, max( 8, absint( $input['rp_sku_size']   ?? $defaults['rp_sku_size'] ) ) );
        $clean['rp_title_color']       = sanitize_hex_color( $input['rp_title_color'] ?? $defaults['rp_title_color'] ) ?: $defaults['rp_title_color'];
        $clean['rp_title_size']        = min( 20, max( 10, absint( $input['rp_title_size']  ?? $defaults['rp_title_size'] ) ) );
        $clean['rp_title_weight']      = in_array( (int)( $input['rp_title_weight'] ?? 600 ), [400, 500, 600, 700], true ) ? (int)$input['rp_title_weight'] : 600;
        $clean['rp_price_color']       = sanitize_hex_color( $input['rp_price_color'] ?? $defaults['rp_price_color'] ) ?: $defaults['rp_price_color'];
        $clean['rp_price_size']        = min( 22, max( 10, absint( $input['rp_price_size']  ?? $defaults['rp_price_size'] ) ) );

        $clean['show_button']          = ! empty( $input['show_button'] ) ? 1 : 0;
        $clean['button_text']          = sanitize_text_field( $input['button_text'] ?? $defaults['button_text'] );
        $clean['button_bg']            = sanitize_hex_color( $input['button_bg'] ?? $defaults['button_bg'] ) ?: $defaults['button_bg'];
        $clean['button_text_color']    = sanitize_hex_color( $input['button_text_color'] ?? $defaults['button_text_color'] ) ?: $defaults['button_text_color'];
        $clean['button_border_radius'] = absint( $input['button_border_radius'] ?? $defaults['button_border_radius'] );

        $clean['show_products']        = ! empty( $input['show_products'] ) ? 1 : 0;
        $clean['products_limit']       = min( 10, absint( $input['products_limit'] ?? $defaults['products_limit'] ) );
        $clean['results_page_limit']   = min( 100, max( 6, absint( $input['results_page_limit'] ?? $defaults['results_page_limit'] ) ) );
        $clean['show_price']           = ! empty( $input['show_price'] ) ? 1 : 0;
        $clean['show_sku']             = ! empty( $input['show_sku'] ) ? 1 : 0;
        $clean['show_product_image']   = ! empty( $input['show_product_image'] ) ? 1 : 0;
        $clean['see_all_button']       = ! empty( $input['see_all_button'] ) ? 1 : 0;
        $clean['see_all_text']         = sanitize_text_field( $input['see_all_text'] ?? $defaults['see_all_text'] );

        $clean['show_categories']      = ! empty( $input['show_categories'] ) ? 1 : 0;
        $clean['categories_limit']     = absint( $input['categories_limit'] ?? $defaults['categories_limit'] );
        $allowed = isset( $input['allowed_categories'] ) ? array_map( 'absint', (array) $input['allowed_categories'] ) : [];
        $clean['allowed_categories']   = $allowed;

        $clean['show_suggestions']     = ! empty( $input['show_suggestions'] ) ? 1 : 0;
        $clean['suggestions_limit']    = absint( $input['suggestions_limit'] ?? $defaults['suggestions_limit'] );
        $src = in_array( $input['suggestion_source'] ?? '', [ 'recent', 'popular', 'manual' ], true )
            ? $input['suggestion_source']
            : $defaults['suggestion_source'];
        $clean['suggestion_source']    = $src;
        $clean['manual_suggestions']   = sanitize_textarea_field( $input['manual_suggestions'] ?? '' );

        $clean['show_popular_cats_on_noresult'] = ! empty( $input['show_popular_cats_on_noresult'] ) ? 1 : 0;
        $clean['show_search_instead']           = ! empty( $input['show_search_instead'] ) ? 1 : 0;
        $clean['noresult_message']              = sanitize_text_field( $input['noresult_message'] ?? $defaults['noresult_message'] );

        /* Algolia */
        $clean['algolia_app_id']      = sanitize_text_field( $input['algolia_app_id']      ?? $defaults['algolia_app_id'] );
        $clean['algolia_api_key']     = sanitize_text_field( $input['algolia_api_key']     ?? '' );
        $clean['algolia_index_name']  = sanitize_text_field( $input['algolia_index_name']  ?? $defaults['algolia_index_name'] );
        $clean['algolia_field_title'] = sanitize_text_field( $input['algolia_field_title'] ?? $defaults['algolia_field_title'] );
        $clean['algolia_field_image'] = sanitize_text_field( $input['algolia_field_image'] ?? $defaults['algolia_field_image'] );
        $clean['algolia_field_url']   = sanitize_text_field( $input['algolia_field_url']   ?? $defaults['algolia_field_url'] );
        $clean['algolia_field_id']    = sanitize_text_field( $input['algolia_field_id']    ?? $defaults['algolia_field_id'] );
        $clean['algolia_field_price'] = sanitize_text_field( $input['algolia_field_price'] ?? $defaults['algolia_field_price'] );
        $clean['algolia_field_sku']   = sanitize_text_field( $input['algolia_field_sku']   ?? $defaults['algolia_field_sku'] );
        $clean['algolia_live_enrich'] = ! empty( $input['algolia_live_enrich'] ) ? 1 : 0;
        $clean['search_results_url']  = sanitize_text_field( $input['search_results_url'] ?? $defaults['search_results_url'] );
        // Admin key — stored encrypted-at-rest by WP but never localized to JS
        $raw_key = $input['algolia_admin_key'] ?? '';
        // Strip any placeholder masking characters (•, *, etc.) that get re-submitted
        // when the user saves another setting without touching the key field.
        // If the submitted value is all bullets/asterisks, treat it as blank → preserve existing.
        $raw_key = preg_replace( '/^[\x{2022}\*\s]+$/u', '', trim( $raw_key ) );
        if ( $raw_key === '' ) {
            $existing = get_option( self::OPTION_KEY, [] );
            $clean['algolia_admin_key'] = $existing['algolia_admin_key'] ?? '';
        } else {
            $clean['algolia_admin_key'] = sanitize_text_field( $raw_key );
        }

        return $clean;
    }

    public static function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'was-settings' ) === false ) return;
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_add_inline_script( 'wp-color-picker', '
            jQuery(function($){
                $(".was-color-picker").wpColorPicker();
            });
        ' );
    }

    /* -----------------------------------------------------------------------
       Admin page HTML
    ----------------------------------------------------------------------- */
    public static function render_page(): void {
        $s    = self::get();
        $cats = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );

        $active   = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'algolia';
        $css_file = WAS_PLUGIN_DIR . 'assets/css/was-search.css';
        $js_file  = WAS_PLUGIN_DIR . 'assets/js/was-search.js';
        $css_ver  = was_asset_version( $css_file );
        $purge_url = wp_nonce_url(
            add_query_arg( [ 'page' => 'was-settings', 'tab' => $active, 'was_purge_cache' => '1' ], admin_url( 'admin.php' ) ),
            'was_purge_cache'
        );
        ?>
        <div class="wrap was-admin-wrap">
            <h1 style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <span style="background:#0d1b3e;color:#fff;padding:4px 12px;border-radius:6px;font-size:13px;">WAS</span>
                <?php esc_html_e( 'WooCommerce Advanced Search Settings', 'woo-advanced-search' ); ?>
                <a href="<?php echo esc_url( $purge_url ); ?>"
                   style="margin-left:auto;background:#dc2626;color:#fff;padding:6px 16px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;"
                   onclick="return confirm('Purge CSS &amp; JS cache now?');">
                    🔄 <?php esc_html_e( 'Purge CSS/JS Cache', 'woo-advanced-search' ); ?>
                </a>
            </h1>

            <?php if ( isset( $_GET['purged'] ) && $_GET['purged'] === '1' ) : ?>
                <div class="notice notice-success is-dismissible" style="margin:10px 0;">
                    <p><strong><?php esc_html_e( 'Cache purged!', 'woo-advanced-search' ); ?></strong>
                    <?php printf( esc_html__( 'Assets will reload with version %s.', 'woo-advanced-search' ), '<code>' . esc_html( $css_ver ) . '</code>' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <p style="color:#555;margin-bottom:4px;">
                <?php esc_html_e( 'Use shortcode', 'woo-advanced-search' ); ?>
                <code>[woo_advanced_search]</code>
                <?php esc_html_e( 'to embed the search bar anywhere.', 'woo-advanced-search' ); ?>
            </p>
            <p style="color:#888;font-size:12px;margin-bottom:20px;">
                <?php esc_html_e( 'Current asset version:', 'woo-advanced-search' ); ?> <code><?php echo esc_html( $css_ver ); ?></code>
                &nbsp;·&nbsp; <?php esc_html_e( 'CSS modified:', 'woo-advanced-search' ); ?>
                <code><?php echo file_exists( $css_file ) ? esc_html( date( 'Y-m-d H:i:s', filemtime( $css_file ) ) ) : 'n/a'; ?></code>
                &nbsp;·&nbsp; <?php esc_html_e( 'JS modified:', 'woo-advanced-search' ); ?>
                <code><?php echo file_exists( $js_file ) ? esc_html( date( 'Y-m-d H:i:s', filemtime( $js_file ) ) ) : 'n/a'; ?></code>
            </p>

            <?php
            $tabs = [
                'algolia'     => '🔑 ' . __( 'Algolia',      'woo-advanced-search' ),
                'synonyms'    => '🔄 ' . __( 'Synonyms',     'woo-advanced-search' ),
                'search'      => '🔍 ' . __( 'Search Field', 'woo-advanced-search' ),
                'button'      => '🔘 ' . __( 'Button',       'woo-advanced-search' ),
                'products'    => '📦 ' . __( 'Products',     'woo-advanced-search' ),
                'categories'  => '🗂 '  . __( 'Categories',  'woo-advanced-search' ),
                'suggestions' => '💡 ' . __( 'Suggestions',  'woo-advanced-search' ),
                'noresults'   => '🚫 ' . __( 'No Results',   'woo-advanced-search' ),
                'styling'     => '🎨 ' . __( 'Styling',      'woo-advanced-search' ),
            ];
            ?>

            <form method="post" action="options.php" id="was-settings-form">
                <?php settings_fields( 'was_settings_group' ); ?>
                <input type="hidden" name="was_active_tab" id="was_active_tab" value="<?php echo esc_attr( $active ); ?>">

                <nav class="nav-tab-wrapper was-nav-tabs" style="margin-bottom:20px;">
                    <?php foreach ( $tabs as $slug => $label ) : ?>
                        <a href="#was-tab-<?php echo esc_attr( $slug ); ?>"
                           class="nav-tab was-tab-link <?php echo $active === $slug ? 'nav-tab-active' : ''; ?>"
                           data-tab="<?php echo esc_attr( $slug ); ?>">
                            <?php echo esc_html( $label ); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <?php foreach ( $tabs as $slug => $label ) : ?>
                <div id="was-tab-<?php echo esc_attr( $slug ); ?>"
                     class="was-tab-panel"
                     style="<?php echo $active === $slug ? '' : 'display:none;'; ?>">
                    <table class="form-table was-form-table" role="presentation">

                    <?php if ( $slug === 'algolia' ) : ?>

                        <tr>
                            <th colspan="2">
                                <h3 style="margin:0 0 4px;">Algolia Connection</h3>
                                <p style="font-weight:normal;color:#666;margin:0;">Products are fetched directly from Algolia in the visitor's browser — faster than a WordPress AJAX call. Categories and suggestions still come from WordPress. <strong>This tab must be filled in for the search to show products.</strong></p>
                            </th>
                        </tr>
                        <tr>
                            <th><label for="alg_app_id">Application ID</label></th>
                            <td>
                                <input type="text" id="alg_app_id" name="was_settings[algolia_app_id]" value="<?php echo esc_attr( $s['algolia_app_id'] ); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="alg_api_key">Search-Only API Key</label></th>
                            <td>
                                <input type="text" id="alg_api_key" name="was_settings[algolia_api_key]" value="<?php echo esc_attr( $s['algolia_api_key'] ); ?>" class="regular-text">
                                <p class="description"><strong>Important:</strong> use the Algolia <em>Search-Only</em> (public) key — never the Admin key. This key is sent to every visitor's browser.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="alg_index">Index Name</label></th>
                            <td>
                                <input type="text" id="alg_index" name="was_settings[algolia_index_name]" value="<?php echo esc_attr( $s['algolia_index_name'] ); ?>" class="regular-text">
                                <p class="description">The index that contains your WooCommerce products. If using the official <em>Search by Algolia</em> plugin, check your Algolia dashboard → Indices for the full name (e.g. <code>wp_searchable_posts</code> or <code>wp_posts_product</code>).</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Connection test</th>
                            <td>
                                <button type="button" id="was-alg-test-btn" class="button">▶ Test Now</button>
                                <div id="was-alg-status" style="margin-top:10px;padding:10px 14px;border-radius:5px;font-size:13px;line-height:1.5;display:none;"></div>
                                <p class="description" style="margin-top:6px;">Hits Algolia directly from your browser with the credentials above. Run this whenever results aren't appearing on the front-end.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Live price &amp; SKU</th>
                            <td>
                                <label><input type="checkbox" name="was_settings[algolia_live_enrich]" value="1" <?php checked( $s['algolia_live_enrich'] ); ?>>
                                Fetch live price &amp; stock code from WooCommerce after results load</label>
                                <p class="description">Recommended. Algolia records often don't include real-time price. When enabled, a small AJAX call fills in the current price and SKU right after the product cards render.</p>
                            </td>
                        </tr>

                        <tr>
                            <th><label for="was_search_results_url">Search results page URL</label></th>
                            <td>
                                <input type="text" id="was_search_results_url" name="was_settings[search_results_url]" value="<?php echo esc_attr( $s['search_results_url'] ); ?>" class="large-text">
                                <p class="description">
                                    Where the <strong>Enter key</strong> and <strong>Products (N) button</strong> navigate. Use <code>%s</code> as the search query placeholder.<br>
                                    <strong style="color:#065f46">✓ Correct (use <code>?query=</code>):</strong> <code>/algolia-search/?query=%s</code><br>
                                    <strong style="color:#b91c1c">✗ Causes 404 (avoid <code>?s=</code>):</strong> <code>/algolia-search/?s=%s</code> — WordPress intercepts <code>?s=</code> as a search request before the page loads, causing a 404. Use <code>?query=</code> instead.
                                </p>
                            </td>
                        </tr>

                        <tr><th colspan="2"><h3 style="margin:20px 0 4px;">Field Mapping</h3><p style="font-weight:normal;color:#666;margin:0;">Dot-paths into each Algolia hit record. Defaults match the standard <em>wp-search-with-algolia</em> record shape. Only change these if your index uses different attribute names.</p></th></tr>
                        <tr>
                            <th><label for="alg_f_title">Product title</label></th>
                            <td><input type="text" id="alg_f_title" name="was_settings[algolia_field_title]" value="<?php echo esc_attr( $s['algolia_field_title'] ); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="alg_f_image">Thumbnail URL</label></th>
                            <td><input type="text" id="alg_f_image" name="was_settings[algolia_field_image]" value="<?php echo esc_attr( $s['algolia_field_image'] ); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="alg_f_url">Product URL</label></th>
                            <td><input type="text" id="alg_f_url" name="was_settings[algolia_field_url]" value="<?php echo esc_attr( $s['algolia_field_url'] ); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="alg_f_id">WordPress post ID</label></th>
                            <td>
                                <input type="text" id="alg_f_id" name="was_settings[algolia_field_id]" value="<?php echo esc_attr( $s['algolia_field_id'] ); ?>" class="regular-text">
                                <p class="description">Used for the live price/SKU lookup above.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="alg_f_price">Price (if indexed)</label></th>
                            <td>
                                <input type="text" id="alg_f_price" name="was_settings[algolia_field_price]" value="<?php echo esc_attr( $s['algolia_field_price'] ); ?>" class="regular-text">
                                <p class="description">Leave as-is if price isn't in your Algolia records — live enrichment will fill it in.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="alg_f_sku">SKU (if indexed)</label></th>
                            <td><input type="text" id="alg_f_sku" name="was_settings[algolia_field_sku]" value="<?php echo esc_attr( $s['algolia_field_sku'] ); ?>" class="regular-text"></td>
                        </tr>

                        <tr><th colspan="2" style="border-top:1px solid #f0f0f0;padding-top:20px;"><h3 style="margin:0 0 4px;">Admin API Key</h3><p style="font-weight:normal;color:#666;margin:0;">Required only for the Synonyms Manager. Never sent to visitors' browsers — stored and used server-side only.</p></th></tr>
                        <tr>
                            <th><label for="alg_admin_key">Admin API Key</label></th>
                            <td>
                                <?php if ( ! empty( $s['algolia_admin_key'] ) ) : ?>
                                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                                        <span style="display:inline-flex;align-items:center;gap:5px;background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;border-radius:5px;padding:4px 10px;font-size:13px;font-weight:600;">
                                            ✓ Key saved — ending in <code style="background:none;font-size:12px;"><?php echo esc_html( substr( $s['algolia_admin_key'], -6 ) ); ?></code>
                                        </span>
                                        <span style="color:#888;font-size:12px;">Paste a new key below only if you need to replace it</span>
                                    </div>
                                <?php endif; ?>
                                <input type="password" id="alg_admin_key" name="was_settings[algolia_admin_key]"
                                       value=""
                                       placeholder="<?php echo ( $s['algolia_admin_key'] ?? '' ) ? esc_attr__( 'Leave blank to keep the existing key', 'woo-advanced-search' ) : esc_attr__( 'Paste your Algolia Admin API Key here', 'woo-advanced-search' ); ?>"
                                       autocomplete="new-password" class="regular-text">
                                <p class="description">Find under Algolia dashboard → API Keys → <strong>Admin API Key</strong> (the one labelled "All operations"). This unlocks the Synonyms Manager tab.</p>
                            </td>
                        </tr>

                    <?php elseif ( $slug === 'synonyms' ) : ?>
                        <?php
                        $has_admin_key = ! empty( $s['algolia_admin_key'] );
                        $syn_nonce     = wp_create_nonce( 'was_synonyms_nonce' );
                        $stored_syns   = (array) get_option( 'was_synonyms', [] );

                        // NAW default synonyms from client spreadsheet
                        $naw_defaults = [
                            ['id'=>'naw-rcd',     'type'=>'regular',  'terms'=>'RCD, Residual Current Device',                           'input'=>'', 'notes'=>'Also covers residual current breaker if used in copy.'],
                            ['id'=>'naw-rcbo',    'type'=>'regular',  'terms'=>'RCBO, Residual Current Breaker with Overcurrent protection','input'=>'', 'notes'=>''],
                            ['id'=>'naw-elcb',    'type'=>'regular',  'terms'=>'ELCB, Earth Leakage Circuit Breaker',                    'input'=>'', 'notes'=>'Older/legacy term some tradespeople still search.'],
                            ['id'=>'naw-mcb',     'type'=>'regular',  'terms'=>'MCB, Miniature Circuit Breaker',                         'input'=>'', 'notes'=>''],
                            ['id'=>'naw-mccb',    'type'=>'regular',  'terms'=>'MCCB, Moulded Case Circuit Breaker',                     'input'=>'', 'notes'=>''],
                            ['id'=>'naw-msb',     'type'=>'regular',  'terms'=>'MSB, Main Switchboard',                                  'input'=>'', 'notes'=>''],
                            ['id'=>'naw-db',      'type'=>'regular',  'terms'=>'DB, Distribution Board',                                 'input'=>'', 'notes'=>'Verify no collision with other catalogue abbreviations.'],
                            ['id'=>'naw-vsd-vfd', 'type'=>'regular',  'terms'=>'VSD, VFD, Variable Speed Drive, Variable Frequency Drive','input'=>'', 'notes'=>'Grouped as one multi-way cluster — both terms used interchangeably.'],
                            ['id'=>'naw-dol',     'type'=>'regular',  'terms'=>'DOL, Direct On Line',                                    'input'=>'', 'notes'=>'Motor starter type.'],
                            ['id'=>'naw-plc',     'type'=>'regular',  'terms'=>'PLC, Programmable Logic Controller',                     'input'=>'', 'notes'=>''],
                            ['id'=>'naw-hmi',     'type'=>'regular',  'terms'=>'HMI, Human Machine Interface',                           'input'=>'', 'notes'=>''],
                            ['id'=>'naw-scada',   'type'=>'regular',  'terms'=>'SCADA, Supervisory Control and Data Acquisition',        'input'=>'', 'notes'=>''],
                            ['id'=>'naw-bms',     'type'=>'regular',  'terms'=>'BMS, Building Management System',                        'input'=>'', 'notes'=>''],
                            ['id'=>'naw-ats',     'type'=>'regular',  'terms'=>'ATS, Automatic Transfer Switch',                         'input'=>'', 'notes'=>''],
                            ['id'=>'naw-ups',     'type'=>'regular',  'terms'=>'UPS, Uninterruptible Power Supply',                      'input'=>'', 'notes'=>''],
                            ['id'=>'naw-ssr',     'type'=>'regular',  'terms'=>'SSR, Solid State Relay',                                 'input'=>'', 'notes'=>''],
                            ['id'=>'naw-spd',     'type'=>'regular',  'terms'=>'SPD, Surge Protection Device',                           'input'=>'', 'notes'=>''],
                            ['id'=>'naw-ct',      'type'=>'regular',  'terms'=>'CT, Current Transformer',                                'input'=>'', 'notes'=>'Short 2-letter — test against catalogue for false matches.'],
                            ['id'=>'naw-pt',      'type'=>'regular',  'terms'=>'PT, Potential Transformer, Voltage Transformer',         'input'=>'', 'notes'=>'Same caution as CT.'],
                            ['id'=>'naw-ip',      'type'=>'regular',  'terms'=>'IP Rating, Ingress Protection Rating',                   'input'=>'', 'notes'=>'Consider one-way synonym from "waterproof" to "IP".'],
                            ['id'=>'naw-gpo',     'type'=>'regular',  'terms'=>'GPO, General Purpose Outlet, Power Point, Socket Outlet','input'=>'', 'notes'=>'High-value consumer-facing term.'],
                            ['id'=>'naw-ka-1',    'type'=>'oneway',   'terms'=>'kA',                                                     'input'=>'Breaking Capacity', 'notes'=>'One-way: descriptive term expands to kA but not vice versa.'],
                            ['id'=>'naw-ka-2',    'type'=>'oneway',   'terms'=>'kA',                                                     'input'=>'Fault Rating',      'notes'=>'One-way: same reasoning as Breaking Capacity.'],
                            ['id'=>'naw-din',     'type'=>'regular',  'terms'=>'DIN Rail, DIN Mount',                                    'input'=>'', 'notes'=>''],
                            ['id'=>'naw-oem',     'type'=>'regular',  'terms'=>'OEM, Original Equipment Manufacturer',                   'input'=>'', 'notes'=>''],
                            ['id'=>'naw-emc',     'type'=>'regular',  'terms'=>'EMC, Electromagnetic Compatibility',                     'input'=>'', 'notes'=>''],
                            ['id'=>'naw-led',     'type'=>'regular',  'terms'=>'LED, Light Emitting Diode',                              'input'=>'', 'notes'=>'Low priority — LED is already common usage.'],
                            ['id'=>'naw-asnzs',   'type'=>'regular',  'terms'=>'AS/NZS, Australian Standard, Australian New Zealand Standard','input'=>'', 'notes'=>'Useful for compliance-driven searches.'],

                            // Partial-phrase one-way synonyms
                            // These let single words or short phrases trigger the right product category
                            // even before the user types the full technical term.
                            ['id'=>'naw-partial-miniature',         'type'=>'oneway',   'terms'=>'MCB',                       'input'=>'miniature',                   'notes'=>'miniature alone → MCB products'],
                            ['id'=>'naw-partial-miniature-circuit', 'type'=>'oneway',   'terms'=>'MCB',                       'input'=>'miniature circuit',            'notes'=>'miniature circuit → MCB products'],
                            ['id'=>'naw-partial-residual',          'type'=>'oneway',   'terms'=>'RCD',                       'input'=>'residual',                     'notes'=>'residual alone → RCD products'],
                            ['id'=>'naw-partial-residual-current',  'type'=>'oneway',   'terms'=>'RCD',                       'input'=>'residual current',             'notes'=>'residual current → RCD products'],
                            ['id'=>'naw-partial-variable-speed',    'type'=>'oneway',   'terms'=>'VSD, VFD',                  'input'=>'variable speed, variable frequency', 'notes'=>'partial phrases → VSD/VFD products'],
                            ['id'=>'naw-partial-moulded',           'type'=>'oneway',   'terms'=>'MCCB',                      'input'=>'moulded case',                 'notes'=>'moulded case → MCCB products'],
                            ['id'=>'naw-partial-surge',             'type'=>'oneway',   'terms'=>'SPD',                       'input'=>'surge protection, surge protector', 'notes'=>'surge protection → SPD products'],
                            ['id'=>'naw-partial-power-point',       'type'=>'oneway',   'terms'=>'GPO',                       'input'=>'power point, socket outlet',   'notes'=>'common consumer terms → GPO'],
                        ];
                        ?>
                        <tr>
                            <th colspan="2">
                                <?php if ( ! $has_admin_key ) : ?>
                                    <div style="background:#fff8e1;border:1px solid #ffd740;border-radius:6px;padding:14px 16px;margin-bottom:16px;">
                                        ⚠ <strong>Admin API Key required.</strong> Go to the <a href="?page=was-settings&tab=algolia">Algolia tab</a>, paste your Algolia Admin API Key, and save before using this tab.
                                    </div>
                                <?php else : ?>
                                    <div style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:6px;padding:14px 16px;margin-bottom:16px;">
                                        ✓ <strong>Admin API Key configured.</strong> You can push synonyms to Algolia and pull existing ones back.
                                    </div>
                                <?php endif; ?>

                                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
                                    <button type="button" id="was-syn-add" class="button button-primary">+ Add synonym</button>
                                    <button type="button" id="was-syn-load-defaults" class="button">↓ Load NAW defaults (<?php echo count($naw_defaults); ?> pairs)</button>
                                    <span style="flex:1"></span>
                                    <button type="button" id="was-syn-push" class="button" <?php disabled( ! $has_admin_key ); ?>>▲ Push to Algolia</button>
                                    <button type="button" id="was-syn-pull" class="button" <?php disabled( ! $has_admin_key ); ?>>▼ Pull from Algolia</button>
                                    <span id="was-syn-status" style="font-size:13px;color:#555;"></span>
                                </div>

                                <p style="color:#666;font-size:12px;margin:0 0 14px;">
                                    Changes are saved to WordPress when you click <strong>Save Settings</strong> below. Use <strong>Push to Algolia</strong> to sync the table to your Algolia index (required for searches to benefit). <strong>Pull from Algolia</strong> imports what Algolia currently has back into this table.
                                </p>

                                <!-- Hidden data store for synonyms JS -->
                                <input type="hidden" id="was-syn-nonce" value="<?php echo esc_attr( $syn_nonce ); ?>">
                                <input type="hidden" id="was-syn-ajaxurl" value="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
                                <input type="hidden" id="was-syn-data" value="<?php echo esc_attr( json_encode( $stored_syns ) ); ?>">
                                <input type="hidden" id="was-syn-defaults" value="<?php echo esc_attr( json_encode( $naw_defaults ) ); ?>">
                                <input type="hidden" id="was-syn-app-id" value="<?php echo esc_attr( $s['algolia_app_id'] ?? '' ); ?>">
                                <input type="hidden" id="was-syn-index" value="<?php echo esc_attr( $s['algolia_index_name'] ?? '' ); ?>">

                                <!-- Synonym table -->
                                <table id="was-syn-table" class="widefat striped" style="table-layout:fixed;">
                                    <colgroup>
                                        <col style="width:6%">
                                        <col style="width:40%">
                                        <col style="width:9%">
                                        <col style="width:20%">
                                        <col style="width:17%">
                                        <col style="width:8%">
                                    </colgroup>
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Terms (comma-separated)</th>
                                            <th>Type</th>
                                            <th>Input (one-way only)</th>
                                            <th>Notes</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="was-syn-tbody">
                                        <tr id="was-syn-empty"><td colspan="6" style="text-align:center;color:#999;padding:20px;">No synonyms yet. Add one above or load the NAW defaults.</td></tr>
                                    </tbody>
                                </table>
                            </th>
                        </tr>

                        <style>
                        #was-syn-table input,#was-syn-table select{width:100%;box-sizing:border-box;font-size:12px;padding:4px 6px;border:1px solid #ddd;border-radius:3px;}
                        #was-syn-table select{height:28px;}
                        .was-syn-btn{cursor:pointer;border:none;border-radius:3px;padding:3px 8px;font-size:11px;font-weight:600;}
                        .was-syn-del{background:#fce4e4;color:#c62828;}
                        .was-syn-del:hover{background:#e53935;color:#fff;}
                        .was-syn-badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600;}
                        .was-syn-regular{background:#e3f2fd;color:#1565c0;}
                        .was-syn-oneway{background:#fce4ec;color:#880e4f;}
                        </style>

                        <script>
                        (function(){
                        var syns = JSON.parse(document.getElementById('was-syn-data').value || '[]');
                        var defaults = JSON.parse(document.getElementById('was-syn-defaults').value || '[]');
                        var nonce  = document.getElementById('was-syn-nonce').value;
                        var ajaxUrl = document.getElementById('was-syn-ajaxurl').value;
                        var tbody  = document.getElementById('was-syn-tbody');
                        var empty  = document.getElementById('was-syn-empty');
                        var status = document.getElementById('was-syn-status');

                        function uid(){ return 'naw-syn-' + Date.now() + '-' + Math.random().toString(36).slice(2,6); }

                        function setStatus(msg, ok) {
                            status.textContent = msg;
                            status.style.color = ok === false ? '#c62828' : ok === true ? '#2e7d32' : '#555';
                        }

                        function renderRow(s, idx) {
                            var tr = document.createElement('tr');
                            tr.dataset.id = s.id;
                            tr.innerHTML = '<td style="text-align:center;color:#999;">' + (idx+1) + '</td>' +
                                '<td><input type="text" value="' + esc(s.terms) + '" data-field="terms" placeholder="Term1, Term2, Full Name..."></td>' +
                                '<td><select data-field="type">' +
                                    '<option value="regular"' + (s.type==='regular'?' selected':'') + '>Regular</option>' +
                                    '<option value="oneway"'  + (s.type==='oneway'?' selected':'')  + '>One-way</option>' +
                                '</select></td>' +
                                '<td><input type="text" value="' + esc(s.input||'') + '" data-field="input" placeholder="Trigger term(s)"></td>' +
                                '<td><input type="text" value="' + esc(s.notes||'') + '" data-field="notes" placeholder="Optional note..."></td>' +
                                '<td style="text-align:center;"><button class="was-syn-btn was-syn-del" data-id="' + esc(s.id) + '">✕</button></td>';
                            return tr;
                        }

                        function esc(str){ var d=document.createElement('div'); d.textContent=str||''; return d.innerHTML; }

                        function collectRows() {
                            var out = [];
                            tbody.querySelectorAll('tr[data-id]').forEach(function(tr) {
                                out.push({
                                    id:    tr.dataset.id,
                                    type:  tr.querySelector('[data-field="type"]').value,
                                    terms: tr.querySelector('[data-field="terms"]').value.trim(),
                                    input: tr.querySelector('[data-field="input"]').value.trim(),
                                    notes: tr.querySelector('[data-field="notes"]').value.trim(),
                                });
                            });
                            return out;
                        }

                        function renderAll(data) {
                            syns = data;
                            tbody.innerHTML = '';
                            if (!syns.length) { tbody.appendChild(empty); return; }
                            syns.forEach(function(s, i){ tbody.appendChild(renderRow(s, i)); });
                        }

                        function saveToServer(data, cb) {
                            var fd = new FormData();
                            fd.append('action','was_synonyms_save');
                            fd.append('nonce', nonce);
                            data.forEach(function(s,i){ for(var k in s) fd.append('synonyms['+i+']['+k+']', s[k]); });
                            fetch(ajaxUrl, {method:'POST', body:fd})
                                .then(function(r){return r.json();})
                                .then(function(res){ if(cb) cb(res); })
                                .catch(function(e){ setStatus('Save error: '+e.message, false); });
                        }

                        // Initial render
                        renderAll(syns);

                        // Add row
                        document.getElementById('was-syn-add').addEventListener('click', function(){
                            var s={id:uid(), type:'regular', terms:'', input:'', notes:''};
                            syns.push(s);
                            if(empty.parentNode) empty.remove();
                            tbody.appendChild(renderRow(s, syns.length-1));
                            tbody.lastChild.querySelector('[data-field="terms"]').focus();
                        });

                        // Load NAW defaults
                        document.getElementById('was-syn-load-defaults').addEventListener('click', function(){
                            if(!confirm('This will replace all current synonyms with the NAW default list (' + defaults.length + ' pairs). Continue?')) return;
                            renderAll(defaults);
                            setStatus('NAW defaults loaded. Save Settings to keep them, then Push to Algolia.', true);
                        });

                        // Delete row
                        tbody.addEventListener('click', function(e){
                            var btn = e.target.closest('.was-syn-del');
                            if(!btn) return;
                            var id = btn.dataset.id;
                            var tr = tbody.querySelector('tr[data-id="'+id+'"]');
                            if(tr) tr.remove();
                            if(!tbody.querySelector('tr[data-id]')) tbody.appendChild(empty);
                        });

                        // Push to Algolia (server-side)
                        document.getElementById('was-syn-push') && document.getElementById('was-syn-push').addEventListener('click', function(){
                            var data = collectRows().filter(function(s){ return s.terms.length > 0; });
                            setStatus('Saving and pushing…', null);
                            saveToServer(data, function(saved){
                                var fd2 = new FormData();
                                fd2.append('action','was_synonyms_push');
                                fd2.append('nonce', nonce);
                                fetch(ajaxUrl, {method:'POST', body:fd2})
                                    .then(function(r){return r.json();})
                                    .then(function(res){
                                        if(res.success) setStatus('✓ Pushed '+res.data.pushed+' synonym(s) to Algolia (taskID: '+res.data.taskID+'). Changes are live within seconds.', true);
                                        else setStatus('✗ Push failed: '+(res.data||'Unknown error'), false);
                                    })
                                    .catch(function(e){ setStatus('✗ Network error: '+e.message, false); });
                            });
                        });

                        // Pull from Algolia (server-side)
                        document.getElementById('was-syn-pull') && document.getElementById('was-syn-pull').addEventListener('click', function(){
                            if(!confirm('This will overwrite the current table with whatever synonyms Algolia currently has. Continue?')) return;
                            setStatus('Pulling from Algolia…', null);
                            var fd = new FormData();
                            fd.append('action','was_synonyms_pull');
                            fd.append('nonce', nonce);
                            fetch(ajaxUrl, {method:'POST', body:fd})
                                .then(function(r){return r.json();})
                                .then(function(res){
                                    if(res.success){
                                        var pulled = JSON.parse(document.getElementById('was-syn-data').value || '[]');
                                        // Reload page so fresh data shows (simplest approach)
                                        setStatus('✓ Pulled '+res.data.synced+' synonym(s). Reloading…', true);
                                        setTimeout(function(){ window.location.href=window.location.href+'&pulled=1'; }, 1000);
                                    } else {
                                        setStatus('✗ Pull failed: '+(res.data||'Unknown error'), false);
                                    }
                                })
                                .catch(function(e){ setStatus('✗ Network error: '+e.message, false); });
                        });

                        // Auto-save before form submit
                        document.getElementById('was-settings-form') && document.getElementById('was-settings-form').addEventListener('submit', function(){
                            var data = collectRows().filter(function(s){ return s.terms.length > 0; });
                            saveToServer(data, null);
                        });

                        })();
                        </script>

                    <?php elseif ( $slug === 'search' ) : ?>
                        <tr>
                            <th><?php esc_html_e( 'Placeholder Text', 'woo-advanced-search' ); ?></th>
                            <td><input type="text" name="was_settings[placeholder]" value="<?php echo esc_attr( $s['placeholder'] ); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Minimum Characters', 'woo-advanced-search' ); ?></th>
                            <td>
                                <input type="number" min="1" max="5" name="was_settings[min_chars]" value="<?php echo esc_attr( $s['min_chars'] ); ?>" class="small-text">
                                <p class="description"><?php esc_html_e( 'How many characters before live search fires.', 'woo-advanced-search' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Debounce (ms)', 'woo-advanced-search' ); ?></th>
                            <td>
                                <input type="number" min="100" max="2000" step="50" name="was_settings[debounce_ms]" value="<?php echo esc_attr( $s['debounce_ms'] ); ?>" class="small-text">
                                <p class="description"><?php esc_html_e( 'Delay before firing a new search request while typing.', 'woo-advanced-search' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Search in SKU', 'woo-advanced-search' ); ?></th>
                            <td><label><input type="checkbox" name="was_settings[search_in_sku]" value="1" <?php checked( $s['search_in_sku'] ); ?>> <?php esc_html_e( 'Include SKU in category/suggestions matching (products now searched via Algolia)', 'woo-advanced-search' ); ?></label></td>
                        </tr>

                    <?php elseif ( $slug === 'button' ) : ?>
                        <tr>
                            <th><?php esc_html_e( 'Show Button', 'woo-advanced-search' ); ?></th>
                            <td><label><input type="checkbox" name="was_settings[show_button]" value="1" <?php checked( $s['show_button'] ); ?>> <?php esc_html_e( 'Display search button', 'woo-advanced-search' ); ?></label></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Button Text', 'woo-advanced-search' ); ?></th>
                            <td><input type="text" name="was_settings[button_text]" value="<?php echo esc_attr( $s['button_text'] ); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Button Background Color', 'woo-advanced-search' ); ?></th>
                            <td><input type="text" name="was_settings[button_bg]" value="<?php echo esc_attr( $s['button_bg'] ); ?>" class="was-color-picker"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Button Text Color', 'woo-advanced-search' ); ?></th>
                            <td><input type="text" name="was_settings[button_text_color]" value="<?php echo esc_attr( $s['button_text_color'] ); ?>" class="was-color-picker"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Button Border Radius (px)', 'woo-advanced-search' ); ?></th>
                            <td>
                                <input type="number" min="0" max="50" name="was_settings[button_border_radius]" value="<?php echo esc_attr( $s['button_border_radius'] ); ?>" class="small-text">
                                <p class="description"><?php esc_html_e( '0 = square, 50 = pill', 'woo-advanced-search' ); ?></p>
                            </td>
                        </tr>

                    <?php elseif ( $slug === 'products' ) : ?>
                        <tr>
                            <th><?php esc_html_e( 'Show Products Panel', 'woo-advanced-search' ); ?></th>
                            <td><label><input type="checkbox" name="was_settings[show_products]" value="1" <?php checked( $s['show_products'] ); ?>> <?php esc_html_e( 'Show live product results (fetched from Algolia)', 'woo-advanced-search' ); ?></label></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Dropdown products limit', 'woo-advanced-search' ); ?></th>
                            <td>
                                <input type="number" min="1" max="10" name="was_settings[products_limit]" value="<?php echo esc_attr( $s['products_limit'] ); ?>" class="small-text">
                                <p class="description"><?php esc_html_e( 'Max products shown in the live search dropdown (1–10). Applies to', 'woo-advanced-search' ); ?> <code>[woo_advanced_search]</code>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Results page products limit', 'woo-advanced-search' ); ?></th>
                            <td>
                                <input type="number" min="6" max="100" step="6" name="was_settings[results_page_limit]" value="<?php echo esc_attr( $s['results_page_limit'] ); ?>" class="small-text">
                                <p class="description"><?php esc_html_e( 'Products per page on the full search results page (6–100). Applies to', 'woo-advanced-search' ); ?> <code>[woo_advanced_search_results]</code>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Show Product Image', 'woo-advanced-search' ); ?></th>
                            <td><label><input type="checkbox" name="was_settings[show_product_image]" value="1" <?php checked( $s['show_product_image'] ); ?>> <?php esc_html_e( 'Display product thumbnail', 'woo-advanced-search' ); ?></label></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Show Price', 'woo-advanced-search' ); ?></th>
                            <td><label><input type="checkbox" name="was_settings[show_price]" value="1" <?php checked( $s['show_price'] ); ?>> <?php esc_html_e( 'Show product price', 'woo-advanced-search' ); ?></label></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Show SKU', 'woo-advanced-search' ); ?></th>
                            <td><label><input type="checkbox" name="was_settings[show_sku]" value="1" <?php checked( $s['show_sku'] ); ?>> <?php esc_html_e( 'Show product SKU / stock code', 'woo-advanced-search' ); ?></label></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( '"See All" Button', 'woo-advanced-search' ); ?></th>
                            <td><label><input type="checkbox" name="was_settings[see_all_button]" value="1" <?php checked( $s['see_all_button'] ); ?>> <?php esc_html_e( 'Show "See All Products" button at the bottom of the dropdown', 'woo-advanced-search' ); ?></label></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( '"See All" Button Text', 'woo-advanced-search' ); ?></th>
                            <td>
                                <input type="text" name="was_settings[see_all_text]" value="<?php echo esc_attr( $s['see_all_text'] ); ?>" class="regular-text">
                                <p class="description"><?php esc_html_e( 'Button label in the dropdown. Use {count} to insert the result count — e.g. "See All Products ({count})" → "See All Products (495)". If omitted the count is appended automatically.', 'woo-advanced-search' ); ?></p>
                            </td>
                        </tr>

                    <?php elseif ( $slug === 'categories' ) : ?>
                        <tr>
                            <th><?php esc_html_e( 'Show Categories', 'woo-advanced-search' ); ?></th>
                            <td><label><input type="checkbox" name="was_settings[show_categories]" value="1" <?php checked( $s['show_categories'] ); ?>> <?php esc_html_e( 'Show matching categories in dropdown', 'woo-advanced-search' ); ?></label></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Category Results Limit', 'woo-advanced-search' ); ?></th>
                            <td><input type="number" min="1" max="20" name="was_settings[categories_limit]" value="<?php echo esc_attr( $s['categories_limit'] ); ?>" class="small-text"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Allowed Categories', 'woo-advanced-search' ); ?></th>
                            <td>
                                <p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'Leave all unchecked to include every category.', 'woo-advanced-search' ); ?></p>
                                <div style="max-height:200px;overflow-y:auto;border:1px solid #ddd;padding:10px;background:#fafafa;border-radius:4px;">
                                    <?php if ( ! is_wp_error( $cats ) && $cats ) : ?>
                                        <?php foreach ( $cats as $cat ) : ?>
                                            <label style="display:block;margin-bottom:4px;">
                                                <input type="checkbox"
                                                       name="was_settings[allowed_categories][]"
                                                       value="<?php echo esc_attr( $cat->term_id ); ?>"
                                                    <?php checked( in_array( $cat->term_id, (array) $s['allowed_categories'] ) ); ?>>
                                                <?php echo esc_html( $cat->name ); ?>
                                                <span style="color:#999;font-size:12px;">(<?php echo esc_html( $cat->count ); ?>)</span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <em><?php esc_html_e( 'No product categories found.', 'woo-advanced-search' ); ?></em>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                    <?php elseif ( $slug === 'suggestions' ) : ?>
                        <tr>
                            <th><?php esc_html_e( 'Show Suggestions', 'woo-advanced-search' ); ?></th>
                            <td><label><input type="checkbox" name="was_settings[show_suggestions]" value="1" <?php checked( $s['show_suggestions'] ); ?>> <?php esc_html_e( 'Show search suggestions in dropdown', 'woo-advanced-search' ); ?></label></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Suggestions Limit', 'woo-advanced-search' ); ?></th>
                            <td><input type="number" min="1" max="10" name="was_settings[suggestions_limit]" value="<?php echo esc_attr( $s['suggestions_limit'] ); ?>" class="small-text"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Suggestion Source', 'woo-advanced-search' ); ?></th>
                            <td>
                                <select name="was_settings[suggestion_source]">
                                    <option value="recent"  <?php selected( $s['suggestion_source'], 'recent'  ); ?>><?php esc_html_e( 'Recent Searches (per user)',   'woo-advanced-search' ); ?></option>
                                    <option value="popular" <?php selected( $s['suggestion_source'], 'popular' ); ?>><?php esc_html_e( 'Popular Searches (site-wide)', 'woo-advanced-search' ); ?></option>
                                    <option value="manual"  <?php selected( $s['suggestion_source'], 'manual'  ); ?>><?php esc_html_e( 'Manual List (below)',          'woo-advanced-search' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Manual Suggestions', 'woo-advanced-search' ); ?></th>
                            <td>
                                <textarea name="was_settings[manual_suggestions]" rows="6" class="large-text"><?php echo esc_textarea( $s['manual_suggestions'] ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'One suggestion per line. Only used when source = Manual List.', 'woo-advanced-search' ); ?></p>
                            </td>
                        </tr>

                    <?php elseif ( $slug === 'noresults' ) : ?>
                        <tr>
                            <th><?php esc_html_e( 'No-Results Message', 'woo-advanced-search' ); ?></th>
                            <td>
                                <input type="text" name="was_settings[noresult_message]" value="<?php echo esc_attr( $s['noresult_message'] ); ?>" class="large-text">
                                <p class="description"><?php esc_html_e( 'Use {query} as a placeholder for the search term.', 'woo-advanced-search' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Show Popular Categories', 'woo-advanced-search' ); ?></th>
                            <td><label><input type="checkbox" name="was_settings[show_popular_cats_on_noresult]" value="1" <?php checked( $s['show_popular_cats_on_noresult'] ); ?>> <?php esc_html_e( 'Show popular categories grid on no-results screen', 'woo-advanced-search' ); ?></label></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( '"Search instead" Chips', 'woo-advanced-search' ); ?></th>
                            <td><label><input type="checkbox" name="was_settings[show_search_instead]" value="1" <?php checked( $s['show_search_instead'] ); ?>> <?php esc_html_e( 'Show keyword chips in no-results state', 'woo-advanced-search' ); ?></label></td>
                        </tr>

                    <?php elseif ( $slug === 'styling' ) : ?>
                        <tr>
                            <th><?php esc_html_e( 'Primary Color', 'woo-advanced-search' ); ?></th>
                            <td><input type="text" name="was_settings[primary_color]" value="<?php echo esc_attr( $s['primary_color'] ); ?>" class="was-color-picker">
                            <p class="description"><?php esc_html_e( 'Search bar border &amp; Search button background.', 'woo-advanced-search' ); ?></p></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Accent Color', 'woo-advanced-search' ); ?></th>
                            <td><input type="text" name="was_settings[accent_color]" value="<?php echo esc_attr( $s['accent_color'] ); ?>" class="was-color-picker">
                            <p class="description"><?php esc_html_e( 'Category links and other accents.', 'woo-advanced-search' ); ?></p></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Keyword Highlight Color', 'woo-advanced-search' ); ?></th>
                            <td><input type="text" name="was_settings[highlight_color]" value="<?php echo esc_attr( $s['highlight_color'] ); ?>" class="was-color-picker">
                            <p class="description"><?php esc_html_e( 'Bold + underline colour applied to the matched search terms in product names.', 'woo-advanced-search' ); ?></p></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Font Size (px)', 'woo-advanced-search' ); ?></th>
                            <td><input type="number" min="12" max="20" name="was_settings[font_size]" value="<?php echo esc_attr( $s['font_size'] ); ?>" class="small-text"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Dropdown Max Height (px)', 'woo-advanced-search' ); ?></th>
                            <td><input type="number" min="200" max="900" step="10" name="was_settings[dropdown_max_height]" value="<?php echo esc_attr( $s['dropdown_max_height'] ); ?>" class="small-text"></td>
                        </tr>
                        <tr><th colspan="2" style="padding-top:20px;border-top:1px solid #f0f0f0;"><strong><?php esc_html_e( 'Results Page Cards', 'woo-advanced-search' ); ?></strong>
                            <p style="font-weight:normal;color:#666;margin:4px 0 0;"><?php esc_html_e( 'Controls appearance of product cards on the', 'woo-advanced-search' ); ?> <code>[woo_advanced_search_results]</code> <?php esc_html_e( 'page.', 'woo-advanced-search' ); ?></p>
                        </th></tr>
                        <tr>
                            <th><?php esc_html_e( 'Card border radius (px)', 'woo-advanced-search' ); ?></th>
                            <td>
                                <input type="number" min="0" max="30" name="was_settings[card_radius]" value="<?php echo esc_attr( $s['card_radius'] ); ?>" class="small-text">
                                <p class="description"><?php esc_html_e( '0 = square corners, 10 = default rounded, 20 = very rounded.', 'woo-advanced-search' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Card border color', 'woo-advanced-search' ); ?></th>
                            <td><input type="text" name="was_settings[card_border_color]" value="<?php echo esc_attr( $s['card_border_color'] ); ?>" class="was-color-picker"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Product image height (%)', 'woo-advanced-search' ); ?></th>
                            <td>
                                <input type="number" min="50" max="150" step="5" name="was_settings[img_ratio]" value="<?php echo esc_attr( $s['img_ratio'] ); ?>" class="small-text">
                                <p class="description"><?php esc_html_e( 'Image area height as % of card width. 75 = landscape, 90 = square (default), 120 = portrait. Increase if images are being cut off.', 'woo-advanced-search' ); ?></p>
                            </td>
                        </tr>

                        <!-- Stock Code / SKU -->
                        <tr><th colspan="2" style="padding-top:16px;padding-bottom:4px;"><em style="color:#555"><?php esc_html_e( 'Stock Code (SKU)', 'woo-advanced-search' ); ?></em></th></tr>
                        <tr>
                            <th><?php esc_html_e( 'SKU text color', 'woo-advanced-search' ); ?></th>
                            <td><input type="text" name="was_settings[rp_sku_color]" value="<?php echo esc_attr( $s['rp_sku_color'] ?? '#6E727B' ); ?>" class="was-color-picker"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'SKU font size (px)', 'woo-advanced-search' ); ?></th>
                            <td><input type="number" min="8" max="16" name="was_settings[rp_sku_size]" value="<?php echo esc_attr( $s['rp_sku_size'] ?? 10 ); ?>" class="small-text"></td>
                        </tr>

                        <!-- Product Title -->
                        <tr><th colspan="2" style="padding-top:16px;padding-bottom:4px;"><em style="color:#555"><?php esc_html_e( 'Product Title', 'woo-advanced-search' ); ?></em></th></tr>
                        <tr>
                            <th><?php esc_html_e( 'Title text color', 'woo-advanced-search' ); ?></th>
                            <td><input type="text" name="was_settings[rp_title_color]" value="<?php echo esc_attr( $s['rp_title_color'] ?? '#1a1f29' ); ?>" class="was-color-picker"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Title font size (px)', 'woo-advanced-search' ); ?></th>
                            <td><input type="number" min="10" max="20" name="was_settings[rp_title_size]" value="<?php echo esc_attr( $s['rp_title_size'] ?? 13 ); ?>" class="small-text"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Title font weight', 'woo-advanced-search' ); ?></th>
                            <td>
                                <select name="was_settings[rp_title_weight]">
                                    <?php foreach ( [400 => 'Normal (400)', 500 => 'Medium (500)', 600 => 'Semi-bold (600)', 700 => 'Bold (700)'] as $w => $label ) : ?>
                                    <option value="<?php echo esc_attr( $w ); ?>" <?php selected( (int)( $s['rp_title_weight'] ?? 600 ), $w ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>

                        <!-- Price -->
                        <tr><th colspan="2" style="padding-top:16px;padding-bottom:4px;"><em style="color:#555"><?php esc_html_e( 'Price', 'woo-advanced-search' ); ?></em></th></tr>
                        <tr>
                            <th><?php esc_html_e( 'Price text color', 'woo-advanced-search' ); ?></th>
                            <td><input type="text" name="was_settings[rp_price_color]" value="<?php echo esc_attr( $s['rp_price_color'] ?? '#1a1f29' ); ?>" class="was-color-picker"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Price font size (px)', 'woo-advanced-search' ); ?></th>
                            <td><input type="number" min="10" max="22" name="was_settings[rp_price_size]" value="<?php echo esc_attr( $s['rp_price_size'] ?? 15 ); ?>" class="small-text"></td>
                        </tr>
                    <?php endif; ?>

                    </table>
                </div><!-- end .was-tab-panel -->
                <?php endforeach; ?>

                <?php submit_button( __( 'Save Settings', 'woo-advanced-search' ) ); ?>
            </form>
        </div>

        <style>
            .was-admin-wrap .was-form-table th { width: 240px; }
            .was-admin-wrap code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; }
            .was-tab-link { cursor: pointer; }
        </style>
        <script>
        jQuery(document).ready(function($) {

            // ── Tab switching ──────────────────────────────────────────────────
            var $links  = $('.was-tab-link');
            var $panels = $('.was-tab-panel');
            var $hidden = $('#was_active_tab');

            $links.on('click', function(e){
                e.preventDefault();
                var tab = $(this).data('tab');
                $links.removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $panels.hide();
                $('#was-tab-' + tab).show();
                try { $('#was-tab-' + tab).find('.was-color-picker').wpColorPicker(); } catch(e) {}
                $hidden.val(tab);
                if (history.replaceState) {
                    history.replaceState(null, '', '?page=was-settings&tab=' + tab);
                }
            });
            try { $('.was-tab-panel:visible .was-color-picker').wpColorPicker(); } catch(e) {}

            // ── Algolia live connection test ───────────────────────────────────
            function wasTestAlgolia() {
                var appId  = ($('#alg_app_id').val()  || '').trim();
                var apiKey = ($('#alg_api_key').val() || '').trim();
                var idx    = ($('#alg_index').val()   || '').trim();
                var $badge = $('#was-alg-status');

                $badge.show();

                if (!appId || !apiKey || !idx) {
                    $badge.css({background:'#fef3c7', color:'#92400e', border:'1px solid #fcd34d'})
                          .html('⚠ Fill in Application ID, Search-Only API Key, and Index Name first, then run the test.');
                    return;
                }

                $badge.css({background:'#f3f4f6', color:'#6b7280', border:'1px solid #e5e7eb'})
                      .html('⏳ Querying Algolia index <code>' + idx + '</code>…');

                fetch('https://' + appId + '.algolia.net/1/indexes/' + encodeURIComponent(idx) + '/query', {
                    method: 'POST',
                    headers: {
                        'x-algolia-application-id': appId,
                        'x-algolia-api-key':        apiKey,
                        'Content-Type':             'application/json'
                    },
                    body: JSON.stringify({ params: 'query=&hitsPerPage=1' })
                })
                .then(function(r) {
                    return r.json().then(function(d) { return { status: r.status, data: d }; });
                })
                .then(function(res) {
                    if (res.status === 403) {
                        $badge.css({background:'#fee2e2', color:'#991b1b', border:'1px solid #fca5a5'})
                              .html('✗ <strong>403 Forbidden</strong> — Search-Only API Key is incorrect or expired. Copy the Search API Key from your Algolia dashboard → API Keys.');
                        return;
                    }
                    if (res.status === 404) {
                        $badge.css({background:'#fee2e2', color:'#991b1b', border:'1px solid #fca5a5'})
                              .html('✗ <strong>Index not found</strong> — no index named <code>' + idx + '</code> exists. Try <code>wp_searchable_posts</code> or <code>wp_posts_product</code>. Check Algolia dashboard → Indices.');
                        return;
                    }
                    if (res.status !== 200) {
                        $badge.css({background:'#fee2e2', color:'#991b1b', border:'1px solid #fca5a5'})
                              .html('✗ <strong>Error ' + res.status + '</strong>: ' + (res.data.message || 'Unknown error. Check Application ID.'));
                        return;
                    }
                    var n = res.data.nbHits;
                    if (n === 0) {
                        $badge.css({background:'#fef3c7', color:'#92400e', border:'1px solid #fcd34d'})
                              .html('⚠ <strong>Connected but 0 records</strong> in <code>' + idx + '</code>. This is why no products appear. Re-index from the official Algolia plugin, or switch to <code>wp_searchable_posts</code>.');
                        // Auto-check wp_searchable_posts
                        fetch('https://' + appId + '.algolia.net/1/indexes/wp_searchable_posts/query', {
                            method: 'POST',
                            headers: { 'x-algolia-application-id': appId, 'x-algolia-api-key': apiKey, 'Content-Type': 'application/json' },
                            body: JSON.stringify({ params: 'query=&hitsPerPage=1' })
                        })
                        .then(function(r2) { return r2.json(); })
                        .then(function(d2) {
                            if (d2.nbHits > 0) {
                                $badge.html($badge.html() + '<br><br>💡 <strong>Quick fix:</strong> <code>wp_searchable_posts</code> has <strong>' + d2.nbHits.toLocaleString() + ' records</strong>. Change Index Name above to <code>wp_searchable_posts</code> and save.');
                            }
                        }).catch(function(){});
                        return;
                    }
                    // Validate field mapping against the sample record
                    var hit     = res.data.hits && res.data.hits[0];
                    var warnings = [];
                    if (hit) {
                        if (!hit.post_title)                                                  warnings.push('<code>post_title</code> missing — check Title field mapping');
                        if (!hit.permalink)                                                   warnings.push('<code>permalink</code> missing — check URL field mapping');
                        if (!(hit.images && hit.images.thumbnail && hit.images.thumbnail.url)) warnings.push('<code>images.thumbnail.url</code> missing — product images may not show');
                    }
                    var warnHtml = warnings.length
                        ? '<br><br>⚠ Field warnings:<ul style="margin:6px 0 0 16px">' + warnings.map(function(w){ return '<li>' + w + '</li>'; }).join('') + '</ul>'
                        : '';
                    $badge.css({background:'#d1fae5', color:'#065f46', border:'1px solid #6ee7b7'})
                          .html('✓ <strong>Connected — ' + n.toLocaleString() + ' records</strong> in <code>' + idx + '</code>. Your search should now show products.' + warnHtml);
                })
                .catch(function(err) {
                    $badge.css({background:'#fee2e2', color:'#991b1b', border:'1px solid #fca5a5'})
                          .html('✗ <strong>Network error</strong> — could not reach Algolia. Check Application ID format (e.g. <code>X2PDNQPACG</code>).<br><small>' + err.message + '</small>');
                });
            }

            // Auto-run when Algolia tab is the active one
            if (window.location.search.indexOf('tab=algolia') !== -1 || window.location.search.indexOf('tab=') === -1) {
                wasTestAlgolia();
            }

            $(document).on('click', '#was-alg-test-btn', wasTestAlgolia);
            $(document).on('blur',  '#alg_app_id, #alg_api_key, #alg_index', wasTestAlgolia);

        }); // end document.ready
        </script>
        <?php
    }
}
