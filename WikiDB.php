<?php
if (!defined('MEDIAWIKI'))
	die("MediaWiki extensions cannot be run directly.");
/**
 * WikiDB
 * A MediaWiki extension which adds database functionality to your wiki, which
 * behaves in a truly wiki-like way.
 *
 * The extension is fairly well developed, but there are a lot more features
 * in the pipeline.  Visit my test wiki for documentation and updates, to get help
 * or to leave feedback.  See the extension credits (below) for the URL.  Bug
 * reports, patches and feature suggestions welcome.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2455 $
 */

// The $wgVersion global variable was deprecated in MW 1.35, in favour of a new
// constant.  All code should use the constant rather than the variable, but for
// backwards compatibility we create the constant if it is not already defined.
// Note that the constant was back-ported to other release branches, but was not
// present in the *.0 release, so they do not affect the minimum version requirements
// for removing this shim.
// @back-compat MW < 1.35
	if (!defined("MW_VERSION"))
		define("MW_VERSION", $wgVersion);

// WikiDB requires MW 1.16 or above.
// For reference, MediaWiki 1.16 supports PHP 5.1 and above, therefore that is our
// minimum PHP requirement.
	if (version_compare(MW_VERSION, '1.16', '<')) {
		WikiDB_FatalError("Incompatible MediaWiki Extension",
						  "WikiDB cannot be installed on MediaWiki versions earlier "
						  . "than 1.16.0.  You need to disable the extension, or "
						  . "upgrade your copy of MediaWiki.");
	}

//////////////////////////
// EXTENSION CREDITS

// The version number, manually updated just prior to release.
// The number is in the form X.Y.Z and follows the semantic versioning conventions
// documented at http://www.kennel17.co.uk/testwiki/WikiDB/Versioning.
	$pWikiDB_Version = "5.1.4";

	$wgExtensionCredits['other'][] = array(
		'path' => __FILE__,
		'name' => "WikiDB",
		'author' => "Mark Clements",
		'descriptionmsg' => 'wikidb-description',
		'version' => $pWikiDB_Version,
		'url' => "http://www.kennel17.co.uk/testwiki/WikiDB",
		'license-name' => "CC-BY-SA-2.0-UK",
	);

//////////////////////////
// INCLUDE FILES

// Need to define WikiDB to let them know that it is safe to load.
	define("WikiDB", true);

// We calculate the include path here, so it only needs to be done once.
	$pWikiDB_IncludePath = dirname(__FILE__) . "/includes/";

// Support files.
	require_once($pWikiDB_IncludePath . "Constants.php");
	require_once($pWikiDB_IncludePath . "Utility.php");
	require_once($pWikiDB_IncludePath . "Regex.php");
	$wgAutoloadClasses["pWikiDB_BuiltInTypeHandlers"]
							= $pWikiDB_IncludePath . "BuiltInTypes.php";

// Core WikiDB classes.
	$wgAutoloadClasses["WikiDB"]
							= $pWikiDB_IncludePath . "classWikiDB.php";
	$wgAutoloadClasses["WikiDB_Table"]
							= $pWikiDB_IncludePath . "classWikiDB_Table.php";
	$wgAutoloadClasses["WikiDB_Query"]
							= $pWikiDB_IncludePath . "classWikiDB_Query.php";
	$wgAutoloadClasses["WikiDB_QueryResult"]
							= $pWikiDB_IncludePath . "classWikiDB_QueryResult.php";
	$wgAutoloadClasses["WikiDB_Parser"]
							= $pWikiDB_IncludePath . "classWikiDB_Parser.php";
	$wgAutoloadClasses["WikiDB_HTMLRenderer"]
							= $pWikiDB_IncludePath . "classWikiDB_HTMLRenderer.php";
	$wgAutoloadClasses["WikiDB_TypeHandler"]
							= $pWikiDB_IncludePath . "classWikiDB_TypeHandler.php";

