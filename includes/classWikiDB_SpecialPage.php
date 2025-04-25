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
 * Special page base classes
 * These classes extend the standard IncludableSpecialPage to add a couple of
 * standard functions that WikiDB needs.
 * There is one class that provides general-purpose functionality (mostly for
 * backwards-compatibility purposes) and a more specific class to provide shared
 * functionality for list pages.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2443 $
 */

////////////////////////////////////////////////////////////////////////////////
// WikiDB_SpecialPage
// Base class used by all special pages, to provide some functionality that all
// WikiDB special pages will need.
// Primarily, this relates to backwards-compatibility.

abstract class WikiDB_SpecialPage extends IncludableSpecialPage {

// getSkin()
// As of MW 1.18, the recommended method of getting the skin from within a special
// page is to call $this->getSkin().  The old method of pulling it from $wgUser
// is deprecated, and was removed in MW 1.27.
// To maintain backwards-compatibility with pre-1.18 versions, we have this wrapper
// function, which calls the parent's getSkin() if it exists, otherwise uses the old
// $wgUser method.
// @back-compat MW < 1.18
	function getSkin() {
		if (method_exists(get_parent_class($this), "getSkin")) {
			return parent::getSkin();
		}
		else {
		// $wgUser is already deprecated and will ultimately be removed so, to avoid
		// confusion, we put the global declaration in this backwards-compatibility
		// code branch, not at the top of the function (as best-practice would
		// normally dictate).
			global $wgUser;

			return $wgUser->getSkin();
		}
	}

// getOutput()
// Backwards-compatibility shim, as the getOutput() function was only added to the
// SpecialPage class in MediaWiki 1.18.
// Our version calls the parent function, if it exists, otherwise it returns the
// global $wgOut variable, which is how this was handled on older MediaWiki versions.
// @back-compat MW < 1.18
	function getOutput() {
		global $wgOut;

		if (method_exists(get_parent_class($this), "getOutput"))
			return parent::getOutput();
		else
			return $wgOut;
	}

// getRequest()
// Backwards-compatibility shim, as the getRequest() function was only added to the
// SpecialPage class in MediaWiki 1.18.
// Our version calls the parent function, if it exists, otherwise it returns the
// global $wgRequest variable, which is how this was handled on older MediaWiki
// versions.
// @back-compat MW < 1.18
	function getRequest() {
		global $wgRequest;

		if (method_exists(get_parent_class($this), "getRequest"))
			return parent::getRequest();
		else
			return $wgRequest;
	}

// pGetLinkToTitle()
// The method of generating a link from a Skin object (which extends the Linker
// class) has also changed.
// See WikiDB_MakeLink() for a description of what the changes were and when they
// occurred.
// TODO: Can this be reworked to use WikiDB_MakeLink(), to avoid the duplication?
//		 The behaviour is a bit different, as the SpecialPage class has access to
//		 some local context that isn't otherwise available, so it may not be
//		 possible.
	function pGetLinkToTitle($Title) {
	// If this is MW >= 1.28, then the special page now has a LinkRenderer object
	// available to it, so we use that.
		if (method_exists($this, "getLinkRenderer")) {
			$LinkRenderer = $this->getLinkRenderer();
			return $LinkRenderer->makeLink($Title);
		}
	// Otherwise, we are using an older version of MediaWiki.
		else {
			$Skin = $this->getSkin();

		// If the $Skin object has a 'link' function, we call it.  This is required
		// for MW versions between the function being implemented and before it being
		// rewritten to work statically (where we need to call it on an object
		// instance).
		// @back-compat MW < 1.18
			if (method_exists($Skin, "link")) {
				return $Skin->link($Title);
			}
		// If none of the above apply, we are on a MW version between the point where
		// Linker::link() became a static function and where the LinkRenderer class
		// was introduced (in MW 1.28).  In this case, the static function is the one
		// to use.
		// @back-compat MW < 1.28
			else {
				return Linker::link($Title);
			}
		}
	}

} // END class WikiDB_SpecialPage

////////////////////////////////////////////////////////////////////////////////
// WikiDB_ListBasedSpecialPage
// Provides shared functionality used by all list-based special pages.
// This includes functionality relating to managing the database queries that
// power the page.

abstract class WikiDB_ListBasedSpecialPage extends WikiDB_SpecialPage {

	var $pItemsPerPage = 50;
	var $pOffset = 0;

