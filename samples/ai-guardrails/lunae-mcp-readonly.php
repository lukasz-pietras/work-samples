<?php
/**
 * Plugin Name: lunæ read-only MCP user
 * Description: The `claude-mcp` WordPress user (used by Claude Code's `woocommerce-live` MCP server:
 *              ssh ovh → wp mcp-adapter serve --user=claude-mcp) may only run abilities annotated
 *              `readonly` (orders-query, products-query, site/user/environment info). Every other ability
 *              (order status/notes, product create/update/delete, …) is refused in its permission check,
 *              and is hidden from MCP discovery. Other users (wp-admin) are not affected.
 *
 * Installed by tools/deploy-staging.sh into wp-content/mu-plugins.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

const LUNAE_MCP_READONLY_LOGIN = 'claude-mcp';

function lunae_mcp_readonly_user(): bool {
    $user = wp_get_current_user();
    return $user instanceof WP_User && LUNAE_MCP_READONLY_LOGIN === $user->user_login;
}

add_filter( 'wp_register_ability_args', static function ( $args, $name ) {
    if ( ! empty( $args['meta']['annotations']['readonly'] ) ) {
        return $args;
    }
    // The adapter's gateway runs OTHER abilities; each of those still goes through its own (wrapped) check.
    if ( 'mcp-adapter/execute-ability' === $name ) {
        return $args;
    }
    if ( isset( $args['meta']['mcp'] ) && is_array( $args['meta']['mcp'] ) ) {
        $args['meta']['mcp']['public'] = false; // not offered as an MCP tool
    }
    $original = $args['permission_callback'] ?? null;
    $args['permission_callback'] = static function ( ...$input ) use ( $original, $name ) {
        if ( lunae_mcp_readonly_user() ) {
            return new WP_Error( 'lunae_mcp_readonly', sprintf( 'Ability %s is not allowed: the MCP user is read-only.', $name ), [ 'status' => 403 ] );
        }
        return is_callable( $original ) ? call_user_func_array( $original, $input ) : false;
    };
    return $args;
}, 99, 2 );
