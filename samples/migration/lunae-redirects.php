<?php
/**
 * Plugin Name: lunæ redirects
 * Description: 301s from the old Shoper store URLs (lunae.pl/pl/…, /en_US/…) to the WooCommerce paths.
 *
 * Installed by tools/deploy-staging.sh into wp-content/mu-plugins together with lunae-redirects.csv
 * (a copy of data/redirects.csv: old_path,new_path). Shoper resolves products, categories, info pages and
 * collections by their numeric ID alone (/pl/p/<any slug>/7), so the CSV rows also give an ID map, which
 * covers renamed slugs and the en_US variants. Anything else under a Shoper locale prefix goes to the
 * closest WooCommerce page (cart, account, search, shop) or home. WordPress paths are never touched.
 *
 * Shoper-hosted files (/userdata/…, /environment/cache/…) die with Shoper. Product photos are matched by gfx ID
 * and storefront banners by UUID, in every cached size and format; a `media:<data file>` target is the imported
 * attachment with that `_lunae_source_file` (the hi-res crop of the same photo). Other files answer 410 Gone.
 *
 * Self-test: wp lunae-redirects test   (every CSV row and the catch-all samples → target, MISSING if it 404s)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'LUNAE_REDIRECTS_CSV' ) ) {
    define( 'LUNAE_REDIRECTS_CSV', __DIR__ . '/lunae-redirects.csv' );
}

// Shoper URL prefixes: /pl/…, /en_US/…, and the permalink forms /pl_PL/…; /en/ never existed but is cheap to cover.
const LUNAE_REDIRECTS_LOCALE = '(?:pl|pl_pl|en|en_us|en_gb)';

// Resolver result for a Shoper file with no successor: answered with 410 Gone instead of a redirect.
const LUNAE_REDIRECTS_GONE = '410';

/** Lower-case, decoded, query-less path without trailing slash ("/" for the root). */
function lunae_redirects_normalize( string $path ): string {
    $path = strtolower( rawurldecode( (string) strtok( $path, '?#' ) ) );
    $path = '/' . trim( (string) preg_replace( '#/+#', '/', $path ), '/' );
    return $path;
}

/**
 * ID key of a Shoper media path: "gfx:248" for product photos (/userdata/public/gfx/248/<name>,
 * /environment/cache/images/productGfx_248_500_500/<name>), "sf:<uuid>" for storefront images
 * (/userdata/public/storefront/images/<uuid>.jpeg, /environment/cache/images/storefrontImages_<uuid>_740_0.png).
 */
function lunae_redirects_media_key( string $path ): ?string {
    if ( preg_match( '#^/(?:userdata/public/gfx/|environment/cache/images/productgfx_)(\d+)(?:[/_.]|$)#', $path, $m ) ) { return 'gfx:' . $m[1]; }
    if ( preg_match( '#^/(?:userdata/public/storefront/images/|environment/cache/images/storefrontimages_)([0-9a-f-]{36})[_.]#', $path, $m ) ) { return 'sf:' . $m[1]; }
    return null;
}

/** "media:<data file>" → URL of the imported attachment (newest with that _lunae_source_file), Gone if absent. */
function lunae_redirects_media( string $target ): string {
    if ( ! str_starts_with( $target, 'media:' ) ) { return $target; }
    $ids = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'DESC', 'meta_key' => '_lunae_source_file', 'meta_value' => substr( $target, 6 ) ] );
    $url = $ids ? wp_get_attachment_url( (int) $ids[0] ) : false;
    return $url ?: LUNAE_REDIRECTS_GONE;
}

/**
 * Parsed CSV: [ 'exact' => [ normalized old path => new path ], 'ids' => [ "p:7" => new path, … ] ].
 */
function lunae_redirects_map(): array {
    static $map = null;
    if ( null !== $map ) { return $map; }
    $map = [ 'exact' => [], 'ids' => [] ];
    $fh  = is_readable( LUNAE_REDIRECTS_CSV ) ? fopen( LUNAE_REDIRECTS_CSV, 'r' ) : false;
    if ( ! $fh ) { return $map; }
    while ( false !== ( $row = fgetcsv( $fh, 0, ',', '"', '' ) ) ) {
        if ( count( $row ) < 2 || '' === trim( $row[0] ) || 'old_path' === $row[0] || '#' === $row[0][0] ) { continue; }
        $old = lunae_redirects_normalize( trim( $row[0] ) );
        $new = trim( $row[1] );
        $map['exact'][ $old ] = $new;
        $media = lunae_redirects_media_key( $old );
        if ( null !== $media ) { $map['ids'][ $media ] = $new; }
        if ( preg_match( '#^/' . LUNAE_REDIRECTS_LOCALE . '/(p|c|i|collection|producer)/[^/]+/(\d+)$#', $old, $m ) ) {
            // The Polish row wins: en_US rows only fill IDs the Polish rows lack.
            $key = $m[1] . ':' . $m[2];
            if ( ! isset( $map['ids'][ $key ] ) || str_starts_with( $old, '/pl/' ) ) { $map['ids'][ $key ] = $new; }
        }
    }
    fclose( $fh );
    return $map;
}