	function execute($PathInfo) {
		$fname = get_class($this) . "::" . __FUNCTION__;

		$this->pSetupPagination($PathInfo);
		$TextKeyPrefix = $this->GetTextKeyPrefix();

		$DB = wfGetDB(DB_REPLICA);

		$QueryInfo = $this->GetQuery($DB);
		$QueryInfo = $this->pTidyQueryInfo($QueryInfo);

		$objOutput = $this->getOutput();

	// Don't show the navigation if we're including the page.
		if (!$this->mIncluding) {
			$this->setHeaders();
			WikiDB_AddWikiText(WikiDB_Msg($TextKeyPrefix . '-header'), $objOutput);

			$RowCount = $this->pGetCount($QueryInfo, $DB);
			if ($RowCount > 0)
				$this->pOutputPagination($RowCount);
		}

		if (!IsBlank($QueryInfo['order'])) {
			$QueryInfo['options']['ORDER BY'] = $QueryInfo['order'];
		}

		$QueryInfo['options']['LIMIT'] = $this->pItemsPerPage;
		$QueryInfo['options']['OFFSET'] = $this->pOffset;

		$objResult = $DB->select($QueryInfo['tables'], $QueryInfo['fields'],
								 $QueryInfo['conds'], $fname, $QueryInfo['options'],
								 $QueryInfo['join_conds']);

		$RowCount = $objResult->numRows();
		if ($RowCount > 0) {
		// Make list
			$objOutput->addHTML("<ol>\n");
			while ($row = $objResult->fetchObject())
				$objOutput->addHTML($this->pMakeListItem($row));
			$objOutput->addHTML("</ol>\n");
		}
		else {
			WikiDB_AddWikiText(WikiDB_Msg($TextKeyPrefix . '-none'), $objOutput);
		}
		$objResult->free();
	}

	abstract function GetQuery($DB);
	abstract function GetTextKeyPrefix();
	abstract function pMakeListItem($Row);

	function pSetupPagination($PathInfo) {
		$objRequest = $this->getRequest();

	// Get the 'items per page' from the path info provided.
		$ItemsPerPage = intval($PathInfo);

	// If the path info was set to zero, contained an invalid integer or was not
	// present at all, ignore it and instead use the 'limit' argument (falling-back
	// to our built-in default if this is also not set).
		if ($ItemsPerPage == 0) {
			$ItemsPerPage = $objRequest->getIntOrNull('limit');
			if (!$ItemsPerPage)
				$ItemsPerPage = 50;
		}

		$this->pItemsPerPage = $ItemsPerPage;

	// Setup offset of current page (zero-based).
		$this->pOffset = $objRequest->getInt('offset');
	}

	function pOutputPagination($TotalRows) {
		$objOutput = $this->getOutput();

		$objOutput->addHTML('<p>'
							. wfShowingResults($this->pOffset, $this->pItemsPerPage)
							. '</p>');

		$LastRowOnPage = $this->pOffset + $this->pItemsPerPage;
		if ($LastRowOnPage >= $TotalRows)
			$IsLastPage = true;
		else
			$IsLastPage = false;

		$PageName = WikiDB_GetLocalSpecialPageName($this->mName);
		$Title = Title::newFromText($PageName);

		$Output = WikiDB_GetPaginationLinks(
								 $this->pOffset,
								 $this->pItemsPerPage,
								 $Title,
								 array(),
								 $IsLastPage,
								 $this
								);

		$objOutput->addHTML( '<p>' . $Output . '</p>' );
	}

	function pTidyQueryInfo($QueryInfo) {
		$arrExpectedFields = array('tables', 'fields', 'conds', 'order', 'options',
								   'join_conds');

		foreach ($arrExpectedFields as $Key) {
			if (isset($QueryInfo[$Key]))
				$QueryInfo[$Key] = (array) $QueryInfo[$Key];
			else
				$QueryInfo[$Key] = array();
		}

	// MW 1.18 and above support arguments being specified as arrays in all cases
	// where this is appropriate.  However, for older versions, we need to manually
	// collapse them into strings where necessary.
	// @back-compat MW < 1.18
		if (version_compare(MW_VERSION, 1.18, "<")) {
			if (isset($QueryInfo['order']) && is_array($QueryInfo['order'])) {
				$QueryInfo['order'] = implode(", ", $QueryInfo['order']);
			}

			if (isset($QueryInfo['options']['GROUP BY'])
				&& is_array($QueryInfo['options']['GROUP BY']))
			{
				$QueryInfo['options']['GROUP BY']
								= implode(", ", $QueryInfo['options']['GROUP BY']);
			}
		}

	// Return the tidied array, with all expected elements present.
		return $QueryInfo;
	}

	function pGetCount($QueryInfo, $DB) {
		$fname = get_class($this) . "::" . __FUNCTION__;

		$objResult = $DB->select($QueryInfo['tables'], $QueryInfo['fields'],
								 $QueryInfo['conds'], $fname, $QueryInfo['options'],
								 $QueryInfo['join_conds']);

		$Count = 0;
		if ($objResult) {
			$Count = $objResult->numRows();
			$objResult->free();
		}

		return $Count;
	}

} // END class WikiDB_ListBasedSpecialPage
