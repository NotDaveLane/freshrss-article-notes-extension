<?php

declare(strict_types=1);

use ArticleNotes\UrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlNormalizerTest extends TestCase {
	/** @return iterable<string,array{0:string,1:string}> */
	public static function normalizations(): iterable {
		yield 'already normal' => ['https://example.com/a/b?x=1', 'https://example.com/a/b?x=1'];
		yield 'scheme and host are lowercased' => ['HTTPS://Example.COM/Path', 'https://example.com/Path'];
		yield 'http equals https' => ['http://example.com/a', 'https://example.com/a'];
		yield 'leading www is dropped' => ['https://www.example.com/a', 'https://example.com/a'];
		yield 'only the leading www' => ['https://news.www.example.com/a', 'https://news.www.example.com/a'];
		yield 'default http port' => ['http://example.com:80/a', 'https://example.com/a'];
		yield 'default https port' => ['https://example.com:443/a', 'https://example.com/a'];
		yield 'other port is kept' => ['https://example.com:8443/a', 'https://example.com:8443/a'];
		yield 'fragment is dropped' => ['https://example.com/a#section-2', 'https://example.com/a'];
		yield 'empty path is the root' => ['https://example.com', 'https://example.com/'];
		yield 'root keeps its slash' => ['https://example.com/', 'https://example.com/'];
		yield 'trailing slash is stripped' => ['https://example.com/a/b/', 'https://example.com/a/b'];
		yield 'trailing slash before a query' => ['https://example.com/a/?x=1', 'https://example.com/a?x=1'];
		yield 'tracking parameters are removed' => [
			'https://example.com/a?utm_source=x&id=7&fbclid=abc&gclid=1&mc_cid=2&mc_eid=3&ref_src=tw&UTM_Medium=m',
			'https://example.com/a?id=7',
		];
		yield 'only tracking parameters' => ['https://example.com/a?utm_campaign=x', 'https://example.com/a'];
		yield 'query parameters are sorted' => ['https://example.com/a?b=2&a=2&a=1', 'https://example.com/a?a=1&a=2&b=2'];
		yield 'valueless parameter' => ['https://example.com/a?z&b=1', 'https://example.com/a?b=1&z'];
		yield 'empty query' => ['https://example.com/a?', 'https://example.com/a'];
		yield 'unreserved percent-encodings are decoded' => ['https://example.com/%7Euser/a%2Db%5Fc?q=%41', 'https://example.com/~user/a-b_c?q=A'];
		yield 'reserved percent-encodings stay, upper-cased' => ['https://example.com/a%2fb%20c', 'https://example.com/a%2Fb%20c'];
		yield 'credentials are dropped' => ['https://user:secret@example.com/a', 'https://example.com/a'];
		yield 'surrounding whitespace' => ["  https://example.com/a\n", 'https://example.com/a'];
		yield 'IDN host becomes punycode' => ['https://Bücher.example/a', 'https://xn--bcher-kva.example/a'];
		yield 'IPv6 host' => ['http://[2001:DB8::1]:80/a', 'https://[2001:db8::1]/a'];
		yield 'non-ASCII path is percent-encoded' => ['https://example.com/caf%C3%A9', 'https://example.com/caf%C3%A9'];
	}

	#[DataProvider('normalizations')]
	public function testNormalize(string $url, string $expected): void {
		self::assertSame($expected, UrlNormalizer::normalize($url));
	}

	public function testVariantsShareOneKey(): void {
		$variants = [
			'https://example.com/post/42',
			'http://www.example.com/post/42/',
			'HTTP://EXAMPLE.COM:80/post/42?utm_source=newsletter#comments',
			'https://example.com/post/42?fbclid=xyz',
		];
		$hashes = array_unique(array_map(static fn (string $url): string => UrlNormalizer::hash((string)UrlNormalizer::normalize($url)), $variants));
		self::assertCount(1, $hashes);
		self::assertNotSame(UrlNormalizer::hash('https://example.com/post/43'), $hashes[0]);
		self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $hashes[0]);
	}

	/** @return iterable<string,array{0:string}> */
	public static function rejected(): iterable {
		foreach ([
			'', '   ', 'example.com/a', '/relative/path', 'ftp://example.com/a', 'javascript:alert(1)', 'mailto:a@example.com',
			'file:///etc/passwd', 'data:text/plain,hi', 'about:blank', 'chrome://settings', 'https://', 'https:///a', 'https://exa mple.com/',
			"https://example.com/a\x00b", 'http://example.com:99999/', 'http://example.com:abc/',
			] as $url) {
			yield 'url ' . json_encode($url, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) => [$url];
		}
		yield 'too long' => ['https://example.com/' . str_repeat('a', 17000)];
	}

	#[DataProvider('rejected')]
	public function testNonHttpOrInvalidUrlsAreRejected(string $url): void {
		self::assertNull(UrlNormalizer::normalize($url));
	}

	public function testForEntryFallsBackToTheGuid(): void {
		self::assertSame('https://example.com/a', UrlNormalizer::forEntry('http://www.example.com/a/', 'https://other.example/guid'));
		self::assertSame('https://other.example/guid', UrlNormalizer::forEntry('', 'https://other.example/guid'));
		self::assertNull(UrlNormalizer::forEntry('', 'tag:example.com,2025:42'));
	}
}
