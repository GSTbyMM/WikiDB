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
 * The 'AllTables' special page provided by WikiDB.
 *
 * @author Mark Clements <mclements at kennel17 dot co dot uk>
 * @copyright Copyright © 2006-2024, Mark Clements
 * @license http://creativecommons.org/licenses/by-sa/2.0/uk/ CC BY-SA 2.0 UK
 * @version $Rev: 2439 $
 */

class WikiDB_SP_AllTables extends WikiDB_ListBasedSpecialPage {

	function __construct() {
		parent::__construct('AllTables');
	}

	function GetTextKeyPrefix() {
		return "alltables";
	}

	function GetQuery($DB) {
		$tblTables = $DB->tableName(pWIKIDB_tblTables);
		$tblRows = $DB->tableName(pWIKIDB_tblRows);

		$GroupFields = array(
					$tblTables . ".table_namespace",
					$tblTables . ".table_title",
				);

	// Later versions of MW allow us to use 'Alias' => "FieldDef", but to support
	// older versions we need to use "FieldDef AS Alias".
	// @back-compat MW < 1.20
		$Fields = array(
					"COUNT(" . $tblRows . ".table_title) AS record_count",
				);
		$Fields = array_merge($Fields, $GroupFields);

		return array(
			'tables' => array(pWIKIDB_tblRows, pWIKIDB_tblTables),
			'fields' => $Fields,
			'join_conds' => array(
							pWIKIDB_tblRows => array(
									'LEFT JOIN',
									array(
											$tblRows . ".table_namespace = "
												. $tblTables . ".table_namespace",
											$tblRows . ".table_title = "
												. $tblTables . ".table_title",
										),
								),
							),
			'order' => array(
							$tblTables . ".table_namespace",
							$tblTables . ".table_title",
						),
			'options' => array(
							'GROUP BY'	=> $GroupFields,
						),
		);
	}

	function pMakeListItem($Row) {
		$Title = Title::makeTitleSafe($Row->table_namespace, $Row->table_title);
		if (!is_null($Title)) {
			$DefLink = $this->pGetLinkToTitle($Title);
			$DataLink = $Title->getLocalURL('action=viewdata');
			$DataLinkText = WikiDB_Msg('alltables-record', $Row->record_count);

			if ($Row->record_count == 0)
				$Class = ' class="new"';
			else
				$Class = '';

			return("<li>" . $DefLink . ' - <a href="' . $DataLink . '"'
				   . $Class . '>' . $DataLinkText . '</a></li>' . "\n");
		}
		else {
			return("<!-- Invalid title " . WikiDB_EscapeHTML($Row->table_title)
				 . " in namespace " . WikiDB_EscapeHTML($Row->table_namespace)
				 . " -->\n");
		}
	}

} // END class WikiDB_SP_AllTables
