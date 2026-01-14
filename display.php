<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\ConstraintActions;
use PhpPgAdmin\Database\Actions\RowActions;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\ByteaQueryModifier;
use PhpPgAdmin\Database\QueryResultMetadataProbe;
use PhpPgAdmin\Gui\FormRenderer;
use PhpPgAdmin\Gui\RowBrowserRenderer;
use PHPSQLParser\PHPSQLParser;

/**
 * Common relation browsing function that can be used for views,
 * tables, reports, arbitrary queries, etc. to avoid code duplication.
 * @param string $query The SQL SELECT string to execute
 * @param string $count The same SQL query, but only retrieves the count of the rows (AS total)
 * @param mixed $return The return section
 * @param int $page The current page
 *
 * $Id: display.php,v 1.68 2008/04/14 12:44:27 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

// Prevent timeouts on large exports (non-safe mode only)
if (!ini_get('safe_mode'))
	set_time_limit(0);


/**
 * Show confirmation of edit or insert and perform insert or update
 */
function doEditRow($confirm, $msg = '')
{

	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$conf = AppContainer::getConf();
	$lang = AppContainer::getLang();
	$rowActions = new RowActions($pg);
	$tableActions = new TableActions($pg);

	$insert = !isset($_REQUEST['key']);
	if (!$insert) {
		if (is_array($_REQUEST['key']))
			$keyFields = $_REQUEST['key'];
		else
			$keyFields = unserialize(urldecode($_REQUEST['key']));
		$rs = $rowActions->browseRow($_REQUEST['table'], $keyFields);
	} else {
		$rs = null;
	}

	$attrs = $tableActions->getTableAttributes($_REQUEST['table']);

	if (isset($_REQUEST['edit-inline'])) {
		// edit field inline
		if ($confirm) {
			// load data
		} else {
			// save data
		}
	}

	if ($confirm) {

		$formRenderer = new FormRenderer();

		//var_dump($keyFields);
		$initial = empty($_POST);

		$misc->printTrail($_REQUEST['subject']);
		$misc->printTitle($insert ? $lang['strinsertrow'] : $lang['streditrow']);
		$misc->printMsg($msg);

		if (($conf['autocomplete'] != 'disable')) {
			$fksprops = $misc->getAutocompleteFKProperties($_REQUEST['table'], 'insert');
			if ($fksprops !== false)
				echo $fksprops['code'];
		} else
			$fksprops = false;

		$function_def = <<<EOT
Date/Time
CURRENT_DATE, CURRENT_TIME, NOW (), DATE_TRUNC (value), AGE (value), TO_CHAR (value), TO_DATE (value), INTERVAL
Strings/Text
LENGTH (value), CHAR_LENGTH (value), LOWER (value), UPPER (value), TRIM (value), LTRIM (value), RTRIM (value), MD5 (value), ENCODE (value,'base64'), ENCODE (value,'escape'), ENCODE (value,'hex'), DECODE (value,'base64'), DECODE (value,'escape'), DECODE (value,'hex')
Math
ABS (value), CEIL (value), FLOOR (value), ROUND (value), EXP (value), LOG (value), LOG10 (value), POWER (value), SQRT (value), PI (value), SIN (value), COS (value), TAN (value)
UUID
gen_random_uuid (), uuid_generate_v4 ()
Network
inet, cidr, host (value), hostmask (value), network (value), masklen (value)
System/Info
current_user, session_user, version (), database ()
EOT;
		$functions_by_category = [];
		$all_functions = [];
		$category = null;
		foreach (explode("\n", $function_def) as $line) {
			if (!isset($category)) {
				$category = $line;
				continue;
			}
			$functions_subset = explode(', ', $line);
			$functions_by_category[$category] = $functions_subset;
			$all_functions = array_merge_recursive($all_functions, $functions_subset);
			$category = null;
		}
		// make function searchable by key
		$all_functions = array_combine($all_functions, $all_functions);

		echo "<form action=\"display.php\" method=\"post\" id=\"ac_form\" enctype=\"multipart/form-data\">\n";
		$error = true;
		if ($attrs->recordCount() > 0 && ($insert || $rs->recordCount() == 1)) {
			echo "<table>\n";

			// Output table header
			echo "<tr>\n";
			//echo "<th class=\"data\"></th>\n";
			echo "<th class=\"data\">{$lang['strcolumn']}</th>\n";
			echo "<th class=\"data\">{$lang['strtype']}</th>";
			echo "<th class=\"data\">{$lang['strfunction']}</th>\n";
			echo "<th class=\"data\">{$lang['strnull']}</th>\n";
			echo "<th class=\"data\">{$lang['strvalue']}</th>\n";
			echo "<th class=\"data\">{$lang['strexpr']}</th>\n";
			echo "</tr>";

			$i = 0;
			while (!$attrs->EOF) {

				$attrs->fields['attnotnull'] = $pg->phpBool($attrs->fields['attnotnull']);
				$id = (($i & 1) == 0 ? '1' : '2');

				// Initialise variables
				//if (!isset($_REQUEST['format'][$attrs->fields['attname']]))
				//	$_REQUEST['format'][$attrs->fields['attname']] = 'VALUE';

				if ($initial) {
					if ($insert) {
						$value = $attrs->fields['adsrc'];
						if (!empty($value)) {
							$search = str_replace("()", " ()", strtoupper($value));
							$function = $all_functions[$search] ?? null;
							if (!empty($function)) {
								// use function
								$_REQUEST['format'][$attrs->fields['attname']] = $function;
								$value = '';
							} else {
								// use expression
								$_REQUEST['expr'][$attrs->fields['attname']] = 1;
							}
							//$_REQUEST['expr'][$attrs->fields['attname']] = 1;
						}
					} else {
						$value = $rs->fields[$attrs->fields['attname']];
					}
				} else {
					$value = $_REQUEST["values"][$attrs->fields['attname']];
				}

				echo "<tr class=\"data{$id}\">\n";
				//echo "<td class=\"info\">#", $i+1, "</td>";
				echo "<th>", $misc->printVal($attrs->fields['attname']), "</th>";
				echo "<td>\n";
				echo $misc->printVal($pg->formatType($attrs->fields['type'], $attrs->fields['atttypmod']));
				//echo "<input type=\"hidden\" name=\"types[", htmlspecialchars($attrs->fields['attname']), "]\" value=\"", htmlspecialchars($attrs->fields['type']), "\" /></td>";
				echo "<td>\n";
				$sel_fnc_id = "sel_fnc_" . htmlspecialchars($attrs->fields['attname']);
				echo "<select id=\"$sel_fnc_id\" name=\"format[", htmlspecialchars($attrs->fields['attname']), "]\">\n";
				echo "<option></option>\n";
				$format = $_REQUEST['format'][$attrs->fields['attname']] ?? '';
				foreach ($functions_by_category as $category => $functions) {
					echo "<optgroup label=\"", htmlspecialchars($category), "\">\n";
					foreach ($functions as $function) {
						$selected = $format == $function ? " selected" : "";
						$function_html = htmlspecialchars($function);
						echo "<option value=\"$function_html\"{$selected}>$function_html</option>\n";
					}
					echo "</optgroup>\n";
				}
				/*
				echo "<option value=\"VALUE\"", ($_REQUEST['format'][$attrs->fields['attname']] == 'VALUE') ? ' selected="selected"' : '', ">{$lang['strvalue']}</option>\n";
				echo "<option value=\"EXPRESSION\"", ($_REQUEST['format'][$attrs->fields['attname']] == 'EXPRESSION') ? ' selected="selected"' : '', ">{$lang['strexpression']}</option>\n";
				*/
				echo "</select>\n</td>\n";
				echo "<td class=\"text-center\">";
				// Output null box if the column allows nulls (doesn't look at CHECKs or ASSERTIONS)
				if (!$attrs->fields['attnotnull']) {
					// Set initial null values
					if ($initial && ($insert || $rs->fields[$attrs->fields['attname']] === null)) {
						$_REQUEST['nulls'][$attrs->fields['attname']] = 'on';
					}
					$null_cb_id = "cb_null_" . htmlspecialchars($attrs->fields['attname']);
					echo "<label><span><input type=\"checkbox\" name=\"nulls[{$attrs->fields['attname']}]\" id=\"$null_cb_id\"",
						isset($_REQUEST['nulls'][$attrs->fields['attname']]) ? ' checked="checked"' : '', " /></span></label>\n";
				} else {
					echo "&nbsp;";
					$null_cb_id = "";
				}
				echo "</td>\n";

				echo "<td id=\"row_att_{$attrs->fields['attnum']}\">";

				$extras = [
					'data-field' => $attrs->fields['attname'],
				];

				//$extras['onChange'] = 'document.getElementById("' . $sel_fnc_id . '").value = "";';

				// If the column allows nulls, then we put a JavaScript action on
				// the data field to unset the NULL checkbox as soon as anything
				// is entered in the field.
				if (!$attrs->fields['attnotnull']) {
					$extras['onChange'] = 'document.getElementById("' . $null_cb_id . '").checked = false;';
				}

				if (($fksprops !== false) && isset($fksprops['byfield'][$attrs->fields['attnum']])) {
					$extras['id'] = "attr_{$attrs->fields['attnum']}";
					$extras['autocomplete'] = 'off';
					$extras['data-fk-context'] = 'insert';
					$extras['data-attnum'] = $attrs->fields['attnum'];
				}

				$formRenderer->printField(
					"values[{$attrs->fields['attname']}]",
					$value,
					$attrs->fields['type'],
					$extras
				);

				echo "</td>";
				echo "<td class=\"text-center\">\n";
				$expr_cb_id = "cb_expr_" . htmlspecialchars($attrs->fields['attname']);
				echo "<label><span><input type=\"checkbox\" id=\"$expr_cb_id\" name=\"expr[{$attrs->fields['attname']}]\"",
					!empty($_REQUEST['expr'][$attrs->fields['attname']]) ? ' checked="checked"' : '', " /></span></label>\n";
				echo "</td>";
				echo "</tr>\n";
				$i++;
				$attrs->moveNext();
			}
			echo "</table>\n";

			$error = false;
		} elseif ($rs->recordCount() != 1) {
			echo "<p>{$lang['strrownotunique']}</p>\n";
		} else {
			echo "<p>{$lang['strinvalidparam']}</p>\n";
		}

		echo "<input type=\"hidden\" name=\"action\" value=\"editrow\" />\n";
		echo $misc->form;
		if ($insert) {
			if (!isset($_SESSION['counter']))
				$_SESSION['counter'] = 0;
			echo "<input type=\"hidden\" name=\"protection_counter\" value=\"" . $_SESSION['counter'] . "\" />\n";
		} else {
			foreach ($keyFields as $field => $val) {
				echo "<input type=\"hidden\" name=\"key[", htmlspecialchars($field), "]\" value=\"", htmlspecialchars($val), "\" />\n";
			}
			//echo "<input type=\"hidden\" name=\"key\" value=\"", html_esc(urlencode(serialize($keyFields))), "\" />\n";
		}
		if (isset($_REQUEST['table']))
			echo "<input type=\"hidden\" name=\"table\" value=\"", htmlspecialchars($_REQUEST['table']), "\" />\n";
		if (isset($_REQUEST['subject']))
			echo "<input type=\"hidden\" name=\"subject\" value=\"", htmlspecialchars($_REQUEST['subject']), "\" />\n";
		if (isset($_REQUEST['query']))
			echo "<input type=\"hidden\" name=\"query\" value=\"", htmlspecialchars($_REQUEST['query']), "\" />\n";
		if (isset($_REQUEST['count']))
			echo "<input type=\"hidden\" name=\"count\" value=\"", htmlspecialchars($_REQUEST['count']), "\" />\n";
		if (isset($_REQUEST['return']))
			echo "<input type=\"hidden\" name=\"return\" value=\"", htmlspecialchars($_REQUEST['return']), "\" />\n";
		if (isset($_REQUEST['page']))
			echo "<input type=\"hidden\" name=\"page\" value=\"", htmlspecialchars($_REQUEST['page']), "\" />\n";
		if (isset($_REQUEST['orderby'])) {
			foreach ($_REQUEST['orderby'] as $field => $val) {
				echo "<input type=\"hidden\" name=\"orderby[", htmlspecialchars($field), "]\" value=\"", htmlspecialchars($val), "\" />\n";
			}
		}
		if (isset($_REQUEST['strings']))
			echo "<input type=\"hidden\" name=\"strings\" value=\"", htmlspecialchars($_REQUEST['strings']), "\" />\n";

		echo "<p>";
		if ($insert) {
			echo "<input type=\"submit\" name=\"insert\" value=\"{$lang['strinsert']}\" />\n";
			echo "<input type=\"submit\" name=\"insert_and_repeat\" accesskey=\"r\" value=\"{$lang['strinsertandrepeat']}\" />\n";
		} else {
			if (!$error)
				echo "<input type=\"submit\" name=\"save\" accesskey=\"r\" value=\"{$lang['strsave']}\" />\n";
		}
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" />\n";

		if ($fksprops !== false) {
			echo "&nbsp;&nbsp;&nbsp;";
			if ($conf['autocomplete'] != 'default off')
				echo "<input type=\"checkbox\" id=\"no_ac\" value=\"1\" checked=\"checked\" /> <label for=\"no_ac\"> {$lang['strac']}</label>\n";
			else
				echo "<input type=\"checkbox\" id=\"no_ac\" value=\"0\" /> <label for=\"no_ac\"> {$lang['strac']}</label>\n";
		}

		echo "</p>\n";
		echo "</form>\n";
	} else {

		if (!isset($_POST['values']))
			$_POST['values'] = [];
		if (!isset($_POST['nulls']))
			$_POST['nulls'] = [];
		if (!isset($_POST['expr']))
			$_POST['expr'] = [];

		$fields = [];
		$types = [];
		while (!$attrs->EOF) {
			$fields[$attrs->fields['attnum']] = $attrs->fields['attname'];
			$types[$attrs->fields['attname']] = $attrs->fields['type'];
			$attrs->moveNext();
		}

		if ($insert) {
			if ($_SESSION['counter']++ == $_POST['protection_counter']) {
				$status = $rowActions->insertRow(
					$_POST['table'],
					$fields,
					$_POST['values'],
					$_POST['nulls'],
					$_POST['format'],
					$_POST['expr'],
					$types
				);
				if ($status == 0) {
					if (isset($_POST['insert_and_repeat'])) {
						$_POST = [];
						unset($_REQUEST['values']);
						unset($_REQUEST['expr']);
						unset($_REQUEST['nulls']);
						unset($_REQUEST['format']);
						doEditRow(true, $lang['strrowinserted']);
					} else
						doBrowse($lang['strrowinserted']);
				} else
					doEditRow(true, $lang['strrowinsertedbad']);
			} else
				doEditRow(true, $lang['strrowduplicate']);
		} else {
			$status = $rowActions->editRow(
				$_POST['table'],
				$_POST['values'],
				$_POST['nulls'],
				$_POST['format'],
				$_POST['expr'],
				$types,
				$keyFields
			);
			if ($status == 0)
				doBrowse($lang['strrowupdated']);
			elseif ($status == -2)
				doEditRow(true, $lang['strrownotunique']);
			else
				doEditRow(true, $lang['strrowupdatedbad']);
		}
	}
}