// Special page base classes, providing some common functionality.
	$wgAutoloadClasses["WikiDB_SpecialPage"]
							= $pWikiDB_IncludePath . "classWikiDB_SpecialPage.php";
	$wgAutoloadClasses["WikiDB_ListBasedSpecialPage"]
							= $pWikiDB_IncludePath . "classWikiDB_SpecialPage.php";

// Special pages.
	$pWikiDB_SpecialPath = $pWikiDB_IncludePath . "specials/";

	$wgAutoloadClasses["WikiDB_SP_UndefinedTables"]
							= $pWikiDB_SpecialPath . "UndefinedTables.php";
	$wgAutoloadClasses["WikiDB_SP_EmptyTables"]
							= $pWikiDB_SpecialPath . "EmptyTables.php";
	$wgAutoloadClasses["WikiDB_SP_AllTables"]
							= $pWikiDB_SpecialPath . "AllTables.php";

// Other MW interface hooks.
	require_once($pWikiDB_IncludePath . "Hooks.php");
	require_once($pWikiDB_IncludePath . "ParserTags.php");

// From MW 1.19 onwards, custom actions are handled via the $wgActions array.
// For earlier versions of MediaWiki we need to use the UnknownAction hook.
// We define the appropriate one depending on the MediaWiki version.
// @back-compat MW < 1.19
	if (version_compare(MW_VERSION, '1.19', '>=')) {
		$wgAutoloadClasses["WikiDB_ViewDataAction"]
								= $pWikiDB_IncludePath . "Actions.php";
		$wgActions['viewdata'] = "WikiDB_ViewDataAction";
	}
	else {
		$wgHooks['UnknownAction'][] = "efWikiDB_SpecialActions";
	}

	unset($pWikiDB_IncludePath);

//////////////////////////
// HOOK REGISTRATION

// Update our internal tables when page content is modified.
// The LinksUpdate hook is run whenever the internal links table is updated for
// a page.  This happens when a page is saved.  However it also happens when a
// template that the page uses is changed (which is triggered via the job queue).
// By using this hook we ensure that our internal data structures are updated
// whenever the page content changes even if the wikitext for that page is not
// directly edited (i.e. it changes due to transclusion).
// As of MW 1.33, this hook is also triggered when a page is deleted.  For older MW
// versions, the ArticleDeleteComplete hook is used, instead.
// Note that, for page moves, the hook is fired for the destination page in all
// cases, but is only fired for the source page if a redirect is left behind.  If
// a redirect is not left then LinksUpdate does not fire for the original location
// (tested on MW 1.37).  This means that the TitleMoveComplete/PageMoveComplete
// hooks are still required in order to ensure the DB is updated to reflect the
// removal of the source page, after a move.
	$wgHooks['LinksUpdate'][] = 'efWikiDB_LinksUpdate';

// Update our internal tables when a page is deleted.
// From MW 1.33 onwards, the LinksUpdate hook is called when an article is deleted,
// so no special hook is required for modern MW versions.
// For earlier versions, we need to use a custom hook to trigger the data update for
// WikiDB.  Note that 'ArticleDeleteComplete' was deprecated in MW 1.37.
// @back-compat MW < 1.33
	if (version_compare(MW_VERSION, '1.33', '<')) {
		$wgHooks['ArticleDeleteComplete'][] = "efWikiDB_ArticleDeleteComplete";
	}

// Update our internal tables when a page is undeleted.
	$wgHooks['ArticleUndelete'][] = 'efWikiDB_ArticleUndelete';

// Update our internal tables when a page is moved.
// The TitleMoveComplete hook was hard deprecated in MW 1.35, and has been replaced
// with the new PageMoveComplete hook.
// We choose which hook to use based on the version of MediaWiki we are running on.
// @back-compat MW < 1.35
	if (version_compare(MW_VERSION, '1.35', '<'))
		$wgHooks['TitleMoveComplete'][] = 'efWikiDB_TitleMoveComplete';
	else
		$wgHooks['PageMoveComplete'][] = 'efWikiDB_PageMoveComplete';

