<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020 Edward Chernenko.

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
*/

/**
 * @file
 * Trait that provides makeEdit() for quickly precreating pages.
 */

/**
 * @method static assertTrue($a, $message='')
 */
trait MakeEditTestTrait {
	/**
	 * Make one test edit on behalf of $user in page $title.
	 * @param Title $title
	 * @param User $user
	 * @param string|null $text
	 * @return int rev_id of the newly created edit.
	 */
	protected function makeEdit( Title $title, User $user, $text = null ) {
		if ( !$text ) {
			$text = 'Some text ' . rand( 0, 100000 ) . ' in page ' .
				$title->getFullText() . ' by ' . $user->getName();
		}

		$page = WikiPage::factory( $title );
		$status = $page->doEditContent(
			ContentHandler::makeContent( $text, null, CONTENT_MODEL_WIKITEXT ),
			'Some edit summary',
			EDIT_INTERNAL,
			false,
			$user
		);
		$this->assertTrue( $status->isGood(), "Edit failed: " . $status->getMessage()->plain() );

		// @phan-suppress-next-line PhanTypeArraySuspiciousNullable
		return $status->value['revision']->getId();
	}
}