/**
 * Show confirmation of drop and perform actual drop
 */
function doDelRow($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$rowActions = new RowActions($pg);

	if ($confirm) {
		$misc->printTrail($_REQUEST['subject']);
		$misc->printTitle($lang['strdeleterow']);

		$pg->conn->SetFetchMode(ADODB_FETCH_NUM);
		$rs = $rowActions->browseRow($_REQUEST['table'], $_REQUEST['key']);
		$pg->conn->SetFetchMode(ADODB_FETCH_ASSOC);

		echo "<form action=\"display.php\" method=\"post\">\n";
		echo $misc->form;

		if ($rs->recordCount() == 1) {
			echo "<p>{$lang['strconfdeleterow']}</p>\n";

			$rowBrowser = new RowBrowserRenderer();
			$fkinfo = [];
			echo "<table><tr>";
			$rowBrowser->printTableHeaderCells($rs, false, true);
			echo "</tr>";
			echo "<tr class=\"data1\">\n";
			$rowBrowser->printTableRowCells($rs, $fkinfo, true);
			echo "</tr>\n";
			echo "</table>\n";
			echo "<br />\n";

			echo "<input type=\"hidden\" name=\"action\" value=\"delrow\" />\n";
			echo "<input type=\"submit\" name=\"yes\" value=\"{$lang['stryes']}\" />\n";
			echo "<input type=\"submit\" name=\"no\" value=\"{$lang['strno']}\" />\n";
		} elseif ($rs->recordCount() != 1) {
			echo "<p>{$lang['strrownotunique']}</p>\n";
			echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" />\n";
		} else {
			echo "<p>{$lang['strinvalidparam']}</p>\n";
			echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" />\n";
		}
		if (isset($_REQUEST['table']))
			echo "<input type=\"hidden\" name=\"table\" value=\"", html_esc($_REQUEST['table']), "\" />\n";
		if (isset($_REQUEST['subject']))
			echo "<input type=\"hidden\" name=\"subject\" value=\"", html_esc($_REQUEST['subject']), "\" />\n";
		if (isset($_REQUEST['query']))
			echo "<input type=\"hidden\" name=\"query\" value=\"", html_esc($_REQUEST['query']), "\" />\n";
		if (isset($_REQUEST['count']))
			echo "<input type=\"hidden\" name=\"count\" value=\"", html_esc($_REQUEST['count']), "\" />\n";
		if (isset($_REQUEST['return']))
			echo "<input type=\"hidden\" name=\"return\" value=\"", html_esc($_REQUEST['return']), "\" />\n";
		echo "<input type=\"hidden\" name=\"page\" value=\"", html_esc($_REQUEST['page']), "\" />\n";
		if (isset($_REQUEST['orderby'])) {
			foreach ($_REQUEST['orderby'] as $key => $val) {
				echo "<input type=\"hidden\" name=\"orderby[", htmlspecialchars($key), "]\" value=\"", htmlspecialchars($val), "\" />\n";
			}
		}
		echo "<input type=\"hidden\" name=\"strings\" value=\"", html_esc($_REQUEST['strings']), "\" />\n";
		echo "<input type=\"hidden\" name=\"key\" value=\"", html_esc(urlencode(serialize($_REQUEST['key']))), "\" />\n";
		echo "</form>\n";
	} else {
		$status = $rowActions->deleteRow($_POST['table'], unserialize(urldecode($_POST['key'])));
		if ($status == 0)
			doBrowse($lang['strrowdeleted']);
		elseif ($status == -2)
			doBrowse($lang['strrownotunique']);
		else
			doBrowse($lang['strrowdeletedbad']);
	}
}

