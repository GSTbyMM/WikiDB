<?php
/**
 * WikiDB: General functions to help with the various command-line scripts.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2439 $
 */

abstract class WikiDB_Maintenance extends Maintenance {

	function __construct() {
		parent::__construct();

// Disabled, due to our backwards-compatibility requirements.
// See code in finalSetup() for more details.
// @back-compat MW < 1.28
// TODO: My initial testing indicated that this was present in MW 1.25, but the
//		 docblock comment for the function indicates 1.28.  If we go above 1.25
//		 but not above 1.28, it might be worth investigating whether it is actually
//		 supported on that version, or not.
//		$this->requireExtension('WikiDB');
	}

// finalSetup()
// Does some final setup steps, required by all our command-line scripts:
// * Checks that the WikiDB extension is enabled (we can't use the built-in mechanism
//   for this, with our current MW version requirements).
// Attempting these in the constructor or in the setup() function is too early and
// fails.  This seems to be the only appropriate function that we can hook onto
// prior to execute() being called, but after the necessary inclusions have taken
// place.
// Note that the $settingsBuilder argument was added in MW 1.38 and an error
// will be thrown (fatal on PHP 8) if this argument is not defined.  For earlier
// MW versions it does no harm to define the argument and pass it up the chain, it
// will just never be set or used.  We can't include a specific type hint as the
// classname for the parameter changed over time, but the PHP versions where a
// missing type declaration results in an error (E_WARNING in PHP 7.0-7.1 and
// E_STRICT in PHP 5) are not supported by MW 1.38 and above and therefore there
// should be no real-world combination of MW + PHP that will result in an error
// being generated.
// TODO: It would be better if we overwrote afterFinalSetup() but that was not
//		 present in MW 1.16 therefore we use finalSetup() to ensure full
//		 backwards-compatibility.
// @back-compat MW < 1.17
	function finalSetup($settingsBuilder = null) {
		parent::finalSetup($settingsBuilder);

	// If the main WikiDB extension was not loaded, then die() with an error.  Some
	// of the command-line scripts will fail with big errors if the extension is not
	// actually installed, and users may attempt to run them before updating
	// LocalSettings.php.
	// Unfortunately, we can't perform this check in the constructor, as extensions
	// have not been loaded at that point.
	// @back-compat MW < 1.28
	// TODO: We can't use the built-in check for our extension (as per the
	//		 commented-out line in the constructor) unless we switch to using
	//		 extension.json to define the extension parameters, which would require
	//		 dropping support for MW < 1.28, or maintaining two extension
	//		 registration implementations in parallel.
		if (!defined("WikiDB")) {
			print("ERROR: WikiDB extension not loaded!\n"
				  . "You need to add the WikiDB extension to LocalSettings.php "
				  . "before running the maintenance scripts.\n");
			exit(1);
		}
	}

// GetDBConnection()
// Returns a DB object of the specified type.
// TODO: Do our scripts need to wait for replication slaves to catch-up?
//		 If so, we should handle it here, either unconditionally or via an
//		 additional argument.
	function GetDBConnection($DBType) {
	// If the getDB() function exists, use it.
	// Otherwise, call the global function.
	// @back-compat MW < 1.18
		if (method_exists($this, "getDB"))
			return $this->getDB($DBType);
		else
			return wfGetDB($DBType);
	}

// RunScript()
// Runs the named WikiDB command-line script.
// This function is able to be very simple to call due to the consistent naming
// schemes and file locations - it is not appropriate for calling command-line
// scripts outside of the WikiDB extension.
	function RunScript($ScriptName) {
		$ClassName = "WikiDB_" . $ScriptName;
		$Path = dirname(__FILE__) . "/" . $ScriptName . ".php";

		$objScript = $this->runChild($ClassName, $Path);
		$objScript->execute();
	}

//////////////////////////////
// SQL HELPER FUNCTIONS

// Helper functions for installation code.
// This was written for WikiDB, but some bits may be useful for other
// extensions.

// ApplySQLFile()
// Applies the specified SQL file to the specified DB, outputting the message
// beforehand and an indication of success afterwards.
// TODO: Do we need to provide a separate PostgreSQL version of these SQL files?
//		 Use ($DB->getType() === 'postgres') as the test, if this is required,
//		 and pick the appropriate version from within this function.
	function ApplySQLFile($Message, $SQLFileName, $DB) {

	// Set full path to schema file.
		$FilePath = dirname( __FILE__ ) . "/sql/" . $SQLFileName;

	// Set flags to control behaviour that differs between database back-ends.
		$WarnUnsupported = false;
		$DeleteTemporaryFile = false;

	// Perform any actions that vary depending on DB type.
		switch ($DB->getType()) {
		// PostgreSQL requires some transformations in order to work.
		// These are written to a temporary file, which is returned in $FilePath so
		// that it can be read by $DB->sourceFile(), and we set the
		// $DeleteTemporaryFile flag to true, to ensure the file is deleted when we
		// are done.
			case 'postgres':
				$FilePath = self::ApplySQLFileTransformation($FilePath, "postgres");
				$DeleteTemporaryFile = true;
				break;

		// MySQL and SQLite back-ends are fully supported, and don't need any
		// transformations done on the
			case 'mysql':
			case 'sqlite':
				break;

		// For any other database back-end, we let the script run normally, but we
		// issue a warning that it is untested.  We don't block it - who knows, it
		// might work!
			default:
				$WarnUnsupported = true;
				break;
		}

	// If this is an unsupported back-end, issue a warning.
		if ($WarnUnsupported) {
			print("WARNING: WikiDB has not been tested using the "
				  . $DB->getType() . " database back-end.  Please let me know "
				  . "how you get on with it!\n");
		}

	// Load the source file into the database.
		print($Message . "...");
		$Result = $DB->sourceFile($FilePath);

	// If $FilePath points to a temporary file, delete it now.
		if ($DeleteTemporaryFile) {
			@unlink($FilePath);
		}

	// Report result.
		if (is_string($Result)) {
			print("ERROR!\n" . $Result . "\n");
			exit(1);
		}
		else {
			print("ok\n");
		}
	}

// ApplySQLFileTransformation()
// If we need to transform the SQL file for a specific database back-end, then we
// do so by creating a temporary file containing the modified schema, which we then
// return a path to.
// If an error occurs, this function will exit with an error message.  Otherwise
// it will return the full path to the temporary file that contains the translated
// DB schema.
	function ApplySQLFileTransformation($FilePath, $DBType) {

	// Read the source SQL.
		$SQL = @file_get_contents($FilePath);

	// If $SQL is false or an empty string, report the error.
		if ($SQL == false) {
			print("Error reading file: " . $FilePath . "\nABORTING!\n");
			exit(1);
		}

	// Transform the SQL based on $DBType.
		switch ($DBType) {
		// Apply PostgreSQL transformations.
			case 'postgres':
				$SQL = self::ApplySQLFileTransformation_PostgreSQL($SQL);
				break;

		// Any other DB type doesn't require translation, so we return the original
		// file path.  This should never happen in practice.
			default:
				return $FilePath;
		}

	// Create a temporary file containing the transformed SQL.
		$TempFilePath = tempnam(sys_get_temp_dir(), "wikidb_");

		if (!@file_put_contents($TempFilePath, $SQL)) {
			print("Error writing temporary file: " . $TempFilePath
				  . "\nABORTING!\n");
			exit(1);
		}

	// Return the path to the temporary file, containing the modified SQL.
		return $TempFilePath;
	}

