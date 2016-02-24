<?php
ini_set('max_execution_time', 172800);
ini_set('memory_limit','2048M');

$token = "";
$server = "";

if (isset($argv[1])) {
	$csvfile = $argv[1];
}
else {
	//Set some default csv file path here if no arg is given.
	$csvfile = './userlistformatted.csv';
	echo ("No csv file specified. Reading ".$csvfile."\n");
}

$csv = csv_to_array($csvfile, ',');

$listsize = count($csv);
$listiteration = 1;

require_once("moodle.php");

$moodlefields['token'] = $token;
$moodlefields['server'] = $server;
$moodlefields['dir'] = "";

$moodle = new Moodle($moodlefields);
$moodle->init($moodlefields);
//echo(print_r($csv));
//Note on using $csv vs $user: the for loop creates a copy of the array, so any changes made to that are not actually saved.
//Must use $csv to keep the changes.
foreach ($csv as $key => $user) {
	foreach ($user as $field => $value) { //every field listed as "profile_field_xxx"
		//must be placed into a 'customfields' array and stripped of "profile_field_"
		if (substr($field, 0, 14) == "profile_field_") {
			$fieldname = str_replace("profile_field_", "", $field);
			$csv[$key]['customfields'][] = array($fieldname => $value);
			unset($csv[$key][$field]);

			//Be sure to check if field is empty before using explode()- affiliations, join reason, employment status

		}
	}

	foreach ($csv[$key]['customfields'] as $field => $value) {
		// $field is the key of each customfield array
		// $value is the array for each customfield (i.e. array("mailchimp" => 0))
		if (isset($value['affiliations'])) {
			if ($value['affiliations'] == "") {
				unset($csv[$key]['customfields'][$field]);
				continue;
			}

			//$value = str_replace("\"", "", $value);
			$values = explode(", ", $value['affiliations']);

			foreach ($values as $affiliation) {
				$code = code_affiliations($affiliation);
				if (!$code) {
					$fieldname = "affiliationsother";
					$csv[$key]['customfields'][] = array($fieldname => $affiliation);
				}
				else {
					$fieldname = "affiliations".$code;
					$csv[$key]['customfields'][] = array($fieldname => 1);
				}
			}
			unset($csv[$key]['customfields'][$field]);
		}
		if (isset($value['join'])) {
			if ($value['join'] == "") {
				unset($csv[$key]['customfields'][$field]);
				continue;
			}

			//$value = str_replace("\"", "", $value);
			$values = explode(", ", $value['join']);

			foreach ($values as $join) {
				$code = code_joinreason($join);
				if (!$code) {
					$fieldname = "joinother";
					$csv[$key]['customfields'][] = array($fieldname => $join);
				}
				else {
					$fieldname = "join".$code;
					$csv[$key]['customfields'][] = array($fieldname => 1);
				}
			}
			unset($csv[$key]['customfields'][$field]);
		}
		if (isset($value['empstatus'])) {
			if ($value['empstatus'] == "") {
				unset($csv[$key]['customfields'][$field]);
				continue;
			}

			//$value = str_replace("\"", "", $value);
			$values = explode(", ", $value['empstatus']);

			foreach ($values as $empstatus) {
				$code = code_empstatus($empstatus);
				$fieldname = "empstatus".$code;
				$csv[$key]['customfields'][] = array($fieldname => 1);
			}
			unset($csv[$key]['customfields'][$field]);
		}
		// if (isset($value['agerange'])) {
		// 	$fieldkey = get_key_agerange($value['agerange']);

		// 	if ($fieldkey != "false") {
		// 		$csv[$key]['customfields'][$field]['agerange'] = $fieldkey;
		// 	}
		// }
		// if (isset($value['educationlevel'])) {
		// 	$fieldkey = get_key_educationlevel($value['educationlevel']);
		// 	if ($fieldkey != "false") {
		// 		$csv[$key]['customfields'][$field]['educationlevel'] = $fieldkey;
		// 	}
		// }
		// if (isset($value['studentorteacher'])) {
		// 	$fieldkey = get_key_studentorteacher($value['studentorteacher']);

		// 	if ($fieldkey != "false") {
		// 		$csv[$key]['customfields'][$field]['studentorteacher'] = $fieldkey;
		// 	}
		// }
		// if (isset($value['firstlanguage'])) {
		// 	$fieldkey = get_key_firstlanguage($value['firstlanguage']);

		// 	if ($fieldkey != "false") {
		// 		$csv[$key]['customfields'][$field]['firstlanguage'] = $fieldkey;
		// 	}
		// }
	}

	// Change country name to two letter code.
	if (isset($csv[$key]['country'])) {
		$country = convert_country2code($csv[$key]['country']);
		if ($country) {
			$csv[$key]['country'] = $country;
		}
		else if (!$country) {
			echo("Could not determine country code for user ".$user['username'].".\n");
			unset($csv[$key]['country']); //We won't update or set the country for this user
		}
	}


    //var_dump($csv[$key]);
	//if($key > 20){break;}; // Stop at first 20 users for debugging
	echo($listiteration."/".$listsize."    ");
	// Connect to moodle and update/create the user
	if (!$moodle->updateUser($csv[$key])) {
		echo($moodle->error."\n\n");
		echo("User ".$user['username']." could not be updated. Creating user.\n");
		if(!$moodle->createUser($csv[$key])) {
			echo($moodle->error."\n\n");
			echo("Error creating user ".$user['username']."\n");
			echo(print_r($csv[$key]));
		}
		else {
			echo("Created user ".$user['username'].".\n");
		}
	}
	else {
		echo("Updated user ".$user['username'].".\n");
	}
	$listiteration = $listiteration + 1;
}


