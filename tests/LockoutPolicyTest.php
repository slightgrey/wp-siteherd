<?php

use PHPUnit\Framework\TestCase;

final class LockoutPolicyTest extends TestCase {

	public function test_auto_trusts_cloudflare_header_only_from_cloudflare() {
		$cf = array(
			'REMOTE_ADDR'           => '172.68.1.2',
			'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
		);
		$this->assertSame( '203.0.113.9', Siteherd_Lockout_Policy::client_ip( $cf, 'auto' ) );

		$spoofed = array(
			'REMOTE_ADDR'           => '198.51.100.7',
			'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
		);
		$this->assertSame( '198.51.100.7', Siteherd_Lockout_Policy::client_ip( $spoofed, 'auto' ) );
	}

	public function test_auto_handles_ipv6_cloudflare() {
		$server = array(
			'REMOTE_ADDR'           => '2606:4700:10::ac43:1',
			'HTTP_CF_CONNECTING_IP' => '2001:db8::1',
		);
		$this->assertSame( '2001:db8::1', Siteherd_Lockout_Policy::client_ip( $server, 'auto' ) );
	}

	public function test_forwarded_for_uses_the_entry_the_proxy_added() {
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.2',
			'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.9',
		);
		$this->assertSame( '203.0.113.9', Siteherd_Lockout_Policy::client_ip( $server, 'x-forwarded-for' ) );
		$this->assertSame( '10.0.0.2', Siteherd_Lockout_Policy::client_ip( $server, 'none' ) );
	}

	public function test_invalid_headers_fall_back_to_remote_addr() {
		$server = array(
			'REMOTE_ADDR'    => '198.51.100.7',
			'HTTP_X_REAL_IP' => 'not-an-ip',
		);
		$this->assertSame( '198.51.100.7', Siteherd_Lockout_Policy::client_ip( $server, 'x-real-ip' ) );
		$this->assertSame( '', Siteherd_Lockout_Policy::client_ip( array(), 'auto' ) );
	}

	public function test_in_ranges_respects_partial_bytes() {
		$this->assertTrue( Siteherd_Lockout_Policy::in_ranges( '173.245.63.255', array( '173.245.48.0/20' ) ) );
		$this->assertFalse( Siteherd_Lockout_Policy::in_ranges( '173.245.64.0', array( '173.245.48.0/20' ) ) );
	}

	public function test_locks_out_after_max_retries() {
		$record = null;
		for ( $i = 1; $i < 5; $i++ ) {
			$result = Siteherd_Lockout_Policy::after_failure( $record, 1000 + $i, 5, 15 );
			$this->assertSame( 0, $result['locked'] );
			$record = $result['record'];
		}
		$result = Siteherd_Lockout_Policy::after_failure( $record, 1010, 5, 15 );
		$this->assertSame( 900, $result['locked'] );
		$this->assertSame( 900, Siteherd_Lockout_Policy::locked_for( $result['record'], 1010 ) );
		$this->assertSame( 0, Siteherd_Lockout_Policy::locked_for( $result['record'], 1910 ) );
	}

	public function test_old_failures_stop_counting() {
		$record = Siteherd_Lockout_Policy::after_failure( null, 1000, 2, 15 )['record'];
		$result = Siteherd_Lockout_Policy::after_failure( $record, 1000 + 3601, 2, 15 );
		$this->assertSame( 0, $result['locked'] );
		$this->assertSame( 1, $result['record']['fails'] );
	}

	public function test_fourth_lockout_in_a_day_lasts_a_day() {
		$record = null;
		$now    = 1000;
		$locks  = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$result  = Siteherd_Lockout_Policy::after_failure( $record, $now, 1, 15 );
			$locks[] = $result['locked'];
			$record  = $result['record'];
			$now    += 1000;
		}
		$this->assertSame( array( 900, 900, 900, 86400 ), $locks );
	}
}
