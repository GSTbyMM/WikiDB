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
 * WikiDB class.
 * A set of static functions relating to the WikiDB database.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2439 $
 */

class WikiDB {

// $pIsParsingForSave flag - see IsParsingForSave() for details.
	static $pIsParsingForSave = false;

	function __construct() {
	// Never instantiate this object!
	}

// TablesInstalled()
// Returns true if the WikiDB tables have been installed to the site database, false
// otherwise.
// We cache the result, so this function should not have much performance hit if it
// is called multiple times.
	static function TablesInstalled() {
		static $Result;

		if (!isset($Result)) {
            if (class_exists('MediaWiki\MediaWikiServices')) {
                $DB = \MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY);
            } else {
                $DB = wfGetDB(DB_PRIMARY);
            }
			$Result = $DB->tableExists(pWIKIDB_tblRows);
		}

		return $Result;
	}

// IsParsingForSave()
// Returns true if we are currently in the middle of a re-parse in order to update
// the internal WikiDB pages, or false at all other times.
// This allows our parser to indicate that it is parsing for the purposes of saving
// data, which causes the functions that handle the <data> tag and table definitions
// to store the data to the DB, rather than rendering it for output, and causes the
// other tags (which only affect rendering) to be skipped completely.
	static function IsParsingForSave() {
		return self::$pIsParsingForSave;
	}

// GetDefaultTableNamespace()
// Returns the namespace ID of the default table namespace.
// This is the first namespace defined in $wgWikiDBNamespaces.  If no table namespace
// is defined then NS_MAIN is used.
// TODO: Falling back on NS_MAIN is probably a bad idea for many reasons!
	static function GetDefaultTableNamespace() {
		global $wgWikiDBNamespaces;

	// We use the $Enabled variable to hold the value in our foreach loop.
	// We set it to false initially (so that if no table namespaces are defined
	// we don't get an undefined variable error) and it will be set to true if an
	// enabled table namespace is found.
		$Enabled = false;

	// If no default namespace specified, pick the first defined DB namespace.
	// This loops through the array of table namespaces setting the
	// $DefaultNamespace and $Enabled flag for each iteration.  If $Enabled is true
	// then we break out of the loop, as $DefaultNamespace has now been correctly
	// filled in.  If we get to the end of the loop $Enabled will still be false
	// so the value in $DefaultNamespace, which will be the last namespace in the
	// array, will subsequently be overwritten by the correct default.
		foreach ($wgWikiDBNamespaces as $DefaultNamespace => $Enabled) {
			if ($Enabled)
				break;
		}

	// If no namespaces are defined, or there are defined namespaces but they
	// are all disabled, fall back on the Main namespace.
	// TODO: Is this wise? (see above)
		if (!$Enabled)
			$DefaultNamespace = NS_MAIN;

		return $DefaultNamespace;
	}

// pSetupPageObjects()
// Helper function, which takes a $PageObject, which may be a MediaWiki Title or
// Article object, a new WikiPage object (as of MW 1.18), or a WikiDB_Table object,
// and sets up the $Title and $Article variables appropriately.  It also recognises
// any sub-classes of these, in case they have been extended (as is the case with
// the Semantic MediaWiki extension, for example).
// The $Title and $Article variables should be initialised to null by the calling
// function and passed into this function which will populate them (as they are
// passed by reference).
// Returns false on error (i.e. $PageObject is not one of the recognised classes).
	static function pSetupPageObjects($PageObject, &$Title, &$Article) {
		$ClassName = strtolower(get_class($PageObject));

	// Article may be sub-classed, so we use is_a() for comparison.
		if (is_a($PageObject, "Article")) {
			$Title = $PageObject->getTitle();

		// If this is actually an Article, then assign it directly.  Otherwise
		// create a new Article object from the Title.
			if ($ClassName == "article")
				$Article = $PageObject;
			else
				$Article = new Article($Title);
		}
	// Title - so far as I know - is never sub-classed, so we do a direct name
	// comparison.  If we find it does get sub-classed then we need to change the
	// direct assignment of $PageObject to $Title so it guarantees a direct Title
	// object.
		elseif ($ClassName == "title") {
			$Article = new Article($PageObject);
			$Title = $PageObject;
		}
	// There are various children of the WikiPage class, so we use is_a() for this
	// check.
		elseif (is_a($PageObject, "WikiPage")) {
			$Title = $PageObject->getTitle();
			$Article = new Article($Title);
		}
	// WikiDB_Table will not be sub-classed, so we do a direct comparison.
		elseif ($ClassName == "wikidb_table") {
			$Article = $PageObject->GetArticle();
			$Title = $PageObject->GetTitle();
		}
	// Anything else is unsupported, so we raise an error.
		else {
			trigger_error("Unexpected object type passed to " . __CLASS__
						  . "::" . __FUNCTION__ . "(): " . $ClassName, E_USER_ERROR);
			return false;
		}

		return true;
	}