// Add hook to handle rendering of pages in the table namespace.
	$wgHooks['ParserBeforeInternalParse'][] = "efWikiDB_ParserBeforeInternalParse";

// Add the 'viewdata' tab to page views in table namespaces.
// MW 1.18 replaced some of the SkinTemplate* hooks with new hooks that have
// been generalised, and which are designed around the new Vector skin.
// We therefore need to set up an additional callback in order to ensure that
// the 'viewdata' tab is present in 1.18.  In earlier versions, the
// 'SkinTemplateContentActions' will be used.  In 1.18 and above, this hook no
// longer exists, and 'SkinTemplateNavigation::Universal' is used instead.
// As the old hook was removed at the same time as the new one was added, we
// don't need to do a version check to avoid the hook being called twice.
// @back-compat MW < 1.18
	$wgHooks['SkinTemplateContentActions'][] = "efWikiDB_AddActionTabs";
	$wgHooks['SkinTemplateNavigation::Universal'][] = "efWikiDB_SkinHooks";

// Display related tables/data sources when editing a page.
	$wgHooks['EditPage::showEditForm:initial'][]
									= 'efWikiDB_EditPage_showEditForm_initial';

// Add our custom CSS files to the page.
	$wgHooks['BeforePageDisplay'][] = "efWikiDB_AddCSS";

// For MW 1.35+, use PageSaveComplete to ensure data is updated on save
if ( version_compare( MW_VERSION, '1.35', '>=' ) ) {
	$wgHooks['PageSaveComplete'][] = function ( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
			if ( class_exists( 'WikiDB' ) ) {
					WikiDB::PageUpdated( $wikiPage );
			}
			return true;
	};
}
//////////////////////////
// OTHER EXTENSION REGISTRATION

// Register special pages.
	$wgSpecialPages['AllTables'] = 'WikiDB_SP_AllTables';
	$wgSpecialPages['UndefinedTables'] = 'WikiDB_SP_UndefinedTables';
	$wgSpecialPages['EmptyTables'] = 'WikiDB_SP_EmptyTables';

// Specify the location of our messages file.
	$wgExtensionMessagesFiles['WikiDB'] = dirname( __FILE__ ) . '/WikiDB.i18n.php';

// Register parser hooks.
// Although this is implemented via a hook function, it is really part of the
// extension registration, so we declare it in this section and define the callback
// locally, rather than in Hooks.php.
	$wgHooks['ParserFirstCallInit'][] = "efWikiDB_RegisterParserHooks";

	function efWikiDB_RegisterParserHooks(&$Parser) {
		$Parser->setHook("data", "efWikiDB_DataTag");
		$Parser->setHook("repeat", "efWikiDB_RepeatTag");
		$Parser->setHook("guesstypes", "efWikiDB_GuessTypes");
		return true;
	}

