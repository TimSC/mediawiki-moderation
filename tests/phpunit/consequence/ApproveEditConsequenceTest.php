<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020-2021 Edward Chernenko.

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
 * Unit test of ApproveEditConsequence.
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Moderation\ApproveEditConsequence;
use MediaWiki\Revision\SlotRecord;

require_once __DIR__ . "/autoload.php";

/**
 * @group Database
 */
class ApproveEditConsequenceTest extends ModerationUnitTestCase {
	use MakeEditTestTrait;

	/** @var string[] */
	protected $tablesUsed = [ 'user', 'page', 'logging', 'log_search' ];

	/**
	 * Verify that ApproveEditConsequence makes a new edit.
	 * @covers MediaWiki\Moderation\ApproveEditConsequence
	 * @dataProvider dataProviderApproveEdit
	 * @param array $params
	 */
	public function testApproveEdit( array $params ) {
		$opt = (object)$params;

		$opt->existing = $opt->existing ?? false;
		$opt->bot = $opt->bot ?? false;
		$opt->minor = $opt->minor ?? false;
		$opt->summary = $opt->summary ?? 'Some summary ' . rand( 0, 100000 );

		$user = empty( $opt->anonymously ) ?
			self::getTestUser( $opt->bot ? [ 'bot' ] : [] )->getUser() :
			User::newFromName( '127.0.0.1', false );
		$title = Title::newFromText( $opt->title ?? 'UTPage-' . rand( 0, 100000 ) );
		$newText = 'New text ' . rand( 0, 100000 );

		// Edits shouldn't be intercepted (including edit caused by approval).
		$this->setMwGlobals( 'wgModerationEnable', false );

		if ( $opt->existing ) {
			// Precreate the page.
			$this->makeEdit( $title, User::newFromName( '127.0.0.2', false ), "Before $newText" );
		}

		$baseRevId = $opt->existing ?
			$title->getLatestRevID( IDBAccessObject::READ_LATEST ) : 0;

		// Monitor RecentChange_save hook.
		// Note: we can't use $this->setTemporaryHook(), because it removes existing hook (if any),
		// and Moderation itself uses this hook (so it can't be removed during tests).
		global $wgHooks;
		// @phan-suppress-next-line PhanPossiblyUndeclaredVariable - false positive
		$hooks = $wgHooks; // For setMwGlobals() below

		$hookFired = false;
		$hooks['RecentChange_save'][] = function ( RecentChange $rc )
			use ( &$hookFired, $user, $title, $newText, $baseRevId, $opt )
		{
			$hookFired = true;

			$performer = ModerationTestUtil::getRecentChangePerformer( $rc );

			$this->assertEquals( $title->getFullText(), $rc->getTitle()->getFullText() );
			$this->assertEquals( $user->getName(), $performer->getName() );
			$this->assertEquals( $user->getId(), $performer->getId() );
			$this->assertEquals( $opt->minor ? 1 : 0, $rc->getAttribute( 'rc_minor' ) );
			$this->assertEquals( $opt->bot ? 1 : 0, $rc->getAttribute( 'rc_bot' ) );
			$this->assertEquals( $opt->summary, $rc->getAttribute( 'rc_comment' ) );

			$revid = $rc->getAttribute( 'rc_this_oldid' );
			$this->assertNotSame( 0, $revid );

			$rec = MediaWikiServices::getInstance()->getRevisionLookup()->getRevisionById( $revid );
			$this->assertEquals( $baseRevId, $rec->getParentId() );
			$this->assertEquals( $newText, $rec->getSlot( SlotRecord::MAIN )->getContent()->getNativeData() );

			return true;
		};
		$this->setMwGlobals( 'wgHooks', $hooks );

		// Create and run the Consequence.
		$consequence = new ApproveEditConsequence(
			$user, $title, $newText, $opt->summary, $opt->bot, $opt->minor, $baseRevId );
		$status = $consequence->run();

		$this->assertTrue( $status->isOK(),
			"ApproveEditConsequence failed: " . $status->getMessage()->plain() );
		$this->assertTrue( $hookFired, "ApproveEditConsequence: didn't edit anything." );
	}

