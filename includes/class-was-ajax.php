<?php
defined( 'ABSPATH' ) || exit;

class WAS_Ajax {

    public static function init(): void {
        // was_search now returns ONLY categories + suggestions (products come from Algolia in the browser)
        add_action( 'wp_ajax_was_search',        [ __CLASS__, 'handle' ] );
        add_action( 'wp_ajax_nopriv_was_search', [ __CLASS__, 'handle' ] );

        // Live price + SKU enrichment from WooCommerce after Algolia renders product cards
        add_action( 'wp_ajax_was_enrich',        [ __CLASS__, 'enrich' ] );
        add_action( 'wp_ajax_nopriv_was_enrich', [ __CLASS__, 'enrich' ] );

        add_action( 'wp_ajax_was_track_search',        [ __CLASS__, 'track_search' ] );
        add_action( 'wp_ajax_nopriv_was_track_search', [ __CLASS__, 'track_search' ] );

        // Synonyms Manager — admin-only endpoints
        add_action( 'wp_ajax_was_synonyms_save', [ __CLASS__, 'synonyms_save' ] );
        add_action( 'wp_ajax_was_synonyms_push', [ __CLASS__, 'synonyms_push' ] );
        add_action( 'wp_ajax_was_synonyms_pull', [ __CLASS__, 'synonyms_pull' ] );
    }

