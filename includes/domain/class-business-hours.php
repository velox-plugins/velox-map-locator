<?php
/**
 * Business-hours schema helpers.
 *
 * @package VeloxMapLocator
 */

namespace VeloxPlugins\VeloxMapLocator\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and normalizes weekly and special opening hours.
 */
final class Business_Hours {

	/**
	 * Maximum opening intervals permitted for one day.
	 */
	const MAX_INTERVALS_PER_DAY = 4;

	/**
	 * Maximum special-date overrides accepted in one Location payload.
	 */
	const MAX_SPECIAL_DATES = 500;

	/**
	 * Days in canonical order.
	 *
	 * @var string[]
	 */
	const DAYS = array(
		'monday',
		'tuesday',
		'wednesday',
		'thursday',
		'friday',
		'saturday',
		'sunday',
	);

	/**
	 * Normalize weekly hours.
	 *
	 * @param mixed $value Submitted hours.
	 * @return array<string,mixed>|null
	 */
	public static function normalize_weekly( $value ) {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$output = array();

		foreach ( self::DAYS as $day ) {
			$day_value  = isset( $value[ $day ] ) ? $value[ $day ] : array( 'closed' => true );
			$normalized = self::normalize_day( $day_value );

			if ( null === $normalized ) {
				return null;
			}

			$output[ $day ] = $normalized;
		}

		return $output;
	}

	/**
	 * Normalize special opening hours.
	 *
	 * @param mixed $value Submitted special hours.
	 * @return array<int,array<string,mixed>>|null
	 */
	public static function normalize_special( $value ) {
		if ( ! is_array( $value ) || count( $value ) > self::MAX_SPECIAL_DATES ) {
			return null;
		}

		$output     = array();
		$seen_dates = array();

		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['date'] ) || ! self::is_valid_date( $entry['date'] ) ) {
				return null;
			}

			$date = (string) $entry['date'];

			if ( isset( $seen_dates[ $date ] ) ) {
				return null;
			}

			$seen_dates[ $date ] = true;
			$day_value            = array(
				'closed'    => array_key_exists( 'closed', $entry ) ? $entry['closed'] : false,
				'all_day'   => array_key_exists( 'all_day', $entry ) ? $entry['all_day'] : false,
				'intervals' => isset( $entry['intervals'] ) ? $entry['intervals'] : array(),
			);
			$normalized           = self::normalize_day( $day_value );

			if ( null === $normalized ) {
				return null;
			}

			$normalized['date']  = $date;
			$normalized['label'] = isset( $entry['label'] ) && is_scalar( $entry['label'] ) ? trim( (string) $entry['label'] ) : '';
			$output[]            = $normalized;
		}

		usort(
			$output,
			static function ( $first, $second ) {
				return strcmp( $first['date'], $second['date'] );
			}
		);

		return $output;
	}

	/**
	 * Normalize a single day's hours.
	 *
	 * @param mixed $value Day value.
	 * @return array<string,mixed>|null
	 */
	private static function normalize_day( $value ) {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$closed  = self::normalize_boolean( array_key_exists( 'closed', $value ) ? $value['closed'] : false );
		$all_day = self::normalize_boolean( array_key_exists( 'all_day', $value ) ? $value['all_day'] : false );

		if ( null === $closed || null === $all_day ) {
			return null;
		}

		if ( $closed ) {
			return array(
				'closed'    => true,
				'all_day'   => false,
				'intervals' => array(),
			);
		}

		if ( $all_day ) {
			return array(
				'closed'    => false,
				'all_day'   => true,
				'intervals' => array(),
			);
		}

		$intervals = isset( $value['intervals'] ) ? $value['intervals'] : array();

		if ( ! is_array( $intervals ) || empty( $intervals ) || count( $intervals ) > self::MAX_INTERVALS_PER_DAY ) {
			return null;
		}

		$normalized_intervals = array();

		foreach ( $intervals as $interval ) {
			if ( ! is_array( $interval ) || ! isset( $interval['open'], $interval['close'] ) ) {
				return null;
			}

			$open  = is_scalar( $interval['open'] ) ? (string) $interval['open'] : '';
			$close = is_scalar( $interval['close'] ) ? (string) $interval['close'] : '';

			if ( ! self::is_valid_time( $open ) || ! self::is_valid_time( $close ) || $open === $close ) {
				return null;
			}

			$normalized_intervals[] = array(
				'open'  => $open,
				'close' => $close,
			);
		}

		usort(
			$normalized_intervals,
			static function ( $first, $second ) {
				return strcmp( $first['open'], $second['open'] );
			}
		);

		if ( self::intervals_overlap( $normalized_intervals ) ) {
			return null;
		}

		return array(
			'closed'    => false,
			'all_day'   => false,
			'intervals' => $normalized_intervals,
		);
	}

	/**
	 * Determine whether normalized intervals overlap when anchored to one day.
	 *
	 * Overnight intervals are represented as ending on the following day.
	 *
	 * @param array<int,array<string,string>> $intervals Normalized intervals.
	 * @return bool
	 */
	private static function intervals_overlap( $intervals ) {
		$previous_end = null;

		foreach ( $intervals as $interval ) {
			$start = self::time_to_minutes( $interval['open'] );
			$end   = self::time_to_minutes( $interval['close'] );

			if ( $end <= $start ) {
				$end += 24 * 60;
			}

			if ( null !== $previous_end && $start < $previous_end ) {
				return true;
			}

			$previous_end = $end;
		}

		return false;
	}

	/**
	 * Convert HH:MM to minutes after midnight.
	 *
	 * @param string $value Time value.
	 * @return int
	 */
	private static function time_to_minutes( $value ) {
		$parts = array_map( 'intval', explode( ':', $value ) );
		return ( $parts[0] * 60 ) + $parts[1];
	}

	/**
	 * Normalize an accepted boolean representation.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool|null
	 */
	private static function normalize_boolean( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( 1 === $value || 1.0 === $value ) {
			return true;
		}

		if ( 0 === $value || 0.0 === $value ) {
			return false;
		}

		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			if ( in_array( $value, array( '1', 'true' ), true ) ) {
				return true;
			}
			if ( in_array( $value, array( '', '0', 'false' ), true ) ) {
				return false;
			}
		}

		return null;
	}

	/**
	 * Validate a 24-hour HH:MM value.
	 *
	 * @param string $value Time value.
	 * @return bool
	 */
	private static function is_valid_time( $value ) {
		return 1 === preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $value );
	}

	/**
	 * Validate a YYYY-MM-DD calendar date.
	 *
	 * @param mixed $value Date value.
	 * @return bool
	 */
	private static function is_valid_date( $value ) {
		if ( ! is_scalar( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $value, $matches ) ) {
			return false;
		}

		return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );
	}
}