// Register unit test files.
// Although this is implemented via a hook function, it is really part of the
// extension registration, so we declare it in this section and define the callback
// locally, rather than in Hooks.php.
// Note that the hook was introduced in MediaWiki 1.17.0, so it is not possible to
// run the unit tests against earlier MediaWiki versions.
// @back-compat MW < 1.17.0 not supported.
// Also, as of MW 1.28, test files will be automatically located without the need for
// this hook so long as the files are in the correct location and use the correct
// naming convention, which is the case for our tests.  Therefore, in our case, the
// hook isn't necessary for MW 1.28 and above.
// @back-compat MW < 1.28.0
	$wgHooks['UnitTestsList'][] = "efWikiDB_RegisterUnitTests";

	function efWikiDB_RegisterUnitTests(&$arrPaths) {
		$TestPath = dirname(__FILE__) . "/tests/phpunit/";

	// If we are using MW 1.24 or above we only need to add the test directory to
	// the array.
		if (version_compare(MW_VERSION, '1.24', '>=')) {
			$arrPaths[] = $TestPath;
		}
	// For later versions, we need to add the individual files.
	// We don't use the File_Iterator_Facade class that is used in MW 1.24 and
	// above, as this may not be present in earlier versions (I haven't checked,
	// but I presume it is not available in 1.17, which is the earliest version
	// that uses this hook).
	// We therefore use our own implementation that only utilises built-in PHP
	// classes, available in all supported PHP versions.
	// * RecursiveDirectoryIterator and RecursiveIteratorIterator were added in
	//   PHP 5.0.
	// * RegexIterator and RecursiveRegexIterator were added in PHP 5.2.  Although
	//   the extension supports PHP 5.1, this hook was only added in MW 1.17,
	//   which raises the minimum requirement to PHP 5.2.3, therefore this is not an
	//   issue in this case.
	// @back-compat MW < 1.24.0
		else {
		// Set up a regex to match against files that are named *Test.php - all other
		// files are ignored.
			$FileRegex = '/Test\.php$/i';

		// We want to search all sub folders of our test path.
			$RecursedDirs = new RecursiveDirectoryIterator($TestPath);
			$FlattenedFiles = new RecursiveIteratorIterator($RecursedDirs);
			$FilteredFiles = new RegexIterator($FlattenedFiles,
											   $FileRegex,
											   RecursiveRegexIterator::GET_MATCH);

		// Add the individual matched files to the array of test paths.
			foreach ($FilteredFiles as $FilePath => $Match) {
				$arrPaths[] = $FilePath;
			}
		}

	// The MediaWikiTestCase class was renamed to MediaWikiIntegrationTestCase
	// in MW 1.34.  The original name remained as an alias until it was removed
	// in MW 1.38.  Our code uses the new name, but we need to define a class
	// alias so that our tests still work on older MediaWiki versions, prior to the
	// rename.
	// Note that, although the source file claims it was added in MW 1.18, the
	// MediaWikiTestCase class was actually added in MW 1.16, so we don't need a
	// check to ensure the class exists.
	// @back-compat MW < 1.34
		if (!class_exists('MediaWikiIntegrationTestCase')) {
		// The class_alias() function was added in PHP 5.3, so we include a fallback
		// to ensure the code still works on PHP 5.2.
		// @back-compat PHP < 5.3 (MW < 1.20)
			if (function_exists("class_alias")) {
				class_alias('MediaWikiTestCase', 'MediaWikiIntegrationTestCase');
			}
			else {
				eval('class MediaWikiIntegrationTestCase '
					 . 'extends MediaWikiTestCase {}');
			}
		}

		return true;
	}