/**
 * Convert a comma separated file into an associated array.
 * The first row should contain the array keys.
 * 
 * Example:
 * 
 * @param string $filename Path to the CSV file
 * @param string $delimiter The separator used in the file
 * @return array
 * @link http://gist.github.com/385876
 * @author Jay Williams <http://myd3.com/>
 * @copyright Copyright (c) 2010, Jay Williams
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
function csv_to_array($filename='', $delimiter=',')
{
	if(!file_exists($filename) || !is_readable($filename))
		return FALSE;
	
	$header = NULL;
	$data = array();
	if (($handle = fopen($filename, 'r')) !== FALSE)
	{
		while (($row = fgetcsv($handle, 1000, $delimiter, '"')) !== FALSE)
		{
			if(!$header)
				$header = $row;
			else
				if(!$data[] = array_combine($header, $row)){
					print_r($header);
					echo("\n\n");
					print_r($row);
					echo("\n\n\n\n\n\n");
					$error = true;
				}
		}
		fclose($handle);
	}
	if ($error) {
		echo("There was an error parsing the CSV. Make sure all records are formatted properly. Exiting.\n");
		exit(1);
	}
	return $data;
}

/**
* convert_country2code
* Convert country names to the two letter country code.
*
* @param string $name The country name to convert
* @return string The two letter country code
**/

