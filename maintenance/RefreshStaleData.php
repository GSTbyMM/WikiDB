<?php
/**
 * RefreshStaleData.php
 * Field data can go stale if the the table definition they belong to gets updated.
 * This is because we do not automatically update all data when the table changes,
 * because for tables with a lot of data this would grind the wiki to a halt.
 * Instead, when a table definition changes, we mark all affected rows as stale and
 * then update them in batches on each page request.
 * This script allows you to manually update stale records from the command-line.
 *
 * Arguments:
 *  	--report	This simply reports the number of stale records and exits.
 *  	--limit=N	Allows you to perform a batch update of N records.  If omitted
 *  				then all stale records are updated.  This switch is useful if you
 *  				run the script via a regular cron-job and want to avoid
 *  				excessive load.
 *					Note that we can't use the built-in 'batch-size' parameter, as
 *					this doesn't provide the ability to default to 'no limit'.
 *		--force		If this is specified then it will force all rows to be marked as
 *					stale before the script runs.  This is useful if the refreshing
 *					is required due to code changes rather than data changes.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2439 $
 */

// Include header file required for all WikiDB maintenance scripts.
	require_once(dirname( __FILE__ ) . "/header.inc");

class WikiDB_RefreshStaleData extends WikiDB_Maintenance {

	function __construct() {
		parent::__construct();

		$this->mDescription = "Refreshes any stale field data that has gone out of "
							. "date to changes to its table definition.  By default "
							. "the script refreshes all stale data.  Use --limit=N "
							. "to restrict this.  If you specify the --report "
							. "switch, then the script will simply report the "
							. "number of stale records and exit.";

		$this->addOption('limit', "May be used to limit the number of records that "
						 . "are updated in a single script invocation.  If omitted, "
						 . "all stale records are updated.", false, true);
		$this->addOption('report', "Report the number of stale records without "
						 . "making any changes to the database.", false);
		$this->addOption('force', "Forces all records to be updated, regardless of "
						 . "whether they are stale or not.", false);
	}

	function execute() {
	// Set variable to hold file name, which we use as the '$fname' (function name)
	// argument for the various MediaWiki functions that use it.
	// This aids profiling/debugging.
		$FunctionName = basename(__FILE__);

	// Setup the limit based on the --limit argument (if present).
		$Limit = 0;
		if ($this->hasOption('limit')) {
			$SpecifiedLimit = $this->getOption('limit');

		// If --limit is not a valid positive, non-zero integer then die with an
		// error.
			if (!preg_match('/^\d+$/', $SpecifiedLimit) || $SpecifiedLimit < 1) {
				print("Invalid value for --limit argument: " . $SpecifiedLimit
					  . "\n");
				$this->maybeHelp(true);
				die();
			}

			$Limit = intval($SpecifiedLimit);
		}

	// Get a connection to the primary database.
	// TODO: Does this need to be primary?
		$DB = $this->GetDBConnection(DB_PRIMARY);

	// If the '--force' option is specified, mark all rows as stale prior to
	// performing the update.  This is useful in situations where neither table data
	// nor article data needs to be reparsed, but we want to rebuild tblFields, for
	// example if the FormatForSorting code has changed for an existing data type.
		if ($this->hasOption('force')) {
			$SQL = "UPDATE " . $DB->tableName(pWIKIDB_tblRows) . " SET is_stale = "
				 . $DB->AddQuotes(true) . ";";
			$DB->query($SQL, $FunctionName);
			print("--force flag specified.  All rows have been marked as stale.\n");
		}

	// Retrieve and report the current number of stale links.
		$StaleRows = $DB->selectField(pWIKIDB_tblRows, "COUNT(*)",
									  array("is_stale" => true), $FunctionName);
		if ($StaleRows == 0)
			print("No");
		else
			print($StaleRows);
		print(" stale data row");
		if ($StaleRows != 1)
			print("s");
		print(" in DB.");

	// If there are no stale links, or the --report flag was set, that's all we need
	// to do!
		if ($StaleRows == 0 || $this->hasOption('report')) {
			print("\n");
			return;
		}

	// If limit is greater than the number of links to refresh then we don't need
	// a limit.  (I am assuming no race conditions here!)
		if ($Limit >= $StaleRows)
			$Limit = 0;

	// Report on what we're going to do.
		print("  ");
		if ($Limit === 0)
			print("All");
		else
			print($Limit);
		print(" stale row");
		if ($Limit != 1)
			print("s");
		print(" will be refreshed.\n");

		WikiDB::RefreshStaleFieldData($Limit, true);

	// Report on remaining stale rows.  Note that this is re-counted from the DB, and
	// it is possible that new rows have become stale since the script started.
		$StaleRows = $DB->selectField(pWIKIDB_tblRows, "COUNT(*)",
									  array("is_stale" => true), $FunctionName);

		print($StaleRows . " stale row");
		if ($StaleRows != 1)
			print("s");
		print(" remaining.\n");
	}

} // END class WikiDB_RefreshStaleData

$maintClass = 'WikiDB_RefreshStaleData';
require_once(RUN_MAINTENANCE_IF_MAIN);
