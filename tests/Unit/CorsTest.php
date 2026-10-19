<?php

declare(strict_types=1);

use ArticleNotes\Cors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CorsTest extends TestCase {
	public function testParsesOneOriginPerLine(): void {
		$parsed = Cors::parse("https://Notes.Example.com\n\n  http://localhost:8000  \r\nmoz-extension://0F1E2D3C-4B5A-6978-8796-A5B4C3D2E1F0\n"
			. "chrome-extension://abcdefghijklmnopabcdefghijklmnop\nhttps://notes.example.com:443\n");
		self::assertSame([], $parsed['invalid']);
		self::assertSame([
			'https://notes.example.com',
			'http://localhost:8000',
			'moz-extension://0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
			'chrome-extension://abcdefghijklmnopabcdefghijklmnop',
		], $parsed['origins']);
	}

	public function testEmptyTextAllowsNothing(): void {
		self::assertSame(['origins' => [], 'invalid' => []], Cors::parse(" \n\n"));
	}

	/** @return iterable<string,array{0:string}> */
	public static function invalidOrigins(): iterable {
		yield 'wildcard' => ['*'];
		yield 'wildcard host' => ['https://*.example.com'];
		yield 'wildcard port' => ['https://example.com:*'];
		yield 'null' => ['null'];
		yield 'path' => ['https://example.com/app'];
		yield 'trailing slash' => ['https://example.com/'];
		yield 'no scheme' => ['example.com'];
		yield 'ftp' => ['ftp://example.com'];
		yield 'query' => ['https://example.com?x=1'];
		yield 'credentials' => ['https://user@example.com'];
		yield 'port range' => ['https://example.com:70000'];
		yield 'extension wildcard' => ['moz-extension://*'];
		yield 'extension without uuid' => ['moz-extension://abc'];
		yield 'chrome extension too short' => ['chrome-extension://abc'];
		yield 'space' => ['https://exa mple.com'];
	}

	#[DataProvider('invalidOrigins')]
	public function testRefusesWhatIsNotAnExactOrigin(string $origin): void {
		self::assertSame([$origin], Cors::parse($origin)['invalid']);
		self::assertSame([], Cors::parse($origin)['origins']);
	}

	public function testOneBadLineIsReportedAmongGoodOnes(): void {
		$parsed = Cors::parse("https://a.example\n*\nhttps://b.example");
		self::assertSame(['*'], $parsed['invalid']);
	}

	public function testAllowedOriginMatchesExactlyOrNotAtAll(): void {
		$allowed = ['https://notes.example.com'];
		self::assertSame('https://notes.example.com', Cors::allowedOrigin('https://notes.example.com', $allowed));
		self::assertNull(Cors::allowedOrigin('https://evil.example.com', $allowed));
		self::assertNull(Cors::allowedOrigin('https://notes.example.com.evil.com', $allowed));
		self::assertNull(Cors::allowedOrigin(null, $allowed));
		self::assertNull(Cors::allowedOrigin('', $allowed));
		self::assertNull(Cors::allowedOrigin('https://notes.example.com', []));
	}

	public function testHeadersNeverAllowCredentials(): void {
		$headers = Cors::preflightHeaders('https://notes.example.com', ['GET', 'PUT', 'OPTIONS']);
		self::assertSame('https://notes.example.com', $headers['Access-Control-Allow-Origin']);
		self::assertSame('Origin', $headers['Vary']);
		self::assertSame('ETag', $headers['Access-Control-Expose-Headers']);
		self::assertSame('GET, PUT, OPTIONS', $headers['Access-Control-Allow-Methods']);
		self::assertSame('Authorization, Content-Type, If-Match', $headers['Access-Control-Allow-Headers']);
		self::assertSame('600', $headers['Access-Control-Max-Age']);
		self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $headers);
		self::assertArrayNotHasKey('Access-Control-Allow-Credentials', Cors::responseHeaders('https://notes.example.com'));
	}
}
