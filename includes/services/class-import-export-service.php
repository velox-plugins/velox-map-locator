<?php
/**
 * CSV import/export application service.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Services;

use VeloxPlugins\VeloxMapLocator\Content\Taxonomies;
use VeloxPlugins\VeloxMapLocator\Repositories\Location_Repository_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles safe CSV staging, mapping, validation and chunked Location import.
 */
final class Import_Export_Service {

	/** Import session lifetime. */
	const SESSION_TTL = HOUR_IN_SECONDS;

	/** Maximum rows processed by one commit request. */
	const CHUNK_SIZE = 100;

	/**
	 * Location service.
	 *
	 * @var Location_Service
	 */
	private $location_service;

	/**
	 * Location repository.
	 *
	 * @var Location_Repository_Interface
	 */
	private $repository;

	/**
	 * Location validator.
	 *
	 * @var Location_Validator
	 */
	private $validator;

	/**
	 * Term service.
	 *
	 * @var Term_Service
	 */
	private $term_service;

	/**
	 * Constructor.
	 *
	 * @param Location_Service              $location_service Location service.
	 * @param Location_Repository_Interface $repository       Location repository.
	 * @param Location_Validator            $validator        Location validator.
	 * @param Term_Service                  $term_service     Term service.
	 */
	public function __construct( Location_Service $location_service, Location_Repository_Interface $repository, Location_Validator $validator, Term_Service $term_service ) {
		$this->location_service = $location_service;
		$this->repository       = $repository;
		$this->validator        = $validator;
		$this->term_service     = $term_service;
	}

	/**
	 * Canonical import/export columns.
	 *
	 * @return array<string,string>
	 */
	public static function columns() {
		return array(
			'external_id'        => __( 'External ID', 'velox-map-locator' ),
			'name'               => __( 'Name', 'velox-map-locator' ),
			'status'             => __( 'Status', 'velox-map-locator' ),
			'description'        => __( 'Description', 'velox-map-locator' ),
			'menu_order'         => __( 'Sort priority', 'velox-map-locator' ),
			'featured_image_id'  => __( 'Featured image ID', 'velox-map-locator' ),
			'address_line_1'     => __( 'Address line 1', 'velox-map-locator' ),
			'address_line_2'     => __( 'Address line 2', 'velox-map-locator' ),
			'city'               => __( 'City', 'velox-map-locator' ),
			'region'             => __( 'Region / state', 'velox-map-locator' ),
			'postal_code'        => __( 'Postal code', 'velox-map-locator' ),
			'country_code'       => __( 'Country code', 'velox-map-locator' ),
			'display_address'    => __( 'Display address', 'velox-map-locator' ),
			'latitude'           => __( 'Latitude', 'velox-map-locator' ),
			'longitude'          => __( 'Longitude', 'velox-map-locator' ),
			'timezone'           => __( 'Timezone', 'velox-map-locator' ),
			'phone'              => __( 'Phone', 'velox-map-locator' ),
			'email'              => __( 'Email', 'velox-map-locator' ),
			'website'            => __( 'Website', 'velox-map-locator' ),
			'contact_name'       => __( 'Contact person', 'velox-map-locator' ),
			'directions_url'     => __( 'Directions URL', 'velox-map-locator' ),
			'weekly_hours_json'  => __( 'Weekly hours (JSON)', 'velox-map-locator' ),
			'special_hours_json' => __( 'Special hours (JSON)', 'velox-map-locator' ),
			'operational_status' => __( 'Operational status', 'velox-map-locator' ),
			'status_label'       => __( 'Status label', 'velox-map-locator' ),
			'status_note'        => __( 'Status note', 'velox-map-locator' ),
			'type_slugs'         => __( 'Type slugs', 'velox-map-locator' ),
			'primary_type_slug'  => __( 'Primary Type slug', 'velox-map-locator' ),
			'group_paths'        => __( 'Group paths', 'velox-map-locator' ),
			'marker_override'    => __( 'Marker override', 'velox-map-locator' ),
			'marker_icon'        => __( 'Marker icon', 'velox-map-locator' ),
			'marker_media_id'    => __( 'Marker media ID', 'velox-map-locator' ),
			'marker_color'       => __( 'Marker color', 'velox-map-locator' ),
			'marker_icon_color'  => __( 'Marker icon color', 'velox-map-locator' ),
			'marker_size'        => __( 'Marker size', 'velox-map-locator' ),
			'extra_fields_json'  => __( 'Additional fields (JSON)', 'velox-map-locator' ),
		);
	}

