<?php

declare(strict_types=1);

namespace ArticleNotes;

/** One user's settings, kept in the user's extension configuration. */
final class Settings {
	public const LABEL_ENABLED_KEY = 'label_enabled';
	public const LABEL_NAME_KEY = 'label_name';
	public const DEFAULT_LABEL_NAME = 'Annotated';
	public const MAX_LABEL_NAME_LENGTH = 63;

	/**
	 * @param \Closure(string):mixed $read the stored value of a key, null when absent
	 * @param \Closure(non-empty-string,mixed):void $write stores a value
	 */
	public function __construct(private readonly \Closure $read, private readonly \Closure $write) {
	}

	/** Whether annotating an article also labels it (on unless the user turned it off). */
	public function labelEnabled(): bool {
		return ($this->read)(self::LABEL_ENABLED_KEY) !== false;
	}

	public function labelName(): string {
		$name = ($this->read)(self::LABEL_NAME_KEY);
		$name = is_string($name) ? self::normalizeLabelName($name) : null;
		return $name ?? self::DEFAULT_LABEL_NAME;
	}

	/** @return string|null the trimmed name, null when it is empty or longer than {@see MAX_LABEL_NAME_LENGTH} characters */
	public static function normalizeLabelName(string $name): ?string {
		$name = trim($name);
		$length = mb_strlen($name, 'UTF-8');
		return $length >= 1 && $length <= self::MAX_LABEL_NAME_LENGTH ? $name : null;
	}

	/**
	 * Validates and stores the settings.
	 *
	 * @throws \InvalidArgumentException when the label name is empty or too long (nothing is stored)
	 */
	public function save(bool $labelEnabled, string $labelName): void {
		$name = self::normalizeLabelName($labelName);
		if ($name === null) {
			throw new \InvalidArgumentException('The label name must have 1 to ' . self::MAX_LABEL_NAME_LENGTH . ' characters');
		}
		($this->write)(self::LABEL_ENABLED_KEY, $labelEnabled);
		($this->write)(self::LABEL_NAME_KEY, $name);
	}
}