function convert_country2code($name) {

	$name_lc = mb_strtolower($name, 'UTF-8');

	$COUNTRIES = array (
		"andorra" => "AD",
		"united arab emirates" => "AE",
		"uae" => "AE",
		"afghanista" => "AF",
		"antigua and barbuda" => "AG",
		"antigua" => "AG",
		"barbuda" => "AG",
		"anguilla" => "AI",
		"albania" => "AL",
		"armenia" => "AM",
		"netherlands antilles" => "AN",
		"angola" => "AO",
		"antarctica" => "AQ",
		"argentina" => "AR",
		"american samoa" => "AS",
		"austria" => "AT",
		"australia" => "AU",
		"aruba" => "AW",
		"Åland islands" => "AX",
		"�land islands" => "AX", //strtolower seems to handle the å oddly
		"åland islands" => "AX",
		"aland islands" => "AX",
		"azerbaijan" => "AZ",
		"baraik" => "IN",
		"bosnia" => "BA",
		"herzegovina" => "BA",
		"bosnia and herzegovina" => "BA",
		"barbados" => "BB",
		"bangladesh" => "BD",
		"belgium" => "BE",
		"burkina faso" => "BF",
		"bulgaria" => "BG",
		"bahrain" => "BH",
		"burundi" => "BI",
		"benin" => "BJ",
		"saint barthélemy" => "BL",
		"saint barthelemy" => "BL",
		"bermuda" => "BM",
		"brunei darussalam" => "BN",
		"bolivia" => "BO",
		"bolivia (plurinational state of)" => "BO",
		"british antarctic territory" => "BQ",
		"bonaire" => "BQ",
		"sint eustatius" => "BQ",
		"saba" => "BQ",
		"bonaire, sint eurstatius and saba" => "BQ",
		"brazil" => "BR",
		"brasil" => "BR",
		"bahamas" => "BS",
		"bahamas (the)" => "BS",
		"the bahamas" => "BS",
		"bahamas, the" => "BS",
		"bhutan" => "BT",
		"burma" => "BU",
		"bouvet island" => "BV",
		"botswana" => "BW",
		"byelorussian ssr" => "BY",
		"belarus" => "BY",
		"belize" => "BZ",
		"canada" => "CA",
		"cocos islands" => "CC",
		"cocos islands, the" => "CC",
		"keeling islands" => "CC",
		"keeling islands, the" => "CC",
		"cocos (keeling) islands (the)" => "CC",
		"congo" => "CD",
		"congo (the democratic republic of the)" => "CD",
		"congo, the democratic republic of the" => "CD",
		"switzerland" => "CH",
		"côte d'ivoire" => "CI",
		"cote d'ivoire" => "CI",
		"cote divoire" => "CI",
		"cook islands" => "CK",
		"cook islands, the" => "CK",
		"cook islands (the)" => "CK",
		"chile" => "CL",
		"cameroon" => "CM",
		"china" => "CN",
		"china, people's republic of" => "CN",
		"colombia" => "CO",
		"costa rica" => "CR",
		"czechoslovakia" => "CS",
		"serbia" => "CS",
		"montenegro" => "ME",
		"serbia and montenegro" => "CS",
		"canton islands" => "CT",
		"enderbury islands" => "CT",
		"canton and enderbury islands" => "CT",
		"cuba" => "CU",
		"cabo verde" => "CV",
		"curaçao" => "CW",
		"curacao" => "CW",
		"christmas island" => "CX",
		"cyprus" => "CY",
		"czech republic (the)" => "CZ",
		"czech republic, the" => "CZ",
		"the chzech republic" => "CZ",
		"czech republic" => "CZ",
		"german democratic republic" => "DD",
		"germany" => "DE",
		"djibouti" => "DJ",
		"denmark" => "DK",
		"dominica" => "DM",
		"dominican republic (the)" => "DO",
		"dominican republic, the" => "DO",
		"the dominican republic" => "DO",
		"dominican republic" => "DO",
		"dahomey" => "DY",
		"algeria" => "DZ",
		"ecuador" => "EC",
		"estonia" => "EE",
		"egypt" => "EG",
		"western sahara" => "EH",
		"eritrea" => "ER",
		"spain" => "ES",
		"ethiopia" => "ET",
		"finland" => "FI",
		"fiji" => "FJ",
		"falkland islands" => "FK",
		"the falkland islands" => "FK",
		"falkland islands (the)" => "FK",
		"falkland islands, the" => "FK",
		"falkland islands, the (malvinas)" => "FK",
		"falkland islands (malvinas)" => "FK",
		"micronesia" => "FM",
		"micronesia, federated states of" => "FM",
		"micronesia (federated states of" => "FM",
		"federated states of micronesia" => "FM",
		"faroe islands (the)" => "FO",
		"faroe islands, the" => "FO",
		"the faroe islands" => "FO",
		"faroe islands" => "FO",
		"french southern and antartic territories" => "FQ",
		"france" => "FR",
		"france, metropolitan" => "FX",
		"gabon" => "GA",
		"united kingdom" => "GB",
		"united kingdom of great britain" => "GB",
		"united kingdom of great britain and northern ireland" => "GB",
		"united kingdom of great britain and northern ireland (the)" => "GB",
		"united kingdom of great britain and northern ireland, the" => "GB",
		"the united kingdom of great britain and northern ireland" => "GB",
		"grenada" => "GD",
		"gilbert island" => "GE",
		"ellice island" => "GE",
		"gilbert and ellice islands" => "GE",
		"georgia" => "GE",
		"french guiana" => "GF",
		"guernsey" => "GG",
		"ghana" => "GH",
		"gibraltar" => "GI",
		"greenland" => "GL",
		"gambia" => "GM",
		"gambia (the)" => "GM",
		"gambia, the" => "GM",
		"guinea" => "GN",
		"guadaloupe" => "GP",
		"equatorial guinea" => "GQ",
		"greece" => "GR",
		"south georgia and the south sandwich islands" => "GS",
		"south sandwich islands" => "GS",
		"guatemala" => "GT",
		"guam" => "GU",
		"guinea-bissau" => "GW",
		"guyana" => "GY",
		"hong kong" => "HK",
		"heard island and mcdonald islands" => "HM",
		"honduras" => "HN",
		"croatia" => "HR",
		"haiti" => "HT",
		"hungary" => "HU",
		"upper volta" => "HV",
		"indonesia" => "ID",
		"ireland" => "IE",
		"israel" => "IL",
		"isle of man" => "IM",
		"india" => "IN",
		"british indian ocean territory" => "IO",
		"british indian ocean territory (the)" => "IO",
		"british indian ocean territory, the" => "IO",
		"iraq" => "IQ",
		"iran (islamic republic of)" => "IR",
		"iran, islamic republic of" => "IR",
		"iran" => "IR",
		"iceland" => "IS",
		"italy" => "IT",
		"jersey" => "JE",
		"jamaica" => "JM",
		"jordan" => "JO",
		"japan" => "JP",
		"johnston island" => "JT",
		"kenya" => "KE",
		"kyrgyzstan" => "KG",
		"cambodia" => "KH",
		"kiribati" => "KI",
		"comoros (the)" => "KM",
		"comoros, the" => "KM",
		"comoros" => "KM",
		"saint kitts" => "KN",
		"nevis" => "KN",
		"saint kitts and nevis" => "KN",
		"korea (the democratic people's republic of)" => "KP",
		"korea, the democratic people's republic of" => "KP",
		"korea, democratic people's republic of" => "KP",
		"korea, democratic people's republic" => "KP",
		"korea" => "KR",
		"korea (the republic of)" => "KR",
		"korea, the republic of" => "KR",
		"korea, republic of" => "KR",
		"korea, (republic of)" => "KR",
		"kuwait" => "KW",
		"cayman islands" => "KY",
		"cayman islands (the)" => "KY",
		"cayman islands, the" => "KY",
		"the cayman islands" => "KY",
		"kazakhstan" => "KZ",
		"lao people's democratic republic" => "LA",
		"lao people's democratic republic (the)" => "LA",
		"lao people's democratic republic, the" => "LA",
		"the lao people's democratic republic" => "LA",
		"lebanon" => "LB",
		"saint lucia" => "LC",
		"liechtenstein" => "LI",
		"sri lanka" => "LK",
		"liberia" => "LR",
		"lesotho" => "LS",
		"lithuania" => "LT",
		"luxembourg" => "LU",
		"latvia" => "LV",
		"libya" => "LY",
		"morocco" => "MA",
		"monaco" => "MC",
		"moldova" => "MD",
		"moldova (the republic of)" => "MD",
		"moldova, the republic of" => "MD",
		"moldova, (republic of)" => "MD",
		"moldova, republic of" => "MD",
		"saint martin" => "MF",
		"madagascar" => "MG",
		"marshall islands" => "MH",
		"marshall islands (the)" => "MH",
		"marshall islands, the" => "MH",
		"midway islands" => "MI",
		"macedonia" => "MK",
		"macedonia (the former yugoslav republic of)" => "MK",
		"macedonia (former yugoslav republic of)" => "MK",
		"macedonia (former yugoslav republic)" => "MK",
		"macedonia, the former yugoslav republic of" => "MK",
		"macedonia, the former yugoslav republic" => "MK",
		"macedonia, former yugoslav republic of" => "MK",
		"macedonia, former yugoslav republic" => "MK",
		"mali" => "ML",
		"myanmar" => "MM",
		"mongolia" => "MN",
		"macao" => "MO",
		"northern mariana islands" => "MP",
		"northern mariana islands (the)" => "MP",
		"northern mariana islands, the" => "MP",
		"martinique" => "MQ",
		"mauritania" => "MR",
		"montserrat" => "MS",
		"malta" => "MT",
		"mauritius" => "MU",
		"maldives" => "MV",
		"malawi" => "MW",
		"mexico" => "MX",
		"malaysia" => "MY",
		"mozambique" => "MZ",
		"namibia" => "NA",
		"new caledonia" => "NC",
		"niger" => "NE",
		"niger (the)" => "NE",
		"niger, the" => "NE",
		"norfolk island" => "NF",
		"nigeria" => "NG",
		"new hebrides" => "NH",
		"nicaragua" => "NI",
		"netherlands" => "NL",
		"netherlands (the)" => "NL",
		"netherlands, the" => "NL",
		"the netherlands" => "NL",
		"norway" => "NO",
		"nepal" => "NP",
		"dronning maud land" => "NQ",
		"nauru" => "NR",
		"neutral zone" => "NT",
		"niue" => "NU",
		"new zealand" => "NZ",
		"oman" => "OM",
		"panama" => "PA",
		"pacific islands" => "PC",
		"pacific islands (trust territory)" => "PC",
		"pacific islands, trust territory" => "PC",
		"peru" => "PE",
		"french polynesia" => "PF",
		"papua new guinea" => "PG",
		"philippines (the)" => "PH",
		"philippines, the" => "PH",
		"philippines" => "PH",
		"pakistan" => "PK",
		"poland" => "PL",
		"saint pierre and miquelon" => "PM",
		"pitcairn" => "PN",
		"puerto rico" => "PR",
		"palestine" => "PS",
		"palestine, state of" => "PS",
		"united states miscellaneous pacific islands" => "PU",
		"palau" => "PW",
		"paraguay" => "PY",
		"panama canal zone" => "PZ",
		"panama" => "PZ",
		"qatar" => "QA",
		"réunion" => "RE",
		"reunion" => "RE",
		"southern rhodesia" => "RH",
		"romania" => "RO",
		"serbia" => "RS",
		"russia" => "RU",
		"russian federation" => "RU",
		"russian federation, the" => "RU",
		"russian federation (the)" => "RU",
		"rwanda" => "RW",
		"saudi arabia" => "SA",
		"solomon islands" => "SB",
		"seychelles" => "SC",
		"sudan" => "SD",
		"sudan (the)" => "SD",
		"sudan, the" => "SD",
		"sweden" => "SE",
		"singapore" => "SG",
		"saint helena" => "SH",
		"saint helenda, ascension and tristan da cunha" => "SH",
		"slovenia" => "SI",
		"svalbard" => "SJ",
		"svalbard and jan mayen" => "SJ",
		"slovakia" => "SK",
		"sikkim" => "SK",
		"sierra leone" => "SL",
		"san marino" => "SM",
		"senegal" => "SN",
		"somalia" => "SO",
		"suriname" => "SR",
		"south sudan" => "SS",
		"sao tome and principe" => "ST",
		"ussr" => "SU",
		"el salvador" => "SV",
		"sint maarten" => "SX",
		"syrian arab republic" => "SY",
		"syria" => "SY",
		"swaziland" => "SZ",
		"turks and caicos islands" => "TC",
		"turks and caicos islands (the)" => "TC",
		"turks and caicos islands, the" => "TC",
		"chad" => "TD",
		"french southern territories" => "TF",
		"french southern territories (the)" => "TF",
		"french southern territories, the" => "TF",
		"togo" => "TG",
		"thailand" => "TH",
		"tajikistan" => "TJ",
		"tokelau" => "TK",
		"timor-leste" => "TL",
		"turkmenistan" => "TM",
		"tunisia" => "TN",
		"tonga" => "TO",
		"east timor" => "TP",
		"turkey" => "TR",
		"trinidad and tobago" => "TT",
		"tuvalu" => "TV",
		"taiwan" => "TW",
		"taiwan (province of china)" => "TW",
		"taiwan, province of china" => "TW",
		"tanzania" => "TZ",
		"tanzania, united republic of" => "TZ",
		"ukraine" => "UA",
		"uganda" => "UG",
		"united states minor outlying islands" => "UM",
		"united states" => "US",
		"united states of america" => "US",
		"united states of america, the" => "US",
		"united states of america (the)" => "US",
		"uruguay" => "UY",
		"uzbekistan" => "UZ",
		"holy see" => "VA",
		"holy see (the)" => "VA",
		"holy see, the" => "VA",
		"saint vincent" => "VC",
		"grenadines" => "VC",
		"saint vincent and the grenadines" => "VC",
		"viet-nam, democratic republic of" => "VD",
		"vietnam" => "VN",
		"viet nam" => "VN",
		"viet-nam" => "VN",
		"vietnam, socialist republic" => "VN",
		"vietnam, socialist republic of" => "VN",
		"vietnam (socialist republic)" => "VN",
		"vietnam (socialist republic of)" => "VN",
		"viet nam, socialist republic" => "VN",
		"venezuela" => "VE",
		"venezuela (bolivarian republic of)" => "VE",
		"venezuela (bolivarian republic)" => "VE",
		"venezuela, bolvarian republic of" => "VE",
		"venezuela, bolvarian republic" => "VE",
		"virgin islands (british)" => "VI",
		"virgin islands, british" => "VI",
		"virgin islands (u.s.)" => "VI",
		"virgin islands (us)" => "VI",
		"virgin islands, u.s." => "VI",
		"virgin islands, us" => "VI",
		"vanuatu" => "VU",
		"wallis and futuna" => "WF",
		"wake island" => "WK",
		"samoa" => "WS",
		"yemen, democratic" => "YD",
		"yemen" => "YE",
		"mayotte" => "YT",
		"yugoslavia" => "YU",
		"south africa" => "ZA",
		"zambia" => "ZM",
		"zaire" => "ZR",
		"zimbabwe" => "ZW"
		);

	if (isset($COUNTRIES[$name_lc])) {
		return $COUNTRIES[$name_lc];
	}

	return false;
}

