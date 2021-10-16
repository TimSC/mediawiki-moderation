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
 * Unit test of ApproveUploadConsequence.
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Moderation\ApproveUploadConsequence;

require_once __DIR__ . "/autoload.php";

/**
 * @group Database
 */
class ApproveUploadConsequenceTest extends ModerationUnitTestCase {
	use UploadTestTrait;

	/** @var string[] */
	protected $tablesUsed = [ 'user', 'page', 'uploadstash', 'image', 'oldimage',
		'logging', 'log_search' ];

	/**
	 * Verify that ApproveUploadConsequence uploads a new file.
	 * @covers MediaWiki\Moderation\ApproveUploadConsequence
	 * @covers ModerationUploadStorage
	 * @dataProvider dataProviderApproveUpload
	 * @param bool $existing
	 */
	public function testApproveUpload( $existing ) {
		$user = self::getTestUser()->getUser();
		$title = Title::newFromText( 'File:UTUpload-' . rand( 0, 100000 ) . '.png' );
		$comment = 'Upload comment';
		$pageText = 'New description text';

		// Uploads shouldn't be intercepted (including upload caused by approval).
		$this->setMwGlobals( 'wgModerationEnable', false );

		if ( $existing ) {
			// Precreate file with the same name.
			$initialText = 'Description text of image that already exists';

			$upload = $this->prepareTestUpload( $title );
			$upload->performUpload( '', $initialText, false, $user );

			$expectedText = $initialText; // Reuploads shouldn't modify the File: description page.
		} else {
			$expectedText = $pageText;
		}

		$stash = ModerationUploadStorage::getStash();
		$stashKey = $stash->stashFile( $this->anotherSampleImageFile )->getFileKey();

		// Create and run the Consequence.
		$consequence = new ApproveUploadConsequence( $stashKey, $title, $user, $comment, $pageText );
		$status = $consequence->run();

		$this->assertTrue( $status->isOK(),
			"ApproveUploadConsequence failed: " . $status->getMessage()->plain() );

		// Check whether the newly approved file has been uploaded.
		$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $title->getText(), [ 'latest' => true ] );
		$this->assertEquals( $comment, $file->getDescription() );

		$uploader = method_exists( $file, 'getUploader' ) ?
			$file->getUploader( File::RAW ) : // MediaWiki 1.36+
			$file->getUser( 'object' ); // MediaWiki 1.35 only

		$this->assertEquals( $user->getName(), $uploader->getName() );
		$this->assertEquals( $user->getId(), $uploader->getId() );

		$page = WikiPage::factory( $title );
		$this->assertEquals( $expectedText, $page->getContent()->getNativeData() );
	}

	/**
	 * Provide datasets for testApproveUpload() runs.
	 * @return array
	 */
	public function dataProviderApproveUpload() {
		return [
			'upload (new image)' => [ false ],
			'reupload' => [ true ],
		];
	}

	/**
	 * Verify that ApproveUploadConsequence fails if the file can't be found in the Stash.
	 * @covers MediaWiki\Moderation\ApproveUploadConsequence
	 */
	public function testMissingStashFile() {
		$user = self::getTestUser()->getUser();
		$title = Title::newFromText( 'File:UTUpload-' . rand( 0, 100000 ) . '.png' );

		$missingStashKey = 'missing' . rand( 0, 1000000 ) . '.jpg';

		// Create and run the Consequence.
		$consequence = new ApproveUploadConsequence( $missingStashKey, $title, $user, '', '' );
		$status = $consequence->run();

		$this->assertFalse( $status->isOK(),
			"ApproveUploadConsequence didn't fail for incorrect stash key." );
		$this->assertTrue( $status->hasMessage( 'moderation-missing-stashed-image' ),
			"ApproveUploadConsequence didn't return 'moderation-missing-stashed-image' Status." );

		$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $title->getText(), [ 'latest' => true ] );
		$this->assertFalse( $file,
			"Target file exists after ApproveUploadConsequence has failed." );
	}
}
