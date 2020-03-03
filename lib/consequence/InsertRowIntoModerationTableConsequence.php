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
 * Consequence that inserts new row into the "moderation" SQL table.
 */

namespace MediaWiki\Moderation;

use ModerationVersionCheck;
use RollbackResistantQuery;

class InsertRowIntoModerationTableConsequence implements IConsequence {
	/** @var array */
	protected $fields;

	/**
	 * @param array $fields
	 */
	public function __construct( array $fields ) {
		$this->fields = $fields;
	}

	/**
	 * Execute the consequence.
	 * @return int mod_id of affected row.
	 */
	public function run() {
		$uniqueFields = [
			'mod_preloadable',
			'mod_namespace',
			'mod_title',
			'mod_preload_id'
		];
		if ( ModerationVersionCheck::hasModType() ) {
			$uniqueFields[] = 'mod_type';
		}

		$dbw = wfGetDB( DB_MASTER );
		RollbackResistantQuery::upsert( $dbw, [
			'moderation',
			$this->fields,
			[ $uniqueFields ],
			$this->fields,
			__METHOD__
		] );

		// FIXME: we can't rely on $dbw->insertId() for PostgreSQL, because $dbw->upsert()
		// doesn't use native UPSERT, and instead does UPDATE and then INSERT IGNORE.
		// Since values of PostgreSQL sequences always increase (even after no-op INSERT IGNORE),
		// $dbw->insertId() will return the sequence number after INSERT.
		// But if changes were caused by UPDATE (not by INSERT), then this number won't be correct
		// (we would want insertId() from UPDATE, which is lost during $dbw->upsert()).

		return $dbw->insertId();
	}
}
