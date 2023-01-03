<?php
/**
 * Relevanssi (Extra) Light
 *
 * /relevanssi-light.php
 *
 * @package Relevanssi (Extra) Light
 * @author  Mikko Saari
 * @license https://wordpress.org/about/gpl/ GNU General Public License
 * @see     https://www.relevanssi.com/light/
 *
 * @wordpress-plugin
 * Plugin Name: Relevanssi (Extra) Light
 * Plugin URI: https://www.relevanssi.com/light/
 * Description: Replaces the default WP search with a fulltext index search. (AME Customized)
 * Version: 1.2.2
 * Author: Mikko Saari
 * Author URI: https://www.mikkosaari.fi/
 * Text Domain: relevanssilight
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

/*
	Copyright 2022 Mikko Saari  (email: mikko@mikkosaari.fi)

	This file is part of Relevanssi Light, a search plugin for WordPress.

	Relevanssi Light is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	Relevanssi Light is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with Relevanssi Light.  If not, see <http://www.gnu.org/licenses/>.
*/

require 'relevanssi-light-admin-ajax.php';
require 'relevanssi-light-menu.php';

add_action( 'init', 'relevanssi_light_init' );
add_action( 'admin_init', 'relevanssi_light_install' );
add_action( 'wp_ajax_relevanssi_light_database_alteration', 'relevanssi_light_database_alteration_action' );
add_action( 'wp_ajax_nopriv_relevanssi_light_database_alteration', 'relevanssi_light_database_alteration_action' );
add_action( 'wp_insert_site', 'relevanssi_light_new_blog', 10, 1 );

register_activation_hook( __FILE__, 'relevanssi_light_activate' );

/**
 * Adds the required filters.
 *
 * Includes a check for the MySQL version number. If the version number is too
 * low, won't add the filters. If the version number is good, filters are added
 * and no more checks for the version number are made in the future.
 */
function relevanssi_light_init() {
	$options = get_option(
		'relevanssi_light',
		array(
			'mysql_version_good' => false,
		)
	);

	if ( ! $options['mysql_version_good'] ) {
		if ( relevanssi_light_is_mysql_good() ) {
			$options['mysql_version_good'] = true;
			update_option( 'relevanssi_light', $options );
		}
	}

	if ( $options['mysql_version_good'] ) {
		add_filter( 'posts_search', 'relevanssi_light_posts_search', 10, 2 );
		// add_filter( 'posts_search_orderby', 'relevanssi_light_posts_search_orderby', 10, 2 ); // NOTE Disabled to keep standard sorting
		add_filter( 'posts_request', 'relevanssi_light_posts_request', 10, 2 );
	}

}

/**
 * Checks whether the DB version is at least MySQL 5.6 or MariaDB 10.0.5.
 *
 * Fulltext indexing is not available for MySQL versions under 5.6. Not that you
 * should be using them for WordPress anyway...
 *
 * @return boolean True if version is at least 5.6, false otherwise.
 */
function relevanssi_light_is_mysql_good() {
	global $wpdb;
	$db_version = $wpdb->get_var( 'SELECT VERSION()' );
	if ( stripos( $db_version, 'mariadb' ) !== false ) {
		list( $version, ) = explode( '-', $db_version, 2 );
		if ( version_compare( $version, '10.0.5', '>=' ) ) {
			return true;
		}
	}
	if ( version_compare( $wpdb->db_version(), '5.6', '>=' ) ) {
		return true;
	}
	return false;
}

/**
 * Adds an option that is later checked in an admin_init action in order to run
 * this just once per activation (apparently it's impossible to launch an AJAX
 * action directly from this activation hook).
 */
function relevanssi_light_activate() {
	add_option( 'relevanssi_light_activated', 'yes' );
}

/**
 * Runs the relevanssi_light_database_alteration_action() function through an
 * AJAX action to make it run as an async background action (because it takes
 * a long time to run).
 */
function relevanssi_light_install() {
	$plugin_active_here = false;
	if ( is_plugin_active_for_network( 'relevanssi-light/relevanssi-light.php' )
		&& 'done' !== get_option( 'relevanssi_light_activated' ) ) {
		$plugin_active_here = true;
	}
	if ( is_admin() && 'yes' === get_option( 'relevanssi_light_activated' ) ) {
		$plugin_active_here = true;
	}
	if ( $plugin_active_here ) {
		update_option( 'relevanssi_light_activated', 'done' );
		relevanssi_light_launch_ajax_action(
			'relevanssi_light_database_alteration'
		);
	}
}

/**
 * Installs Relevanssi Light on a new site.
 *
 * Hooks on to 'wp_insert_site' action hooks and runs the installation function
 * 'relevanssi_light_install' on the new site.
 *
 * @param object $site The new site object.
 */
function relevanssi_light_new_blog( $site ) {
	if ( is_plugin_active_for_network( 'relevanssi-light/relevanssi-light.php' ) ) {
		switch_to_blog( $site->id );
		relevanssi_light_install();
		restore_current_blog();
	}
}

