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
 * Constants required by WikiDB.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2439 $
 */

/////////////////////
// MediaWiki Backwards-Compatibility Fixes
// These built-in MediaWiki constants may not be present in older MediaWiki versions.
// To ensure compatibility with all supported versions, we define them here if not
// already defined.
/////////////////////

// DB_REPLICA was introduced in MW 1.27, at which point DB_SLAVE was made into an
// alias.  DB_SLAVE was removed altogether in MW 1.30 (or possibly 1.34, as it is
// also mentioned in those release notes!).
// To maintain support for older versions of MediaWiki, if DB_REPLICA is not defined,
// then we define it here.
// @back-compat MW < 1.27
	if (!defined("DB_REPLICA")) {
		define("DB_REPLICA", DB_SLAVE);
	}

// DB_PRIMARY was introduced in MW 1.36, at which point DB_MASTER was made into an
// alias.
// To maintain support for older versions of MediaWiki, if DB_PRIMARY is not defined,
// then we define it here.
// @back-compat MW < 1.36
	if (!defined("DB_PRIMARY")) {
		define("DB_PRIMARY", DB_MASTER);
	}

/////////////////////
// External constants
// These constants are defined for use by people customising WikiDB.
/////////////////////

// Constants for use in custom Type functions.
	define("WIKIDB_Validate",			0);
	define("WIKIDB_FormatForDisplay",	1);
	define("WIKIDB_FormatForSorting",	2);
	define("WIKIDB_GetSimilarity",		3);

// $wgWikiDBMaxRefreshRate can be either set to a number indicating the number of
// stale rows to refresh on each page view, or to one of the following constants.
// The first disables this auto-refresh completely (presumably because you are
// handling this via a cron job) and the second ensures all stale rows are updated
// on every page request, so there is never any stale data.
	define("WIKIDB_DisableAutoRefresh",	-1);
	define("WIKIDB_RefreshAll",			0);

/////////////////////
// Internal constants
// These are 'private' constants that should not need to be used outside of the
// WikiDB code.
/////////////////////

// For compatibility with all supported MediaWiki versions, we define a local
// constant to match Revision::RAW/RevisionRecord::RAW, depending on what is
// available.
// Once we drop support for MW < 1.32 then this constant can be removed and we can
// revert to using RevisionRecord::RAW directly.  That said, it may keep the code
// simpler to retain the constant, and therefore avoid having to use the long
// namespace path.  I'll decide when we get there.
// Note that the RevisionRecord class was introduced in MW 1.31, but under a
// different namespace (MediaWiki\Storage).  In MW 1.32 it was moved to the current
// location (MediaWiki\Revision).  We allow for both locations, in order to ensure
// compatibility with both revisions.
	if (class_exists("MediaWiki\\Revision\\RevisionRecord")) {
	// To ensure the code remains syntax-compatible with all supported versions of
	// PHP, we can't use a literal namespace.  Nor can we use $ClassName::CONSTANT.
	// We therefore need to use eval() in order to access the class constant.
	// @back-compat PHP < 5.3 (MW < 1.20)
		$RawConstant = eval("return MediaWiki\\Revision\\RevisionRecord::RAW;");
	}
// @back-compat MW < 1.32
	elseif (class_exists("MediaWiki\\Storage\\RevisionRecord")) {
	// To ensure the code remains syntax-compatible with all supported versions of
	// PHP, we can't use a literal namespace.  Nor can we use $ClassName::CONSTANT.
	// We therefore need to use eval() in order to access the class constant.
	// @back-compat PHP < 5.3 (MW < 1.20)
		$RawConstant = eval("return MediaWiki\\Storage\\RevisionRecord::RAW;");
	}
// @back-compat MW < 1.31
	else {
		$RawConstant = Revision::RAW;
	}
	define("WIKIDB_RawRevisionText", $RawConstant);

// Table names
	define("pWIKIDB_tblTables",		"wikidb_tables");
	define("pWIKIDB_tblRows",		"wikidb_rowdata");
	define("pWIKIDB_tblFields",		"wikidb_fielddata");

// Constants used when manipulating the array of defined Types in class
// WikiDB_TypeHandler.
	define("pWIKIDB_AddType",				0);
	define("pWIKIDB_GetCallbackFunction",	1);
	define("pWIKIDB_GetAllTypes",			2);
	define("pWIKIDB_TypeDefined",			3);

// The reporting interval dictates how often we should report our progress when
// running command-line update scripts.
	define("pWIKIDB_ReportingInterval",		100);

// The default value of $wgWikiDBMaxRefreshRate, if the user doesn't specify.
// Currently this value is completely arbitrary - we should do some fine tuning
// once we have seen how this works in practice.
	define("pWIKIDB_DefaultMaxRefreshRate",	100);