/**
 * Target path (relative to home, or absolute URL) for an old request URI, or null to leave the request alone.
 */
function lunae_redirects_resolve( string $uri ): ?string {
    $path = lunae_redirects_normalize( $uri );
    // Never touch WordPress itself, even if a CSV row says otherwise.
    if ( preg_match( '#^/(wp-admin|wp-json|wp-content|wp-includes|wp-login\.php|wp-cron\.php|xmlrpc\.php|wc-api|wc-auth|wp-sitemap)#', $path ) ) {
        return null;
    }
    $map = lunae_redirects_map();
    if ( isset( $map['exact'][ $path ] ) ) { return lunae_redirects_media( $map['exact'][ $path ] ); }

    // Shoper-hosted files: photos and banners by ID in any size/format; skins, scripts, icons and unknown IDs are gone.
    if ( preg_match( '#^/(userdata|environment)(/|$)#', $path ) ) {
        $key = lunae_redirects_media_key( $path );
        return null !== $key && isset( $map['ids'][ $key ] ) ? lunae_redirects_media( $map['ids'][ $key ] ) : LUNAE_REDIRECTS_GONE;
    }

    // Shoper's Google sitemap index and its per-list pages.
    if ( preg_match( '#^/console/integration/execute/name/googlesitemap(/|$)#', $path ) ) { return '/wp-sitemap.xml'; }

    $page = static function ( string $wc_page ): string {
        $id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( $wc_page ) : 0;
        return $id > 0 ? (string) get_permalink( $id ) : '/';
    };

    // Customer panel without a locale prefix (linked from Shoper's header).
    if ( preg_match( '#^/panel(/|$)#', $path ) ) { return $page( 'myaccount' ); }

    if ( ! preg_match( '#^/' . LUNAE_REDIRECTS_LOCALE . '(/.*)?$#', $path, $m ) ) { return null; }
    $rest = $m[1] ?? '';
    if ( '' === $rest ) { return '/'; }

    // Products, categories, info pages, collections, producer: by Shoper ID, whatever the slug or locale.
    if ( preg_match( '#^/(p|c|i|collection|producer)/(?:[^/]+/)?(\d+)(/|$)#', $rest, $id ) ) {
        $key = $id[1] . ':' . $id[2];
        if ( isset( $map['ids'][ $key ] ) ) { return $map['ids'][ $key ]; }
        return 'i' === $id[1] ? '/' : $page( 'shop' );
    }
    // The same path under the Polish prefix (e.g. /en_US/contact → /pl/contact).
    if ( isset( $map['exact'][ '/pl' . $rest ] ) ) { return $map['exact'][ '/pl' . $rest ]; }

    if ( preg_match( '#^/(p|c|collection|producer|producers|categories|promotions|new|bestsellers)(/|$)#', $rest ) ) { return $page( 'shop' ); }
    if ( preg_match( '#^/basket(/|$)#', $rest ) ) { return $page( 'cart' ); }
    if ( preg_match( '#^/(panel|login|register|signup|password|logout)(/|$)#', $rest ) ) { return $page( 'myaccount' ); }
    if ( preg_match( '#^/searchquery/([^/]+)#', $rest, $q ) ) {
        // The original (not lower-cased) term reads better in the search box.
        $term = preg_match( '#/searchquery/([^/?]+)#i', rawurldecode( $uri ), $raw ) ? $raw[1] : $q[1];
        return '/?s=' . rawurlencode( str_replace( '+', ' ', $term ) ) . '&post_type=product';
    }
    return '/';
}

/** Campaign parameters survive the redirect; Shoper's own query strings do not. */
function lunae_redirects_target_url( string $target, string $uri ): string {
    $url = preg_match( '#^https?://#', $target ) ? $target : home_url( $target );
    parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );
    $keep = array_filter( $query, static fn( $k ) => preg_match( '/^(utm_[a-z]+|gclid|fbclid|msclkid)$/', (string) $k ), ARRAY_FILTER_USE_KEY );
    return $keep ? add_query_arg( array_map( 'rawurlencode', $keep ), $url ) : $url;
}