// PageUpdated()
// Updates the WikiDB tables for the given $PageObject with the given Text
// (presumably the text of the current revision).  $PageObject can be any of the
// object types recognised by pSetupPageObjects().
// The $Text argument may be omitted, in which case the current article text
// is used.
// The function extracts all data tags from the $Text and stores them in the
// database, replacing the existing data stored for this article.  Ditto field
// definitions if this is in the table namespace. This code doesn't handle
// rendering - it just extracts and saves the data.
// See efWikiDB_ParserBeforeInternalParse() for rendering code.
// It is assumed that the MediaWiki code is transaction safe, so if either the main
// article save or the extension's DB changes fail then the other is rolled back if
// necessary.
// TODO: Investigate transactions, and if not safe some security measures will be
//		 needed in this function.
	static function PageUpdated($PageObject, $Text = null) {
		$fname = __CLASS__ . "::" . __FUNCTION__;

	// Setup $Title and $Article from $PageObject.
		$Title = null;
		$Article = null;
		if (!self::pSetupPageObjects($PageObject, $Title, $Article))
			return false;

	// Pages in the MediaWiki namespace can't contain data or table definitions.
		if ($Title->getNamespace() == NS_MEDIAWIKI)
			return false;

	// If no $Text argument was supplied, use the current article text.
		if ($Text === null)
			$Text = WikiDB_GetArticleText($Article);

	// Delete any existing data for this page.
		self::pDeleteDataForPage($Title);

	// Parse the article.  This will pass through our various standard parsing
	// functions, specifically the ParserBeforeInternalParse callback (for table
	// definitions) and the <data> tag (for data).  These functions check the value
	// of $pIsParsingForSave (by calling IsParsingForSave()) and if true they perform
	// the appropriate save action to update the DB, whilst if false they render the
	// page for display.
	// This ensures that the page is parsed identically for rendering as it is for
	// saving.
		self::$pIsParsingForSave = true;
		$arrTags = WikiDB_Parser::ParseTagsFromText($Text, $Title, $Article);
		self::$pIsParsingForSave = false;

	// Update the database with the schema and data extracted from the parse.

		foreach ($arrTags['tabledef'] as $arrTagInfo) {
			self::pAddTableDefinition($arrTagInfo['Title'],
									  $arrTagInfo['Content']);
		}

		foreach ($arrTags['data'] as $arrTagInfo) {
			self::pAddDataDefinition($arrTagInfo['Title'], $arrTagInfo['Content'],
									 $arrTagInfo['Arguments']);
		}

	// NOTE: Originally I called RefreshStaleFieldData() at the end of this
	//		 function, so that we refresh stale data up to the limit imposed by
	// 		 $wgWikiDBMaxRefreshRate as part of the initial update.  However,
	//		 it appears that MW does a redirect after a page update, meaning that
	//		 the call to RefreshStaleFieldData() that happens on each page
	//		 request will automatically kick in, and we don't want to run this
	//		 twice.  I don't know whether MW always redirects in this manner, or
	//		 if this is down to server configuration, so I'm leaving this note
	//		 here as a reminder to investigate and make sure that we will always
	//		 get an initial update.

		return true;
	}