function code_joinreason($reason) {

	$joinreasons = array (
		"Prefer Not To Say" => "pnts",
		"To prepare for a formal education program that I will be enrolled in" => "tpfe",
		"To supplement a formal education program that I am currently enrolled in" => "tsfece",
		"To supplement formal learning that I have already completed" => "tsfeac",
		"To get college credit" => "tgcc",
		"To improve my resume (c.v.)" => "ticv",
		"To advance in my job" => "taj",
		"To help educate others" => "ted",
		"To explore personal interests or for love of learning" => "tepi"
		);

	if (isset($joinreasons[$reason])) {
		return $joinreasons[$reason];
	}
	return false;
}

function code_empstatus($status) {
	$empstatuses = array (
		"Prefer Not To Say" => "pnts",
		"Full-time employed/self-employed" => "ftese",
		"Part-time employed/self-employed" => "ptese",
		"Full-time voluntary work" => "ftvw",
		"Part-time voluntary work" => "ptvw",
		"Full-time student" => "fts",
		"Part-time student" => "pts",
		"Unwaged and seeking employment" => "use",
		"Unwaged with domestic responsibilities" => "udr",
		"Disabled and not able to work" => "d",
		"Retired" => "r"
		);
	if (isset($empstatuses[$status])) {
		return $empstatuses[$status];
	}
	return false;
}