// Before WordPress queries anything; parse_request only runs for front-end requests (not wp-admin, cron or CLI).
add_action( 'parse_request', static function (): void {
    if ( ! in_array( $_SERVER['REQUEST_METHOD'] ?? 'GET', [ 'GET', 'HEAD' ], true ) ) { return; }
    $uri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
    // Paths are matched relative to home (WordPress in a subdirectory).
    $base = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
    if ( '' !== $base && str_starts_with( $uri, $base . '/' ) ) { $uri = substr( $uri, strlen( $base ) ); }
    $target = lunae_redirects_resolve( $uri );
    if ( null === $target ) { return; }
    if ( LUNAE_REDIRECTS_GONE === $target ) {
        // No HTML page: these are image/script requests, and 410 drops the URL from search indexes fastest.
        status_header( 410 );
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'X-Redirect-By: lunae-redirects' );
        echo "410 Gone\n";
        exit;
    }
    $url = lunae_redirects_target_url( $target, $uri );
    if ( lunae_redirects_normalize( (string) wp_parse_url( $url, PHP_URL_PATH ) ) === lunae_redirects_normalize( $uri ) ) { return; }
    wp_redirect( $url, 301, 'lunae-redirects' );
    exit;
}, 0 );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    /**
     * Lists every CSV row and the catch-all samples with their target; MISSING marks targets that would 404.
     * Exits non-zero when a CSV row resolves to a missing page.
     */
    WP_CLI::add_command( 'lunae-redirects test', static function (): void {
        $exists = static function ( string $target ): bool {
            $path = (string) wp_parse_url( $target, PHP_URL_PATH );
            if ( '/' === $path || '' === $path || str_contains( $target, '?s=' ) ) { return true; }
            if ( str_ends_with( $path, '.xml' ) ) { return true; }
            // Files: imported attachments (media: rows) and theme assets.
            $content = (string) wp_parse_url( content_url(), PHP_URL_PATH );
            if ( str_starts_with( $path, $content . '/' ) ) { return is_file( WP_CONTENT_DIR . substr( rawurldecode( $path ), strlen( $content ) ) ); }
            if ( preg_match( '#/kategoria-produktu/(?:.*/)?([^/]+)/?$#', $path, $m ) ) { return (bool) get_term_by( 'slug', $m[1], 'product_cat' ); }
            // The shop page is an archive, which url_to_postid() does not resolve.
            return url_to_postid( home_url( $path ) ) > 0 || (bool) get_page_by_path( trim( $path, '/' ) );
        };
        $rows = [];
        $fh   = fopen( LUNAE_REDIRECTS_CSV, 'r' );
        if ( ! $fh ) { WP_CLI::error( 'Cannot read ' . LUNAE_REDIRECTS_CSV ); }
        while ( false !== ( $row = fgetcsv( $fh, 0, ',', '"', '' ) ) ) {
            if ( count( $row ) >= 2 && 'old_path' !== $row[0] && '' !== trim( $row[0] ) ) { $rows[] = [ trim( $row[0] ), true ]; }
        }
        fclose( $fh );
        foreach ( [
            '/pl', '/pl/', '/en_US/', '/PL/P/BIUSTONOSZ-IRIS-INCARNATA/7/', '/pl/p/stara-nazwa/7?utm_source=ig',
            '/pl/p/Nieistniejacy/99', '/pl/c/Inna/99', '/pl/i/Inna/99', '/pl/producer/Lunae/1', '/pl_PL/producer/Lunae/1',
            '/en_US/p/top-Iris-incarnata/7', '/en_US/c/Robes/7', '/en_US/i/Regulamin-sklepu/1', '/en_US/contact',
            '/pl/basket', '/pl/login', '/pl/panel', '/panel/', '/pl/searchquery/figi', '/pl/news/1',
            '/console/integration/execute/name/GoogleSitemap', '/console/integration/execute/name/GoogleSitemap/list/products/locale/pl_PL/page/1',
            '/', '/sklep/', '/wp-admin/', '/wp-json/wc/store/v1/cart', '/wc-api/x', '/wp-content/uploads/a.jpg', '/plany/',
            '/userdata/public/gfx/248/Stara-nazwa.jpg', '/environment/cache/images/productGfx_248_500_500/x.webp', '/environment/cache/images/productGfx_280_150_150/x.jpg',
            '/environment/cache/images/storefrontImages_65a51917-a89f-401c-a817-3071bf7cf964_1920_0.jpeg', '/environment/cache/images/storefrontImages_bb8178b4-8aa1-472c-9386-2c048a29f850_740_0.png',
            '/userdata/public/assets//formularz-zwrotu-lunae.pdf', '/userdata/public/gfx/999/x.jpg', '/userdata/public/storefront/images/02b6d418-6b57-4898-b3c4-c25624798754.svg',
            '/userdata/public/skins/a5485060de80278cebd492f67ccbb22e/cache/x.css', '/environment/cache/images/storefrontImages_289e6601-9335-4f68-8901-eebc0e55ce7a_0_0.png',
        ] as $sample ) { $rows[] = [ $sample, false ]; }

        $missing = 0;
        foreach ( $rows as [ $old, $from_csv ] ) {
            $target = lunae_redirects_resolve( $old );
            $gone   = LUNAE_REDIRECTS_GONE === $target;
            // A CSV row is never meant to be gone: that means its media: attachment is missing.
            $ok = null === $target || ( $gone ? ! $from_csv : $exists( $target ) );
            if ( ! $ok && $from_csv ) { $missing++; }
            WP_CLI::line( sprintf( '%-4s %-62s → %s%s', $from_csv ? 'csv' : '', $old,
                null === $target ? '(not redirected)' : ( $gone ? '410 Gone' : lunae_redirects_target_url( $target, $old ) ), $ok ? '' : '   MISSING' ) );
        }
        $missing ? WP_CLI::error( "$missing CSV target(s) missing." ) : WP_CLI::success( count( $rows ) . ' paths checked.' );
    } );
}