// pAddTableDefinition()
// Updates the DB to the new WikiDB table definition for the specified page
// (as defined by $TableTitle which is a MediaWiki Title object), based on parsing
// the content provided in $PageText.
// It is assumed that the title is in a valid table namespace - it is up to the
// calling function to check this if it may not be the case.
	static function pAddTableDefinition($TableTitle, $PageText) {
		$fname = __CLASS__ . "::" . __FUNCTION__;
		if (class_exists('MediaWiki\MediaWikiServices')) {
            $DB = \MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY);
        } else {
            $DB = wfGetDB(DB_PRIMARY);
        }

	// Delete the old definition.
	// TODO: This should probably be done using an update query if the page
	// 		 exists, or an insert if it is a new page, to save on a DB call.
	//		 However if we need a SELECT to work this out then this method might
	//		 work out just as fast...
		$DB->delete(pWIKIDB_tblTables,
					array(
						'table_namespace'	=> $TableTitle->getNamespace(),
						'table_title'		=> $TableTitle->getDBKey(),
					), $fname);

	// Generate the field data from the page contents, using the parser in
	// the WikiDB class.
		$FieldData = WikiDB_Parser::ParseTableDef($PageText, false);

	// If page is a redirect, fill in the redirect fields.
	// We only add redirects that point to other tables.  Redirects pointing
	// to a namespace that is not a table namespace are ignored.
		$Redirect = WikiDB_NewFromRedirect($PageText);
		if ($Redirect !== null && WikiDB_IsValidTable($Redirect)) {
			$RedirectNS = $Redirect->getNamespace();
			$RedirectTitle = $Redirect->getDBKey();
		}
		else {
			$RedirectNS = null;
			$RedirectTitle = null;
		}

	// Insert the new definition.
		$DB->insert(pWIKIDB_tblTables,
					array(
						'table_namespace'		=> $TableTitle->getNamespace(),
						'table_title'			=> $TableTitle->getDBKey(),
						'table_def'				=> serialize($FieldData),
						'redirect_namespace'	=> $RedirectNS,
						'redirect_title'		=> $RedirectTitle,
					), $fname, 'IGNORE');

	// Mark all rows that might be affected by modifications to this table
	// as stale, i.e. rows belonging to this table or any of its aliases (though
	// not for tables this is an alias of - they are unaffected).
	// TODO: This query may not be fully portable across DB back-ends.
		$Table = new WikiDB_Table($TableTitle);
		$Aliases = $Table->GetTableAliases();

		$Where = "";
		foreach ($Aliases as $Table) {
			if (!IsBlank($Where))
				$Where .= " OR ";
			$Where .= "(table_namespace = "
					. $DB->addQuotes($Table->GetNamespace())
					. " AND table_title = "
					. $DB->addQuotes($Table->GetDBKey())
					. ")";
		}
		$SQL = "UPDATE " . $DB->tableName(pWIKIDB_tblRows) . " SET is_stale = "
			 . $DB->addQuotes(true) . " WHERE " . $Where . ";";
		$DB->query($SQL, $fname);
	}

// pDeleteDataForPage()
// Deletes all cached data for the given page - either the page has been deleted
// or we are wiping existing data before a reparse.
	static function pDeleteDataForPage($Title) {
		$fname = __CLASS__ . "::" . __FUNCTION__;
		if (class_exists('MediaWiki\MediaWikiServices')) {
            $DB = \MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY);
        } else {
            $DB = wfGetDB(DB_PRIMARY);
        }

	// Retrieve existing data row_ids for this article
		$objResult = $DB->select(pWIKIDB_tblRows, array('row_id'),
								 array('page_namespace' 	=> $Title->getNamespace(),
									   'page_title' 		=> $Title->getDBKey())
								 );

		$ExistingRowIDs = array();
		if (is_object($objResult)) {
			while ($Row = $objResult->fetchObject()) {
				$ExistingRowIDs[] = $Row->row_id;
			}
			$objResult->free();
		}

	// If there were existing rows, delete the old data.
		if (count($ExistingRowIDs) > 0) {
			$DB->delete(pWIKIDB_tblFields, array('row_id' => $ExistingRowIDs),
						$fname);
			$DB->delete(pWIKIDB_tblRows, array('row_id' => $ExistingRowIDs), $fname);
		}
	}

