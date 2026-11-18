<?php
/**
 * Overlay the private Shoper admin API export (tools/shoper-export.py) onto a store that
 * already ran tools/import-catalog.php. Run after every catalog import:
 * LUNAE_SHOPER_DIR=/abs/export LUNAE_DATA_DIR=/abs/data [LUNAE_SHOPER_PARTS=catalog,shipping,customers,orders] \
 *   wp eval-file tools/import-shoper.php
 * Parts: catalog (stock quantities, low-stock levels, sale dates, SEO, en_US texts),
 * shipping (Shoper carriers + free-shipping threshold in the PL zone), customers (registered
 * accounts, addresses, newsletter list), orders (history with lines, parcels, notes).
 * Idempotent. Sends no e-mail and never changes stock through order status transitions.
 */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WooCommerce' ) ) {
    throw new RuntimeException( 'Run inside WordPress with WooCommerce active.' );
}

(function () {
    $root = rtrim( (string) getenv( 'LUNAE_SHOPER_DIR' ), '/\\' );
    if ( '' === $root || ! is_readable( $root . '/manifest.json' ) ) {
        throw new RuntimeException( 'Set LUNAE_SHOPER_DIR to a shoper-export.py output directory.' );
    }
    $data_dir = rtrim( getenv( 'LUNAE_DATA_DIR' ) ?: dirname( __DIR__ ) . '/data', '/\\' );
    $read = static function ( $file ) use ( $root ) {
        $path = $root . '/' . $file . '.json';
        if ( ! is_readable( $path ) ) { throw new RuntimeException( 'Missing export resource: ' . $path ); }
        return json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
    };
    $parts = array_filter( array_map( 'trim', explode( ',', getenv( 'LUNAE_SHOPER_PARTS' ) ?: 'catalog,shipping,customers,orders' ) ) );
    if ( $unknown = array_diff( $parts, [ 'catalog', 'shipping', 'customers', 'orders' ] ) ) {
        throw new RuntimeException( 'Unknown part: ' . implode( ',', $unknown ) );
    }
    // Shared with import-catalog.php so the two importers never overlap.
    if ( ! add_option( '_lunae_import_lock', time(), '', false ) ) {
        throw new RuntimeException( 'Another import holds _lunae_import_lock. If a process crashed, remove the option before retrying.' );
    }
    // Historical data must not mail customers or move the stock Shoper already reports.
    add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
    foreach ( [ 'woocommerce_payment_complete', 'woocommerce_order_status_completed', 'woocommerce_order_status_processing', 'woocommerce_order_status_on-hold' ] as $hook ) {
        remove_action( $hook, 'wc_maybe_reduce_stock_levels' );
    }
    foreach ( [ 'woocommerce_order_status_cancelled', 'woocommerce_order_status_pending' ] as $hook ) {
        remove_action( $hook, 'wc_maybe_increase_stock_levels' );
    }
    $counts = [];
    $warnings = [];
    $count = static function ( $key ) use ( &$counts ) { $counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1; };
    try {
        $check = static function ( $value ) {
            if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_message() ); }
            return $value;
        };
        $find = static function ( $type, $key, $value ) {
            $ids = get_posts( [ 'post_type' => $type, 'post_status' => 'any', 'meta_key' => $key, 'meta_value' => (string) $value, 'fields' => 'ids', 'numberposts' => 2 ] );
            if ( count( $ids ) > 1 ) { throw new RuntimeException( 'Ambiguous import identity: ' . $key . '=' . $value ); }
            return (int) ( $ids[0] ?? 0 );
        };
        $meta = static function ( $object, $key, $value ) {
            if ( '' === $value || null === $value || [] === $value ) { $object->delete_meta_data( $key ); }
            else { $object->update_meta_data( $key, $value ); }
        };
        // Non-default locales only; pl_PL is the store language and already imported.
        $i18n = static function ( $translations, $fields ) {
            $out = [];
            foreach ( $translations as $locale => $t ) {
                if ( 'pl_PL' === $locale ) { continue; }
                $row = [];
                foreach ( $fields as $field => $html ) {
                    $value = trim( (string) ( $t[ $field ] ?? '' ) );
                    if ( '' !== $value ) { $row[ $field ] = $html ? wp_kses_post( $value ) : sanitize_text_field( $value ); }
                }
                if ( $row ) { $out[ $locale ] = $row; }
            }
            return $out;
        };

        if ( in_array( 'catalog', $parts, true ) ) {
            $stocks = array_column( $read( 'product-stocks' ), null, 'stock_id' );
            $mapped = [];
            foreach ( get_posts( [ 'post_type' => 'product_variation', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_lunae_shoper_stock_id' ] ) as $vid ) {
                $sid = (string) get_post_meta( $vid, '_lunae_shoper_stock_id', true );
                $s = $stocks[ $sid ] ?? null;
                if ( ! $s ) { $warnings[] = "variation $vid: Shoper stock $sid not in export"; continue; }
                $mapped[ $sid ] = true;
                $v = wc_get_product( $vid );
                $v->set_manage_stock( true );
                $v->set_stock_quantity( (int) round( (float) $s['stock'] ) );
                $v->set_backorders( 'no' );
                $v->set_low_stock_amount( max( 0, (int) $s['warn_level'] ) );
                if ( '' !== (string) $s['ean'] && method_exists( $v, 'set_global_unique_id' ) ) { $v->set_global_unique_id( $s['ean'] ); }
                $v->save();
                $count( 'stock_updated' );
            }
            foreach ( $stocks as $sid => $s ) {
                if ( '1' === (string) $s['extended'] && '1' === (string) $s['active'] && ! isset( $mapped[ $sid ] ) ) {
                    $warnings[] = "Shoper stock $sid (product {$s['product_id']}, {$s['stock']} pcs) has no WooCommerce variation";
                }
            }

            $offers = [];
            foreach ( $read( 'specialoffers' ) as $offer ) { $offers[ $offer['product_id'] ][] = $offer; }
            foreach ( $read( 'products' ) as $row ) {
                $pid = $find( 'product', '_lunae_shoper_id', $row['product_id'] );
                if ( ! $pid ) { $warnings[] = "Shoper product {$row['product_id']} not imported"; continue; }
                $p = wc_get_product( $pid );
                $pl = $row['translations']['pl_PL'] ?? [];
                $meta( $p, '_lunae_seo_title', sanitize_text_field( $pl['seo_title'] ?? '' ) );
                $meta( $p, '_lunae_seo_description', sanitize_text_field( $pl['seo_description'] ?? '' ) );
                $meta( $p, '_lunae_i18n', $i18n( $row['translations'], [ 'name' => false, 'short_description' => true, 'description' => true, 'seo_title' => false, 'seo_description' => false ] ) );
                $p->save();
                $count( 'products_updated' );
                // The catalog snapshot only knows the sale price; Shoper knows when it ends.
                $offer = $offers[ $row['product_id'] ] ?? [];
                if ( count( $offer ) > 1 ) { $warnings[] = "product {$row['product_id']}: several special offers, sale dates left unset"; continue; }
                if ( ! $offer ) { continue; }
                $offer = $offer[0];
                foreach ( $p->get_children() as $vid ) {
                    $v = wc_get_product( $vid );
                    $regular = (float) $v->get_regular_price();
                    $expected = '1' === (string) $offer['discount_type'] ? $regular * ( 1 - (float) $offer['discount'] / 100 ) : $regular - (float) $offer['discount'];
                    if ( '' === $v->get_sale_price() || abs( (float) $v->get_sale_price() - $expected ) > 0.005 ) {
                        $warnings[] = "variation $vid: sale price {$v->get_sale_price()} does not match Shoper offer {$offer['promo_id']} ($expected)";
                        continue;
                    }
                    // Shoper dates are shop-local; WooCommerce reads zone-less strings in the site timezone.
                    $v->set_date_on_sale_from( $offer['date_from'] );
                    $v->set_date_on_sale_to( $offer['date_to'] );
                    $v->save();
                    $count( 'sale_dates_set' );
                }
                WC_Product_Variable::sync( $pid ); wc_delete_product_transients( $pid );
            }

            // Category identity: catalog.json keeps each term's Shoper URL, the API its permalink.
            $catalog = json_decode( file_get_contents( $data_dir . '/catalog.json' ), true, 512, JSON_THROW_ON_ERROR );
            $slugs = [];
            foreach ( $catalog['categories'] as $cat ) { $slugs[ rtrim( (string) $cat['source_url'], '/' ) ] = $cat['slug']; }
            foreach ( $read( 'categories' ) as $row ) {
                $pl = $row['translations']['pl_PL'] ?? [];
                $slug = $slugs[ rtrim( (string) ( $pl['permalink'] ?? '' ), '/' ) ] ?? null;
                $term = $slug ? get_term_by( 'slug', $slug, 'product_cat' ) : null;
                if ( ! $term ) { $warnings[] = "Shoper category {$row['category_id']} has no product_cat term"; continue; }
                foreach ( [ '_lunae_seo_title' => $pl['seo_title'] ?? '', '_lunae_seo_description' => $pl['seo_description'] ?? '' ] as $key => $value ) {
                    $value = sanitize_text_field( $value );
                    if ( '' === $value ) { delete_term_meta( $term->term_id, $key ); } else { update_term_meta( $term->term_id, $key, $value ); }
                }
                update_term_meta( $term->term_id, '_lunae_description_bottom', wp_kses_post( $pl['description_bottom'] ?? '' ) );
                update_term_meta( $term->term_id, '_lunae_i18n', $i18n( $row['translations'], [ 'name' => false, 'description' => true, 'description_bottom' => true, 'seo_title' => false, 'seo_description' => false ] ) );
                // Never overwrite a description edited in WordPress.
                if ( '' === trim( $term->description ) && '' !== trim( (string) ( $pl['description'] ?? '' ) ) ) {
                    $check( wp_update_term( $term->term_id, 'product_cat', [ 'description' => wp_kses_post( $pl['description'] ) ] ) );
                }
                $count( 'categories_updated' );
            }
        }

        if ( in_array( 'shipping', $parts, true ) ) {
            $zone = WC_Shipping_Zones::get_zone_matching_package( [ 'destination' => [ 'country' => 'PL', 'state' => '', 'postcode' => '' ] ] );
            if ( ! $zone->get_id() ) {
                $zone = new WC_Shipping_Zone();
                $zone->set_zone_name( 'Polska' );
                $zone->set_zone_locations( [ (object) [ 'code' => 'PL', 'type' => 'country' ] ] );
                $zone->save();
            }
            $instances = (array) get_option( 'lunae_shoper_shipping_instances', [] );
            $existing = $zone->get_shipping_methods();
            $carriers = array_values( array_filter( $read( 'shippings' ), static fn( $s ) => '1' === (string) $s['active'] && '1' === (string) $s['visible'] && isset( $s['countries']['179'] ) ) );
            // Shoper's default carrier first, then its own ordering.
            usort( $carriers, static fn( $a, $b ) => [ -(int) $a['is_default'], (int) $a['order'] ] <=> [ -(int) $b['is_default'], (int) $b['order'] ] );
            $thresholds = [];
            $wanted = [];
            foreach ( $carriers as $s ) {
                $ranges = $s['ranges'] ?? [];
                $cost = (string) $s['cost'];
                $free_from = null;
                foreach ( $ranges as $r ) {
                    if ( 0.0 === (float) $r['from'] ) { $cost = (string) $r['price']; }
                    elseif ( 0.0 === (float) $r['price'] && 0.0 === (float) $r['to'] ) { $free_from = (float) $r['from']; }
                }
                $thresholds[] = $free_from;
                if ( '2' !== (string) $s['depend_on_w'] && $ranges ) { $warnings[] = "carrier {$s['shipping_id']}: ranges are not by order value; imported flat cost $cost"; }
                // Shoper costs are gross (23% VAT); theme/lunae/inc/shipping.php takes the VAT out of them.
                $wanted[ $s['shipping_id'] ] = [ 'flat_rate', [ 'title' => $s['translations']['pl_PL']['name'] ?? $s['name'], 'tax_status' => 'taxable', 'cost' => $cost ] ];
            }
            // Shoper makes each carrier free above its own threshold; WooCommerce models one shared threshold.
            $thresholds = array_unique( $thresholds, SORT_REGULAR );
            if ( 1 === count( $thresholds ) && null !== $thresholds[0] ) {
                // Holds the threshold; theme/lunae/inc/shipping.php turns the carriers free instead of listing it.
                $wanted['free'] = [ 'free_shipping', [ 'title' => 'Darmowa dostawa', 'requires' => 'min_amount', 'min_amount' => (string) $thresholds[0], 'ignore_discounts' => 'no' ] ];
            } elseif ( array_filter( $thresholds ) ) {
                $warnings[] = 'carriers have different free-shipping thresholds; no free_shipping method configured';
            }
            global $wpdb;
            $order = 0;
            $keep = [];
            foreach ( $wanted as $key => [ $type, $settings ] ) {
                $iid = (int) ( $instances[ $key ] ?? 0 );
                if ( ! $iid || ! isset( $existing[ $iid ] ) || $existing[ $iid ]->id !== $type ) {
                    // Adopt an unmapped method of the same type (e.g. the setup placeholder) before adding one.
                    $iid = 0;
                    foreach ( $existing as $candidate_id => $candidate ) {
                        if ( $candidate->id === $type && ! in_array( $candidate_id, $instances, true ) && ! in_array( $candidate_id, $keep, true ) ) { $iid = (int) $candidate_id; break; }
                    }
                    $iid = $iid ?: (int) $zone->add_shipping_method( $type );
                    $count( 'shipping_methods_created' );
                } else {
                    $count( 'shipping_methods_updated' );
                }
                if ( ! $iid ) { throw new RuntimeException( 'Could not add shipping method ' . $type ); }
                // `wp wc shipping_zone_method update --settings` silently no-ops; write the option directly.
                update_option( "woocommerce_{$type}_{$iid}_settings", array_merge( (array) get_option( "woocommerce_{$type}_{$iid}_settings", [] ), $settings ) );
                $wpdb->update( $wpdb->prefix . 'woocommerce_shipping_zone_methods', [ 'method_order' => $order++, 'is_enabled' => 1 ], [ 'instance_id' => $iid ] );
                $instances[ $key ] = $iid;
                $keep[] = $iid;
            }
            foreach ( array_keys( $zone->get_shipping_methods() ) as $iid ) {
                if ( ! in_array( (int) $iid, $keep, true ) ) { $zone->delete_shipping_method( $iid ); $count( 'shipping_methods_removed' ); }
            }
            update_option( 'lunae_shoper_shipping_instances', $instances );
            WC_Cache_Helper::get_transient_version( 'shipping', true );
        }

        $address = static function ( $a ) {
            return [
                'first_name' => $a['firstname'] ?? '', 'last_name' => $a['lastname'] ?? '', 'company' => $a['company_name'] ?? $a['company'] ?? '',
                'address_1' => $a['street_1'] ?? $a['street1'] ?? '', 'address_2' => $a['street_2'] ?? $a['street2'] ?? '',
                'city' => $a['city'] ?? '', 'postcode' => $a['zip_code'] ?? $a['postcode'] ?? '', 'country' => ( $a['country_code'] ?? '' ) ?: 'PL', 'phone' => $a['phone'] ?? '',
            ];
        };
        $set_address = static function ( $object, $type, $fields ) {
            foreach ( $fields as $field => $value ) {
                if ( 'shipping' === $type && 'phone' === $field && ! method_exists( $object, 'set_shipping_phone' ) ) { continue; }
                $object->{"set_{$type}_{$field}"}( sanitize_text_field( (string) $value ) );
            }
        };
        $user_for = static function ( $shoper_id ) {
            $ids = get_users( [ 'meta_key' => '_lunae_shoper_user_id', 'meta_value' => (string) $shoper_id, 'fields' => 'ID', 'number' => 2 ] );
            if ( count( $ids ) > 1 ) { throw new RuntimeException( 'Ambiguous import identity: _lunae_shoper_user_id=' . $shoper_id ); }
            return (int) ( $ids[0] ?? 0 );
        };

        if ( in_array( 'customers', $parts, true ) ) {
            $addresses = [];
            foreach ( $read( 'user-addresses' ) as $a ) { $addresses[ $a['user_id'] ][] = $a; }
            $subscribers = [];
            foreach ( $read( 'subscribers' ) as $s ) {
                $email = sanitize_email( $s['email'] );
                if ( $email ) { $subscribers[ strtolower( $email ) ] = [ 'email' => $email, 'active' => '1' === (string) $s['active'], 'subscribed_at' => $s['dateadd'], 'lang_id' => $s['lang_id'] ]; }
            }
            foreach ( $read( 'users' ) as $u ) {
                // Shoper records guest checkouts as users too; only real accounts become WordPress users.
                if ( '1' !== (string) $u['registered'] ) { $count( 'guests_skipped' ); continue; }
                $email = sanitize_email( $u['email'] );
                if ( ! $email ) { $warnings[] = "Shoper user {$u['user_id']}: invalid e-mail"; continue; }
                $uid = $user_for( $u['user_id'] ) ?: (int) ( get_user_by( 'email', $email )->ID ?? 0 );
                if ( ! $uid ) {
                    $login = $base = sanitize_user( $email, true );
                    for ( $i = 2; username_exists( $login ); ++$i ) { $login = $base . '-' . $i; }
                    $uid = (int) $check( wp_insert_user( [
                        'user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password( 32 ), 'role' => 'customer',
                        'first_name' => $u['firstname'], 'last_name' => $u['lastname'], 'display_name' => trim( $u['firstname'] . ' ' . $u['lastname'] ) ?: strstr( $email, '@', true ),
                        'user_registered' => get_gmt_from_date( $u['date_add'] ),
                    ] ) );
                    $count( 'customers_created' );
                } else {
                    $count( 'customers_updated' );
                }
                update_user_meta( $uid, '_lunae_shoper_user_id', (string) $u['user_id'] );
                $newsletter = '1' === (string) $u['newsletter'] || ! empty( $subscribers[ strtolower( $email ) ]['active'] );
                update_user_meta( $uid, '_lunae_newsletter', $newsletter ? 'yes' : 'no' );
                // Accounts that were not created as customers (e.g. the admin) keep their own profile.
                if ( ! in_array( 'customer', get_userdata( $uid )->roles, true ) ) { $warnings[] = "Shoper user {$u['user_id']} linked to existing non-customer account $uid; profile untouched"; continue; }
                $c = new WC_Customer( $uid );
                $book = $addresses[ $u['user_id'] ] ?? [];
                $billing = current( array_filter( $book, static fn( $a ) => '1' === (string) $a['default'] ) ) ?: ( $book[0] ?? null );
                $shipping = current( array_filter( $book, static fn( $a ) => '1' === (string) $a['shipping_default'] ) ) ?: $billing;
                if ( $billing ) {
                    $set_address( $c, 'billing', $address( $billing ) );
                    $nip = trim( (string) ( $billing['tax_identification_number'] ?? '' ) );
                    if ( '' !== $nip ) { $c->update_meta_data( '_lunae_billing_nip', sanitize_text_field( $nip ) ); }
                }
                if ( $shipping ) { $set_address( $c, 'shipping', $address( $shipping ) ); }
                $c->set_billing_email( $email );
                $c->save();
            }
            // No newsletter plugin yet: keep the consented list for whichever service is chosen.
            update_option( 'lunae_newsletter_subscribers', array_values( $subscribers ), false );
            $counts['newsletter_subscribers'] = count( $subscribers );
        }

        if ( in_array( 'orders', $parts, true ) ) {
            $statuses = array_column( $read( 'statuses' ), null, 'status_id' );
            $payments = array_column( $read( 'payments' ), null, 'payment_id' );
            $carriers = array_column( $read( 'shippings' ), null, 'shipping_id' );
            $instances = (array) get_option( 'lunae_shoper_shipping_instances', [] );
            $lines = [];
            foreach ( $read( 'order-products' ) as $line ) { $lines[ $line['order_id'] ][] = $line; }
            $parcels = [];
            foreach ( $read( 'parcels' ) as $parcel ) { $parcels[ $parcel['order_id'] ][] = $parcel; }
            $users = array_column( $read( 'users' ), null, 'user_id' );
            $wc_status = static function ( $order ) use ( $statuses ) {
                $status = $statuses[ $order['status_id'] ] ?? null;
                $name = mb_strtolower( $status['translations']['pl_PL']['name'] ?? '' );
                switch ( (string) ( $status['type'] ?? '' ) ) {
                    case '1': return $order['is_paid'] ? 'processing' : 'pending';
                    case '2': return str_contains( $name, 'płatno' ) ? 'pending' : 'processing';
                    case '3': return 'completed';
                    case '4':
                        if ( str_contains( $name, 'reklam' ) ) { return 'completed'; }
                        return str_contains( $name, 'zwróc' ) && $order['is_paid'] ? 'refunded' : 'cancelled';
                }
                throw new RuntimeException( "Unknown Shoper status {$order['status_id']} on order {$order['order_id']}" );
            };
            foreach ( $read( 'orders' ) as $row ) {
                $status = $wc_status( $row );
                $status_name = $statuses[ $row['status_id'] ]['translations']['pl_PL']['name'] ?? $row['status_id'];
                $found = wc_get_orders( [ 'limit' => 2, 'return' => 'ids', 'status' => array_keys( wc_get_order_statuses() ), 'meta_query' => [ [ 'key' => '_lunae_shoper_order_id', 'value' => (string) $row['order_id'] ] ] ] );
                if ( count( $found ) > 1 ) { throw new RuntimeException( 'Ambiguous import identity: _lunae_shoper_order_id=' . $row['order_id'] ); }
                if ( $found ) {
                    // History is immutable once imported; only follow later Shoper status changes.
                    $order = wc_get_order( $found[0] );
                    if ( $order->get_meta( '_lunae_shoper_status' ) !== $status_name ) {
                        $order->update_meta_data( '_lunae_shoper_status', $status_name );
                        $order->set_status( $status, sprintf( 'Status w Shoper: „%s”.', $status_name ) );
                        $order->save();
                        $count( 'orders_status_updated' );
                    } else {
                        $count( 'orders_unchanged' );
                    }
                    continue;
                }
                $order = new WC_Order();
                $order->set_created_via( 'shoper-import' );
                $order->set_currency( 'PLN' );
                $order->set_prices_include_tax( true );
                $order->set_date_created( $row['date'] );
                $order->set_customer_ip_address( (string) ( $row['ip_address'] ?? '' ) );
                $email = sanitize_email( $row['email'] );
                $customer = '1' === (string) $row['registered'] ? $user_for( $row['user_id'] ) : 0;
                if ( '1' === (string) $row['registered'] && ! $customer ) { $warnings[] = "order {$row['order_id']}: registered customer not imported (run the customers part first)"; }
                $order->set_customer_id( $customer );
                $set_address( $order, 'billing', $address( $row['billing_address'] ?? [] ) );
                $set_address( $order, 'shipping', $address( $row['delivery_address'] ?: ( $row['billing_address'] ?? [] ) ) );
                $order->set_billing_email( $email );
                $nip = trim( (string) ( $row['billing_address']['tax_identification_number'] ?? '' ) );
                if ( '' !== $nip ) { $order->update_meta_data( '_lunae_billing_nip', sanitize_text_field( $nip ) ); }
                $payment = $payments[ $row['payment_id'] ] ?? null;
                $order->set_payment_method( 'shoper_' . ( $payment['name'] ?? $row['payment_id'] ) );
                $order->set_payment_method_title( $payment['translations']['pl_PL']['title'] ?? ( $payment['name'] ?? '' ) );
                $order->set_customer_note( sanitize_textarea_field( (string) ( $row['notes'] ?? '' ) ) );
                $order->update_meta_data( '_lunae_shoper_order_id', (string) $row['order_id'] );
                $order->update_meta_data( '_lunae_shoper_status', $status_name );
                $order->save();
                foreach ( $lines[ $row['order_id'] ] ?? [] as $line ) {
                    $total = round( (float) $line['price'] * (float) $line['quantity'], 2 );
                    $vid = $find( 'product_variation', '_lunae_shoper_stock_id', $line['stock_id'] );
                    $product = $vid ? wc_get_product( $vid ) : null;
                    if ( $product ) {
                        $order->add_product( $product, (int) $line['quantity'], [ 'subtotal' => $total, 'total' => $total ] );
                    } else {
                        $item = new WC_Order_Item_Product();
                        $item->set_name( trim( $line['name'] . ( $line['option'] ? ' – ' . $line['option'] : '' ) ) );
                        $item->set_quantity( (int) $line['quantity'] );
                        $item->set_subtotal( $total ); $item->set_total( $total );
                        $order->add_item( $item );
                        $warnings[] = "order {$row['order_id']}: stock {$line['stock_id']} has no variation; line kept as text";
                    }
                }
                $carrier = $carriers[ $row['shipping_id'] ] ?? null;
                $ship = new WC_Order_Item_Shipping();
                $ship->set_method_title( $carrier['translations']['pl_PL']['name'] ?? ( $carrier['name'] ?? 'Dostawa' ) );
                $ship->set_method_id( 'flat_rate' );
                $ship->set_instance_id( (string) ( $instances[ $row['shipping_id'] ] ?? '' ) );
                $ship->set_total( (float) $row['shipping_cost'] );
                $point = $row['shipping_additional_fields'] ?? [];
                if ( ! empty( $row['pickup_point'] ) ) {
                    $where = trim( implode( ', ', array_filter( [ $point['description'] ?? '', trim( ( $point['street'] ?? '' ) . ' ' . ( $point['house_number'] ?? '' ) ), trim( ( $point['post_code'] ?? '' ) . ' ' . ( $point['city'] ?? '' ) ) ] ) ) );
                    $ship->add_meta_data( 'Punkt odbioru', $row['pickup_point'] . ( $where ? ' — ' . $where : '' ) );
                    $order->update_meta_data( '_lunae_shoper_pickup_point', $row['pickup_point'] );
                }
                $order->add_item( $ship );
                $order->calculate_totals( false );
                // Basket-tier/customer discounts are not in Shoper's line prices; record them as a negative fee.
                $discounts = array_filter( [ 'progowy' => (float) $row['discount_levels'], 'klienta' => (float) $row['discount_client'], 'grupowy' => (float) $row['discount_group'], 'kod' => (float) $row['discount_code'] ] );
                $difference = round( (float) $row['sum'] - (float) $order->get_total(), 2 );
                if ( $difference < 0 && $discounts ) {
                    $fee = new WC_Order_Item_Fee();
                    $fee->set_name( 'Rabat ' . implode( ' + ', array_map( static fn( $k, $v ) => "$k $v%", array_keys( $discounts ), $discounts ) ) );
                    $fee->set_tax_status( 'none' );
                    $fee->set_total( $difference );
                    $order->add_item( $fee );
                    $order->calculate_totals( false );
                }
                if ( abs( (float) $order->get_total() - (float) $row['sum'] ) > 0.009 ) {
                    $warnings[] = "order {$row['order_id']}: computed total {$order->get_total()} ≠ Shoper sum {$row['sum']}; Shoper sum kept";
                    $order->set_total( $row['sum'] );
                }
                $tracking = array_values( array_filter( array_map( static fn( $p ) => trim( (string) $p['shipping_code'] ), $parcels[ $row['order_id'] ] ?? [] ) ) );
                $meta( $order, '_lunae_tracking_numbers', $tracking );
                if ( $row['is_paid'] ) { $order->set_date_paid( $row['date'] ); }
                if ( 'completed' === $status ) { $order->set_date_completed( $row['status_date'] ); }
                // Shoper's stock already reflects these sales. Cancelled ones were never deducted.
                $order->set_order_stock_reduced( ! in_array( $status, [ 'cancelled', 'pending', 'failed' ], true ) );
                $order->set_status( $status );
                $order->save();
                $note = sprintf( 'Zaimportowano z Shoper: zamówienie #%s, status „%s”.', $row['order_id'], $status_name );
                if ( $tracking ) { $note .= ' Numer przesyłki: ' . implode( ', ', $tracking ) . '.'; }
                $order->add_order_note( $note );
                if ( '' !== trim( (string) ( $row['notes_priv'] ?? '' ) ) ) { $order->add_order_note( 'Notatka Shoper: ' . sanitize_textarea_field( $row['notes_priv'] ) ); }
                $count( 'orders_created' );
            }
        }
        echo 'lunæ shoper import: ' . wp_json_encode( [ 'parts' => array_values( $parts ), 'counts' => $counts, 'warnings' => $warnings ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . PHP_EOL;
    } finally {
        delete_option( '_lunae_import_lock' );
    }
})();