	function ApplySQLFileTransformation_PostgreSQL($SQL) {
	// Translate field types that PostgreSQL hates.
	// These could be made more general (e.g. using regexes to allow variable
	// spacing, or alternative variants) but this isn't necessary for now.
		$SQL = str_ireplace("int(8) unsigned NOT NULL PRIMARY KEY auto_increment",
							"serial NOT NULL PRIMARY KEY", $SQL);
		$SQL = str_ireplace("varchar(255) binary", "text", $SQL);
		$SQL = str_ireplace("varchar(255)", "text", $SQL);
		$SQL = str_ireplace("mediumblob", "text", $SQL);
		$SQL = str_ireplace("int(8) unsigned", "int", $SQL);

	// Note that PostgreSQL has a boolean type, which would be a better map, but
	// MediaWiki escapes PHP boolean values as 0 and 1 which causes comparisons to
	// fail, as PostgreSQL is strict about type (and so comparing a boolean with an
	// integer raises a fatal query error).
	// Therefore, our boolean fields are represented as smallint, instead.
	// This is as per the recommendations in /docs/databases/postgresql.txt, in the
	// main MediaWiki codebase.
		$SQL = str_ireplace("tinyint(1) unsigned", "smallint", $SQL);

	// Very hard-coded!
	// TODO: Make these a bit more general, so we don't break things if the source
	//		 file is updated.
		$SQL = str_ireplace("field_name(75)", "field_name", $SQL);
		$SQL = str_ireplace("field_value(75)", "field_value", $SQL);

		return $SQL;
	}