// pAddDataDefinition()
// Updates the DB to hold the data definition whose contents are held in
// $DataTagText.  The data was located on $SourcePage and destined for the
// $DestinationTable.
	static function pAddDataDefinition($SourcePage, $DataTagText, $Args) {
		$fname = __CLASS__ . "::" . __FUNCTION__;
		if (class_exists('MediaWiki\MediaWikiServices')) {
            $DB = \MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY);
        } else {
            $DB = wfGetDB(DB_PRIMARY);
        }

	// Ensure that $DestinationTable is a WikiDB_Table object.
		$DestinationTable = new WikiDB_Table($Args['table']);

	// Parse the tag's contents to get the data.
		$ParsedData = WikiDB_Parser::ParseDataTag(
														$DataTagText,
														$Args['fields'],
														$Args['separator']
													);

		foreach ($ParsedData as $RowData) {
		// If any value=data pairs were found, save this row.
			if (count($RowData) > 0) {
			// In MW < 1.30, you needed to call nextSequenceValue() to get the RowID
			// for DB back-ends that use sequences (pre-allocated IDs) rather than
			// autonumbers (allocated on write), i.e. PostgreSQL and Oracle.
			// From MW 1.30 onwards, this is no longer necessary, and insertId() will
			// work correctly for all supported back-ends.
			// The nextSequenceValue() function was therefore deprecated in MW 1.30
			// and now always returns null.  At some point it will be removed, so
			// to future-proof our code we check that the method exists prior to
			// calling it.
			// @back-compat MW < 1.30
				if (method_exists($DB, "nextSequenceValue"))
					$RowID = $DB->nextSequenceValue(pWIKIDB_tblRows . '_row_id_seq');
				else
					$RowID = null;

				$DB->insert(pWIKIDB_tblRows,
							array(
								'row_id'			=> $RowID,
								'page_namespace'	=> $SourcePage->getNamespace(),
								'page_title'    	=> $SourcePage->getDBKey(),
								'table_namespace'
												=> $DestinationTable->GetNamespace(),
								'table_title'		=> $DestinationTable->GetDBKey(),
								'parsed_data'		=> serialize($RowData)
							), $fname, 'IGNORE');
				$RowID = $DB->insertId();

				self::pAddFieldDefs($RowID, $DestinationTable, $RowData, $DB, true);
			}
		}
	}

// pAddFieldDefs()
// Updates all field definitions for the specified row, based on the current
// definitions for the given table.  The table is resolved to the final data
// destination so that fields can be formatted appropriately.
// This function assumes that all field names in $Fields have already been
// normalised, via WikiDB_Table::NormaliseFieldName().
// The $SkipDelete argument can be used to bypass the delete query if you know that
// it is unnecessary (e.g. when a page is updated and all records for that page were
// deleted in one go).
	static function pAddFieldDefs($RowID, $Table, $Fields, $DB, $SkipDelete = false)
	{
		$fname = __CLASS__ . "::" . __FUNCTION__;

		if (!$SkipDelete)
			$DB->delete(pWIKIDB_tblFields, array('row_id' => $RowID), $fname);

	// If the table is an alias it needs to be resolved so that we get the
	// destination into which the fields will be put.  We need to do this in order
	// to use the correct field definitions for formatting the field values.
		$Table = $Table->GetDataDestination();

		foreach ($Fields as $FieldName => $arrFieldValues) {
		// If this is a single-value field, convert it into an array so that we can
		// just loop over the values in order to store them.
		// Note that if the value is blank we still store it in the DB, so we can
		// perform comparisons against this field, therefore we don't need to
		// special-case blank values.
			if (!is_array($arrFieldValues))
				$arrFieldValues = array($arrFieldValues);

		// Store each of the values as a separate instance.
		// Searching for a single value in a multi-value field or searching for
		// multiple values using OR will work via the standard joining rules that
		// we use for single-value fields.  However, AND queries will need special
		// handling.
			foreach ($arrFieldValues as $FieldValue) {
			// We format the field value appropriately for the field's type.  If the
			// field has no definition then the string is returned unmodified (though
			// in either case it is truncated, if necessary, to fit into a
			// VARCHAR(255)).
				$FieldValue = $Table->FormatFieldForSorting($FieldName, $FieldValue);

			// Note that the field name we store in the DB is the actual field name
			// specified - i.e. we don't resolve aliases.  Because there may be stale
			// data, our SQL for querying the data needs to check all aliases of a
			// field otherwise data might be missed, therefore there is no advantage
			// to resolving it here.  If we decide to increase speed at the expense
			// of accuracy then we could make an assumption that all aliases are
			// resolved correctly and store the alias here instead - stale records
			// may not be handled correctly in that situation, however.

			// Insert the data row for this field into the DB.
				$DB->insert(pWIKIDB_tblFields,
							array(
								'row_id'			=> $RowID,
								'field_name'		=> $FieldName,
								'field_value'		=> $FieldValue,
							), $fname, 'IGNORE');
			}
		}
	}