/**
 * Download bytea field data
 */
function doDownloadBytea()
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);
	$schemaActions = new SchemaActions($pg);

	// Validate required parameters
	if (empty($_REQUEST['table']) || empty($_REQUEST['column']) || empty($_REQUEST['schema'])) {
		header('HTTP/1.0 400 Bad Request');
		echo 'Missing required parameters';
		exit;
	}

	if (empty($_REQUEST['key']) || !is_array($_REQUEST['key'])) {
		header('HTTP/1.0 400 Bad Request');
		echo 'Missing key fields';
		exit;
	}

	$table = $_REQUEST['table'];
	$column = $_REQUEST['column'];
	$schema = $_REQUEST['schema'];
	$keyFields = $_REQUEST['key'];

	// Ensure schema context for attribute checks
	$schemaActions->setSchema($schema);

	// Verify column exists and is bytea type
	$attrs = $tableActions->getTableAttributes($table);
	$columnExists = false;
	$isBytea = false;

	if ($attrs && $attrs->recordCount() > 0) {
		while (!$attrs->EOF) {
			if ($attrs->fields['attname'] === $column) {
				$columnExists = true;
				$type = $attrs->fields['type'] ?? '';
				$isBytea = (strpos($type, 'bytea') === 0);
				break;
			}
			$attrs->moveNext();
		}
	}

	if (!$columnExists || !$isBytea) {
		header('HTTP/1.0 400 Bad Request');
		echo 'Invalid column or not bytea type';
		exit;
	}

	// Build WHERE clause from key fields
	$whereParts = [];
	foreach ($keyFields as $field => $value) {
		if ($value === null || (is_string($value) && strcasecmp($value, 'NULL') === 0)) {
			$whereParts[] = $pg->escapeIdentifier($field) . ' IS NULL';
		} else {
			$whereParts[] = $pg->escapeIdentifier($field) . ' = ' . $pg->clean($value);
		}
	}
	$whereClause = implode(' AND ', $whereParts);

	// Query to fetch the bytea data
	$sql = 'SELECT ' . $pg->escapeIdentifier($column) .
		' FROM ' . $pg->escapeIdentifier($schema) . '.' . $pg->escapeIdentifier($table) .
		' WHERE ' . $whereClause .
		' LIMIT 1';

	$result = $pg->selectSet($sql);

	if ($result && $result->recordCount() === 1) {
		$data = $result->fields[$column];

		if ($data === null) {
			header('HTTP/1.0 404 Not Found');
			echo 'Data is NULL';
			exit;
		}

		// Send binary data
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="' . $table . '_' . $column . '.bin"');
		header('Content-Length: ' . strlen($data));
		header('Cache-Control: must-revalidate');
		header('Pragma: public');

		echo $data;
		exit;
	} else {
		header('HTTP/1.0 404 Not Found');
		echo 'Data not found';
		exit;
	}
}


