<?php
/**
 * Velox Map Locator uninstall routine.
 *
 * @package VeloxMapLocator
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Return all capabilities introduced by Velox Map Locator.
 *
 * @return string[]
 */
function velox_map_locator_uninstall_capabilities() {
	return array(
		'edit_velomalo_location',
		'read_velomalo_location',
		'delete_velomalo_location',
		'edit_velomalo_locations',
		'edit_others_velomalo_locations',
		'publish_velomalo_locations',
		'read_private_velomalo_locations',
		'delete_velomalo_locations',
		'delete_private_velomalo_locations',
		'delete_published_velomalo_locations',
		'delete_others_velomalo_locations',
		'edit_private_velomalo_locations',
		'edit_published_velomalo_locations',
		'create_velomalo_locations',
		'edit_velomalo_locator',
		'read_velomalo_locator',
		'delete_velomalo_locator',
		'edit_velomalo_locators',
		'edit_others_velomalo_locators',
		'publish_velomalo_locators',
		'read_private_velomalo_locators',
		'delete_velomalo_locators',
		'delete_private_velomalo_locators',
		'delete_published_velomalo_locators',
		'delete_others_velomalo_locators',
		'edit_private_velomalo_locators',
		'edit_published_velomalo_locators',
		'create_velomalo_locators',
		'manage_velomalo_terms',
		'manage_velomalo_settings',
		'manage_velomalo_providers',
		'import_velomalo_locations',
		'export_velomalo_locations',
	);
}

/**
 * Remove plugin capabilities on the current site.
 *
 * @return void
 */
function velox_map_locator_uninstall_remove_capabilities() {
	$roles = wp_roles();

	if ( ! $roles ) {
		return;
	}

	foreach ( $roles->role_objects as $role ) {
		foreach ( velox_map_locator_uninstall_capabilities() as $capability ) {
			$role->remove_cap( $capability );
		}
	}
}

/**
 * Delete plugin-owned content and settings on the current site.
 *
 * Media Library attachments are intentionally never deleted.
 *
 * @return void
 */
function velox_map_locator_uninstall_delete_site_data() {
	register_post_type( 'velomalo_location', array( 'public' => false ) );
	register_post_type( 'velomalo_locator', array( 'public' => false ) );
	register_taxonomy( 'velomalo_type', array( 'velomalo_location' ), array( 'public' => false ) );
	register_taxonomy( 'velomalo_group', array( 'velomalo_location' ), array( 'public' => false ) );

	$post_statuses = array_keys( get_post_stati() );

	do {
		$post_ids = get_posts(
			array(
				'post_type'      => array( 'velomalo_location', 'velomalo_locator' ),
				'post_status'    => $post_statuses,
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$deleted_count = 0;

		foreach ( $post_ids as $post_id ) {
			if ( wp_delete_post( (int) $post_id, true ) ) {
				++$deleted_count;
			}
		}

		if ( ! empty( $post_ids ) && 0 === $deleted_count ) {
			break;
		}
	} while ( ! empty( $post_ids ) );

	foreach ( array( 'velomalo_type', 'velomalo_group' ) as $taxonomy ) {
		$term_ids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		if ( is_wp_error( $term_ids ) ) {
			continue;
		}

		foreach ( $term_ids as $term_id ) {
			wp_delete_term( (int) $term_id, $taxonomy );
		}
	}

	delete_option( 'velomalo_provider_settings' );
	delete_option( 'velomalo_data_version' );
	delete_option( 'velomalo_settings' );
}

/**
 * Process uninstall for one site, respecting that site's explicit deletion setting.
 *
 * @return void
 */
function velox_map_locator_uninstall_site() {
	$settings = get_option( 'velomalo_settings', array() );
	$delete_data = ! empty( $settings['data']['delete_data_on_uninstall'] );

	velox_map_locator_uninstall_remove_capabilities();

	// Best-effort runtime cleanup is safe even when persistent plugin data is retained.
	delete_transient( 'velomalo_runtime_cache_version' );

	if ( $delete_data ) {
		velox_map_locator_uninstall_delete_site_data();
	}
}

if ( is_multisite() ) {
	$velox_map_locator_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $velox_map_locator_site_ids as $velox_map_locator_site_id ) {
		switch_to_blog( (int) $velox_map_locator_site_id );
		velox_map_locator_uninstall_site();
		restore_current_blog();
	}
} else {
	velox_map_locator_uninstall_site();
}

// Staged imports are temporary plugin-owned files and are safe to remove after uninstall.
$velox_map_locator_temp_dir = get_temp_dir();
if ( is_dir( $velox_map_locator_temp_dir ) ) {
	foreach ( array( 'velomalo-import-*' ) as $velox_map_locator_pattern ) {
		$velox_map_locator_paths = glob( trailingslashit( $velox_map_locator_temp_dir ) . $velox_map_locator_pattern );
		if ( ! is_array( $velox_map_locator_paths ) ) {
			continue;
		}
		foreach ( $velox_map_locator_paths as $velox_map_locator_path ) {
			if ( is_file( $velox_map_locator_path ) ) {
				wp_delete_file( $velox_map_locator_path );
			}
		}
	}
}