	function RemoveField($Table, $Field, $DB) {
		if (!$DB->tableExists($Table)) {
			print("Warning: Attempt to remove " . $Field
				  . " field from non-existent table, " . $Table . "!\n");
		}
		elseif (!$DB->fieldExists($Table, $Field)) {
			print("..." . $Field . " field already removed from " . $Table
				  . " table.\n");
		}
		else {
		// TODO: There is no sane way of performing a DROP COLUMN operation in SQLite
		//		 so this code will fail with an error.  Should we fail more
		//		 gracefully?
		//		 Not relevant at the moment, as the only time this is used is when
		//		 upgrading from an old version of WikiDB which did not support SQLite
		//		 anyway, so will only become an issue if a column needs to be dropped
		//		 after the point that SQLite support was completed.
			print("Removing " . $Field . " field from " . $Table . " table....");
			$SQL = "ALTER TABLE " . $DB->tableName($Table)
				 . " DROP COLUMN " . $Field . ";";
			$DB->query($SQL, "WikiDB installer: RemoveField()");
			print("ok\n");
		}
	}

// IsTruncateSupported()
// Returns true if the supplied Database configuration is known to support the
// TRUNCATE statement, false otherwise.
// It returns true for DB types where I know this is supported, and false for all
// other types.  This means that any system which supports TRUNCATE but which is
// not explicitly listed will not benefit from the increased performance that
// TRUNCATE can bring, though everything should nonetheless function correctly (just
// less-optimally).
	function IsTruncateSupported($DB) {
		switch ($DB->getType()) {
		// DB types that are known to support the TRUNCATE statement.
			case "mysql":
				return true;

		// DB types that are known to NOT support the TRUNCATE statement.
			case "sqlite":
				return false;

		// Assume all other DB types do not support it, as this is a safer fall-back
		// (it will lead to slower code, rather than broken code as could happen if
		// we assumed the opposite).
			default:
				return false;
		}
	}

// EmptyTable()
// Deletes all records from the specified $Table, using the supplied $DB connection.
// It will use the TRUNCATE statement if the current DB configuration supports it,
// as it is quicker and also resets auto-numbers.
// If not, then a standard delete() call will be used, to delete all records.
	function EmptyTable($Table, $DB, $fname = __FUNCTION__) {
		if (self::IsTruncateSupported($DB)) {
			$AffectedRows = $DB->selectField($Table, "COUNT(*)", "", $fname);
			$SQL = "TRUNCATE " . $DB->tableName($Table);
			$DB->query($SQL, $fname);
		}
		else {
			$WhereClause = "*";
			$DB->delete($Table, $WhereClause, $fname);
			$AffectedRows = $DB->affectedRows();
		}

		return $AffectedRows;
	}

//////////////////////////////
// WIKIDB HELPER FUNCTIONS

// ReparseArticle()
// Reparses given article using current parser state.  You should disable
// any unnecessary tags/extensions/etc. before this is called, to improve speed.
// Based on fixLinksFromArticle() from refreshLinks.inc (in MW 1.7).
// TODO: Consider updating the function in a similar way to how the source function
//		 has been updated.
	function ReparseArticle($ArticleID) {
	// Get a connection to the primary database.
	// TODO: Does this need to be the primary?
		$DB = $this->GetDBConnection(DB_PRIMARY);

		$Title = Title::newFromID($ArticleID);
		if (is_null($Title))
			return;

		$DB->begin();

		$ArticleText = WikiDB_GetCurrentTextForTitle($Title);

		if ($ArticleText !== false) {
			WikiDB::PageUpdated($Title, $ArticleText);
		}

		$DB->commit();
	}