/* Print the FK row, used in ajax requests */
function doBrowseFK()
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$rowActions = new RowActions($pg);
	$rowBrowser = new RowBrowserRenderer();

	$ops = [];
	foreach ($_REQUEST['fkey'] as $x => $y) {
		$ops[$x] = '=';
	}

	$query = $pg->getSelectSQL($_REQUEST['table'], [], $_REQUEST['fkey'], $ops);
	$_REQUEST['query'] = $query;

	$fkinfo = $rowBrowser->getFKInfo();

	$max_pages = 1;
	// Retrieve page from query.  $max_pages is returned by reference.
	$rs = $rowActions->browseQuery(
		'SELECT',
		$_REQUEST['table'],
		$_REQUEST['query'],
		null,
		1,
		1,
		$max_pages
	);

	echo "<a href=\"#\" style=\"display:table-cell;\" class=\"fk_close\"><img alt=\"[close]\" src=\"" . $misc->icon('Close') . "\" /></a>\n";
	echo "<div style=\"display:table-cell;\">";

	if (is_object($rs) && $rs->recordCount() > 0) {
		/* we are browsing a referenced table here
		 * we should show OID if show_oids is true
		 * so we give true to withOid in functions below
		 * as 3rd parameter */

		echo "<table><tr>";
		$rowBrowser->printTableHeaderCells($rs, false, true);
		echo "</tr>";
		echo "<tr class=\"data1\">\n";
		$rowBrowser->printTableRowCells($rs, $fkinfo, true);
		echo "</tr>\n";
		echo "</table>\n";
	} else
		echo $lang['strnodata'];

	echo "</div>";

	exit;
}

