'use strict';
const Page = require( './page' );

/**
	@brief Represents the editing form of MobileFrontend.
*/

class MobileFrontend extends Page {

	/** @brief "Edit" icon (opens mobile editor when clicked by the user) */
	get openEditorIcon() { return $( '.mw-ui-icon-edit-enabled,#ca-edit.enabled' ); }

	/** @brief Editable element in the editor */
	get content() { return $( '#wikitext-editor,.wikitext-editor' ); }

	/** @brief Button to close "You are not logged in" dialog */
	get editAnonymouslyButton() { return $( 'a=Edit without logging in' ); }

	/** "Next" button (navigates from textarea screen to "Enter edit summary" screen) */
	get nextButton() { return $( '.continue' ); }

	/** "Save" button on the "Enter edit summary" screen */
	get saveButton() { return $( '.submit' ); }

	/** @brief "Summary" field in "Describe what you changed" dialog */
	get summary() { return this.getWhenVisible( '.summary' ); }

	/**
		@brief Text in "Something went wrong" dialog.
	*/
	get errMsg() {
		return $( '.mw-notification-type-error' );
	}

	/**
		@returns Displayed error (if any).
		@retval null No error.
	*/
	get error() {
		return this.errMsg.isVisible() ? this.errMsg.getText() : null;
	}

	/**
		@brief Open MobileFrontend editor for article "name".
	*/
	open( name, section = 0 ) {
		this.switchToMobileSkin();

		/* Make sure that post-edit redirect of MobileFrontend will take us to the article.
			Also a workaround against https://github.com/mozilla/geckodriver/issues/790 */
		super.open( name );

		var hashPath = '#/editor/';
		if ( browser.options.is1_23 ) {
			hashPath = '#editor/'; // No leading slash
		}

		/* Wait for haschange event to become aware of hashPath */
		this.openEditorIcon.waitForExist();

		super.open( name + hashPath + section );
		this.content.waitForExist();

		if ( this.editAnonymouslyButton.isExisting() ) {
			this.editAnonymouslyButton.click();
		}

		this.content.waitForVisible();
	}

	/**
		@brief Edit the page in MobileFrontend.
		@param name Page title, e.g. "List of Linux distributions".
		@param section Section number, e.g. 0.
		@param content Page content (arbitrary text).
		@param summary Edit comment (e.g. "fixed typo").
	*/
	edit( name, section, content, summary = '' ) {
		this.open( name, section );
		this.content.setValue( content );
		this.nextButton.click();

		if ( summary !== false ) {
			this.summary.setValue( summary );
		}

		/* Suppress "Are you sure you want to create a new page?" dialog.
			Overwriting window.confirm is not supported in IE11,
			catching alert with alertAccept() - not supported in Safari.
		*/
		browser.execute( function() {
			window.confirm = function() { return true; };
			return true;
		} );

		this.submitAndWait( this.saveButton );
	}
}

module.exports = new MobileFrontend();
