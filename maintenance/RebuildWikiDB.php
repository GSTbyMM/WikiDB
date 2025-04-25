<?php
/**
 * RebuildWikiDB.php
 * Rebuilds the WikiDB cache, by reparsing all articles in the wiki.
 * Because of this it can be very slow, particularly on large databases!
 *
 * Arguments:
 * 		--start			If specified, start from this article ID, rather than
 *						article 1.
 *  	--wipefirst		If specified, the whole cache is wiped first.  This is
 *						the only way to remove deleted articles from the cache
 *						(though, under normal operation, deleted articles should be
 *						automatically removed).
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

class WikiDB_RebuildWikiDB extends WikiDB_Maintenance {

	function __construct() {
		parent::__construct();

		$this->mDescription = "Rebuilds the whole WikiDB cache by re-parsing all "
							. "pages in the database.  This can be very slow, but "
							. "in normal use is safe to interrupt.  You can specify "
							. "a start article using --start=N.  To ensure deleted "
							. "articles are removed from the cache, use "
							. "--wipefirst.  The --skip-refresh argument can be "
							. "used to bypass the code which updates any stale "
							. "records created due to data definitions changing.";

		$this->addOption('start', "May be used to specify an article ID to start "
						 . "the rebuild from.  If omitted, the script starts from "
						 . "article 1.", false, true);
		$this->addOption('wipefirst', "Wipe the whole cache before starting the "
						 . "rebuild.  This is the only way to remove deleted "
						 . "articles from the cache (though, under normal "
						 . "operation, deleted articles should be automatically "
						 . "removed).", false);
		$this->addOption('skip-refresh', "Don't refresh stale rows at the end of "
						 . "the script.", false);
	}

	public function execute() {
	// Set variable to hold file name, which we use as the '$fname' (function name)
	// argument for the various MediaWiki functions that use it.
	// This aids profiling/debugging.
		$FunctionName = basename(__FILE__);

		$Start = null;
		if ($this->hasOption('start')) {
			$SpecifiedStart = $this->getOption('start');

		// If --start is not a valid positive integer then die with an error.
			if (!preg_match('/^\d+$/', $SpecifiedStart)) {
				print("Invalid value for --start argument: " . $SpecifiedStart
					  . "\n");
				$this->maybeHelp(true);
				die();
			}

			$Start = intval($SpecifiedStart);
		}

	// Give a warning, as this can be a very slow script on large wikis.
		print("About to update the WikiDB data cache, which involves reparsing "
			  . "ALL pages!\n");
		print("Warning: This can be a very slow process for large databases!\n");
		self::AllowUserToCancel();

	// If requested, wipe the table first.
		if ($this->hasOption('wipefirst')) {
			print("'wipefirst' specified - clearing cache completely...");
			self::ClearAllWikiDBCaches();
			print("done.\n");
		}

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

	// Get page IDs
		$SQL = "SELECT page_id FROM " . $dbr->tableName('page');
	// Skip pages in the MediaWiki namespace.
		$SQL .= " WHERE page_namespace <> " . NS_MEDIAWIKI;
	// If a start ID was specified, begin from that record.
		if (!is_null($Start))
			$SQL .= " AND page_id >= " . $Start;
	// Order them by page_id so that we can interrupt and resume intelligently.
		$SQL .= " ORDER BY page_id";

		$objResult = $dbr->query($SQL, $FunctionName);

	// Parse all pages
		$i = 0;
		$LastReport = "";
		$RowCount = $objResult->numRows();

		if (!is_null($Start))
			print("Skipping pages with a page_id lower than " . $Start . ".\n");
		print("Updating " . $RowCount . " pages: ");

		while ($row = $objResult->fetchObject()) {
			if ((++$i % pWIKIDB_ReportingInterval) == 0) {
				print_c_fixed($LastReport, $i);
				$LastReport = $i;
			}

			self::ReparseArticle($row->page_id);
		}

		$objResult->free();

		print_c_fixed($LastReport, "Complete!\n");

	// Update stale data rows.  If '--skip-refresh' option specified then we only
	// report how many are stale.
		if ($this->hasOption('skip-refresh')) {
			print("Fixing of stale records skipped: ");
			$this->mOptions['report'] = true;
		}
		$this->RunScript("RefreshStaleData");
	}

} // END class WikiDB_RebuildWikiDB

$maintClass = 'WikiDB_RebuildWikiDB';
require_once(RUN_MAINTENANCE_IF_MAIN);