/**
 * Makes the required changes to the database.
 *
 * Adds the fulltext index `relevanssi_light_fulltext` which includes the
 * `post_title`, `post_content`, `post_excerpt` columns.
 *
 * @global object $wpdb The WP database interface.
 */
function relevanssi_light_alter_table() {
	global $wpdb;

	$index_exists = $wpdb->get_row( "SHOW index FROM $wpdb->posts where Key_name = 'relevanssi_light_fulltext'" );
	if ( ! $index_exists ) {
		$sql = "ALTER TABLE $wpdb->posts ADD FULLTEXT `relevanssi_light_fulltext` (`post_title`, `post_content`, `post_excerpt` )";
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery
	}
}

/**
 * Triggers the database alterations and checks the nonce.
 */
function relevanssi_light_database_alteration_action() {
	if ( ! wp_verify_nonce( $_REQUEST['_nonce'], 'relevanssi_light_database_alteration' ) ) {
		wp_send_json_error( 'Nonce check failed.', 403 );
	}

	relevanssi_light_alter_table();

	wp_send_json_success();
}

/**
 * Adds the MATCH AGAINST query to the posts_search filter hook.
 *
 * @param string   $search Search SQL for WHERE clause.
 * @param WP_Query $query  The current WP_Query object.
 *
 * @return string The modified SQL search query.
 */
function relevanssi_light_posts_search( $search, $query ) {
	$mode = '';
	/**
	 * Sets the mode for the fulltext search. Defaults to NATURAL LANGUAGE.
	 *
	 * @param boolean If true, enables BOOLEAN MODE.
	 */
	if ( apply_filters( 'relevanssi_light_boolean_mode', false ) ) {
		$mode = 'IN BOOLEAN MODE';
	}
	if ( isset( $query->query['s'] ) && ! empty( $query->query['s'] ) ) {
		$search = " AND MATCH(post_title,post_excerpt,post_content) AGAINST('" . $query->query['s'] . "' $mode)";
	}
	return $search;
}

/**
 * Adds the relevance orderby to the posts_search_orderby filter hook.
 *
 * @param string   $orderby The ORDER BY clause.
 * @param WP_Query $query   The current WP_Query object.
 *
 * @return string The modified ORDER BY clause.
 */
function relevanssi_light_posts_search_orderby( $orderby, $query ) {
	if ( isset( $query->query['s'] ) ) {
		$orderby = 'relevance DESC';
	}
	return $orderby;
}

/**
 * Adds the MATCH AGAINST query to the post query.
 *
 * Adds the MATCH AGAINST query to the main query as a relevance column for
 * the ORDER BY to use.
 *
 * @param string   $request The complete SQL query.
 * @param WP_Query $query   The current WP_Query object.
 *
 * @return string The modified SQL search query.
 */
function relevanssi_light_posts_request( $request, $query ) {
	$mode = '';
	/**
	 * Sets the mode for the fulltext search. Defaults to NATURAL LANGUAGE.
	 *
	 * @param boolean If true, enables BOOLEAN MODE.
	 */
	if ( apply_filters( 'relevanssi_light_boolean_mode', false ) ) {
		$mode = 'IN BOOLEAN MODE';
	}
	if ( isset( $query->query['s'] ) && ! empty( $query->query['s'] ) ) {
		$request = preg_replace(
			'/FROM/',
			", MATCH(post_title,post_excerpt,post_content) AGAINST('" . $query->query['s'] . "' $mode) AS relevance FROM",
			$request,
			1
		);
	}
	return $request;
}

/**
 * Launches an asynchronous Ajax action.
 *
 * Makes a wp_remote_post() call with the specific action. Handles nonce
 * verification.
 *
 * @see wp_remote_post()
 * @see wp_create_nonce()
 *
 * @param string $action       The action to trigger (also the name of the
 * nonce).
 * @param array  $payload_args The parameters sent to the action. Defaults to
 * an empty array.
 *
 * @return WP_Error|array The wp_remote_post() response or WP_Error on failure.
 */
function relevanssi_light_launch_ajax_action( $action, $payload_args = array() ) {
	$cookies = array();
	foreach ( $_COOKIE as $name => $value ) {
		$cookies[] = "$name=" . rawurlencode(
			is_array( $value ) ? wp_json_encode( $value ) : $value
		);
	}
	$default_payload = array(
		'action' => $action,
		'_nonce' => wp_create_nonce( $action ),
	);
	$payload         = array_merge( $default_payload, $payload_args );
	$args            = array(
		'timeout'  => 1,
		'blocking' => false,
		'body'     => $payload,
		'headers'  => array(
			'cookie' => implode( '; ', $cookies ),
		),
	);
	$url             = admin_url( 'admin-ajax.php' );

	return wp_remote_post( $url, $args );
}
