<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** bin/check-php-log, fed a container log on standard input (`--stdin`), as bin/ci runs it after the end-to-end suite. */
final class CheckPhpLogTest extends TestCase {
	private const PREFIX = '[Mon Oct 19 21:18:37.024110 2026] [php:warn] [pid 521] [client 172.18.0.4:47656]';

	/** @return array{status:int,output:string} */
	private function check(string $log): array {
		$process = proc_open(
			['bash', '-c', 'exec "$0" --stdin 2>&1', dirname(__DIR__, 2) . '/bin/check-php-log'],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w']],
			$pipes,
		);
		self::assertIsResource($process);
		fwrite($pipes[0], $log);
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		return ['status' => proc_close($process), 'output' => (string)$output];
	}

	public function testACleanLogPasses(): void {
		$result = $this->check("172.18.0.4 - e2e [19/Oct/2026:21:18:37 +0000] \"GET /i/?c=ArticleNotes&a=settings HTTP/1.1\" 200 9000\n");
		self::assertSame(0, $result['status'], $result['output']);
	}

	public function testAWarningOnAnExtensionPageFailsWithTheOffendingLine(): void {
		$line = self::PREFIX . ' [uri /i/?c=ArticleNotes&a=settings] PHP Warning:  Undefined property: Minz_View::$rss_title'
			. ' in /var/www/FreshRSS/app/layout/layout.phtml on line 53';
		$result = $this->check("noise\n$line\n");
		self::assertSame(1, $result['status'], $result['output']);
		self::assertStringContainsString('Minz_View::$rss_title', $result['output']);
	}

	public function testDiagnosticsFromTheExtensionsFilesFailOnAnyPage(): void {
		$result = $this->check(self::PREFIX . ' [uri /i/?a=normal] PHP Deprecated:  strlen(): Passing null'
			. " in /var/www/FreshRSS/extensions/xExtension-ArticleNotes/NoteBlock.php on line 12\n");
		self::assertSame(1, $result['status'], $result['output']);
		self::assertStringContainsString('NoteBlock.php', $result['output']);
	}

	public function testTheExtensionsApiAndNoticesAndFatalErrorsCount(): void {
		$lines = [
			' [uri /api/misc.php/ArticleNotes/v1/notes] PHP Notice:  Only variables in /var/www/FreshRSS/p/api/misc.php on line 4',
			' [uri /i/?c=ArticleNotes&a=save] PHP Fatal error:  Uncaught TypeError: x in /var/www/FreshRSS/lib/Minz/View.php:12',
			' PHP Warning:  Undefined variable $x in /var/www/FreshRSS/extensions/xExtension-ArticleNotes/extension.php on line 3',
		];
		foreach ($lines as $line) {
			self::assertSame(1, $this->check(self::PREFIX . $line . "\n")['status'], $line);
		}
	}

	public function testDiagnosticsUnrelatedToTheExtensionAreReportedButIgnored(): void {
		$line = self::PREFIX . ' [uri /i/?c=index&a=normal] PHP Deprecated:  Something in /var/www/FreshRSS/lib/simplepie/x.php on line 9';
		$result = $this->check("$line\n");
		self::assertSame(0, $result['status'], $result['output']);
		self::assertStringContainsString('ignored', $result['output']);
		self::assertStringContainsString('simplepie/x.php', $result['output']);
	}
}
