<?php
/**
 * Public Locator renderer.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Frontend;

use VeloxPlugins\VeloxMapLocator\Content\Settings;
use VeloxPlugins\VeloxMapLocator\Domain\Locator;
use VeloxPlugins\VeloxMapLocator\Repositories\WP_Location_Repository;
use VeloxPlugins\VeloxMapLocator\Repositories\WP_Locator_Repository;
use VeloxPlugins\VeloxMapLocator\Services\Locator_Query_Service;
use VeloxPlugins\VeloxMapLocator\Services\Provider_Registry;
use VeloxPlugins\VeloxMapLocator\Services\Public_Locator_Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-renders accessible Locator content and progressive-enhancement hooks.
 */
final class Renderer {

	/** Runtime instance counter. */
	private static $instance = 0;

	/**
	 * Render one Locator.
	 *
	 * @param int  $locator_id Locator ID.
	 * @param bool $allow_draft Whether authorized previews may render drafts.
	 * @return string
	 */
	public static function render_locator( $locator_id, $allow_draft = false ) {
		$locator_id = absint( $locator_id );
		if ( ! $locator_id ) {
			return '';
		}

		$locator_repository = new WP_Locator_Repository();
		$locator            = $locator_repository->find( $locator_id );
		if ( ! $locator ) {
			return self::editor_notice( __( 'This Locator could not be found.', 'velox-map-locator' ) );
		}

		$can_preview = $allow_draft && current_user_can( 'edit_post', $locator_id );
		if ( 'publish' !== $locator->get_status() && ! $can_preview ) {
			return current_user_can( 'edit_post', $locator_id ) ? self::editor_notice( __( 'This Locator is not published.', 'velox-map-locator' ) ) : '';
		}

		return self::render_locator_model( $locator, true );
	}

	/**
	 * Render a protected unsaved Locator preview using the real public renderer.
	 *
	 * Assets are loaded by the admin Builder screen rather than from the REST request.
	 *
	 * @param Locator $locator Normalized preview Locator.
	 * @return string
	 */
	public static function render_preview_locator( Locator $locator ) {
		return self::render_locator_model( $locator, false );
	}

