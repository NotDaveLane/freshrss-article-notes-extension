<?php

declare(strict_types=1);

namespace ArticleNotes;

/** A note cannot be saved because of the request (as opposed to a storage failure). */
final class NoteException extends \RuntimeException {
	public const UNKNOWN_ENTRY = 1;
	public const TOO_LONG = 2;
}