	function ClearTableDefCache($NS = null) {
		$fname = __FUNCTION__;

	// Get a connection to the primary database.
	// TODO: Does this need to be the primary?
		$DB = $this->GetDBConnection(DB_PRIMARY);

	// If no namespace is specified then we are emptying the whole table.
		if ($NS === null) {
			$AffectedRows = self::EmptyTable(pWIKIDB_tblTables, $DB, $fname);
		}
	// Otherwise we are only wiping a single namespace.
		else {
			$WhereClause = array('table_namespace' => $NS);
			$DB->delete(pWIKIDB_tblTables, $WhereClause, $fname);
			$AffectedRows = $DB->affectedRows();
		}

		return $AffectedRows;
	}

	function ClearAllWikiDBCaches() {
		$fname = __FUNCTION__;

	// Get a connection to the primary database.
	// TODO: Does this need to be the primary?
		$DB = $this->GetDBConnection(DB_PRIMARY);

		$arrTables = array(pWIKIDB_tblTables, pWIKIDB_tblRows, pWIKIDB_tblFields);

		foreach ($arrTables as $Table)
			self::EmptyTable($Table, $DB, $fname);
	}

//////////////////////////////
// UI HELPER FUNCTIONS

// AllowUserToCancel()
// Shows a prompt to the user warning them about a dangerous action that is about
// to be performed and performs a count-down, allowing them to cancel it before
// it begins.  By default the count-down is for 5 seconds, but this can be overridden
// via the $Delay argument.
// This encapsulates some of the functionality of Maintenance::countDown() (added in
// MW 1.31) and its predecessor, wfCountDown() (added in MW 1.16 and removed in
// MW 1.34).  However, unlike wfCountDown(), the function respects the --quiet flag
// and, unlike either of them, it shows an appropriate prompt to the user.
// Whilst we could leverage either of the above two functions (depending on MW
// version) there is no benefit to doing so - it is simpler just to use this parallel
// implementation.
	function AllowUserToCancel($Delay = 5) {
	// If '--quick' wasn't specified, give them 5 seconds to make sure they want to
	// proceed with this potentially slow process.
		if (!$this->hasOption('quick')) {
			print("You can abort by pressing Control-C in the next five seconds...");

			$OldVal = "";
			for ($i = $Delay; $i >= 0; $i--) {
				print_c_fixed($OldVal, $i);
				$OldVal = $i;
				sleep(1);
			}
			print("\n");
		}

	}

} // END class WikiDB_Maintenance

// Replacement for print_c() in maintenance/counter.inc
// Original is buggy when number of characters in $last and $current is different,
// at least on Windows (not tested on *nix).
	function print_c_fixed($last, $current) {
	// On Windows backspace on its own moves the cursor, but doesn't blank out the
	// value.  Therefore we do back-space (move back), space (wipe character)
	// back-space (move back again) instead.
		echo str_repeat(chr(8) . " " . chr(8), strlen($last)) . $current;
	}