// PageDeleted()
// Updates the WikiDB tables for the given $PageObject on the basis that the page
// has now been deleted.  $PageObject can be any of the object types recognised by
// pSetupPageObjects().
	static function PageDeleted($PageObject) {
		$fname = __CLASS__ . "::" . __FUNCTION__;
		if (class_exists('MediaWiki\MediaWikiServices')) {
            $DB = \MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY);
        } else {
            $DB = wfGetDB(DB_PRIMARY);
        }

	// Setup $Title and $Article from $PageObject.
		$Title = null;
		$Article = null;
		if (!self::pSetupPageObjects($PageObject, $Title, $Article))
			return false;

	// If we are in a table namespace, we first need to delete the table data.
		if (WikiDB_IsValidTable($Title)) {
			$DB->delete(pWIKIDB_tblTables,
						array(
							'table_namespace'	=> $Title->getNamespace(),
							'table_title'		=> $Title->getDBKey(),
						), $fname);
		}

	// For all pages we need to delete any row/field data.

		$DB->deleteJoin(pWIKIDB_tblFields, pWIKIDB_tblRows,
						$DB->tableName(pWIKIDB_tblFields) . ".row_id",
						$DB->tableName(pWIKIDB_tblRows) . ".row_id",
						array(
							'page_namespace'	=> $Title->getNamespace(),
							'page_title'		=> $Title->getDBKey(),
						), $fname);

		$DB->delete(pWIKIDB_tblRows,
					array(
						'page_namespace'	=> $Title->getNamespace(),
						'page_title'		=> $Title->getDBKey(),
					), $fname);
	}

// RefreshStaleFieldData()
// Refreshes the field data for any WikiDB rows marked as 'stale'.  If $BatchSize
// is specified then the number of rows that will be refreshed is restricted
// to this number, otherwise the value set in $wgWikiDBMaxRefreshRate is used.
// A value of WIKIDB_RefreshAll (passed in, or in the variable) means refresh all
// values.  We don't worry about WIKIDB_DisableAutoRefresh, as this is handled
// in WikiDB.php before we get to this function.
// $ReportProgress should only be set to true when running from the command line.
// TODO: Should command-line output be internationalised?
	static function RefreshStaleFieldData($BatchSize = null, $ReportProgress = false)
	{
		global $wgWikiDBMaxRefreshRate;

	// If the WikiDB tables are not present, there is nothing for us to do.
	// This check is required to stop people's wikis breaking in the period between
	// the extension being enabled and the DB creation scripts being run.
		if (!self::TablesInstalled())
			return;

		$fname = __CLASS__ . "::" . __FUNCTION__;
		if (class_exists('MediaWiki\MediaWikiServices')) {
            $DB = \MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY);
        } else {
            $DB = wfGetDB(DB_PRIMARY);
        }

		if ($BatchSize === null)
			$BatchSize = $wgWikiDBMaxRefreshRate;

		$Options = array();
		if ($BatchSize != 0)
			$Options['LIMIT'] = $BatchSize;

		$objResult = $DB->select(pWIKIDB_tblRows, "*", array("is_stale" => true),
								 $fname, $Options);

		if ($ReportProgress) {
			print("Rows processed: 0");
			$LastReport = 0;
			$i = 0;
		}

		if (is_object($objResult)) {
			while ($Row = $objResult->fetchObject()) {
				$Table = new WikiDB_Table($Row->table_title, $Row->table_namespace);
				$Fields = unserialize($Row->parsed_data);

				self::pAddFieldDefs($Row->row_id, $Table, $Fields, $DB);
				$DB->update(pWIKIDB_tblRows, array("is_stale" => false),
							array("row_id" => $Row->row_id), $fname);

				if ($ReportProgress && (++$i % pWIKIDB_ReportingInterval) == 0) {
					print_c_fixed($LastReport, $i);
					$LastReport = $i;
				}
			}

			$objResult->free();
		}

		if ($ReportProgress)
			print_c_fixed($LastReport, $i . "\n");
	}

} // END: class WikiDB
