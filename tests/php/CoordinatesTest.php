<?php

use PHPUnit\Framework\TestCase;
use VeloxPlugins\VeloxMapLocator\Domain\Coordinates;

final class CoordinatesTest extends TestCase {

	public function test_accepts_valid_boundary_coordinates() {
		$coordinates = Coordinates::from_values( -90, 180 );

		$this->assertInstanceOf( Coordinates::class, $coordinates );
		$this->assertSame(
			array(
				'latitude'  => -90.0,
				'longitude' => 180.0,
			),
			$coordinates->to_array()
		);
	}

	public function test_rejects_out_of_range_coordinates() {
		$this->assertNull( Coordinates::from_values( -90.01, 0 ) );
		$this->assertNull( Coordinates::from_values( 0, 180.01 ) );
		$this->assertNull( Coordinates::from_values( 'not-a-number', 0 ) );
	}
}
