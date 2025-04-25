<?php
if (!defined('MEDIAWIKI'))
	die("MediaWiki extensions cannot be run directly.");
if (!defined('WikiDB')) {
	die("The WikiDB extension has not been set up correctly.<br>'"
		. basename(__FILE__) . "' should not be included directly.<br>Instead, "
		. "add the following to LocalSettings.php: "
		. "<tt><b>include_once(\"WikiDB/WikiDB.php\");</b></tt>");
}
/**
 * Action classes required for WikiDB.
 *
 * Only applicable to MediaWiki 1.19 and above.
 * Older versions use a different method for handling this action, which this class
 * is simply a wrapper to.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2439 $
 */

class WikiDB_ViewDataAction extends FormlessAction {

// getName()
// Returns the name of the action, i.e. the text that is used to identify it in the
// 'action' query parameter.
	public function getName() {
		return 'viewdata';
	}

// requiresWrite()
// Viewing data doesn't require write-access to the database.
// Note: I don't think this function is currently being called, as we are overriding
//		 show().  However, in case I'm wrong or this changes in the future, we
//		 implement it anyway.
	public function requiresWrite() {
		return false;
	}

// onView()
// Abstract function in the FormlessAction class, which we therefore need to create
// here.  However, as this is only called from show(), which we override locally, it
// won't actually be called.
// TODO: We are doing it this way based on suggested implementations in various
//		 places (including the core special pages in MediaWiki).  However, this feels
//		 wrong.  Either we should extend Action (instead of FormlessAction) and
//		 therefore don't need this function, or we should use FormlessAction as
//		 intended, and have our page logic here, and allow the parent's show()
//		 action to fire properly.
	public function onView() {
		return null;
	}

// show()
// Generates and outputs the page content for the action.
// This is a wrapper function around efWikiDB_HandleViewDataAction(), which handles
// the actual processing.
	public function show() {
		$objTitle = $this->getTitle();
		$objOutput = $this->getOutput();
		$objRequest = $this->getRequest();

		$Result = efWikiDB_HandleViewDataAction($objTitle, $objOutput, $objRequest);

	// If the function returns true then the action hasn't been handled, meaning we
	// were not in a table namespace.  We show the standard 'invalid action' error
	// page in this situation.
	// TODO: Would it be better to use a custom 'not in a table' error message,
	//		 instead?
		if ($Result) {
			$objOutput->setStatusCode(404);
			$objOutput->showErrorPage('nosuchaction', 'nosuchactiontext');
		}
	}

} // END: class WikiDB_ViewDataAction
