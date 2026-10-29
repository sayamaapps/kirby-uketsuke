<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Uketsuke\Signature;

/**
 * The vectors were computed with sha512sum over the concatenated string, outside PHP.
 */
#[CoversClass(Signature::class)]
final class SignatureTest extends TestCase
{
	private const FIELDS = ['event' => 'connection_test', 'product_id' => '111111', 'ipn_version' => '1.0', 'order_id' => 'ORD-1'];
	private const VECTOR = '2A69419E7795A0C4D77FA56C581BA020A24E7AD3C8891937566ADBC94D1755028B82BA7486EFAD1BDD218AF8662EBE24E2C30A1FA0FB65C69DA63C01253EEBD6';

	public function testArrayValuesAreFlattenedLikeHttpBuildQuery(): void
	{
		// "custom[a]=b c" + pass, "custom[z]=0" + pass (zero is not empty), "event=x" + pass
		$this->assertSame(
			'21E24BC1122B98B54A505FA373809065218849372C4D7FD10F177EB44BB64EFA2C211E146DF070F4508CED07F230A3C4DD70BDB7C6AC74686555562DF425D1B2',
			Signature::compute('test', ['event' => 'x', 'custom' => ['a' => 'b c', 'z' => '0'], 'sha_sign' => 'ignored'])
		);
	}

	public function testEmptyPassphraseNeverVerifies(): void
	{
		$signed = [...self::FIELDS, 'sha_sign' => Signature::compute('', self::FIELDS)];

		$this->assertFalse(Signature::verify('', $signed));
	}

	public function testEmptyValuesAreSkippedButZeroIsNot(): void
	{
		$this->assertSame(self::VECTOR, Signature::compute('test', [...self::FIELDS, 'empty' => '', 'nothing' => null, 'no' => false]));
		$this->assertNotSame(self::VECTOR, Signature::compute('test', [...self::FIELDS, 'zero' => '0']));
	}

	public function testHashIs128UppercaseHexCharacters(): void
	{
		$this->assertMatchesRegularExpression('/^[0-9A-F]{128}$/', Signature::compute('test', self::FIELDS));
	}

	public function testKnownVector(): void
	{
		$this->assertSame(self::VECTOR, Signature::compute('test', self::FIELDS));
	}

	public function testSignatureFieldsAreNotSigned(): void
	{
		$this->assertSame(self::VECTOR, Signature::compute('test', [...self::FIELDS, 'sha_sign' => 'AA', 'SHASIGN' => 'BB']));
	}

	public function testVerifyAcceptsTheSignedFieldsInAnyCase(): void
	{
		$this->assertTrue(Signature::verify('test', [...self::FIELDS, 'sha_sign' => self::VECTOR]));
		$this->assertTrue(Signature::verify('test', [...self::FIELDS, 'sha_sign' => strtolower(self::VECTOR)]));
		$this->assertTrue(Signature::verify('test', [...self::FIELDS, 'SHASIGN' => self::VECTOR]));
	}

	public function testVerifyRejectsMissingTamperedAndWrongSignatures(): void
	{
		$this->assertFalse(Signature::verify('test', self::FIELDS));
		$this->assertFalse(Signature::verify('test', [...self::FIELDS, 'sha_sign' => '']));
		$this->assertFalse(Signature::verify('test', [...self::FIELDS, 'order_id' => 'ORD-2', 'sha_sign' => self::VECTOR]));
		$this->assertFalse(Signature::verify('wrong', [...self::FIELDS, 'sha_sign' => self::VECTOR]));
	}

	public function testWrongPassphraseChangesTheSignature(): void
	{
		$this->assertNotSame(self::VECTOR, Signature::compute('other', self::FIELDS));
	}
}