/**
 * Displays requested data
 */
function doBrowse($msg = '')
{
	(new RowBrowserRenderer())->doBrowse($msg);
}


// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();
$conf = AppContainer::getConf();

$action = $_REQUEST['action'] ?? '';

// Actions that don't require header and body
switch ($action) {
	case 'dobrowsefk':
		doBrowseFK();
		break;
	case 'downloadbytea':
		doDownloadBytea();
		break;
}

// Set the title based on the subject of the request
$subject_type = $_REQUEST['subject'] ?? '';
$subject_name = $_REQUEST[$subject_type] ?? '';
if (!empty($subject_name)) {
	switch ($subject_type) {
		case 'table':
			$title = $lang['strtables'] . ': ' . $subject_name;
			break;
		case 'view':
			$title = $lang['strviews'] . ': ' . $subject_name;
			break;
		case 'column':
			$title = $lang['strcolumn'] . ': ' . $subject_name;
			break;
	}
} else {
	$title = $lang['strqueryresults'];
}

$misc->printHeader($title ?? '');
$misc->printBody();

switch ($action) {
	case 'editrow':
	case 'insertrow':
		if (isset($_POST['cancel']))
			doBrowse();
		else
			doEditRow(false);
		break;
	case 'confeditrow':
	case 'confinsertrow':
		doEditRow(true);
		break;
	case 'delrow':
		if (isset($_POST['yes']))
			doDelRow(false);
		else
			doBrowse();
		break;
	case 'confdelrow':
		doDelRow(true);
		break;
	default:
		doBrowse();
		break;
}

$misc->printFooter();