function code_affiliations($affiliation) {
	$possibleaffiliations = array (
		"Adamjee Life Academy" => "ala",
		"American Business & Technology University" => "abtu",
		"Bellevue University" => "blvu",
		"Bethel University College of Adult & Professional Studies" => "bucaps",
		"Brandman University" => "brdu",
		"Charter Oak State College" => "cosc",
		"City Vision University" => "cvu",
		"Colorado Technical University" => "ctu",
		"CSU-Global Campus" => "csugc",
		"CUNY Baccalaureate for Unique and Interdisciplinary Studies" => "cuny",
		"Excelsior College" => "excc",
		"Granite State College" => "gsc",
		"Great Bay Community College" => "gbcc",
		"Haitian Connection Network" => "hcn",
		"Martin University" => "mrtu",
		"Paul Smith's College" => "psc",
		"PluggedInVA (PIVA) Medical Program" => "piva",
		"Sinhgad Institute of Management and Computer Application" => "simca",
		"StraighterLine" => "stln",
		"SUNY Empire State College" => "suny",
		"Thomas Edison State College" => "tesc",
		"University of Maryland University College" => "umuc"
		);
	if (isset($possibleaffiliations[$affiliation])) {
		return $possibleaffiliations[$affiliation];
	}
	return false;
}

