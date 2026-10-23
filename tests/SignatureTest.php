<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Check\Signature;

final class SignatureTest extends TestCase {
	public function test_roundtrip_and_forgery(): void {
		$secret = 'k';
		$payload = json_encode( [ 'id' => 'r1', 'state' => 'guest' ] );
		$ts = 1000;
		$h = $ts . '.' . Signature::sign( $payload, $ts, $secret ) . '.' . rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );
		$this->assertSame( 'r1', Signature::verify( $h, $secret, 1010 )['id'] );
		$this->assertNull( Signature::verify( $h, 'other', 1010 ) );              // wrong secret
		$this->assertNull( Signature::verify( $h, $secret, 2000 ) );              // too old
		$this->assertNull( Signature::verify( 'garbage', $secret, 1010 ) );
		$tampered = $ts . '.' . Signature::sign( $payload, $ts, $secret ) . '.' . rtrim( strtr( base64_encode( json_encode( [ 'id' => 'r1', 'state' => 'logged_in' ] ) ), '+/', '-_' ), '=' );
		$this->assertNull( Signature::verify( $tampered, $secret, 1010 ) );       // payload swapped
	}
	public function test_header_for_signs_the_url_path(): void {
		$GLOBALS['bp_test_options']['beltoft_performance_secret'] = 'k';
		$h = Signature::header_for( [ 'id' => 'r1', 'state' => 'guest' ], 'https://staging.mindthetrash.pt/Loja/?bp_check=r1' );
		$this->assertSame( '/loja/', Signature::verify( $h, 'k', time() )['path'] );
	}
}
