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
 * Unit test of ModerationPreload.
 */

use MediaWiki\Moderation\EntryFactory;
use MediaWiki\Moderation\MockConsequenceManager;
use MediaWiki\Moderation\RememberAnonIdConsequence;

require_once __DIR__ . "/autoload.php";

class ModerationPreloadTest extends ModerationUnitTestCase {
	use ConsequenceTestTrait;

	/**
	 * Verify that getId() returns correct values for both logged-in and anonymous users.
	 * @param string|false $expectedResult Value that getId() should return.
	 * @param string $username
	 * @param string $existingAnonId Value of "anon_id" field in SessionData.
	 * @param bool $create This parameter is passed to getId().
	 * @dataProvider dataProviderGetId
	 *
	 * @covers ModerationPreload
	 */
	public function testGetId( $expectedResult, $username, $existingAnonId, $create ) {
		RequestContext::getMain()->getRequest()->setSessionData( 'anon_id', $existingAnonId );

		$entryFactory = $this->createMock( EntryFactory::class );
		$user = $this->createMock( User::class );

		$manager = new MockConsequenceManager();
		$expectedConsequences = [];

		if ( User::isIP( $username ) ) {
			$user->expects( $this->once() )->method( 'isLoggedIn' )->willReturn( false );
			$user->expects( $this->never() )->method( 'getName' );

			if ( !$existingAnonId && $create ) {
				$manager->mockResult( RememberAnonIdConsequence::class, 'NewlyGeneratedAnonId' );
				$expectedConsequences = [
					new RememberAnonIdConsequence()
				];
			}
		} else {
			$user->expects( $this->once() )->method( 'isLoggedIn' )->willReturn( true );
			$user->expects( $this->once() )->method( 'getName' )->willReturn( $username );
		}

		'@phan-var EntryFactory $entryFactory';
		'@phan-var User $user';

		$preload = new ModerationPreload( $entryFactory, $manager );

		$preload->setUser( $user );
		$preloadId = $preload->getId( $create );

		$this->assertEquals( $expectedResult, $preloadId, "Result of getId() doesn't match expected." );
		$this->assertConsequencesEqual( $expectedConsequences, $manager->getConsequences() );
	}

	/**
	 * Provide datasets for testGetId() runs.
	 * @return array
	 */
	public function dataProviderGetId() {
		return [
			'Logged-in user, create=false' => [ '[Test user', 'Test user', null, false ],
			'Logged-in user, create=true' => [ '[Test user', 'Test user', null, true ],
			'Anonymous user, AnonId already exists in the session, create=false' =>
				[ ']ExistingAnonId', '127.0.0.1', 'ExistingAnonId', false ],
			'Anonymous user, AnonId already exists in the session, create=true' =>
				[ ']ExistingAnonId', '127.0.0.1', 'ExistingAnonId', true ],
			'Anonymous user, no AnonId in the session, create=false' =>
				[ false, '127.0.0.1', null, false ],
			'Anonymous user, no AnonId in the session, create=true' =>
				[ ']NewlyGeneratedAnonId', '127.0.0.1', null, true ],
		];
	}

	/**
	 * Verify that getId() will use main RequestContext if setUser() was never called.
	 * @covers ModerationPreload
	 */
	public function testGetIdMainContext() {
		$entryFactory = $this->createMock( EntryFactory::class );
		$manager = new MockConsequenceManager();

		$user = $this->createMock( User::class );
		$user->expects( $this->once() )->method( 'isLoggedIn' )->willReturn( true );
		$user->expects( $this->once() )->method( 'getName' )->willReturn( 'Global User' );

		'@phan-var EntryFactory $entryFactory';
		'@phan-var User $user';

		// Place $user into the global RequestContext.
		RequestContext::getMain()->setUser( $user );

		$preload = new ModerationPreload( $entryFactory, $manager );
		$preloadId = $preload->getId();

		$this->assertEquals( "[Global User", $preloadId, "Result of getId() doesn't match expected." );
		$this->assertNoConsequences( $manager );
	}

	/**
	 * Verify that findPendingEdit() returns expected PendingEdit object.
	 * @covers ModerationPreload
	 */
	public function testFindPendingEdit() {
		RequestContext::getMain()->getRequest()->setSessionData( 'anon_id', 'ExistingAnonId' );
		$title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );

		$entryFactory = $this->createMock( EntryFactory::class );
		$manager = new MockConsequenceManager();

		$entryFactory->expects( $this->once() )->method( 'findPendingEdit' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( ']ExistingAnonId' ),
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( $title )
		)->willReturn( '{MockedResultFromFactory}' );

		'@phan-var EntryFactory $entryFactory';

		$preload = new ModerationPreload( $entryFactory, $manager );
		$pendingEdit = $preload->findPendingEdit( $title );

		$this->assertSame( '{MockedResultFromFactory}', $pendingEdit,
			"Result of findPendingEdit() doesn't match expected." );
		$this->assertNoConsequences( $manager );
	}

	/**
	 * Verify that findPendingEdit will return false if current user doesn't have an existing AnonId.
	 * @covers ModerationPreload
	 */
	public function testNoPendingEdit() {
		$title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$manager = new MockConsequenceManager();

		$entryFactory = $this->createMock( EntryFactory::class );
		$entryFactory->expects( $this->never() )->method( 'findPendingEdit' );

		'@phan-var EntryFactory $entryFactory';

		$preload = new ModerationPreload( $entryFactory, $manager );
		$pendingEdit = $preload->findPendingEdit( $title );

		$this->assertFalse( $pendingEdit,
			"findPendingEdit() should return false for anonymous users who haven't edited." );
		$this->assertNoConsequences( $manager );
	}
}
