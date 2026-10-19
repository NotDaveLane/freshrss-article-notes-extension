<?php

declare(strict_types=1);

use ArticleNotes\Api;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** docs/openapi.yaml describes exactly the API the router handles, and xExtension-ArticleNotes/openapi.json is its copy. */
final class OpenApiTest extends TestCase {
	private const METHODS = ['get', 'put', 'post', 'patch', 'delete'];
	private const ROOT = __DIR__ . '/../..';

	/** @return array<string,mixed> */
	private static function document(): array {
		$document = Yaml::parseFile(self::ROOT . '/docs/openapi.yaml');
		self::assertIsArray($document);
		/** @var array<string,mixed> $document */
		return $document;
	}

	/**
	 * @param array<mixed> $node
	 * @return list<string>
	 */
	private static function references(array $node): array {
		$found = [];
		foreach ($node as $key => $value) {
			if ($key === '$ref' && is_string($value)) {
				$found[] = $value;
			} elseif (is_array($value)) {
				$found = [...$found, ...self::references($value)];
			}
		}
		return $found;
	}

	/** @param array<mixed> $document */
	private static function resolve(array $document, string $reference): mixed {
		self::assertStringStartsWith('#/', $reference);
		$node = $document;
		foreach (explode('/', substr($reference, 2)) as $part) {
			if (!is_array($node) || !array_key_exists($part, $node)) {
				return null;
			}
			$node = $node[$part];
		}
		return $node;
	}

	public function testIsAnOpenApi31DocumentForThisVersion(): void {
		$document = self::document();
		self::assertIsString($document['openapi']);
		self::assertMatchesRegularExpression('/^3\.1\.\d+$/', $document['openapi']);
		self::assertIsArray($document['info']);
		self::assertSame('ArticleNotes API', $document['info']['title']);
		$metadata = json_decode((string)file_get_contents(self::ROOT . '/xExtension-ArticleNotes/metadata.json'), true);
		self::assertIsArray($metadata);
		self::assertSame($metadata['version'], $document['info']['version']);
		self::assertIsArray($document['paths']);
		self::assertNotEmpty($document['paths']);
		self::assertIsArray($document['components']);
	}

	public function testEveryReferenceResolves(): void {
		$document = self::document();
		$references = self::references($document);
		self::assertNotEmpty($references);
		foreach ($references as $reference) {
			self::assertNotNull(self::resolve($document, $reference), 'Unresolved ' . $reference);
		}
	}

	/** @return array<mixed> */
	private static function node(mixed $value): array {
		self::assertIsArray($value);
		return $value;
	}

	/**
	 * @param array<mixed> $document
	 * @param list<string> $schemes
	 */
	private static function assertSecurity(array $document, mixed $requirements, array $schemes): void {
		foreach (self::node($requirements) as $requirement) {
			foreach (array_keys(self::node($requirement)) as $scheme) {
				self::assertContains($scheme, $schemes);
			}
		}
	}

	public function testOperationsAreWellFormed(): void {
		$document = self::document();
		$schemes = array_map(strval(...), array_keys(self::node(self::node($document['components'])['securitySchemes'])));
		$ids = [];
		foreach (self::node($document['paths']) as $path => $item) {
			$item = self::node($item);
			preg_match_all('/\{(\w+)\}/', (string)$path, $matches);
			foreach (self::METHODS as $method) {
				if (!isset($item[$method])) {
					continue;
				}
				$operation = self::node($item[$method]);
				$label = strtoupper($method) . ' ' . $path;
				$id = $operation['operationId'] ?? null;
				self::assertIsString($id, $label . ' has no operationId');
				self::assertArrayNotHasKey($id, $ids, 'Duplicate operationId ' . $id);
				$ids[$id] = true;
				self::assertNotSame('', $operation['summary'] ?? '', $label . ' has no summary');
				$responses = self::node($operation['responses'] ?? null);
				self::assertNotEmpty($responses, $label . ' has no responses');
				foreach (array_keys($responses) as $status) {
					self::assertMatchesRegularExpression('/^[1-5]\d\d$/', (string)$status, $label);
				}
				$declared = [];
				foreach ([...self::node($item['parameters'] ?? []), ...self::node($operation['parameters'] ?? [])] as $parameter) {
					$parameter = self::node($parameter);
					$resolved = isset($parameter['$ref']) ? self::node(self::resolve($document, self::text($parameter['$ref']))) : $parameter;
					if (($resolved['in'] ?? '') === 'path') {
						self::assertTrue($resolved['required'] ?? false, $label . ' path parameter must be required');
						$declared[] = self::text($resolved['name'] ?? null);
					}
				}
				sort($declared);
				$expected = $matches[1];
				sort($expected);
				self::assertSame($expected, $declared, $label . ' path parameters');
				self::assertSecurity($document, $operation['security'] ?? [], $schemes);
			}
		}
		self::assertSecurity($document, $document['security'] ?? [], $schemes);
	}

	private static function text(mixed $value): string {
		self::assertIsString($value);
		return $value;
	}

	/** @return list<string> "METHOD /path" for every operation, sorted */
	private static function describedOperations(): array {
		$document = self::document();
		self::assertIsArray($document['paths']);
		$operations = [];
		foreach ($document['paths'] as $path => $item) {
			self::assertIsArray($item);
			foreach (self::METHODS as $method) {
				if (isset($item[$method])) {
					$operations[] = strtoupper($method) . ' ' . $path;
				}
			}
		}
		sort($operations);
		return $operations;
	}

	public function testEveryRouteIsDescribedAndEveryDescriptionIsARoute(): void {
		$routes = [];
		foreach (Api::ROUTES as $template => $methods) {
			foreach ($methods as $method) {
				$routes[] = $method . ' /' . $template;
			}
		}
		sort($routes);
		self::assertSame($routes, self::describedOperations());
	}

	public function testJsonCopyServedByTheApiMatchesTheSource(): void {
		$source = Yaml::parseFile(self::ROOT . '/docs/openapi.yaml', Yaml::PARSE_OBJECT_FOR_MAP);
		$copy = json_decode((string)file_get_contents(self::ROOT . '/xExtension-ArticleNotes/openapi.json'), false, 512, JSON_THROW_ON_ERROR);
		self::assertEquals($source, $copy, 'xExtension-ArticleNotes/openapi.json is stale: run bin/openapi');
	}
}