	/**
	 * Render a normalized Locator model.
	 *
	 * @param Locator $locator        Locator model.
	 * @param bool    $enqueue_assets Whether public assets should be enqueued.
	 * @return string
	 */
	private static function render_locator_model( Locator $locator, $enqueue_assets ) {
		$builder            = new Public_Locator_Builder( new Locator_Query_Service( new WP_Location_Repository() ) );
		$payload            = $builder->build( $locator );
		$payload['strings'] = self::frontend_strings();
		$config             = isset( $payload['config'] ) && is_array( $payload['config'] ) ? $payload['config'] : array();
		$layout             = isset( $config['layout']['mode'] ) ? sanitize_key( $config['layout']['mode'] ) : 'list_only';
		$map_enabled        = in_array( $layout, array( 'split', 'map_only' ), true );
		$provider_id        = isset( $config['map']['provider'] ) ? sanitize_key( $config['map']['provider'] ) : 'osm';
		$provider           = $map_enabled ? ( new Provider_Registry() )->get( $provider_id ) : null;
		$provider_config    = $provider ? $provider->get_public_config( $config ) : array();
		$provider_engine    = isset( $provider_config['engine'] ) ? sanitize_key( (string) $provider_config['engine'] ) : '';
		$map_available      = $provider && $provider->is_configured( $config ) && ( 'leaflet' !== $provider_engine || Assets::leaflet_available() );
		$privacy_mode       = self::resolved_map_load_mode( $config );

		if ( $provider ) {
			$payload['map_provider'] = $provider_config;
		}
		$payload['map_load_mode'] = $privacy_mode;

		if ( $enqueue_assets ) {
			Assets::enqueue();
			if ( $map_enabled && $map_available ) {
				Assets::enqueue_map( $provider_id );
			}
		}

		$locator_id = $locator->get_id();
		++self::$instance;
		$instance_id = 'vml-' . $locator_id . '-' . self::$instance;
		$classes     = array( 'vml-locator', 'vml-locator--' . $layout );
		$theme       = isset( $config['appearance']['theme'] ) ? sanitize_key( $config['appearance']['theme'] ) : 'velox';
		$mode        = isset( $config['appearance']['mode'] ) ? sanitize_key( $config['appearance']['mode'] ) : 'light';
		$density     = isset( $config['appearance']['density'] ) ? sanitize_key( $config['appearance']['density'] ) : 'comfortable';
		$typography  = isset( $config['appearance']['typography'] ) ? sanitize_key( $config['appearance']['typography'] ) : 'inherit';
		$accent      = isset( $config['appearance']['accent'] ) ? sanitize_hex_color( $config['appearance']['accent'] ) : false;
		$height      = isset( $config['layout']['height'] ) ? max( 300, min( 1200, absint( $config['layout']['height'] ) ) ) : 620;
		$sidebar     = isset( $config['layout']['sidebar_width'] ) ? max( 20, min( 50, absint( $config['layout']['sidebar_width'] ) ) ) : 25;
		$mobile_order = isset( $config['layout']['mobile_order'] ) ? sanitize_key( $config['layout']['mobile_order'] ) : 'map_first';
		$sidebar_position = isset( $config['layout']['sidebar_position'] ) ? sanitize_key( $config['layout']['sidebar_position'] ) : 'auto';
		$style_parts = array( '--vml-locator-height:' . $height . 'px', '--vml-sidebar-width:' . $sidebar . '%' );
		if ( $accent ) {
			$style_parts[] = '--vml-accent:' . $accent;
		}
		$style = implode( ';', $style_parts ) . ';';

		ob_start();
		?>
		<section
			id="<?php echo esc_attr( $instance_id ); ?>"
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			data-vml-locator-id="<?php echo esc_attr( (string) $locator_id ); ?>"
			data-vml-instance="<?php echo esc_attr( $instance_id ); ?>"
			data-vml-theme="<?php echo esc_attr( $theme ); ?>"
			data-vml-mode="<?php echo esc_attr( $mode ); ?>"
			data-vml-density="<?php echo esc_attr( $density ); ?>"
			data-vml-typography="<?php echo esc_attr( $typography ); ?>"
			data-vml-layout="<?php echo esc_attr( $layout ); ?>"
			data-vml-mobile-order="<?php echo esc_attr( $mobile_order ); ?>"
			data-vml-sidebar-position="<?php echo esc_attr( $sidebar_position ); ?>"
			style="<?php echo esc_attr( $style ); ?>"
		>
			<?php if ( empty( $payload['locations'] ) ) : ?>
				<div class="vml-locator__empty">
					<strong><?php echo esc_html__( 'Locations are currently unavailable.', 'velox-map-locator' ); ?></strong>
					<span><?php echo esc_html__( 'Please check back later.', 'velox-map-locator' ); ?></span>
				</div>
			<?php else : ?>
				<?php self::render_controls( $payload['locations'], $config ); ?>
				<div class="vml-locator__body">
					<div class="vml-locator__list-pane<?php echo 'map_only' === $layout ? ' vml-locator__list-pane--map-fallback' : ''; ?>">
						<?php self::render_list( $payload['locations'], $config ); ?>
					</div>
					<?php if ( $map_enabled ) : ?>
						<?php self::render_map( $map_available, $privacy_mode ); ?>
					<?php endif; ?>
				</div>
				<div class="vml-locator__no-results" data-vml-no-results hidden>
					<strong><?php echo esc_html__( 'No locations match your search.', 'velox-map-locator' ); ?></strong>
					<span><?php echo esc_html__( 'Try a different search or clear the current filters.', 'velox-map-locator' ); ?></span>
				</div>
				<div class="vml-locator__feedback" data-vml-feedback hidden></div>
				<div class="screen-reader-text" aria-live="polite" aria-atomic="true" data-vml-live-region></div>
				<script type="application/json" class="vml-locator__data"><?php echo self::json_for_html( $payload ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Encoded with HTML-safe JSON flags. ?></script>
			<?php endif; ?>
		</section>
		<?php
		return trim( (string) ob_get_clean() );
	}

	/** Resolve per-Locator privacy mode against the global default. */
	private static function resolved_map_load_mode( $config ) {
		$mode = isset( $config['privacy']['map_load_mode'] ) ? sanitize_key( $config['privacy']['map_load_mode'] ) : 'inherit';
		if ( 'inherit' !== $mode ) {
			return in_array( $mode, array( 'immediate', 'interaction' ), true ) ? $mode : 'immediate';
		}
		$settings = get_option( Settings::OPTION_SETTINGS, Settings::defaults() );
		$global   = isset( $settings['privacy_defaults']['map_load_mode'] ) ? sanitize_key( $settings['privacy_defaults']['map_load_mode'] ) : 'immediate';
		return 'interaction' === $global ? 'interaction' : 'immediate';
	}

	/** Render map surface, privacy placeholder, or graceful failure. */
	private static function render_map( $available, $privacy_mode ) {
		?>
		<div class="vml-locator__map-pane" data-vml-map-pane role="region" aria-label="<?php echo esc_attr__( 'Location map', 'velox-map-locator' ); ?>">
			<?php if ( ! $available ) : ?>
				<div class="vml-map-state vml-map-state--error" data-vml-map-error role="status">
					<strong><?php echo esc_html__( 'Map temporarily unavailable', 'velox-map-locator' ); ?></strong>
					<span><?php echo esc_html__( 'Location details and directions remain available.', 'velox-map-locator' ); ?></span>
				</div>
			<?php else : ?>
				<div class="vml-locator__map-canvas" data-vml-map-canvas <?php echo 'interaction' === $privacy_mode ? 'hidden' : ''; ?>></div>
				<?php if ( 'interaction' === $privacy_mode ) : ?>
					<div class="vml-map-state vml-map-state--privacy" data-vml-map-privacy>
						<span class="vml-map-state__icon" aria-hidden="true">⌖</span>
						<strong><?php echo esc_html__( 'Interactive Map', 'velox-map-locator' ); ?></strong>
						<span><?php echo esc_html__( 'Load the map to view these locations geographically.', 'velox-map-locator' ); ?></span>
						<button type="button" class="vml-map-state__button" data-vml-load-map><?php echo esc_html__( 'Load Map', 'velox-map-locator' ); ?></button>
					</div>
				<?php endif; ?>
				<div class="vml-map-state vml-map-state--runtime-error" data-vml-map-runtime-error role="status" hidden>
					<strong><?php echo esc_html__( 'Map temporarily unavailable', 'velox-map-locator' ); ?></strong>
					<span><?php echo esc_html__( 'Location details and directions remain available.', 'velox-map-locator' ); ?></span>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Render search/filter/Near Me controls, hidden until JavaScript initializes. */
	private static function render_controls( $locations, $config ) {
		$search      = isset( $config['search'] ) && is_array( $config['search'] ) ? $config['search'] : array();
		$filters     = isset( $config['filters'] ) && is_array( $config['filters'] ) ? $config['filters'] : array();
		$behaviour   = isset( $config['behaviour'] ) && is_array( $config['behaviour'] ) ? $config['behaviour'] : array();
		$dimensions  = isset( $filters['dimensions'] ) && is_array( $filters['dimensions'] ) ? $filters['dimensions'] : array();
		$search_on   = ! empty( $search['enabled'] );
		$near_me     = ! empty( $behaviour['near_me'] );
		$show_count  = ! array_key_exists( 'show_result_count', $filters ) || ! empty( $filters['show_result_count'] );
		$style       = isset( $filters['style'] ) ? sanitize_key( $filters['style'] ) : 'pills';
		$has_filters = ! empty( $dimensions );

		if ( ! $search_on && ! $near_me && ! $has_filters && ! $show_count ) {
			return;
		}
		?>
		<div class="vml-locator__toolbar">
			<div class="vml-locator__interactive" hidden>
				<div class="vml-locator__controls">
					<?php if ( $search_on ) : ?>
						<label class="vml-locator__search">
							<span class="screen-reader-text"><?php echo esc_html__( 'Search locations', 'velox-map-locator' ); ?></span>
							<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M10.5 4a6.5 6.5 0 1 0 4.02 11.61L20 21.1l1.1-1.1-5.49-5.48A6.5 6.5 0 0 0 10.5 4Zm0 1.6a4.9 4.9 0 1 1 0 9.8 4.9 4.9 0 0 1 0-9.8Z"/></svg>
							<input type="search" data-vml-search placeholder="<?php echo esc_attr( ! empty( $search['placeholder'] ) ? $search['placeholder'] : __( 'Search locations…', 'velox-map-locator' ) ); ?>" autocomplete="off" />
						</label>
					<?php endif; ?>

					<?php if ( $has_filters ) : ?>
						<div class="vml-locator__filters">
							<?php foreach ( $dimensions as $dimension ) : ?>
								<?php self::render_filter( $dimension, $locations, $style ); ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php if ( $near_me ) : ?>
						<button type="button" class="vml-locator__near" data-vml-near-me><?php echo esc_html__( 'Near Me', 'velox-map-locator' ); ?></button>
					<?php endif; ?>
					<button type="button" class="vml-locator__reset" data-vml-reset><?php echo esc_html__( 'Reset', 'velox-map-locator' ); ?></button>
				</div>
			</div>
			<?php if ( $show_count ) : ?>
				<?php
				$location_count = count( $locations );
				/* translators: %d: Number of Locations in the Locator. */
				$result_count_label = sprintf( _n( '%d location', '%d locations', $location_count, 'velox-map-locator' ), $location_count );
				?>
				<div class="vml-locator__summary" data-vml-result-count><?php echo esc_html( $result_count_label ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Render one configured filter dimension. */
	private static function render_filter( $dimension, $locations, $style ) {
		$options = self::filter_options( $dimension, $locations );
		if ( count( $options ) < 2 ) {
			return;
		}
		$label = self::filter_label( $dimension );
		if ( 'pills' === $style && 'type' === $dimension && count( $options ) <= 8 ) {
			?>
			<div class="vml-locator__pills" aria-label="<?php echo esc_attr( $label ); ?>">
				<button type="button" class="is-selected" aria-pressed="true" data-vml-filter-pill="" data-vml-filter-dimension="<?php echo esc_attr( $dimension ); ?>"><?php echo esc_html__( 'All', 'velox-map-locator' ); ?></button>
				<?php foreach ( $options as $value => $name ) : ?>
					<button type="button" aria-pressed="false" data-vml-filter-pill="<?php echo esc_attr( (string) $value ); ?>" data-vml-filter-dimension="<?php echo esc_attr( $dimension ); ?>"><?php echo esc_html( $name ); ?></button>
				<?php endforeach; ?>
			</div>
			<?php
			return;
		}
		?>
		<label class="vml-locator__filter-select">
			<span><?php echo esc_html( $label ); ?></span>
			<select data-vml-filter-select="<?php echo esc_attr( $dimension ); ?>">
				<option value=""><?php echo esc_html__( 'All', 'velox-map-locator' ); ?></option>
				<?php foreach ( $options as $value => $name ) : ?>
					<option value="<?php echo esc_attr( (string) $value ); ?>"><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<?php
	}

	/** Build unique filter options from public-safe Location data. */
	private static function filter_options( $dimension, $locations ) {
		$options = array();
		foreach ( $locations as $location ) {
			if ( 'type' === $dimension || 'group' === $dimension ) {
				$key = 'type' === $dimension ? 'types' : 'groups';
				foreach ( isset( $location[ $key ] ) && is_array( $location[ $key ] ) ? $location[ $key ] : array() as $term ) {
					if ( ! empty( $term['id'] ) && ! empty( $term['name'] ) ) {
						$options[ (string) absint( $term['id'] ) ] = (string) $term['name'];
					}
				}
			} elseif ( 'country' === $dimension && ! empty( $location['country_code'] ) ) {
				$options[ (string) $location['country_code'] ] = (string) $location['country_code'];
			} elseif ( 'city' === $dimension && ! empty( $location['city'] ) ) {
				$options[ (string) $location['city'] ] = (string) $location['city'];
			}
		}
		natcasesort( $options );
		return $options;
	}

	/** Human filter label. */
	private static function filter_label( $dimension ) {
		$labels = array(
			'type'    => __( 'Type', 'velox-map-locator' ),
			'group'   => __( 'Group', 'velox-map-locator' ),
			'country' => __( 'Country', 'velox-map-locator' ),
			'city'    => __( 'City', 'velox-map-locator' ),
		);
		return isset( $labels[ $dimension ] ) ? $labels[ $dimension ] : ucfirst( (string) $dimension );
	}

	/** Render list-only card collection. */
	private static function render_list( $locations, $config ) {
		$card_fields   = isset( $config['content']['card_fields'] ) && is_array( $config['content']['card_fields'] ) ? $config['content']['card_fields'] : array();
		$detail_fields = isset( $config['content']['popup_fields'] ) && is_array( $config['content']['popup_fields'] ) ? array_values( array_diff( $config['content']['popup_fields'], $card_fields ) ) : array();
		?>
		<?php $map_capable = in_array( isset( $config['layout']['mode'] ) ? $config['layout']['mode'] : 'list_only', array( 'split', 'map_only' ), true ); ?>
		<div class="vml-locator__list" role="list">
			<?php foreach ( $locations as $location ) : ?>
				<article class="vml-location-card" role="listitem" data-vml-location-id="<?php echo esc_attr( (string) $location['id'] ); ?>" <?php if ( $map_capable ) : ?>tabindex="0"<?php endif; ?>>
					<?php if ( in_array( 'image', $card_fields, true ) && ! empty( $location['image']['url'] ) ) : ?>
						<div class="vml-location-card__image"><img src="<?php echo esc_url( $location['image']['url'] ); ?>" alt="" loading="lazy" /></div>
					<?php endif; ?>
					<div class="vml-location-card__body">
						<div class="vml-location-card__heading">
							<h3><?php echo esc_html( $location['name'] ); ?></h3>
							<?php if ( in_array( 'type', $card_fields, true ) && ! empty( $location['types'][0]['name'] ) ) : ?>
								<span class="vml-location-card__type"><?php echo esc_html( $location['types'][0]['name'] ); ?></span>
							<?php endif; ?>
						</div>
						<?php if ( in_array( 'address', $card_fields, true ) && ! empty( $location['address'] ) ) : ?>
							<address class="vml-location-card__address"><?php echo esc_html( $location['address'] ); ?></address>
						<?php endif; ?>

						<?php if ( in_array( 'status', $card_fields, true ) ) : ?>
							<?php self::render_status_node( $location ); ?>
						<?php endif; ?>

						<?php if ( in_array( 'description', $card_fields, true ) && ! empty( $location['description'] ) ) : ?>
							<p class="vml-location-card__description"><?php echo esc_html( $location['description'] ); ?></p>
						<?php endif; ?>

						<?php self::render_contact_details( $location, $card_fields ); ?>

						<?php if ( in_array( 'hours', $card_fields, true ) && ! empty( $location['weekly_hours'] ) ) : ?>
							<?php self::render_hours( $location['weekly_hours'] ); ?>
						<?php endif; ?>

						<?php if ( in_array( 'extra_fields', $card_fields, true ) && ! empty( $location['extra_fields'] ) ) : ?>
							<?php self::render_extra_fields( $location['extra_fields'] ); ?>
						<?php endif; ?>

						<span class="vml-location-card__distance" data-vml-distance hidden></span>
						<?php self::render_more_details( $location, $detail_fields ); ?>

						<?php if ( in_array( 'directions', $card_fields, true ) && ! empty( $location['directions_url'] ) ) : ?>
							<div class="vml-location-card__actions"><a class="vml-location-card__directions" href="<?php echo esc_url( $location['directions_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Directions', 'velox-map-locator' ); ?><span aria-hidden="true"> →</span></a></div>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/** Render live-status hook, including non-normal operational overrides without JavaScript. */
	private static function render_status_node( $location ) {
		$status = ! empty( $location['operational']['status'] ) ? (string) $location['operational']['status'] : 'normal';
		$label  = '';
		$tone   = 'neutral';
		if ( 'coming_soon' === $status ) {
			$label = ! empty( $location['operational']['label'] ) ? $location['operational']['label'] : __( 'Coming Soon', 'velox-map-locator' );
			$tone  = 'info';
		} elseif ( 'temporarily_closed' === $status ) {
			$label = ! empty( $location['operational']['label'] ) ? $location['operational']['label'] : __( 'Temporarily Closed', 'velox-map-locator' );
			$tone  = 'warning';
		}
		?>
		<div class="vml-location-card__status is-<?php echo esc_attr( $tone ); ?>" data-vml-live-status <?php if ( '' === $label ) : ?>hidden<?php endif; ?>>
			<span class="vml-location-card__status-dot" aria-hidden="true"></span>
			<span class="vml-location-card__status-label" data-vml-status-label><?php echo esc_html( $label ); ?></span>
		</div>
		<?php
	}

	/** Render contact/description style fields for an arbitrary field list. */
	private static function render_contact_details( $location, $fields ) {
		$has = false;
		foreach ( array( 'phone', 'email', 'website', 'contact' ) as $field ) {
			if ( in_array( $field, $fields, true ) && ( ( 'contact' === $field && ! empty( $location['contact_name'] ) ) || ( 'contact' !== $field && ! empty( $location[ $field ] ) ) ) ) {
				$has = true;
			}
		}
		if ( ! $has ) {
			return;
		}
		?>
		<div class="vml-location-card__details">
			<?php if ( in_array( 'phone', $fields, true ) && ! empty( $location['phone'] ) ) : ?><a href="<?php echo esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $location['phone'] ) ); ?>"><?php echo esc_html( $location['phone'] ); ?></a><?php endif; ?>
			<?php if ( in_array( 'email', $fields, true ) && ! empty( $location['email'] ) ) : ?><a href="<?php echo esc_url( 'mailto:' . $location['email'] ); ?>"><?php echo esc_html( $location['email'] ); ?></a><?php endif; ?>
			<?php if ( in_array( 'website', $fields, true ) && ! empty( $location['website'] ) ) : ?><a href="<?php echo esc_url( $location['website'] ); ?>"><?php echo esc_html__( 'Website', 'velox-map-locator' ); ?></a><?php endif; ?>
			<?php if ( in_array( 'contact', $fields, true ) && ! empty( $location['contact_name'] ) ) : ?><span><?php echo esc_html( $location['contact_name'] ); ?></span><?php endif; ?>
		</div>
		<?php
	}

	/** Render secondary list-only details using the configured popup field set. */
	private static function render_more_details( $location, $fields ) {
		$has_content = false;
		foreach ( $fields as $field ) {
			if ( 'image' === $field && ! empty( $location['image']['url'] ) ) {
				$has_content = true;
			}
			if ( 'address' === $field && ! empty( $location['address'] ) ) {
				$has_content = true;
			}
			if ( 'status' === $field && ! empty( $location['operational'] ) ) {
				$has_content = true;
			}
			if ( 'type' === $field && ! empty( $location['types'][0]['name'] ) ) {
				$has_content = true;
			}
			if ( 'hours' === $field && ! empty( $location['weekly_hours'] ) ) {
				$has_content = true;
			}
			if ( 'description' === $field && ! empty( $location['description'] ) ) {
				$has_content = true;
			}
			if ( 'extra_fields' === $field && ! empty( $location['extra_fields'] ) ) {
				$has_content = true;
			}
			if ( 'phone' === $field && ! empty( $location['phone'] ) ) {
				$has_content = true;
			}
			if ( 'email' === $field && ! empty( $location['email'] ) ) {
				$has_content = true;
			}
			if ( 'website' === $field && ! empty( $location['website'] ) ) {
				$has_content = true;
			}
			if ( 'contact' === $field && ! empty( $location['contact_name'] ) ) {
				$has_content = true;
			}
			if ( 'directions' === $field && ! empty( $location['directions_url'] ) ) {
				$has_content = true;
			}
		}
		if ( ! $has_content ) {
			return;
		}
		?>
		<details class="vml-location-card__more">
			<summary><?php echo esc_html__( 'More details', 'velox-map-locator' ); ?></summary>
			<div class="vml-location-card__more-body">
				<?php if ( in_array( 'image', $fields, true ) && ! empty( $location['image']['url'] ) ) : ?>
					<img class="vml-location-card__detail-image" src="<?php echo esc_url( $location['image']['url'] ); ?>" alt="" loading="lazy" />
				<?php endif; ?>
				<?php if ( in_array( 'type', $fields, true ) && ! empty( $location['types'][0]['name'] ) ) : ?>
					<span class="vml-location-card__type"><?php echo esc_html( $location['types'][0]['name'] ); ?></span>
				<?php endif; ?>
				<?php if ( in_array( 'address', $fields, true ) && ! empty( $location['address'] ) ) : ?>
					<address class="vml-location-card__address"><?php echo esc_html( $location['address'] ); ?></address>
				<?php endif; ?>
				<?php if ( in_array( 'status', $fields, true ) ) : ?>
					<?php self::render_status_node( $location ); ?>
				<?php endif; ?>
				<?php if ( in_array( 'description', $fields, true ) && ! empty( $location['description'] ) ) : ?>
					<p class="vml-location-card__description"><?php echo esc_html( $location['description'] ); ?></p>
				<?php endif; ?>
				<?php self::render_contact_details( $location, $fields ); ?>
				<?php if ( in_array( 'hours', $fields, true ) && ! empty( $location['weekly_hours'] ) ) : ?>
					<?php self::render_hours( $location['weekly_hours'] ); ?>
				<?php endif; ?>
				<?php if ( in_array( 'extra_fields', $fields, true ) && ! empty( $location['extra_fields'] ) ) : ?>
					<?php self::render_extra_fields( $location['extra_fields'] ); ?>
				<?php endif; ?>
				<?php if ( in_array( 'directions', $fields, true ) && ! empty( $location['directions_url'] ) ) : ?>
					<div class="vml-location-card__actions">
						<a class="vml-location-card__directions" href="<?php echo esc_url( $location['directions_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Directions', 'velox-map-locator' ); ?><span aria-hidden="true"> →</span></a>
					</div>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}

	/** Render structured extra fields. */
	private static function render_extra_fields( $fields ) {
		?>
		<dl class="vml-location-card__extra">
			<?php foreach ( $fields as $field ) : ?>
				<?php if ( ! empty( $field['label'] ) && isset( $field['value'] ) && '' !== (string) $field['value'] ) : ?>
					<dt><?php echo esc_html( $field['label'] ); ?></dt><dd><?php echo esc_html( $field['value'] ); ?></dd>
				<?php endif; ?>
			<?php endforeach; ?>
		</dl>
		<?php
	}

	/** Render structured weekly hours. */
	private static function render_hours( $hours ) {
		$labels = array(
			'monday' => __( 'Monday', 'velox-map-locator' ), 'tuesday' => __( 'Tuesday', 'velox-map-locator' ), 'wednesday' => __( 'Wednesday', 'velox-map-locator' ), 'thursday' => __( 'Thursday', 'velox-map-locator' ), 'friday' => __( 'Friday', 'velox-map-locator' ), 'saturday' => __( 'Saturday', 'velox-map-locator' ), 'sunday' => __( 'Sunday', 'velox-map-locator' ),
		);
		?>
		<div class="vml-location-card__hours"><strong><?php echo esc_html__( 'Business Hours', 'velox-map-locator' ); ?></strong><dl>
			<?php foreach ( $labels as $day => $label ) : ?>
				<?php if ( ! isset( $hours[ $day ] ) || ! is_array( $hours[ $day ] ) ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( self::format_day_hours( $hours[ $day ] ) ); ?></dd>
			<?php endforeach; ?>
		</dl></div>
		<?php
	}

	/** Format one business day without calculating live Open/Closed state. */
	private static function format_day_hours( $day ) {
		if ( ! empty( $day['closed'] ) ) {
			return __( 'Closed', 'velox-map-locator' );
		}
		if ( ! empty( $day['all_day'] ) ) {
			return __( '24 Hours', 'velox-map-locator' );
		}
		$parts = array();
		foreach ( isset( $day['intervals'] ) && is_array( $day['intervals'] ) ? $day['intervals'] : array() as $interval ) {
			if ( ! empty( $interval['open'] ) && ! empty( $interval['close'] ) ) {
				$parts[] = $interval['open'] . '–' . $interval['close'];
			}
		}
		return $parts ? implode( ', ', $parts ) : __( 'Closed', 'velox-map-locator' );
	}


	/** Translated strings consumed by the framework-free frontend controller. */
	private static function frontend_strings() {
		return array(
			'location_one'            => __( '1 location', 'velox-map-locator' ),
			/* translators: %d: Number of Locations. */
			'locations_many'          => __( '%d locations', 'velox-map-locator' ),
			/* translators: %s: Number of nearby Locations. */
			'near_you'                => __( '%s near you', 'velox-map-locator' ),
			/* translators: %s: Number of matching Locations. */
			'found'                   => __( '%s found.', 'velox-map-locator' ),
			/* translators: %s: Number of Locations sorted by distance. */
			'sorted_distance'         => __( '%s sorted by distance.', 'velox-map-locator' ),
			'locating'                => __( 'Locating…', 'velox-map-locator' ),
			'geo_unavailable'         => __( 'Location services are not available in this browser.', 'velox-map-locator' ),
			'geo_denied'              => __( 'Location access was not enabled. You can still search locations manually.', 'velox-map-locator' ),
			'geo_failed'              => __( 'Your current location could not be determined. You can still search locations manually.', 'velox-map-locator' ),
			'locations_sorted'        => __( 'Locations sorted by distance.', 'velox-map-locator' ),
			/* translators: %s: Formatted distance from the visitor. */
			'distance_away'           => __( '%s away', 'velox-map-locator' ),
			'map_tools_label'         => __( 'Map controls', 'velox-map-locator' ),
			'map_home'                => __( 'Home', 'velox-map-locator' ),
			'map_home_label'          => __( 'Return to the initial map view', 'velox-map-locator' ),
			'map_fit_all'             => __( 'Fit All', 'velox-map-locator' ),
			'map_fit_all_label'       => __( 'Fit visible locations in the map', 'velox-map-locator' ),
			/* translators: %d: Current map zoom level. */
			'map_zoom_level'          => __( 'Zoom level %d', 'velox-map-locator' ),
			/* translators: %d: Number of Locations represented by a map cluster. */
			'cluster_locations'       => __( '%d locations', 'velox-map-locator' ),
			'map_fullscreen_label'     => __( 'Toggle fullscreen map', 'velox-map-locator' ),
			'coming_soon'             => __( 'Coming Soon', 'velox-map-locator' ),
			'temporarily_closed'      => __( 'Temporarily Closed', 'velox-map-locator' ),
			/* translators: %s: Closing time. */
			'closing_soon'            => __( 'Closing soon · %s', 'velox-map-locator' ),
			/* translators: %s: Closing time. */
			'open_closes'             => __( 'Open · Closes %s', 'velox-map-locator' ),
			'open_24'                 => __( 'Open 24 Hours', 'velox-map-locator' ),
			/* translators: %s: Next opening time. */
			'closed_opens'            => __( 'Closed · Opens %s', 'velox-map-locator' ),
			/* translators: %s: Opening time tomorrow. */
			'closed_opens_tomorrow'   => __( 'Closed · Opens tomorrow %s', 'velox-map-locator' ),
			/* translators: 1: Day name, 2: Opening time. */
			'closed_opens_day'        => __( 'Closed · Opens %1$s %2$s', 'velox-map-locator' ),
			/* translators: %s: Day name on which the Location opens all day. */
			'closed_opens_day_allday' => __( 'Closed · Opens %s', 'velox-map-locator' ),
			'closed_opens_tomorrow_allday' => __( 'Closed · Opens tomorrow', 'velox-map-locator' ),
			'closed'                  => __( 'Closed', 'velox-map-locator' ),
			'miles_abbr'              => __( 'mi', 'velox-map-locator' ),
			'kilometres_abbr'         => __( 'km', 'velox-map-locator' ),
			'weekdays'                => array(
				'sunday'    => __( 'Sunday', 'velox-map-locator' ),
				'monday'    => __( 'Monday', 'velox-map-locator' ),
				'tuesday'   => __( 'Tuesday', 'velox-map-locator' ),
				'wednesday' => __( 'Wednesday', 'velox-map-locator' ),
				'thursday'  => __( 'Thursday', 'velox-map-locator' ),
				'friday'    => __( 'Friday', 'velox-map-locator' ),
				'saturday'  => __( 'Saturday', 'velox-map-locator' ),
			),
		);
	}

	/** HTML-safe JSON string for the inline application/json node. */
	private static function json_for_html( $payload ) {
		return wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	}

	/** Show development/editor-only shortcode feedback. */
	private static function editor_notice( $message ) {
		if ( ! current_user_can( 'edit_velomalo_locators' ) ) {
			return '';
		}
		/* translators: %s: Runtime Locator error message. */
		return '<div class="vml-locator-notice">' . esc_html( sprintf( __( 'Velox Map Locator: %s', 'velox-map-locator' ), $message ) ) . '</div>';
	}
}