	/**
	 * Stage an uploaded CSV and return mapping metadata.
	 *
	 * @param array<string,mixed> $file Uploaded file record.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function stage_upload( $file ) {
		$this->cleanup_stale_files();
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new \WP_Error( 'velomalo_import_upload_failed', __( 'The CSV upload could not be read.', 'velox-map-locator' ), array( 'status' => 400 ) );
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		if ( 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return new \WP_Error( 'velomalo_import_file_type', __( 'Please upload a .csv file.', 'velox-map-locator' ), array( 'status' => 400 ) );
		}

		$max_bytes = (int) apply_filters( 'velox_map_locator_import_max_file_bytes', 20 * MB_IN_BYTES );
		$size      = isset( $file['size'] ) ? (int) $file['size'] : (int) filesize( $file['tmp_name'] );
		if ( $size <= 0 || ( $max_bytes > 0 && $size > $max_bytes ) ) {
			return new \WP_Error( 'velomalo_import_file_size', __( 'The CSV file is empty or exceeds the import safety limit.', 'velox-map-locator' ), array( 'status' => 400 ) );
		}

		$token = $this->new_token();

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$staged_file         = $file;
		$staged_file['name'] = 'velomalo-import-' . $token . '.csv';
		$upload_dir_filter   = array( $this, 'filter_import_upload_dir' );

		add_filter( 'upload_dir', $upload_dir_filter, 999 );
		$handled = wp_handle_upload(
			$staged_file,
			array(
				'test_form' => false,
				'mimes'     => array( 'csv' => 'text/csv' ),
			)
		);
		remove_filter( 'upload_dir', $upload_dir_filter, 999 );

		if ( ! is_array( $handled ) || ! empty( $handled['error'] ) || empty( $handled['file'] ) ) {
			$message = is_array( $handled ) && ! empty( $handled['error'] ) ? sanitize_text_field( (string) $handled['error'] ) : __( 'The uploaded CSV could not be staged for validation.', 'velox-map-locator' );
			return new \WP_Error( 'velomalo_import_temp_copy', $message, array( 'status' => 400 ) );
		}

		$path       = (string) $handled['file'];
		$inspection = $this->inspect_file( $path );
		if ( is_wp_error( $inspection ) ) {
			wp_delete_file( $path );
			return $inspection;
		}

		$session = array(
			'user_id'     => get_current_user_id(),
			'path'        => $path,
			'file_name'   => $name,
			'delimiter'   => $inspection['delimiter'],
			'headers'     => $inspection['headers'],
			'row_count'   => $inspection['row_count'],
			'created_at'  => time(),
			'validated'   => false,
			'mapping'     => array(),
			'mode'        => 'upsert',
			'create_terms' => true,
		);
		$this->save_session( $token, $session );

		return array(
			'session'            => $token,
			'file_name'          => $name,
			'headers'            => $inspection['headers'],
			'row_count'          => $inspection['row_count'],
			'sample'             => $inspection['sample'],
			'columns'            => self::columns(),
			'suggested_mapping'  => $this->suggest_mapping( $inspection['headers'] ),
			'can_create_terms'   => current_user_can( 'manage_velomalo_terms' ),
		);
	}

	/**
	 * Validate an import session without writing Locations.
	 *
	 * @param string              $token        Session token.
	 * @param array<string,mixed> $mapping      Source-header to canonical-column mapping.
	 * @param string              $mode         create or upsert.
	 * @param bool                $create_terms Whether missing classification terms may be created.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function validate_session( $token, $mapping, $mode, $create_terms ) {
		$session = $this->get_session( $token );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$mapping = $this->normalize_mapping( $mapping, $session['headers'] );
		if ( is_wp_error( $mapping ) ) {
			return $mapping;
		}
		$mode = in_array( $mode, array( 'create', 'upsert' ), true ) ? $mode : 'upsert';
		$create_terms = (bool) $create_terms && current_user_can( 'manage_velomalo_terms' );

		$file = $this->open_csv_reader( $session['path'], $session['delimiter'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		// Discard header row.
		$file->fgetcsv();
		$row_number = 1;
		$errors     = array();
		$warnings   = array();
		$preview    = array();
		$creates    = 0;
		$updates    = 0;
		$seen_external_ids = array();

		while ( ! $file->eof() ) {
			$cells = $file->fgetcsv();
			if ( false === $cells ) {
				break;
			}
			++$row_number;
			if ( $this->is_blank_row( $cells ) ) {
				continue;
			}
			$row = $this->associate_row( $session['headers'], $cells );
			$prepared = $this->prepare_row( $row, $mapping, $mode, $create_terms, false );
			if ( is_wp_error( $prepared ) ) {
				$errors[] = $this->row_issue( $row_number, $prepared );
				continue;
			}

			$external_id = isset( $prepared['input']['external_id'] ) ? (string) $prepared['input']['external_id'] : '';
			if ( '' !== $external_id ) {
				$key = strtolower( $external_id );
				if ( isset( $seen_external_ids[ $key ] ) ) {
					$errors[] = array(
						'row'     => $row_number,
						'code'    => 'velomalo_import_duplicate_external_id',
						/* translators: 1: External ID value, 2: Earlier CSV row number. */
						'message' => sprintf( __( 'External ID "%1$s" also appears on row %2$d.', 'velox-map-locator' ), $external_id, $seen_external_ids[ $key ] ),
						'field'   => 'external_id',
					);
					continue;
				}
				$seen_external_ids[ $key ] = $row_number;
			}

			if ( 'update' === $prepared['action'] ) {
				++$updates;
			} else {
				++$creates;
			}
			foreach ( $prepared['warnings'] as $warning ) {
				$warnings[] = array( 'row' => $row_number, 'message' => $warning );
			}
			if ( count( $preview ) < 12 ) {
				$preview[] = array(
					'row'         => $row_number,
					'action'      => $prepared['action'],
					'external_id' => $external_id,
					'name'        => isset( $prepared['normalized']['name'] ) ? $prepared['normalized']['name'] : '',
					'status'      => isset( $prepared['normalized']['status'] ) ? $prepared['normalized']['status'] : 'draft',
				);
			}
		}

		$session['validated']    = empty( $errors );
		$session['mapping']      = $mapping;
		$session['mode']         = $mode;
		$session['create_terms'] = $create_terms;
		$session['validated_at'] = time();
		$session['validation_hash'] = $this->validation_hash( $mapping, $mode, $create_terms );
		$this->save_session( $token, $session );

		return array(
			'valid'       => empty( $errors ),
			'row_count'   => (int) $session['row_count'],
			'creates'     => $creates,
			'updates'     => $updates,
			'errors'      => $errors,
			'warnings'    => $warnings,
			'preview'     => $preview,
			'can_commit'  => empty( $errors ),
		);
	}

	/**
	 * Commit one validated import chunk.
	 *
	 * @param string $token  Session token.
	 * @param int    $offset Zero-based data-row offset.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function commit_chunk( $token, $offset ) {
		$session = $this->get_session( $token );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		if ( empty( $session['validated'] ) || empty( $session['validation_hash'] ) || $session['validation_hash'] !== $this->validation_hash( $session['mapping'], $session['mode'], $session['create_terms'] ) ) {
			return new \WP_Error( 'velomalo_import_not_validated', __( 'Validate the CSV mapping before starting the import.', 'velox-map-locator' ), array( 'status' => 409 ) );
		}

		$offset = max( 0, absint( $offset ) );
		$file = $this->open_csv_reader( $session['path'], $session['delimiter'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$file->fgetcsv();

		$data_index = 0;
		$processed  = 0;
		$created    = 0;
		$updated    = 0;
		$failures   = array();
		while ( ! $file->eof() ) {
			$cells = $file->fgetcsv();
			if ( false === $cells ) {
				break;
			}
			if ( $this->is_blank_row( $cells ) ) {
				continue;
			}
			if ( $data_index++ < $offset ) {
				continue;
			}
			if ( $processed >= self::CHUNK_SIZE ) {
				break;
			}

			$row_number = $data_index + 1;
			$row = $this->associate_row( $session['headers'], $cells );
			$prepared = $this->prepare_row( $row, $session['mapping'], $session['mode'], $session['create_terms'], true );
			if ( is_wp_error( $prepared ) ) {
				$failures[] = $this->row_issue( $row_number, $prepared );
				++$processed;
				continue;
			}

			$result = 'update' === $prepared['action']
				? $this->location_service->update( $prepared['existing_id'], $prepared['input'] )
				: $this->location_service->create( $prepared['input'] );
			if ( is_wp_error( $result ) ) {
				$failures[] = $this->row_issue( $row_number, $result );
			} elseif ( 'update' === $prepared['action'] ) {
				++$updated;
			} else {
				++$created;
			}
			++$processed;
		}

		$next_offset = $offset + $processed;
		$done        = $next_offset >= (int) $session['row_count'];
		if ( $done ) {
			wp_delete_file( $session['path'] );
			delete_transient( $this->transient_key( $token ) );
		} else {
			$this->save_session( $token, $session );
		}

		return array(
			'processed'   => $processed,
			'created'     => $created,
			'updated'     => $updated,
			'failed'      => count( $failures ),
			'failures'    => $failures,
			'next_offset' => $next_offset,
			'total'       => (int) $session['row_count'],
			'done'        => $done,
		);
	}

	/**
	 * Export one Location into canonical CSV column values.
	 *
	 * @param array<string,mixed> $data Location domain data.
	 * @return array<string,mixed>
	 */
	public static function export_row( $data ) {
		$address     = isset( $data['address'] ) && is_array( $data['address'] ) ? $data['address'] : array();
		$contact     = isset( $data['contact'] ) && is_array( $data['contact'] ) ? $data['contact'] : array();
		$operational = isset( $data['operational'] ) && is_array( $data['operational'] ) ? $data['operational'] : array();
		$marker      = isset( $data['marker'] ) && is_array( $data['marker'] ) ? $data['marker'] : array();
		$type_ids    = isset( $data['type_ids'] ) && is_array( $data['type_ids'] ) ? array_map( 'absint', $data['type_ids'] ) : array();
		$group_ids   = isset( $data['group_ids'] ) && is_array( $data['group_ids'] ) ? array_map( 'absint', $data['group_ids'] ) : array();

		$type_slugs = array();
		foreach ( $type_ids as $term_id ) {
			$term = get_term( $term_id, Taxonomies::TYPE );
			if ( $term && ! is_wp_error( $term ) ) {
				$type_slugs[] = $term->slug;
			}
		}
		$primary_type_slug = '';
		if ( ! empty( $data['primary_type_id'] ) ) {
			$term = get_term( absint( $data['primary_type_id'] ), Taxonomies::TYPE );
			if ( $term && ! is_wp_error( $term ) ) {
				$primary_type_slug = $term->slug;
			}
		}
		$group_paths = array();
		foreach ( $group_ids as $term_id ) {
			$path = self::group_path( $term_id );
			if ( '' !== $path ) {
				$group_paths[] = $path;
			}
		}

		return array(
			'external_id'        => isset( $data['external_id'] ) ? $data['external_id'] : '',
			'name'               => isset( $data['name'] ) ? $data['name'] : '',
			'status'             => isset( $data['status'] ) ? $data['status'] : 'draft',
			'description'        => isset( $data['description'] ) ? $data['description'] : '',
			'menu_order'         => isset( $data['menu_order'] ) ? (int) $data['menu_order'] : 0,
			'featured_image_id'  => isset( $data['featured_image_id'] ) ? (int) $data['featured_image_id'] : 0,
			'address_line_1'     => isset( $address['line_1'] ) ? $address['line_1'] : '',
			'address_line_2'     => isset( $address['line_2'] ) ? $address['line_2'] : '',
			'city'               => isset( $address['city'] ) ? $address['city'] : '',
			'region'             => isset( $address['region'] ) ? $address['region'] : '',
			'postal_code'        => isset( $address['postal_code'] ) ? $address['postal_code'] : '',
			'country_code'       => isset( $address['country_code'] ) ? $address['country_code'] : '',
			'display_address'    => isset( $address['display_address'] ) ? $address['display_address'] : '',
			'latitude'           => isset( $address['latitude'] ) && null !== $address['latitude'] ? $address['latitude'] : '',
			'longitude'          => isset( $address['longitude'] ) && null !== $address['longitude'] ? $address['longitude'] : '',
			'timezone'           => isset( $address['timezone'] ) ? $address['timezone'] : '',
			'phone'              => isset( $contact['phone'] ) ? $contact['phone'] : '',
			'email'              => isset( $contact['email'] ) ? $contact['email'] : '',
			'website'            => isset( $contact['website'] ) ? $contact['website'] : '',
			'contact_name'       => isset( $contact['contact_name'] ) ? $contact['contact_name'] : '',
			'directions_url'     => isset( $contact['directions_url'] ) ? $contact['directions_url'] : '',
			'weekly_hours_json'  => wp_json_encode( isset( $data['weekly_hours'] ) ? $data['weekly_hours'] : array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'special_hours_json' => wp_json_encode( isset( $data['special_hours'] ) ? $data['special_hours'] : array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'operational_status' => isset( $operational['status'] ) ? $operational['status'] : 'normal',
			'status_label'       => isset( $operational['label'] ) ? $operational['label'] : '',
			'status_note'        => isset( $operational['note'] ) ? $operational['note'] : '',
			'type_slugs'         => implode( '|', $type_slugs ),
			'primary_type_slug'  => $primary_type_slug,
			'group_paths'        => implode( '|', $group_paths ),
			'marker_override'    => ! empty( $marker['override'] ) ? '1' : '0',
			'marker_icon'        => isset( $marker['icon'] ) ? $marker['icon'] : 'pin',
			'marker_media_id'    => isset( $marker['media_id'] ) ? (int) $marker['media_id'] : 0,
			'marker_color'       => isset( $marker['color'] ) ? $marker['color'] : '',
			'marker_icon_color'  => isset( $marker['icon_color'] ) ? $marker['icon_color'] : '',
			'marker_size'        => isset( $marker['size'] ) ? $marker['size'] : 'medium',
			'extra_fields_json'  => wp_json_encode( isset( $data['extra_fields'] ) ? $data['extra_fields'] : array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
		);
	}

	/**
	 * Protect a CSV cell from spreadsheet formula execution.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function protect_csv_cell( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/', $value ) ) {
			return $value;
		}
		if ( preg_match( '/^[\x00-\x20]*[=+\-@]/', $value ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Encode one CSV row using RFC 4180-style quoting.
	 *
	 * @param array<int,mixed> $values Ordered CSV values.
	 * @return string
	 */
	public static function csv_line( $values ) {
		$encoded = array();
		foreach ( $values as $value ) {
			$value = is_scalar( $value ) ? (string) $value : '';
			if ( false !== strpbrk( $value, ",\"\r\n" ) ) {
				$value = '"' . str_replace( '"', '""', $value ) . '"';
			}
			$encoded[] = $value;
		}
		return implode( ',', $encoded ) . "\r\n";
	}

	/**
	 * Open a staged CSV for streaming reads.
	 *
	 * SplFileObject keeps large imports incremental without bypassing WordPress'
	 * upload handling or requiring the full file to be loaded into memory.
	 *
	 * @param string $path      Staged CSV path.
	 * @param string $delimiter CSV delimiter.
	 * @return \SplFileObject|\WP_Error
	 */
	private function open_csv_reader( $path, $delimiter ) {
		try {
			$file = new \SplFileObject( $path, 'r' );
		} catch ( \RuntimeException $exception ) {
			return new \WP_Error( 'velomalo_import_session_file', __( 'The staged CSV file is no longer available. Please upload it again.', 'velox-map-locator' ), array( 'status' => 410 ) );
		}

		$file->setFlags( \SplFileObject::DROP_NEW_LINE );
		$file->setCsvControl( $delimiter, '"', '' );
		return $file;
	}

	/** Inspect CSV structure. */
	private function inspect_file( $path ) {
		try {
			$file = new \SplFileObject( $path, 'r' );
		} catch ( \RuntimeException $exception ) {
			return new \WP_Error( 'velomalo_import_read_failed', __( 'The uploaded CSV could not be opened.', 'velox-map-locator' ), array( 'status' => 400 ) );
		}

		$first_line = $file->fgets();
		if ( false === $first_line || '' === $first_line ) {
			return new \WP_Error( 'velomalo_import_empty', __( 'The uploaded CSV does not contain a header row.', 'velox-map-locator' ), array( 'status' => 400 ) );
		}

		$delimiter = $this->detect_delimiter( $first_line );
		$file->rewind();
		$file->setCsvControl( $delimiter, '"', '' );
		$headers = $file->fgetcsv();
		if ( ! is_array( $headers ) || count( $headers ) < 1 ) {
			return new \WP_Error( 'velomalo_import_headers', __( 'The CSV header row is not valid.', 'velox-map-locator' ), array( 'status' => 400 ) );
		}
		$headers[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $headers[0] );
		$clean_headers = array();
		$seen = array();
		foreach ( $headers as $header ) {
			$header = trim( sanitize_text_field( (string) $header ) );
			if ( '' === $header ) {
				$header = sprintf( 'Column %d', count( $clean_headers ) + 1 );
			}
			$key = strtolower( $header );
			if ( isset( $seen[ $key ] ) ) {
				/* translators: %s: Duplicate CSV column header. */
				return new \WP_Error( 'velomalo_import_duplicate_header', sprintf( __( 'The CSV contains the duplicate header "%s".', 'velox-map-locator' ), $header ), array( 'status' => 400 ) );
			}
			$seen[ $key ] = true;
			$clean_headers[] = $header;
		}

		$row_count = 0;
		$sample = array();
		$max_rows = (int) apply_filters( 'velox_map_locator_import_max_rows', 50000 );
		while ( ! $file->eof() ) {
			$cells = $file->fgetcsv();
			if ( false === $cells ) {
				break;
			}
			if ( $this->is_blank_row( $cells ) ) {
				continue;
			}
			++$row_count;
			if ( $max_rows > 0 && $row_count > $max_rows ) {
				return new \WP_Error( 'velomalo_import_row_limit', __( 'The CSV exceeds the import safety row limit. Split it into smaller files and import them sequentially.', 'velox-map-locator' ), array( 'status' => 400 ) );
			}
			if ( count( $sample ) < 5 ) {
				$sample[] = $this->associate_row( $clean_headers, $cells );
			}
		}
		if ( 0 === $row_count ) {
			return new \WP_Error( 'velomalo_import_no_rows', __( 'The CSV does not contain any Location rows.', 'velox-map-locator' ), array( 'status' => 400 ) );
		}
		return array( 'delimiter' => $delimiter, 'headers' => $clean_headers, 'row_count' => $row_count, 'sample' => $sample );
	}

	/** Suggest mappings using normalized aliases. */
	private function suggest_mapping( $headers ) {
		$aliases = array();
		foreach ( array_keys( self::columns() ) as $column ) {
			$aliases[ $this->normalize_header_key( $column ) ] = $column;
		}
		$aliases['locationname'] = 'name';
		$aliases['title'] = 'name';
		$aliases['externalid'] = 'external_id';
		$aliases['address1'] = 'address_line_1';
		$aliases['address2'] = 'address_line_2';
		$aliases['state'] = 'region';
		$aliases['province'] = 'region';
		$aliases['zip'] = 'postal_code';
		$aliases['postcode'] = 'postal_code';
		$aliases['country'] = 'country_code';
		$aliases['lat'] = 'latitude';
		$aliases['lng'] = 'longitude';
		$aliases['lon'] = 'longitude';
		$aliases['types'] = 'type_slugs';
		$aliases['groups'] = 'group_paths';
		$mapping = array();
		foreach ( $headers as $header ) {
			$key = $this->normalize_header_key( $header );
			$mapping[ $header ] = isset( $aliases[ $key ] ) ? $aliases[ $key ] : '';
		}
		return $mapping;
	}

	/** Normalize and validate source-to-destination mapping. */
	private function normalize_mapping( $mapping, $headers ) {
		if ( ! is_array( $mapping ) ) {
			return new \WP_Error( 'velomalo_import_mapping', __( 'Choose how the CSV columns map to Location fields.', 'velox-map-locator' ), array( 'status' => 400 ) );
		}
		$allowed_headers = array_fill_keys( $headers, true );
		$allowed_columns = array_fill_keys( array_keys( self::columns() ), true );
		$output = array();
		$used = array();
		foreach ( $mapping as $source => $target ) {
			$source = sanitize_text_field( (string) $source );
			$target = sanitize_key( (string) $target );
			if ( '' === $target ) {
				continue;
			}
			if ( ! isset( $allowed_headers[ $source ] ) || ! isset( $allowed_columns[ $target ] ) ) {
				return new \WP_Error( 'velomalo_import_mapping_field', __( 'The CSV mapping contains an unsupported column.', 'velox-map-locator' ), array( 'status' => 400 ) );
			}
			if ( isset( $used[ $target ] ) ) {
				/* translators: %s: Canonical import field label. */
				return new \WP_Error( 'velomalo_import_mapping_duplicate', sprintf( __( 'Only one source column can map to "%s".', 'velox-map-locator' ), self::columns()[ $target ] ), array( 'status' => 400 ) );
			}
			$output[ $source ] = $target;
			$used[ $target ] = true;
		}
		if ( empty( $output ) ) {
			return new \WP_Error( 'velomalo_import_mapping_empty', __( 'Map at least one CSV column before validating the import.', 'velox-map-locator' ), array( 'status' => 400 ) );
		}
		return $output;
	}

	/** Prepare one CSV row for validation or commit. */
	private function prepare_row( $row, $mapping, $mode, $create_terms, $commit ) {
		$values = array();
		foreach ( $mapping as $source => $target ) {
			$values[ $target ] = $this->unprotect_csv_cell( isset( $row[ $source ] ) ? $row[ $source ] : '' );
		}

		$input = array();
		foreach ( array( 'name', 'description', 'external_id' ) as $field ) {
			if ( array_key_exists( $field, $values ) ) {
				$input[ $field ] = $values[ $field ];
			}
		}
		if ( array_key_exists( 'status', $values ) && '' !== trim( $values['status'] ) ) {
			$input['status'] = strtolower( trim( $values['status'] ) );
		}
		if ( array_key_exists( 'menu_order', $values ) ) {
			$input['menu_order'] = '' === trim( $values['menu_order'] ) ? 0 : absint( $values['menu_order'] );
		}
		if ( array_key_exists( 'featured_image_id', $values ) ) {
			$input['featured_image_id'] = '' === trim( $values['featured_image_id'] ) ? 0 : absint( $values['featured_image_id'] );
		}

		$address_map = array(
			'address_line_1' => 'line_1', 'address_line_2' => 'line_2', 'city' => 'city', 'region' => 'region', 'postal_code' => 'postal_code',
			'country_code' => 'country_code', 'display_address' => 'display_address', 'latitude' => 'latitude', 'longitude' => 'longitude', 'timezone' => 'timezone',
		);
		$address = array();
		foreach ( $address_map as $source => $target ) {
			if ( array_key_exists( $source, $values ) ) {
				$address[ $target ] = $values[ $source ];
			}
		}
		if ( ! empty( $address ) ) {
			$input['address'] = $address;
		}

		$contact_map = array( 'phone' => 'phone', 'email' => 'email', 'website' => 'website', 'contact_name' => 'contact_name', 'directions_url' => 'directions_url' );
		$contact = array();
		foreach ( $contact_map as $source => $target ) {
			if ( array_key_exists( $source, $values ) ) {
				$contact[ $target ] = $values[ $source ];
			}
		}
		if ( ! empty( $contact ) ) {
			$input['contact'] = $contact;
		}

		foreach ( array( 'weekly_hours_json' => 'weekly_hours', 'special_hours_json' => 'special_hours', 'extra_fields_json' => 'extra_fields' ) as $source => $target ) {
			if ( ! array_key_exists( $source, $values ) ) {
				continue;
			}
			$decoded = $this->decode_json_cell( $values[ $source ], $source );
			if ( is_wp_error( $decoded ) ) {
				return $decoded;
			}
			$input[ $target ] = $decoded;
		}

		$operational = array();
		if ( array_key_exists( 'operational_status', $values ) && '' !== trim( $values['operational_status'] ) ) {
			$operational['status'] = strtolower( trim( $values['operational_status'] ) );
		}
		if ( array_key_exists( 'status_label', $values ) ) {
			$operational['label'] = $values['status_label'];
		}
		if ( array_key_exists( 'status_note', $values ) ) {
			$operational['note'] = $values['status_note'];
		}
		if ( ! empty( $operational ) ) {
			$input['operational'] = $operational;
		}

		$marker = array();
		if ( array_key_exists( 'marker_override', $values ) ) {
			$marker['override'] = $this->to_bool( $values['marker_override'] );
		}
		if ( array_key_exists( 'marker_icon', $values ) && '' !== trim( $values['marker_icon'] ) ) {
			$marker['icon'] = $values['marker_icon'];
		}
		if ( array_key_exists( 'marker_media_id', $values ) ) {
			$marker['media_id'] = '' === trim( $values['marker_media_id'] ) ? 0 : absint( $values['marker_media_id'] );
		}
		if ( array_key_exists( 'marker_color', $values ) ) {
			$marker['color'] = $values['marker_color'];
		}
		if ( array_key_exists( 'marker_icon_color', $values ) ) {
			$marker['icon_color'] = $values['marker_icon_color'];
		}
		if ( array_key_exists( 'marker_size', $values ) && '' !== trim( $values['marker_size'] ) ) {
			$marker['size'] = $values['marker_size'];
		}
		if ( ! empty( $marker ) ) {
			$input['marker'] = $marker;
		}

		$external_id = isset( $input['external_id'] ) ? trim( (string) $input['external_id'] ) : '';
		$existing = '' !== $external_id ? $this->repository->find_by_external_id( $external_id ) : null;
		$action = 'create';
		if ( $existing && 'upsert' === $mode ) {
			$action = 'update';
		} elseif ( $existing && 'create' === $mode ) {
			/* translators: %s: External ID value. */
			return new \WP_Error( 'velomalo_import_external_id_exists', sprintf( __( 'External ID "%s" already belongs to an existing Location. Use Update or Create mode to update it.', 'velox-map-locator' ), $external_id ), array( 'status' => 409, 'field' => 'external_id' ) );
		}

		if ( 'update' === $action && ! current_user_can( 'edit_post', $existing->get_id() ) ) {
			return new \WP_Error( 'velomalo_import_update_forbidden', __( 'You do not have permission to update the matched Location.', 'velox-map-locator' ), array( 'status' => 403 ) );
		}
		if ( 'create' === $action && ! current_user_can( 'create_velomalo_locations' ) ) {
			return new \WP_Error( 'velomalo_import_create_forbidden', __( 'You do not have permission to create Locations.', 'velox-map-locator' ), array( 'status' => 403 ) );
		}
		if ( isset( $input['status'] ) && 'publish' === $input['status'] && ! current_user_can( 'publish_velomalo_locations' ) ) {
			return new \WP_Error( 'velomalo_import_publish_forbidden', __( 'You do not have permission to publish Locations.', 'velox-map-locator' ), array( 'status' => 403, 'field' => 'status' ) );
		}

		$warnings = array();
		$taxonomy = $this->prepare_taxonomies( $values, $create_terms, $commit, $warnings );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}
		$input = array_merge( $input, $taxonomy );

		$base = $existing ? $existing->to_array() : array();
		$normalized = $this->validator->normalize( $input, $base );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		return array(
			'action'      => $action,
			'existing_id' => $existing ? $existing->get_id() : 0,
			'input'       => $input,
			'normalized'  => $normalized,
			'warnings'    => $warnings,
		);
	}

	/** Resolve Type/Group CSV columns. */
	private function prepare_taxonomies( $values, $create_terms, $commit, &$warnings ) {
		$output = array();
		$type_slugs = null;
		if ( array_key_exists( 'type_slugs', $values ) ) {
			$type_slugs = $this->split_list( $values['type_slugs'] );
			$type_ids = array();
			foreach ( $type_slugs as $slug ) {
				$term_id = $this->resolve_term_slug( Taxonomies::TYPE, $slug, 0, $create_terms, $commit, $warnings );
				if ( is_wp_error( $term_id ) ) {
					return $term_id;
				}
				if ( $term_id ) {
					$type_ids[] = $term_id;
				}
			}
			$output['type_ids'] = array_values( array_unique( $type_ids ) );
		}
		if ( array_key_exists( 'primary_type_slug', $values ) ) {
			$primary_slug = sanitize_title( trim( $values['primary_type_slug'] ) );
			if ( '' === $primary_slug ) {
				$output['primary_type_id'] = 0;
			} else {
				if ( null === $type_slugs ) {
					return new \WP_Error( 'velomalo_import_primary_type_mapping', __( 'Map Type slugs when importing a Primary Type slug.', 'velox-map-locator' ), array( 'status' => 400, 'field' => 'primary_type_slug' ) );
				}
				if ( ! in_array( $primary_slug, array_map( 'sanitize_title', $type_slugs ), true ) ) {
					return new \WP_Error( 'velomalo_import_primary_type_membership', __( 'The Primary Type slug must also appear in the Type slugs column.', 'velox-map-locator' ), array( 'status' => 400, 'field' => 'primary_type_slug' ) );
				}
				$term_id = $this->resolve_term_slug( Taxonomies::TYPE, $primary_slug, 0, $create_terms, $commit, $warnings );
				if ( is_wp_error( $term_id ) ) {
					return $term_id;
				}
				if ( $term_id ) {
					$output['primary_type_id'] = $term_id;
				}
			}
		}

		if ( array_key_exists( 'group_paths', $values ) ) {
			$group_ids = array();
			foreach ( $this->split_list( $values['group_paths'] ) as $path ) {
				$parent = 0;
				$segments = array_values( array_filter( array_map( 'sanitize_title', explode( '/', $path ) ) ) );
				if ( empty( $segments ) ) {
					continue;
				}
				foreach ( $segments as $segment ) {
					$term_id = $this->resolve_term_slug( Taxonomies::GROUP, $segment, $parent, $create_terms, $commit, $warnings );
					if ( is_wp_error( $term_id ) ) {
					return $term_id;
				}
					if ( $term_id ) {
						$parent = $term_id;
					}
				}
				if ( $parent ) {
					$group_ids[] = $parent;
				}
			}
			$output['group_ids'] = array_values( array_unique( $group_ids ) );
		}
		return $output;
	}

	/** Resolve/create one taxonomy slug. */
	private function resolve_term_slug( $taxonomy, $slug, $parent, $create_terms, $commit, &$warnings ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return 0;
		}
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			if ( Taxonomies::GROUP === $taxonomy && (int) $term->parent !== (int) $parent ) {
				/* translators: %s: Group slug. */
				return new \WP_Error( 'velomalo_import_group_hierarchy', sprintf( __( 'Group slug "%s" already exists under a different parent.', 'velox-map-locator' ), $slug ), array( 'status' => 409, 'field' => 'group_paths' ) );
			}
			return (int) $term->term_id;
		}
		if ( ! $create_terms ) {
			/* translators: %s: Missing Type or Group slug. */
			return new \WP_Error( 'velomalo_import_missing_term', sprintf( __( 'Classification slug "%s" does not exist. Enable creation of missing Types/Groups or create it first.', 'velox-map-locator' ), $slug ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'manage_velomalo_terms' ) ) {
			return new \WP_Error( 'velomalo_import_term_forbidden', __( 'You do not have permission to create missing Types or Groups.', 'velox-map-locator' ), array( 'status' => 403 ) );
		}
		if ( ! $commit ) {
			/* translators: %s: Type or Group slug that will be created. */
			$warnings[] = sprintf( __( 'Classification "%s" will be created during import.', 'velox-map-locator' ), $slug );
			return 0;
		}
		$created = $this->term_service->create( $taxonomy, array( 'name' => $this->slug_to_name( $slug ), 'slug' => $slug, 'parent' => $parent ) );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		return isset( $created['id'] ) ? (int) $created['id'] : 0;
	}

	/** Decode one JSON CSV cell. */
	private function decode_json_cell( $value, $field ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return array();
		}
		$decoded = json_decode( $value, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			/* translators: %s: CSV field label. */
			return new \WP_Error( 'velomalo_import_invalid_json', sprintf( __( 'The %s cell must contain valid JSON.', 'velox-map-locator' ), self::columns()[ $field ] ), array( 'status' => 400, 'field' => $field ) );
		}
		return $decoded;
	}

	/** Build a Group slug path. */
	private static function group_path( $term_id ) {
		$term = get_term( absint( $term_id ), Taxonomies::GROUP );
		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}
		$segments = array( $term->slug );
		$parent = (int) $term->parent;
		$guard = 0;
		while ( $parent && $guard++ < 50 ) {
			$ancestor = get_term( $parent, Taxonomies::GROUP );
			if ( ! $ancestor || is_wp_error( $ancestor ) ) {
				break;
			}
			array_unshift( $segments, $ancestor->slug );
			$parent = (int) $ancestor->parent;
		}
		return implode( '/', $segments );
	}

	/** Detect common CSV delimiters. */
	private function detect_delimiter( $line ) {
		$best = ',';
		$best_count = 0;
		foreach ( array( ',', ';', "\t" ) as $candidate ) {
			$count = count( str_getcsv( $line, $candidate ) );
			if ( $count > $best_count ) {
				$best = $candidate;
				$best_count = $count;
			}
		}
		return $best;
	}

	/** Associate CSV cells with headers. */
	private function associate_row( $headers, $cells ) {
		$row = array();
		foreach ( $headers as $index => $header ) {
			$row[ $header ] = isset( $cells[ $index ] ) ? (string) $cells[ $index ] : '';
		}
		return $row;
	}

	/** Whether a parsed CSV row contains no values. */
	private function is_blank_row( $cells ) {
		if ( ! is_array( $cells ) ) {
			return true;
		}
		foreach ( $cells as $cell ) {
			if ( '' !== trim( (string) $cell ) ) {
				return false;
			}
		}
		return true;
	}

	/** Split pipe-delimited taxonomy values. */
	private function split_list( $value ) {
		return array_values( array_filter( array_map( 'trim', explode( '|', (string) $value ) ), 'strlen' ) );
	}

	/** Convert common CSV booleans. */
	private function to_bool( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'y', 'on' ), true );
	}

	/** Undo the apostrophe added by protect_csv_cell for round-trip imports. */
	private function unprotect_csv_cell( $value ) {
		$value = (string) $value;
		return 1 === preg_match( "/^'[\\x00-\\x20]*[=+\\-@]/", $value ) ? substr( $value, 1 ) : $value;
	}

	/** Convert a slug into a readable default term name. */
	private function slug_to_name( $slug ) {
		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}

	/** Normalize header for aliases. */
	private function normalize_header_key( $header ) {
		return preg_replace( '/[^a-z0-9]+/', '', strtolower( (string) $header ) );
	}

	/** Convert WP_Error to a row issue structure. */
	private function row_issue( $row, $error ) {
		$data = $error->get_error_data();
		return array(
			'row'     => (int) $row,
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
			'field'   => is_array( $data ) && isset( $data['field'] ) ? (string) $data['field'] : '',
		);
	}


	/**
	 * Route WordPress' upload handler to the non-public system temporary directory.
	 *
	 * This filter is attached only around the single CSV staging call and removed
	 * immediately afterwards.
	 *
	 * @param array<string,mixed> $uploads WordPress upload-directory data.
	 * @return array<string,mixed>
	 */
	public function filter_import_upload_dir( $uploads ) {
		$temp_dir = untrailingslashit( get_temp_dir() );
		if ( '' === $temp_dir || ! is_dir( $temp_dir ) ) {
			$uploads['error'] = __( 'The server temporary directory is not available.', 'velox-map-locator' );
			return $uploads;
		}

		$uploads['path']    = $temp_dir;
		$uploads['basedir'] = $temp_dir;
		$uploads['url']     = '';
		$uploads['baseurl'] = '';
		$uploads['subdir']  = '';
		$uploads['error']   = false;
		return $uploads;
	}

	/** Remove abandoned Velox import temp files older than the session window. */
	private function cleanup_stale_files() {
		$temp_dir = get_temp_dir();
		if ( ! is_dir( $temp_dir ) ) {
			return;
		}
		$paths = glob( trailingslashit( $temp_dir ) . 'vml-import-*' );
		if ( ! is_array( $paths ) ) {
			return;
		}
		$cutoff = time() - ( 2 * self::SESSION_TTL );
		foreach ( $paths as $path ) {
			if ( is_file( $path ) && filemtime( $path ) < $cutoff ) {
				wp_delete_file( $path );
			}
		}
	}

	/** Create a cryptographically strong session token when possible. */
	private function new_token() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $exception ) {
			return strtolower( wp_generate_password( 32, false, false ) );
		}
	}

	/** Session transient key. */
	private function transient_key( $token ) {
		return 'velomalo_import_' . get_current_user_id() . '_' . sanitize_key( $token );
	}

	/** Save import session. */
	private function save_session( $token, $session ) {
		set_transient( $this->transient_key( $token ), $session, self::SESSION_TTL );
	}

	/** Load and verify session ownership/path. */
	private function get_session( $token ) {
		$token = sanitize_key( $token );
		if ( ! preg_match( '/^[a-z0-9]{20,64}$/', $token ) ) {
			return new \WP_Error( 'velomalo_import_session', __( 'The import session is not valid.', 'velox-map-locator' ), array( 'status' => 400 ) );
		}
		$session = get_transient( $this->transient_key( $token ) );
		if ( ! is_array( $session ) || (int) $session['user_id'] !== get_current_user_id() ) {
			return new \WP_Error( 'velomalo_import_session_expired', __( 'The import session expired. Upload the CSV again.', 'velox-map-locator' ), array( 'status' => 410 ) );
		}
		if ( empty( $session['path'] ) || ! is_readable( $session['path'] ) ) {
			return new \WP_Error( 'velomalo_import_session_file', __( 'The staged CSV file is no longer available. Please upload it again.', 'velox-map-locator' ), array( 'status' => 410 ) );
		}
		$this->save_session( $token, $session );
		return $session;
	}

	/** Hash the exact validated import choices. */
	private function validation_hash( $mapping, $mode, $create_terms ) {
		return hash( 'sha256', wp_json_encode( array( $mapping, $mode, (bool) $create_terms ) ) );
	}
}
