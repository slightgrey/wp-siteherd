<?php

use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase {

	private const SECRET = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const NOW    = 1760000000;
	private const BODY   = '{"wp_version":"6.8"}';

	public function test_matches_dashboard_signature(): void {
		// Same vector as dashboard/src/lib/hmac.test.ts; both sides must agree.
		$this->assertSame(
			'd1a57ceb8873614356924934e0c84e175adcfd1c9403cb023e545de28b4fc42f',
			Siteherd_Signer::sign( self::SECRET, (string) self::NOW, self::BODY )
		);
	}

	public function test_verify_accepts_valid_and_rejects_tampering(): void {
		$sig = Siteherd_Signer::sign( self::SECRET, (string) self::NOW, self::BODY );

		$this->assertTrue( Siteherd_Signer::verify( self::SECRET, (string) self::NOW, self::BODY, $sig, self::NOW ) );
		$this->assertSame( 'bad_signature', Siteherd_Signer::verify( self::SECRET, (string) self::NOW, self::BODY . ' ', $sig, self::NOW ) );
		$this->assertSame( 'bad_signature', Siteherd_Signer::verify( str_repeat( 'b', 64 ), (string) self::NOW, self::BODY, $sig, self::NOW ) );
		$this->assertSame( 'bad_signature', Siteherd_Signer::verify( self::SECRET, (string) self::NOW, self::BODY, strtoupper( $sig ), self::NOW ) );
	}

	public function test_verify_enforces_clock_skew(): void {
		$sig = Siteherd_Signer::sign( self::SECRET, (string) self::NOW, '' );

		$this->assertTrue( Siteherd_Signer::verify( self::SECRET, (string) self::NOW, '', $sig, self::NOW + 300 ) );
		$this->assertSame( 'stale_timestamp', Siteherd_Signer::verify( self::SECRET, (string) self::NOW, '', $sig, self::NOW + 301 ) );
		$this->assertSame( 'bad_timestamp', Siteherd_Signer::verify( self::SECRET, '12.5', '', $sig, self::NOW ) );
	}

	public function test_decodes_dashboard_key(): void {
		// Produced by encodeConnectionKey() in dashboard/src/lib/connection-key.ts.
		$json = '{"url":"https://siteherd.example.com","id":"b7258302-0c75-45bd-bd94-99c0bee7cbc7","secret":"' . str_repeat( 'f', 64 ) . '"}';
		$key  = 'siteherd1_' . rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );

		$this->assertSame(
			array(
				'url'    => 'https://siteherd.example.com',
				'id'     => 'b7258302-0c75-45bd-bd94-99c0bee7cbc7',
				'secret' => str_repeat( 'f', 64 ),
			),
			Siteherd_Signer::decode_key( "  $key\n" )
		);
	}

	public function test_rejects_bad_keys(): void {
		$this->assertNull( Siteherd_Signer::decode_key( 'nope' ) );
		$this->assertNull( Siteherd_Signer::decode_key( 'siteherd1_!!!' ) );
		$this->assertNull( Siteherd_Signer::decode_key( 'fleet1_eyJ1cmwiOiJ4In0' ) );
		$this->assertNull( Siteherd_Signer::decode_key( 'siteherd1_' . rtrim( strtr( base64_encode( '{"url":1}' ), '+/', '-_' ), '=' ) ) );
	}

	// Produced by createSsoToken() in dashboard/src/lib/sso.ts (see sso.test.ts).
	private const SSO_VECTOR = 'eyJzaXRlIjoiYjcyNTgzMDItMGM3NS00NWJkLWJkOTQtOTljMGJlZTdjYmM3IiwidXNlciI6MSwiaWF0IjoxNzYwMDAwMDAwLCJub25jZSI6IjAxMjM0NTY3ODlhYmNkZWYwMTIzNDU2Nzg5YWJjZGVmIn0.c2f8274b81641da44940c9585d98e3eddc73ec6f4894d4930ea68f6c5a2d1ff5';

	public function test_verifies_dashboard_sso_token(): void {
		$this->assertSame(
			array(
				'site'  => 'b7258302-0c75-45bd-bd94-99c0bee7cbc7',
				'user'  => 1,
				'iat'   => 1760000000,
				'nonce' => '0123456789abcdef0123456789abcdef',
			),
			Siteherd_Signer::verify_sso_token( self::SECRET, self::SSO_VECTOR, self::NOW + 60 )
		);
	}

	public function test_rejects_bad_sso_tokens(): void {
		$this->assertSame( 'bad_signature', Siteherd_Signer::verify_sso_token( str_repeat( 'b', 64 ), self::SSO_VECTOR, self::NOW ) );
		$this->assertSame( 'expired', Siteherd_Signer::verify_sso_token( self::SECRET, self::SSO_VECTOR, self::NOW + 121 ) );
		$this->assertSame( 'expired', Siteherd_Signer::verify_sso_token( self::SECRET, self::SSO_VECTOR, self::NOW - 121 ) );
		$this->assertSame( 'malformed', Siteherd_Signer::verify_sso_token( self::SECRET, 'nope', self::NOW ) );
		$this->assertSame( 'malformed', Siteherd_Signer::verify_sso_token( self::SECRET, self::SSO_VECTOR . '.x', self::NOW ) );

		// A report signature over the same bytes must not work as a login token.
		[ $payload ] = explode( '.', self::SSO_VECTOR );
		$report_style = $payload . '.' . hash_hmac( 'sha256', $payload, self::SECRET );
		$this->assertSame( 'bad_signature', Siteherd_Signer::verify_sso_token( self::SECRET, $report_style, self::NOW ) );

		// Correctly signed but the user isn't a positive integer.
		$bad  = rtrim( strtr( base64_encode( '{"site":"x","user":"1","iat":1760000000,"nonce":"0123456789abcdef0123456789abcdef"}' ), '+/', '-_' ), '=' );
		$this->assertSame( 'malformed', Siteherd_Signer::verify_sso_token( self::SECRET, $bad . '.' . hash_hmac( 'sha256', 'sso.' . $bad, self::SECRET ), self::NOW ) );
	}

	public function test_url_rule(): void {
		$this->assertTrue( Siteherd_Signer::is_allowed_url( 'https://dash.example.com' ) );
		$this->assertTrue( Siteherd_Signer::is_allowed_url( 'http://localhost:3000' ) );
		$this->assertTrue( Siteherd_Signer::is_allowed_url( 'http://127.0.0.1:3000' ) );
		$this->assertFalse( Siteherd_Signer::is_allowed_url( 'http://dash.example.com' ) );
		$this->assertFalse( Siteherd_Signer::is_allowed_url( 'ftp://dash.example.com' ) );
		$this->assertFalse( Siteherd_Signer::is_allowed_url( 'not a url' ) );
	}
}