    /* -----------------------------------------------------------------------
       Meta endpoint — categories + suggestions only.
       Products are now searched directly via Algolia JS in the browser.
    ----------------------------------------------------------------------- */
    public static function handle(): void {
        check_ajax_referer( 'was_nonce', 'nonce' );

        $query = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
        $s     = WAS_Settings::get();

        if ( strlen( $query ) < (int) $s['min_chars'] ) {
            wp_send_json_success( [ 'empty' => true ] );
        }

        self::record_query( $query );

        $data = [
            'query'       => $query,
            'categories'  => [],
            'suggestions' => [],
            'see_all_url' => self::build_results_url( $query ),
            'see_all_text' => str_replace( '{count}', '', $s['see_all_text'] ),
        ];

        /* --- Categories ----------------------------------------------- */
        if ( $s['show_categories'] ) {
            $cat_args = [
                'taxonomy'   => 'product_cat',
                'hide_empty' => true,
                'name__like' => $query,
                'number'     => (int) $s['categories_limit'],
            ];
            if ( ! empty( $s['allowed_categories'] ) ) {
                $cat_args['include'] = $s['allowed_categories'];
            }
            $cats = get_terms( $cat_args );
            if ( ! is_wp_error( $cats ) ) {
                foreach ( $cats as $cat ) {
                    $thumb_id  = get_term_meta( $cat->term_id, 'thumbnail_id', true );
                    $cat_img   = $thumb_id ? wp_get_attachment_image_src( $thumb_id, 'thumbnail' ) : false;
                    $data['categories'][] = [
                        'id'    => $cat->term_id,
                        'name'  => html_entity_decode( $cat->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
                        'count' => $cat->count,
                        'url'   => get_term_link( $cat ),
                        'img'   => $cat_img ? $cat_img[0] : '',
                    ];
                }
            }
        }

        /* --- Suggestions ---------------------------------------------- */
        if ( $s['show_suggestions'] ) {
            $data['suggestions'] = self::get_suggestions( $query, $s );
        }

        wp_send_json_success( $data );
    }

    /* -----------------------------------------------------------------------
       Live price + SKU enrichment
       Called after Algolia renders product cards. Receives a list of WP post
       IDs and returns current price HTML + SKU from WooCommerce.
    ----------------------------------------------------------------------- */
    public static function enrich(): void {
        check_ajax_referer( 'was_nonce', 'nonce' );

        $ids = isset( $_POST['ids'] )
            ? array_filter( array_map( 'absint', (array) $_POST['ids'] ) )
            : [];

        if ( empty( $ids ) ) {
            wp_send_json_success( [] );
        }

        $s   = WAS_Settings::get();
        $out = [];

        foreach ( $ids as $id ) {
            $product = wc_get_product( $id );
            if ( ! $product ) continue;

            // Resolve featured image — returns a URL when Algolia has no image for this product
            // (e.g. image was uploaded after the last Algolia re-index, or never indexed).
            $img_url = '';
            if ( $s['show_product_image'] ) {
                $img_id  = $product->get_image_id();
                if ( $img_id ) {
                    // Try medium size first (better quality), fall back to thumbnail
                    $img_url = wp_get_attachment_image_url( $img_id, 'medium' )
                        ?: wp_get_attachment_image_url( $img_id, 'thumbnail' )
                        ?: '';
                }
            }

            $out[ $id ] = [
                'price' => $s['show_price']
                    ? html_entity_decode( wp_strip_all_tags( $product->get_price_html() ), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
                    : '',
                'sku'   => $s['show_sku'] ? $product->get_sku() : '',
                'image' => $img_url,
            ];
        }

        wp_send_json_success( $out );
    }

    /* -----------------------------------------------------------------------
       Suggestions
    ----------------------------------------------------------------------- */
    private static function get_suggestions( string $query, array $s ): array {
        $limit = (int) $s['suggestions_limit'];

        if ( $s['suggestion_source'] === 'manual' ) {
            $lines = array_filter( array_map( 'trim', explode( "\n", $s['manual_suggestions'] ) ) );
            $out   = [];
            foreach ( $lines as $line ) {
                if ( stripos( $line, $query ) !== false ) {
                    $out[] = $line;
                }
                if ( count( $out ) >= $limit ) break;
            }
            return $out;
        }

        if ( $s['suggestion_source'] === 'popular' ) {
            $popular = (array) get_option( 'was_popular_searches', [] );
            arsort( $popular );
            $out = [];
            foreach ( array_keys( $popular ) as $term ) {
                if ( stripos( $term, $query ) !== false ) {
                    $out[] = $term;
                }
                if ( count( $out ) >= $limit ) break;
            }
            return $out;
        }

        // 'recent' is handled client-side via localStorage
        return [];
    }

    /* -----------------------------------------------------------------------
       Track searches for "popular" source
    ----------------------------------------------------------------------- */
    public static function track_search(): void {
        check_ajax_referer( 'was_nonce', 'nonce' );
        $query = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
        if ( $query ) {
            self::record_query( $query );
        }
        wp_send_json_success();
    }

    /**
     * Build the navigation URL for "See All Products" / Enter key.
     * Uses the admin-configured search_results_url pattern.
     */
    public static function build_results_url( string $query ): string {
        $pattern = WAS_Settings::get( 'search_results_url' ) ?: '/algolia-search/?s=%s';
        $url     = str_replace( '%s', rawurlencode( $query ), $pattern );
        if ( strpos( $url, 'http' ) !== 0 ) {
            $url = rtrim( home_url(), '/' ) . '/' . ltrim( $url, '/' );
        }
        return $url;
    }

    /* -----------------------------------------------------------------------
       Synonyms Manager — three server-side endpoints.
       The Algolia Admin API key is ONLY used here (server → Algolia),
       never sent to the visitor's browser.
    ----------------------------------------------------------------------- */

    /** Save synonym list to WP options. */
    public static function synonyms_save(): void {
        check_ajax_referer( 'was_synonyms_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $raw = isset( $_POST['synonyms'] ) ? (array) $_POST['synonyms'] : [];
        $clean = [];
        foreach ( $raw as $item ) {
            $terms = sanitize_text_field( $item['terms'] ?? '' );
            if ( ! $terms ) continue;
            $clean[] = [
                'id'    => sanitize_key( $item['id'] ?? '' ) ?: 'naw-syn-' . md5( $terms ),
                'type'  => in_array( $item['type'] ?? '', [ 'regular', 'oneway' ], true ) ? $item['type'] : 'regular',
                'terms' => $terms,
                'input' => sanitize_text_field( $item['input'] ?? '' ),
                'notes' => sanitize_text_field( $item['notes'] ?? '' ),
            ];
        }
        update_option( 'was_synonyms', $clean, false );
        wp_send_json_success( [ 'count' => count( $clean ) ] );
    }

    /** Push stored synonyms to Algolia via its REST Synonyms API. */
    public static function synonyms_push(): void {
        check_ajax_referer( 'was_synonyms_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $s         = WAS_Settings::get();
        $app_id    = $s['algolia_app_id']    ?? '';
        $admin_key = $s['algolia_admin_key'] ?? '';
        $index     = $s['algolia_index_name'] ?? '';

        if ( ! $app_id || ! $admin_key || ! $index ) {
            wp_send_json_error( 'Application ID, Admin API Key, and Index Name are required. Fill in the Algolia tab and save.' );
        }

        $stored  = (array) get_option( 'was_synonyms', [] );
        $payload = [];

        foreach ( $stored as $item ) {
            $id    = $item['id'] ?: 'naw-syn-' . md5( $item['terms'] );
            $terms = array_values( array_filter( array_map( 'trim', explode( ',', $item['terms'] ) ) ) );

            if ( $item['type'] === 'oneway' ) {
                // One-way: needs ≥1 target term (e.g. "kA") AND ≥1 input trigger (e.g. "Breaking Capacity").
                // The old `count($terms) < 2` check was incorrectly skipping these single-target entries.
                $inputs = array_values( array_filter( array_map( 'trim', explode( ',', $item['input'] ?? '' ) ) ) );
                if ( empty( $terms ) || empty( $inputs ) ) continue;
                foreach ( $inputs as $inp ) {
                    $payload[] = [
                        'objectID' => $id . '-' . md5( $inp ),
                        'type'     => 'oneWaySynonym',
                        'input'    => $inp,
                        'synonyms' => $terms,
                    ];
                }
            } else {
                // Regular / multi-way: needs ≥2 terms.
                if ( count( $terms ) < 2 ) continue;
                $payload[] = [
                    'objectID' => $id,
                    'type'     => 'synonym',
                    'synonyms' => $terms,
                ];
            }
        }

        if ( empty( $payload ) ) {
            wp_send_json_error( 'No valid synonyms to push. Make sure each entry has at least 2 comma-separated terms.' );
        }

        $url      = "https://{$app_id}.algolia.net/1/indexes/{$index}/synonyms/batch?replaceExistingSynonyms=false";
        $response = wp_remote_post( $url, [
            'headers' => [
                'X-Algolia-Application-Id' => $app_id,
                'X-Algolia-API-Key'        => $admin_key,
                'Content-Type'             => 'application/json',
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'Request failed: ' . $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 || $code === 201 ) {
            wp_send_json_success( [ 'pushed' => count( $payload ), 'taskID' => $body['taskID'] ?? null ] );
        } else {
            wp_send_json_error( 'Algolia API error ' . $code . ': ' . ( $body['message'] ?? 'Unknown error. Ensure the Admin API Key is correct (not the Search-Only key).' ) );
        }
    }

    /** Pull all synonyms from Algolia back into WordPress. */
    public static function synonyms_pull(): void {
        check_ajax_referer( 'was_synonyms_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $s         = WAS_Settings::get();
        $app_id    = $s['algolia_app_id']    ?? '';
        $admin_key = $s['algolia_admin_key'] ?? '';
        $index     = $s['algolia_index_name'] ?? '';

        if ( ! $app_id || ! $admin_key || ! $index ) {
            wp_send_json_error( 'Application ID, Admin API Key, and Index Name are required.' );
        }

        $all   = [];
        $page  = 0;
        $pages = 1;

        do {
            $url      = "https://{$app_id}.algolia.net/1/indexes/{$index}/synonyms/search";
            $response = wp_remote_post( $url, [
                'headers' => [
                    'X-Algolia-Application-Id' => $app_id,
                    'X-Algolia-API-Key'        => $admin_key,
                    'Content-Type'             => 'application/json',
                ],
                'body'    => wp_json_encode( [ 'query' => '', 'page' => $page, 'hitsPerPage' => 100 ] ),
                'timeout' => 30,
            ] );
            if ( is_wp_error( $response ) ) break;
            $body  = json_decode( wp_remote_retrieve_body( $response ), true );
            $hits  = $body['hits']    ?? [];
            $pages = $body['nbPages'] ?? 1;
            $all   = array_merge( $all, $hits );
            $page++;
        } while ( $page < $pages );

        $stored = [];
        foreach ( $all as $hit ) {
            if ( $hit['type'] === 'synonym' ) {
                $stored[] = [
                    'id'    => $hit['objectID'],
                    'type'  => 'regular',
                    'terms' => implode( ', ', $hit['synonyms'] ?? [] ),
                    'input' => '',
                    'notes' => '',
                ];
            } elseif ( $hit['type'] === 'oneWaySynonym' ) {
                $stored[] = [
                    'id'    => $hit['objectID'],
                    'type'  => 'oneway',
                    'terms' => implode( ', ', $hit['synonyms'] ?? [] ),
                    'input' => $hit['input'] ?? '',
                    'notes' => '',
                ];
            }
        }
        update_option( 'was_synonyms', $stored, false );
        wp_send_json_success( [ 'synced' => count( $stored ) ] );
    }

    private static function record_query( string $query ): void {
        $popular         = (array) get_option( 'was_popular_searches', [] );
        $key             = strtolower( $query );
        $popular[ $key ] = ( $popular[ $key ] ?? 0 ) + 1;
        if ( count( $popular ) > 200 ) {
            arsort( $popular );
            $popular = array_slice( $popular, 0, 200, true );
        }
        update_option( 'was_popular_searches', $popular, false );
    }

    /* -----------------------------------------------------------------------
       Popular categories (for no-results panel)
    ----------------------------------------------------------------------- */
    public static function popular_categories( int $limit = 8 ): array {
        $s    = WAS_Settings::get();
        $args = [
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'orderby'    => 'count',
            'order'      => 'DESC',
            'number'     => $limit,
        ];
        if ( ! empty( $s['allowed_categories'] ) ) {
            $args['include'] = $s['allowed_categories'];
        }
        $cats = get_terms( $args );
        $out  = [];
        if ( ! is_wp_error( $cats ) ) {
            foreach ( $cats as $cat ) {
                $thumb_id = get_term_meta( $cat->term_id, 'thumbnail_id', true );
                $img_src  = $thumb_id ? wp_get_attachment_image_src( $thumb_id, 'thumbnail' ) : false;
                $out[]    = [
                    'id'   => $cat->term_id,
                    'name' => html_entity_decode( $cat->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
                    'url'  => get_term_link( $cat ),
                    'img'  => $img_src ? $img_src[0] : wc_placeholder_img_src( 'thumbnail' ),
                ];
            }
        }
        return $out;
    }
}