	/**
	 * Provide datasets for testApproveEdit() runs.
	 * @return array
	 */
	public function dataProviderApproveEdit() {
		return [
			'logged-in edit' => [ [] ],
			'anonymous edit' => [ [ 'anonymously' => true ] ],
			'edit in Project namespace' => [ [ 'title' => 'Project:Title in another namespace' ] ],
			'edit in existing page' => [ [ 'existing' => true ] ],
			'edit with edit summary' => [ [ 'summary' => 'Summary 1' ] ],
			'bot edit' => [ [ 'bot' => true ] ],
			'minor edit' => [ [ 'minor' => true, 'existing' => true ] ]
		];
	}

	/**
	 * Verify that ApproveEditConsequence can automatically resolve a resolvable edit conflict.
	 * @covers MediaWiki\Moderation\ApproveEditConsequence
	 * See also: ModerationEditConflictTest::testResolvableEditConflict()
	 */
	public function testResolvableEditConflict() {
		$title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$user = User::newFromName( '127.0.0.1', false );

		// Edits shouldn't be intercepted (including edit caused by approval).
		$this->setMwGlobals( 'wgModerationEnable', false );

		list( $revid1, $revid2 ) = $this->makeTwoEdits( $title,
			"Original paragraph about dogs\n\nOriginal paragraph about cats",
			"Original paragraph about dogs\n\nModified paragraph about cats"
		);

		$textToApprove = "Modified paragraph about dogs\n\nOriginal paragraph about cats";
		$expectedText = "Modified paragraph about dogs\n\nModified paragraph about cats";

		// Create and run the Consequence.
		$consequence = new ApproveEditConsequence(
			$user, $title, $textToApprove, '', false, false, $revid1 );
		$status = $consequence->run();

		$this->assertTrue( $status->isOK(),
			"ApproveEditConsequence failed: " . $status->getMessage()->plain() );

		// @phan-suppress-next-line PhanTypeArraySuspiciousNullable
		$rev = $status->value['revision-record'] ?? $status->value['revision'];
		$revid = $rev->getId();
		$rec = MediaWikiServices::getInstance()->getRevisionLookup()->getRevisionById( $revid );

		$this->assertEquals( $revid2, $rec->getParentId() );
		$this->assertEquals( $expectedText, $rec->getSlot( SlotRecord::MAIN )->getContent()->getNativeData() );
	}

	/**
	 * Verify that ApproveEditConsequence detects a non-resolvable edit conflict.
	 * @covers MediaWiki\Moderation\ApproveEditConsequence
	 * See also: ModerationMergeTest::testMerge()
	 */
	public function testUnresolvableEditConflict() {
		$title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$user = User::newFromName( '127.0.0.1', false );

		// Edits shouldn't be intercepted (including edit caused by approval).
		$this->setMwGlobals( 'wgModerationEnable', false );

		list( $revid1, $revid2 ) = $this->makeTwoEdits( $title,
			"Normal line 1\nNormal line 2\nNormal line 3\n",
			"Normal line 1\nLine 2 was modified\nNormal line 3\n"
		);

		$textToApprove = "Normal line 1, but second line was deleted\nNormal line 3\n";

		// Create and run the Consequence.
		$consequence = new ApproveEditConsequence(
			$user, $title, $textToApprove, '', false, false, $revid1 );
		$status = $consequence->run();

		$this->assertFalse( $status->isOK(),
			"ApproveEditConsequence returned incorrect Success for unresolvable edit conflict." );

		$this->assertEquals( 'moderation-edit-conflict', $status->getMessage()->getKey(),
			"ApproveEditConsequence didn't return \"moderation-edit-conflict\" error." );

		$this->assertEquals( $revid2, $title->getLatestRevID( IDBAccessObject::READ_LATEST ),
			"Page was modified after ApproveEditConsequence on unresolvable edit conflict." );
	}

	/**
	 * Make two edits in the same page with two different users.
	 * @param Title $title
	 * @param string $text1
	 * @param string $text2
	 * @return int[] Array of rev_id of both edits
	 */
	public function makeTwoEdits( Title $title, $text1, $text2 ) {
		$revIds = [];
		$revIds[] = $this->makeEdit( $title, User::newFromName( '127.0.0.2', false ), $text1 );
		$revIds[] = $this->makeEdit( $title, User::newFromName( '127.0.0.3', false ), $text2 );
		return $revIds;
	}
}