function get_key_agerange($agerange) {
	$ageranges = array (
		"Prefer Not To Say" => "0",
		"Under 15 years" => "1",
		"15-18 years" => "2",
		"19-24 years" => "3",
		"25-34 years" => "4",
		"35-44 years" => "5",
		"45-54 years" => "6",
		"55-64 years" => "7",
		"65-74 years" => "8"
		);
	if (isset($ageranges[$agerange])) {
		return $ageranges[$agerange];
	}
	return "false";

}

function get_key_educationlevel($educationlevel) {
	$educationlevels = array (
		"Prefer Not To Say" => "0",
		"No Formal Qualification" => "1",
		"High School Diploma" => "2",
		"Vocational Qualification (i.e. practical, trade-based)" => "3",
		"Attended Some College" => "4",
		"College Certificate/Associates Degree" => "5",
		"Undergraduate/Bachelors University Degree" => "6",
		"Graduate/Masters Degree" => "7",
		"Postgraduate Degree/PhD" => "8"
		);
	if (isset($educationlevels[$educationlevel])) {
		return $educationlevels[$educationlevel];
	}
	return "false";
}

function get_key_studentorteacher($role) {
	$roles = array (
		"Prefer Not To Say" => "0",
		"Student" => "1",
		"Teacher" => "2",
		"Both" => "3"
		);

	if (isset($roles[$role])) {
		return $roles[$role];
	}
	return "false";
}

