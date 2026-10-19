<?php

declare(strict_types=1);

final class ArticleNotesExtension extends Minz_Extension {
	#[\Override]
	public function init(): void {
		parent::init();

		$this->registerTranslates();
	}
}
