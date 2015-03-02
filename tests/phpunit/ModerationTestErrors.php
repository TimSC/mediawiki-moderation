<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015 Edward Chernenko.

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
	@file
	@brief Ensure that known error conditions cause exceptions.
*/

require_once(__DIR__ . "/../ModerationTestsuite.php");

/**
	@covers ModerationError
*/
class ModerationTestErrors extends MediaWikiTestCase
{
	public function testUnknownAction() {
		$t = new ModerationTestsuite();
		$t->loginAs($t->moderator);

		$error = $t->getModerationErrorByURL($t->getSpecialURL() .
			"&modaction=findgirlfriend");
		$this->assertEquals('(moderation-unknown-modaction)', $error);
	}

	public function testNotFound() {
		$t = new ModerationTestsuite();

		$entry = $t->getSampleEntry();

		# Delete this entry by approving it
		$t->loginAs($t->moderator);
		$req = $t->makeHttpRequest($entry->approveLink, 'GET');
		$this->assertTrue($req->execute()->isOK());

		$entry->fakeBlockLink();
		$links = array(
#			$entry->showLink,
			$entry->approveLink,
			$entry->approveAllLink,
#			$entry->rejectLink,
#			$entry->rejectAllLink,
#			$entry->blockLink,
#			$entry->unblockLink
			# TODO: check mergeLink
		);
		foreach($links as $url) {
			$error = $t->getModerationErrorByURL($url);
			$this->assertEquals('(moderation-edit-not-found)', $error);
		}
	}
}
