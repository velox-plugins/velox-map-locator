<?php

use PHPUnit\Framework\TestCase;
use VeloxPlugins\VeloxMapLocator\Domain\Business_Hours;

final class BusinessHoursTest extends TestCase {

	public function test_normalizes_a_closed_week() {
		$week = array();
		foreach ( Business_Hours::DAYS as $day ) {
			$week[ $day ] = array( 'closed' => true );
		}

		$result = Business_Hours::normalize_weekly( $week );

		$this->assertIsArray( $result );
		$this->assertCount( 7, $result );
		$this->assertTrue( $result['monday']['closed'] );
		$this->assertSame( array(), $result['monday']['intervals'] );
	}

	public function test_rejects_duplicate_special_dates() {
		$result = Business_Hours::normalize_special(
			array(
				array( 'date' => '2026-09-19', 'closed' => true ),
				array( 'date' => '2026-09-19', 'closed' => true ),
			)
		);

		$this->assertNull( $result );
	}
}
