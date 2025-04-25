<?php
/**
 * UpdateTableNS.php
 * Rebuilds the wikidb_tabledata table, which holds the parsed field definitions for
 * all pages located in a table namespace (as defined by $wgWikiDBNamespaces).
 *
 * You should run this script whenever you add or remove a table namespace (i.e.
 * when you modify $wgWikiDBNamespaces).  Call it with the IDs of any namespaces
 * that have been added or removed from the array, separated by spaces.
 * You can specify 'all' to rebuild all table namespaces, but this is slow and
 * should only be necessary if an upgrade requires it.
 *
 * Arguments:
 *		--skip-refresh	If specified, then stale rows are not updated by the script.
 *						Instead, they will be updated over time via the job queue.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2439 $
 */

// Include header file required for all WikiDB maintenance scripts.
	require_once(dirname( __FILE__ ) . "/header.inc");

class WikiDB_UpdateTableNS extends WikiDB_Maintenance {

	function __construct() {
		parent::__construct();

		$this->mDescription = "Reparses pages in table namespaces and rebuilds the "
							. "definitions.  You should run this script whenever "
							. "you add or remove a table namespace (i.e. when you "
							. "modify \$wgWikiDBNamespaces).";

		$this->addOption('skip-refresh', "Don't refresh stale rows at the end of "
						 . "the script.", false);
		$this->addArg('NamespaceID', "Namespace ID that you want to update, or "
					  . "'all' to update all table namespaces that are currently "
					  . "defined.", true);
		$this->addArg('...', "Multiple namespace IDs can be specified.", false);
	}

