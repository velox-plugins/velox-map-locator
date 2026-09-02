<?php
/**
 * Location taxonomy registration.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Type and Group taxonomies for locations.
 */
final class Taxonomies {

	const TYPE  = 'velomalo_type';
	const GROUP = 'velomalo_group';

	/**
	 * Register taxonomies.
	 *
	 * @return void
	 */
	public static function register() {
		register_taxonomy(
			self::TYPE,
			array( Post_Types::LOCATION ),
			self::args( false, __( 'Types', 'velox-map-locator' ), __( 'Type', 'velox-map-locator' ) )
		);

		register_taxonomy(
			self::GROUP,
			array( Post_Types::LOCATION ),
			self::args( true, __( 'Groups', 'velox-map-locator' ), __( 'Group', 'velox-map-locator' ) )
		);
	}

	/**
	 * Build shared taxonomy arguments.
	 *
	 * @param bool   $hierarchical Whether the taxonomy is hierarchical.
	 * @param string $plural       Plural label.
	 * @param string $singular     Singular label.
	 * @return array<string,mixed>
	 */
	private static function args( $hierarchical, $plural, $singular ) {
		return array(
			'labels'             => array(
				'name'          => $plural,
				'singular_name' => $singular,
			),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => false,
			'show_in_menu'       => false,
			'show_in_rest'       => false,
			'show_tagcloud'      => false,
			'show_in_quick_edit' => false,
			'hierarchical'       => (bool) $hierarchical,
			'rewrite'            => false,
			'query_var'          => false,
			'capabilities'       => array(
				'manage_terms' => 'manage_velomalo_terms',
				'edit_terms'   => 'manage_velomalo_terms',
				'delete_terms' => 'manage_velomalo_terms',
				'assign_terms' => 'edit_velomalo_locations',
			),
		);
	}
}
