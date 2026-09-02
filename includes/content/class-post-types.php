<?php
/**
 * Private content type registration.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers internal Location and Locator content types.
 */
final class Post_Types {

	const LOCATION = 'velomalo_location';
	const LOCATOR  = 'velomalo_locator';

	/**
	 * Register internal content types.
	 *
	 * @return void
	 */
	public static function register() {
		register_post_type( self::LOCATION, self::location_args() );
		register_post_type( self::LOCATOR, self::locator_args() );
	}

	/**
	 * Location post type arguments.
	 *
	 * @return array<string,mixed>
	 */
	private static function location_args() {
		return array(
			'labels'              => array(
				'name'          => __( 'Locations', 'velox-map-locator' ),
				'singular_name' => __( 'Location', 'velox-map-locator' ),
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => array( 'title', 'excerpt', 'thumbnail', 'author', 'page-attributes' ),
			'capability_type'     => array( 'velomalo_location', 'velomalo_locations' ),
			'capabilities'        => self::location_capabilities(),
			'map_meta_cap'        => true,
			'delete_with_user'    => false,
		);
	}

	/**
	 * Locator post type arguments.
	 *
	 * @return array<string,mixed>
	 */
	private static function locator_args() {
		return array(
			'labels'              => array(
				'name'          => __( 'Locators', 'velox-map-locator' ),
				'singular_name' => __( 'Locator', 'velox-map-locator' ),
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => array( 'title', 'author' ),
			'capability_type'     => array( 'velomalo_locator', 'velomalo_locators' ),
			'capabilities'        => self::locator_capabilities(),
			'map_meta_cap'        => true,
			'delete_with_user'    => false,
		);
	}

	/**
	 * Explicit Location capability mapping.
	 *
	 * @return array<string,string>
	 */
	public static function location_capabilities() {
		return array(
			'edit_post'              => 'edit_velomalo_location',
			'read_post'              => 'read_velomalo_location',
			'delete_post'            => 'delete_velomalo_location',
			'edit_posts'             => 'edit_velomalo_locations',
			'edit_others_posts'      => 'edit_others_velomalo_locations',
			'publish_posts'          => 'publish_velomalo_locations',
			'read_private_posts'     => 'read_private_velomalo_locations',
			'delete_posts'           => 'delete_velomalo_locations',
			'delete_private_posts'   => 'delete_private_velomalo_locations',
			'delete_published_posts' => 'delete_published_velomalo_locations',
			'delete_others_posts'    => 'delete_others_velomalo_locations',
			'edit_private_posts'     => 'edit_private_velomalo_locations',
			'edit_published_posts'   => 'edit_published_velomalo_locations',
			'create_posts'           => 'create_velomalo_locations',
		);
	}

	/**
	 * Explicit Locator capability mapping.
	 *
	 * @return array<string,string>
	 */
	public static function locator_capabilities() {
		return array(
			'edit_post'              => 'edit_velomalo_locator',
			'read_post'              => 'read_velomalo_locator',
			'delete_post'            => 'delete_velomalo_locator',
			'edit_posts'             => 'edit_velomalo_locators',
			'edit_others_posts'      => 'edit_others_velomalo_locators',
			'publish_posts'          => 'publish_velomalo_locators',
			'read_private_posts'     => 'read_private_velomalo_locators',
			'delete_posts'           => 'delete_velomalo_locators',
			'delete_private_posts'   => 'delete_private_velomalo_locators',
			'delete_published_posts' => 'delete_published_velomalo_locators',
			'delete_others_posts'    => 'delete_others_velomalo_locators',
			'edit_private_posts'     => 'edit_private_velomalo_locators',
			'edit_published_posts'   => 'edit_published_velomalo_locators',
			'create_posts'           => 'create_velomalo_locators',
		);
	}
}