	function execute() {
		global $wgWikiDBNamespaces;

	// Check the command-line arguments to get the list of namespaces to check.
		$arrNameSpaces = array();
		$TruncateTableFirst = false;

	// TODO: Is it OK to loop over $mArgs or do we need to use the supplied methods?
	//		 Using the methods is a bit more cumbersome, which is why I have done it
	//		 this way.
		foreach ($this->mArgs as $NameSpace) {
		// If 'all' specified set a flag to indicate that the whole table should be
		// wiped, and then add the table namespaces (which need rebuilding) to the
		// array.
			if (strtolower($NameSpace) == "all") {
				$TruncateTableFirst = true;
				$arrNameSpaces = array_keys($wgWikiDBNamespaces);
			// Break out of loop - no point looking at other arguments, as all
			// namespaces are already being updated.
				break;
			}

		// If an argument is not a valid positive integer then die with an error.
			if (!preg_match('/^\d+$/', $NameSpace)) {
				print("Invalid namespace ID: " . $NameSpace . "\n");
				$this->maybeHelp(true);
				die();
			}

		// Otherwise convert it to an integer and add it to our array of namespaces
		// to rebuild.
			$arrNameSpaces[] = intval($NameSpace);
		}

	// If no valid arguments found, display usage instructions and die.
	// This is most likely to happen if 'all' is specified, but no table namespaces
	// are defined.
		if (count($arrNameSpaces) == 0) {
			print("No table namespaces found.");
			$this->maybeHelp(true);
			die();
		}

	// Tidy up the input: remove any duplicates, and sort the namespaces in
	// numerical order.
		$arrNameSpaces = array_unique($arrNameSpaces);
		sort($arrNameSpaces);

	// Give a warning, as this can be a very slow script on large wikis.
		print("About to update cached table definitions for ");
		if ($TruncateTableFirst) {
			print("ALL namespaces");
		}
		else {
			print(count($arrNameSpaces) . " namespace");
			if (count($arrNameSpaces) > 1)
				print("s");
		}
		print(".\nWarning: This can be a very slow process for large databases!\n");
		self::AllowUserToCancel();

	// If a full rebuild is requested, wipe the table first.
		if ($TruncateTableFirst) {
			print("'all' specified - clearing cache completely...");
			$AffectedRows = self::ClearTableDefCache();
			self::ReportResult("deleted", $AffectedRows);
		}

		foreach ($arrNameSpaces as $NS) {
			if (!isset($wgWikiDBNamespaces[$NS])) {
			// Don't need to check $TruncateTableFirst, as you can't get here if
			// that option has been set.
				self::ReportNSUpdate("Clearing", $NS);
				$AffectedRows = self::ClearTableDefCache($NS);
				self::ReportResult("deleted", $AffectedRows);
			}
			else {
				if (!$TruncateTableFirst) {
					self::ReportNSUpdate("Clearing", $NS);
					$AffectedRows = self::ClearTableDefCache($NS);
					self::ReportResult("deleted", $AffectedRows);
				}

				self::ReportNSUpdate("Rebuilding", $NS);
				$AffectedRows = self::RebuildTableDefCache($NS);
				self::ReportResult("cached", $AffectedRows);
			}
		}

	// Update stale data rows.  If '--skip-refresh' option specified then we only
	// report how many are stale.
		if ($this->hasOption('skip-refresh')) {
			print("Fixing of stale records skipped: ");
			$this->mOptions['report'] = true;
		}
		$this->RunScript("RefreshStaleData");
	}

///////////////// HELPER FUNCTIONS

// Based on refreshLinks()
// function refreshLinks($newOnly = false, $maxLag = false, $end = 0 )
	function RebuildTableDefCache($NS) {
		$fname = __FUNCTION__;

	// Get a connection to the database.
	// TODO: Is it OK to work off a replica, here?
		$dbr = $this->GetDBConnection(DB_REPLICA);

	// Setup parser
	// Don't generate TeX PNGs (lack of a sensible current directory causes errors
	// anyway).  Built-in Maths support was removed in MW 1.18, so check for
	// existence of the constant before attempting to use it.
	// Disabling the generation of these images will improve performance but will
	// otherwise not make a difference to functionality, so there is no harm with
	// this option remaining enabled on later MW versions, where I have not yet
	// found a 'safe' way of disabling.
	// TODO: This code originally came from refreshLinks.php, but this has now been
	//		 updated to use a new MaintenanceRefreshLinksInit hook, instead.  I have
	//		 logged a bug to establish whether it is safe to use this in other
	//		 situations, such as ours: https://phabricator.wikimedia.org/T280189
	//		 I need to wait and see how that is resolved, and decide whether to call
	//		 this hook (or a more generic replacement) to reduce processing time when
	//		 the Math extension is present on MW >= 1.18 or whether this would be
	//		 inappropriate, due to the hook being specifically for refreshLinks.
	// @back-compat MW < 1.18
		if (defined("MW_MATH_SOURCE")) {
		// $wgUser is already deprecated and will ultimately be removed so, to avoid
		// confusion, we put the global declaration in this backwards-compatibility
		// code branch, not at the top of the function (as best-practice would
		// normally dictate).
		// In addition, the setOption() function was deprecated in MW 1.35 and
		// removed altogether in MW 1.38.
			global $wgUser;
			$wgUser->setOption('math', MW_MATH_SOURCE);
		}

		$objResult = $dbr->select('page', array('page_id'),
								  array('page_namespace' => $NS), $fname);

		$i = 0;
		$LastReport = "";
		while ($row = $objResult->fetchObject()) {
			if ((++$i % pWIKIDB_ReportingInterval) == 0) {
				print_c_fixed($LastReport, $i);
				$LastReport = $i;
			}

			self::ReparseArticle($row->page_id);
		}

		$objResult->free();

		print_c_fixed($LastReport, "");
		return $i;
	}

///// REPORTING

	function ReportNSUpdate($Action, $NS) {
		$NSName = WikiDB_GetCanonicalNamespaceName($NS);

	// Remove brackets, if present (as may be the case for 'main') because we don't
	// want a double set of brackets.
		if (substr($NSName, 0, 1) == "(" && substr($NSName, -1) == ")") {
			$NSName = substr($NSName, 1, -1);
		}

		print($Action . " NS-" . $NS . " (" . $NSName . ")...");
	}

	function ReportResult($Action, $AffectedRows) {
		print($AffectedRows . " table def");
		if ($AffectedRows != 1)
			print("s");
		print(" " . $Action . "\n");
	}

} // END class WikiDB_UpdateTableNS

$maintClass = 'WikiDB_UpdateTableNS';
require_once(RUN_MAINTENANCE_IF_MAIN);