function get_key_firstlanguage($language) {
	$languages = array (
		"PreferNotToSay" => "0",
		"Other" => "1",
		"Afrikaans" => "2",
		"Albanian" => "3",
		"Arabic" => "4",
		"Armenian" => "5",
		"Basque" => "6",
		"Bengali" => "7",
		"Bulgarian" => "8",
		"Catalan" => "9",
		"CentralKhmer" => "10",
		"Chinese" => "11",
		"Croatian" => "12",
		"Czech" => "13",
		"Danish" => "14",
		"Dutch" => "15",
		"English" => "16",
		"Estonian" => "17",
		"Fijian" => "18",
		"Finnish" => "19",
		"French" => "20",
		"Georgian" => "21",
		"German" => "22",
		"Gujarati" => "23",
		"Hebrew" => "24",
		"Hindi" => "25",
		"Hungarian" => "26",
		"Icelandic" => "27",
		"Indonesian" => "28",
		"Irish" => "29",
		"Italian" => "30",
		"Japanese" => "31",
		"Korean" => "32",
		"Latin" => "33",
		"Latvian" => "34",
		"Lithuanian" => "35",
		"Macedonian" => "36",
		"Malay" => "37",
		"Malayalam" => "38",
		"Maltese" => "39",
		"Maori" => "40",
		"Marathi" => "41",
		"ModernGreek1453" => "42",
		"Mongolian" => "43",
		"Nepali" => "44",
		"Norwegian" => "45",
		"Panjabi" => "46",
		"Persian" => "47",
		"Polish" => "48",
		"Portuguese" => "49",
		"Quechua" => "50",
		"Romanian" => "51",
		"Russian" => "52",
		"Samoan" => "53",
		"Serbian" => "54",
		"Slovak" => "55",
		"Slovenian" => "56",
		"Spanish" => "57",
		"Swahili" => "58",
		"Swedish" => "59",
		"Tamil" => "60",
		"Tatar" => "61",
		"Telugu" => "62",
		"Thai" => "63",
		"Tibetan" => "64",
		"TongaTongaIslands" => "65",
		"Turkish" => "66",
		"Ukrainian" => "67",
		"Urdu" => "68",
		"Uzbek" => "69",
		"Vietnamese" => "70",
		"Welsh" => "71",
		"Xhosa" => "72"
		);
	$language = str_replace(" ", "", $language);
	$language = str_replace("-", "", $language);
	$language = str_replace("(", "", $language);
	$language = str_replace(")", "", $language);
	if (isset($languages[$language])) {
		return $languages[$language];
	}
	return "false";
}

?>