//////////////////////////
// BOOTSTRAP CODE
// Code to be run once MediaWiki has finished initialising, as opposed to
// registration/configuration, which is handled above.
// The hook currently performs two actions: Ensuring all configuration variables
// are initialised to valid values (including performing any normalisations for
// variables that can be expressed in more than one way); and running the
// RefreshStaleData script on each page request, if this feature is enabled.

	$wgExtensionFunctions[] = "WikiDB_InitialiseConfig";

	function WikiDB_InitialiseConfig() {
		global $wgCommandLineMode;
		global $wgWikiDBNamespaces, $wgWikiDBDefaultClasses, $wgWikiDBMaxRefreshRate;

	//////////////////////////
	// CONFIGURATION SETTINGS
	// This section ensures that all configuration settings used by the extension are
	// initialised to appropriate default values if they haven't already been set.
	// We do this in an extension function so that this code runs after
	// LocalSettings.php has been fully-parsed.  This ensures that the extension
	// works correctly regardless of whether it is loaded before or after the
	// relevant configuration variables have been set by the user.

	// If $wgWikiDBNamespaces has not been set, or is otherwise blank, initialise it
	// to an empty array.
		if (IsBlank(@$wgWikiDBNamespaces)) {
			$wgWikiDBNamespaces = array();
		}
	// Otherwise, if it has been set, make sure it's a valid array.
	// In this case the value is treated as the namespace ID of a single table
	// namespace, which we convert to the standard array representation used by this
	// variable.
		elseif (!is_array($wgWikiDBNamespaces)) {
			$wgWikiDBNamespaces = array($wgWikiDBNamespaces => true);
		}

	// $wgWikiDBDefaultClasses can be used to specify a class string to be used for
	// all data tables output by WikiDB.  If this variable has not been set, it
	// defaults to MediaWiki's built-in "wikitable" class.  Note that the
	// "WikiDBDataTable" class will always be added to this class string before use
	// so that there is a consistent class name in the list, e.g. to make it
	// accessible to JS code.
		if (!isset($wgWikiDBDefaultClasses))
			$wgWikiDBDefaultClasses = "wikitable";

	// If $wgWikiDBMaxRefreshRate has not been set, initialise it to the default
	// value.  This variable can be either set to a number indicating the number of
	// stale rows to refresh on each page view, or to one of the following constants:
	// 	WIKIDB_DisableAutoRefresh
	//		Disables this auto-refresh completely (presumably because you are
	//		handling it via a cron job).
	// 	WIKIDB_RefreshAll
	//		Update all stale rows on every page request (if there are any), so there
	//		is never any stale data.
	// The optimum value for this setting will depend on how many visitors you get,
	// how large your tables are (in terms of numbers of rows) and how often your
	// table definitions change.  The default has been chosen as a good average-case
	// value, but you will probably want to customise this.
	// Note that the recommended method of refreshing stale links is to run
	// RefreshStaleData.php via a regular cron job, in which case you should set this
	// variable to WIKIDB_DisableAutoRefresh.
		if (IsBlank(@$wgWikiDBMaxRefreshRate))
			$wgWikiDBMaxRefreshRate = pWIKIDB_DefaultMaxRefreshRate;

	//////////////////////////
	// REFRESH STALE FIELD DATA
	// On each page request, we update any stale data up to the limit imposed
	// by $wgWikiDBMaxRefreshRate.  If that variable is set to the constant
	// WIKIDB_DisableAutoRefresh then auto-refresh is skipped (presumably because
	// a regular cron-job is handling it instead).
	// We also skip this in command-line mode, as there is a dedicated script for
	// this and we don't want to interfere with the running of that or any other
	// scripts.
	// In the most common case, where there is no stale data, the speed implication
	// should be negligible - if that is not the case then this will need revisiting.

		if (!$wgCommandLineMode
			&& $wgWikiDBMaxRefreshRate !== WIKIDB_DisableAutoRefresh)
		{
			WikiDB::RefreshStaleFieldData();
		}
	}

//////////////////////////
// HELPER FUNCTIONS
// These helper functions are required during the bootstrap process and therefore
// cannot be included in an external file, e.g. Utility.php.

// WikiDB_FatalError()
// Local alternative to wfHttpError() in order to allow us to output an 'invalid
// version' error message early-on in our initialisation process.
// This function is only called for unsupported MediaWiki installations and it needs
// to work on old PHP versions (including PHP 4) and without any dependencies
// external to the function (as these may not be loaded by the point this is called).
// Differences to wfHttpError()
// * $code is hard-coded to 500, so it doesn't need to be passed in.
// * We don't use $wgOut at all, as this isn't defined yet.
// * We pass the character-set into htmlspecialchars() to ensure we get the correct
//   encoding across all PHP versions.
// * We die() at the end.
	function WikiDB_FatalError($label, $desc) {
		header( "HTTP/1.0 500 $label" );
		header( "Status: 500 $label" );

		header( 'Content-type: text/html; charset=utf-8' );
		print "<html><head><title>" .
			htmlspecialchars( $label, ENT_COMPAT, "UTF-8" ) .
			"</title></head><body><h1>" .
			htmlspecialchars( $label, ENT_COMPAT, "UTF-8" ) .
			"</h1><p>" .
			htmlspecialchars( $desc, ENT_COMPAT, "UTF-8" ) .
			"</p></body></html>\n";
		die();
	}
