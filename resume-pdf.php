<?php

// for some reason, incorrectly getting a DEPRECATED error for FPDF construct
// it CORRECTLY uses __construct, but gets error that it is using function FPDF()
error_reporting(~E_DEPRECATED);

set_time_limit(10 * 60);

define('LN_HEIGHT', 6);
define('HILITE_LN_HEIGHT', 4);
define('JOB_SKILLS_LN_HEIGHT', 5);
define('IMAGE_SIZE', 14);
define('LABEL_FONT', 'Signika');
define('LABEL_SIZE', 14);

require 'fpdf2/fpdf_mc_table.php';

$http_host = $_SERVER['HTTP_HOST'];

// get id from GET if present, otherwise use 7 as default for practice
$id = (isset($_GET['id']) && $_GET['id'] && 'undefined' !== $_GET['id']) ? $_GET['id'] : 7;

// first retrieve the api info for the candidate
$candidate = get_candidate($id, $http_host);
// the skills listing is a separate api call
$tech_skills = build_tech_skills($id, $http_host);

$layout = build_layout_obj();

$pdf = set_up_pdf();
build_globals($pdf);
build_resume($pdf, $candidate, $tech_skills, $layout);

$pdf->Output();

//****************************************************

/**
 * Utility functions.
 */
function set_up_pdf()
{
	// have to extend the fpdf class for the header functionality
	class PDF extends FPDF_MC_Table
	{
		public function Header()
		{
			$this->SetFont('');
			$this->Cell(0, 3, '', 0, 1, '', false);
			$this->SetFillColor(245, 30, 30);
			$this->Cell(0, 5, '', 0, 1, '', true);
			$this->SetFillColor(200);
			$this->Cell(0, 8, '', 0, 1, '', true);
			$this->Cell($this->w, 4, '', 0, 2);
		}
	}

	return new PDF();
}

function build_layout_obj()
{
	$layout = [
		'sections' => [
			['name' => 'hd'],
			['name' => 'ob'],
			['name' => 'ts'],
			['name' => 'hi'],
			['name' => 'ex'],
			['name' => 'ed'],
			['name' => 'ct'],
		],
	];

	// check for layout GET object
	$disp_layout = $layout;
	if (isset($_GET['layout'])) {
		if ($tmp = json_decode($_GET['layout'], true)) {
			$disp_layout = $tmp;
		}
	}

	// var_dump($disp_layout);
	// die();

	return $disp_layout;
}

function build_globals($pdf)
{
	$GLOBALS['pagewidth'] = $pdf->GetPageWidth();
	$GLOBALS['pageheight'] = $pdf->GetPageHeight();
	$GLOBALS['xIndentPos'] = 38;
	define('PAGEWIDTH', $GLOBALS['pagewidth']);
	define('PAGEHEIGHT', $GLOBALS['pageheight']);
	define('X_INDEX_POS', $GLOBALS['xIndentPos']);
}

function calc_multicell_lines($pdf, $str, $w)
{
	// must calculate the height of some multi-cell sections
	// to avoid Page break in the middle (spec Job Environment)

	// loop through each word from the beginning checking length.  When it is too long
	// take the word that is too long and start the next line with it.  Repeat for new line
	// when out of words, count lines * line height (ln-h).  Return that value

	$test_w = $w - 10;    // words will not go to very edge of cell
	$words = explode(' ', str_replace('  ', ' ', trim($str)));
	$ln_cnt = 0;
	$tmp_str = '';
	while (count($words)) {
		++$ln_cnt;
		$tmp_str = array_shift($words);
		while ($pdf->GetStringWidth($tmp_str) < $test_w && count($words)) {
			if (!count($words)) {
				break;
			}
			$tmp_str .= array_shift($words);
		}
	}

	return $ln_cnt;
}

function prop_has_value($obj, $prop)
{
	return property_exists($obj, $prop) && $obj->{$prop};
}

function get_candidate($id, $http_host = 'localhost')
{
	$url = "http://{$http_host}/3sixd/api/candidates/{$id}?api_cc=three&api_key=fj49fk390gfk3f50";
	$ret = curl_load_file($url, [], 'GET');

	$tmp = json_decode($ret);
	if (!property_exists($tmp, 'data')) {
		echo 'That candidate could not be found';
		die();
	}

	return $tmp->data;
}

function get_candidate_skills($id, $http_host = 'localhost')
{
	$url = "http://{$http_host}/3sixd/api/candidate_skills/candidate_id/{$id}?api_cc=three&api_key=fj49fk390gfk3f50";
	$ret = curl_load_file($url, [], 'GET');

	$tmp = json_decode($ret);
	if (!property_exists($tmp, 'data')) {
		echo 'That candidate skills could not be found';
		die();
	}

	return $tmp->data;
}

function curl_load_file($url, $post_string = null, $request_type = 'POST')
{
	// create curl resource
	$ch = curl_init();

	// set url
	curl_setopt($ch, CURLOPT_URL, $url);

	//return the transfer as a string
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

	curl_setopt($ch, CURLOPT_TIMEOUT, 180);

	curl_setopt($ch, CURLOPT_USERAGENT, 'localhost test');

	if ('POST' == $request_type) {
		curl_setopt($ch, CURLOPT_POST, 1);
	} else {
		// request_type could be PUT or DELETE
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request_type);
	}

	if ('DELETE' != $request_type) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_string));
	}

	// set up http header fields

	$headers = [
		'Accept: text/json',
		'Pragma: no-cache',
		'Content-Type: application/x-www-form-urlencoded',
		'Connection: keep-alive',
	];

	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

	// add code to accept https certificate
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

	// $output contains the output string
	$output = curl_exec($ch);
	// close curl resource to free up system resources
	curl_close($ch);

	return $output;
}

function build_tech_skills($id, $http_host)
{
	// build the list of skills, grouped by tags for display
	// in the Technical Skills section
	$cand_skills = get_candidate_skills($id, $http_host);
	$tech_skills = [];

	foreach ($cand_skills->skills as $cSkill) {
		if (!$cSkill->resumeTechtagId) {
			continue;
		}
		if (!array_key_exists($cSkill->resumeTechtagId, $tech_skills)) {
			$tech_skills[$cSkill->resumeTechtagId] = [
				'name' => $cSkill->resumeTechtagName,
				'skills' => [],
			];
		}
		// add the skill to the tag, if not there already
		if (!array_search($cSkill->skillName, $tech_skills[$cSkill->resumeTechtagId]['skills'])) {
			$tech_skills[$cSkill->resumeTechtagId]['skills'][] = $cSkill->skillName;
		}
	}

	return $tech_skills;
}

function setDefaults($pdf, $c)
{
	$pdf->SetTitle($c->person->formattedName);
	$pdf->SetMargins(0, 0, 0);
	$pdf->SetAutoPageBreak(true, 10);
	$pdf->AddFont(LABEL_FONT, '', 'signika.php');
	$pdf->AddFont(LABEL_FONT, 'B', 'signikab.php');
	$pdf->AddFont(LABEL_FONT, 'BI', 'signikabi.php');
}

function disp_horiz_separator($pdf, $display_ln = true, $marginTop = 1, $marginBottom = 4)
{
	$pdf->Ln();
	$pdf->Cell(PAGEWIDTH, $marginTop, '', $display_ln ? 'B' : 0, 1);
	$pdf->Cell(PAGEWIDTH, $marginBottom, '', 0, 1);
}

/**
 * Select chosen items if custom resume json w disp properties.
 *
 * @param mixed $items
 * @param mixed $disp
 */
function get_disp_items($items, $disp)
{
	if ($disp === false) {
		$ret_items = $items;
	} else {
		$ret_items = [];
		foreach ($items as $item) {
			if (($fnd_key = array_search($item->id, $disp)) !== false) {
				$ret_items[$fnd_key] = $item;
			}
		}
		ksort($ret_items);
	}
	return $ret_items;
}

/**
 * Candidate Header.
 */
function disp_cand_header($pdf, $c)
{
	// calc Name length
	$pdf->SetFont(LABEL_FONT, 'BI', 22);
	$nameWidth = $pdf->GetStringWidth($c->person->formattedName);

	// calc Title length
	$pdf->SetFont('Arial', '', 16);
	$jobTitle = prop_has_value($c, 'jobTitle') ? $c->jobTitle : $c->experience[0]->jobTitle;
	$titleWidth = $pdf->GetStringWidth($jobTitle);

	// determine number of cert images
	$imgCnt = 0;
	if (prop_has_value($c, 'certifications')) {
		foreach ($c->certifications as $cert) {
			if ($imgCnt < 2 && prop_has_value($cert, 'certificateImage')) {
				++$imgCnt;
			}
		}
	}
	$imageWidth = $imgCnt * (IMAGE_SIZE + 2);

	// calculate left margin and add that to the titleWidth to get the total cell width

	$leftMargin = (PAGEWIDTH - ($nameWidth + $titleWidth + $imageWidth + 12)) / 2;

	$pdf->SetFont(LABEL_FONT, 'BI', 22);
	$pdf->Cell($nameWidth + $leftMargin, 16, $c->person->formattedName, 0, 0, 'R', false);
	$pdf->Cell(12, 16, '', 0, 0, '', false);
	$pdf->SetFont('Arial', '', 16);
	$pdf->Cell($titleWidth + 4, 16, $jobTitle, 0, 0, 'L', false);

	// certificate images, if any
	// max of 2 images
	$tmpY = $pdf->GetY() + 2;
	$tmpX = $pdf->GetX();

	$imgCnt = 0;

	if (prop_has_value($c, 'certifications')) {
		foreach ($c->certifications as $cert) {
			if ($imgCnt < 2 && prop_has_value($cert, 'certificateImage')) {
				$pdf->Image('images/' . $cert->certificateImage, $tmpX, $tmpY, IMAGE_SIZE);
				++$imgCnt;
				$tmpX += IMAGE_SIZE + 2;
			}
		}
	}
}

/**
 * Candidate Highlights.
 *
 * @param mixed $pdf
 * @param mixed $c
 * @param mixed $xPos
 * @param mixed $disp
 */
function disp_cand_highlights($pdf, $c, $disp, $xPos = X_INDEX_POS)
{
	if (prop_has_value($c, 'candidateHighlights') && $disp) {
		$disp_highlights = get_disp_items($c->candidateHighlights, $disp);
		$yPos = $pdf->getY();
		$pdf->SetFont(LABEL_FONT, 'B', LABEL_SIZE);
		$pdf->MultiCell(32, 6, "Professional\nHighlights", 0, 'R');

		$pdf->SetFont('Arial', '', 10);

		foreach ($disp_highlights as $highlight) {
			$pdf->setXY($xPos, $yPos);
			$pdf->Cell(5, HILITE_LN_HEIGHT, chr(127), 0, 0, 'L');
			$pdf->MultiCell(160, HILITE_LN_HEIGHT, trim($highlight->highlight));
			$yPos = $pdf->getY() + 2;
		}
		disp_horiz_separator($pdf, false);
	}
}

/**
 * Candidate Objective.
 *
 * @param mixed $pdf
 * @param mixed $c
 * @param mixed $xPos
 */
function disp_cand_objective($pdf, $c, $xPos = X_INDEX_POS)
{
	if (prop_has_value($c, 'objective')) {
		$yPos = $pdf->getY();
		$pdf->SetFont(LABEL_FONT, 'B', LABEL_SIZE);
		$pdf->MultiCell(32, 6, 'Objective', 0, 'R');

		$pdf->SetFont('Arial', '', 10);

		$pdf->setXY($xPos, $yPos);
		$pdf->MultiCell(160, LN_HEIGHT, trim($c->objective));

		$pdf->Cell(PAGEWIDTH, 2, '', 0, 1);
	}
}

/**
 * Candidate Executive Summary.
 *
 * @param mixed $pdf
 * @param mixed $c
 * @param mixed $xPos
 */
function disp_cand_summary($pdf, $c, $xPos = X_INDEX_POS)
{
	if (prop_has_value($c, 'executiveSummary')) {
		$yPos = $pdf->getY();
		$pdf->SetFont(LABEL_FONT, 'B', LABEL_SIZE);
		$pdf->MultiCell(32, 6, 'Summary', 0, 'R');

		$pdf->SetFont('Arial', '', 10);

		$pdf->setXY($xPos, $yPos);
		$pdf->MultiCell(160, LN_HEIGHT, trim($c->executiveSummary));
	}
}

/**
 * Technical Skills Section.
 *
 * @param mixed $pdf
 * @param mixed $tech_skills
 * @param mixed $xPos
 */
function disp_tech_skills($pdf, $tech_skills, $disp, $xPos = X_INDEX_POS)
{
	if ($tech_skills && $disp) {
		if ($pdf->getY() > PAGEHEIGHT - 40) {
			$pdf->addPage();
			$pdf->Cell(0, 6, '', 0, 1);
		} else {
			$pdf->Cell(0, 12, '', 0, 1);
		}
		$pdf->SetFont(LABEL_FONT, 'B', LABEL_SIZE);
		$tmpY = $pdf->GetY();
		$pdf->MultiCell(32, LN_HEIGHT, "Technical\nSkills", 0, 'R');
		// in case the page break occurred while printing 'Technical Skills,
		// must reset the Y loc
		$tmpY = ($tmpY > $pdf->GetY()) ? $pdf->GetY() - (LN_HEIGHT * 2) : $tmpY;

		$pdf->SetFont('Arial', '', 10);
		// using multi-cell table extension to fpdf
		// first set widths, then send data as array

		$pdf->SetWidths([40, 120]);
		$pdf->SetXY($xPos, $tmpY);
		// tech skills array uses the id as the key
		foreach ($disp as $tech_skill_id) {
			if (array_key_exists($tech_skill_id, $tech_skills)) {
				$pdf->setX($xPos);
				$pdf->Row([$tech_skills[$tech_skill_id]['name'], implode(', ', $tech_skills[$tech_skill_id]['skills'])], LN_HEIGHT);
			}
		}
	}
}

/**
 * Experience functions.
 *
 * @param mixed $pdf
 * @param mixed $c
 * @param mixed $xPos
 */
function disp_cand_exp($pdf, $c, $disp, $xPos = X_INDEX_POS)
{
	if (prop_has_value($c, 'experience') && $disp) {
		// disp for exp is an array of objects so convert to array of ids
		$exp_disp = array_column($disp, 'id');
		$disp_experience = get_disp_items($c->experience, $exp_disp);
		$pdf->SetFont(LABEL_FONT, 'B', LABEL_SIZE);
		$tmpY = $pdf->GetY();
		$pdf->MultiCell(32, LN_HEIGHT, 'Experience', 0, 'R');
		$tmpY = ($tmpY > $pdf->GetY()) ? $pdf->GetY() - LN_HEIGHT : $tmpY;

		$pdf->SetXY($xPos, $tmpY);

		foreach ($disp_experience as $key => $job) {
			$job_disp = $disp ? $disp[$key] : false;
			display_job($job, $pdf, $job_disp, $xPos);
			$pdf->Cell(0, 8, '', 0, 1);
		}
		disp_horiz_separator($pdf, false);
	}
}

// Job
function display_job($job, $pdf, $job_disp, $xPos)
{
	// job title
	$pdf->setX($xPos);
	$pdf->SetFont(LABEL_FONT, 'B', LABEL_SIZE);
	$pdf->SetTextColor(255, 0, 0);
	$pdf->Cell(110, LN_HEIGHT, $job->jobTitle, 0, 0);

	$pdf->SetTextColor(255);
	$pdf->SetFillColor(255, 0, 0);
	$pdf->SetFont(LABEL_FONT, 'B', LABEL_SIZE);
	$pdf->Cell(50, LN_HEIGHT, build_job_dates($job), 0, 1, 'C', true);

	// location and dates
	$pdf->SetX($xPos);
	$pdf->SetTextColor(0);
	$pdf->SetFont(LABEL_FONT, '', LABEL_SIZE);
	$pdf->Cell(110, LN_HEIGHT, build_job_loc($job->company), 0, 1);

	// Job Highlights
	$pdf->SetFont('Arial', 'U', 10);
	$pdf->SetTextColor(0);
	$pdf->SetX($xPos);

	$pdf->Cell(PAGEWIDTH, 4, '', 0, 2);

	if (prop_has_value($job, 'highlights')) {

		$disp_highlights = get_disp_items($job->highlights, $job_disp['expH']);
		$pdf->Cell(0, LN_HEIGHT, 'Responsibilities & Achievements', 0, 2);
		$pdf->Cell(PAGEWIDTH, 2, '', 0, 2);

		$yPos = $pdf->getY();
		$pdf->SetFont('Arial', '', 10);

		foreach ($disp_highlights as $highlight) {
			$pdf->setXY($xPos, $yPos);
			$pdf->Cell(5, HILITE_LN_HEIGHT, chr(127), 0, 0, 'L');
			$pdf->MultiCell(160, HILITE_LN_HEIGHT, trim($highlight->highlight));
			$yPos = $pdf->getY() + 1;
		}
	}

	// Job Environment...skill list
	$job_skills = build_job_environment($job);
	if ($job_skills) {
		$mc_lines = calc_multicell_lines($pdf, $job_skills, 130);
		// echo 'mc lines: ', $mc_lines;
		// echo PAGEHEIGHT, '<br>';
		// echo $pdf->getY();
		// die();
		if (($mc_lines * JOB_SKILLS_LN_HEIGHT) + $pdf->getY() > PAGEHEIGHT - 20) {
			$pdf->addPage();
		}
		$pdf->SetFont('Arial', 'U', 10);
		$pdf->Cell(0, JOB_SKILLS_LN_HEIGHT, '', 0, 1);
		$pdf->SetX($xPos);
		$pdf->Cell(14, LN_HEIGHT, 'Skills:', 0, 0);
		$pdf->SetFont('Arial', '', 10);
		$pdf->MultiCell(130, JOB_SKILLS_LN_HEIGHT, $job_skills);
	}
}

function build_job_environment($job)
{
	$skills = array_column($job->skills, 'name');

	return implode(', ', $skills);
}

function build_job_loc($job)
{
	$city = prop_has_value($job, 'municipality') ? ', ' . $job->municipality : '';
	$state = prop_has_value($job, 'region') ? ', ' . $job->region : '';
	$country = prop_has_value($job, 'countryCode') ? ', ' . $job->countryCode : '';

	return $job->name . $city . $state . $country;
}

function build_job_dates($job)
{
	$ret = '';
	if (prop_has_value($job, 'startDate')) {
		$start = new DateTime($job->startDate);
		$startMonth = $start->format('M/Y');
		if (prop_has_value($job, 'endDate')) {
			$end = new DateTime($job->endDate);
			$endMonth = $end->format('M/Y');
		} else {
			$endMonth = 'Present';
		}
		$ret = $startMonth . ' - ' . $endMonth;
	}

	return $ret;
}

/**
 * Eduction.
 *
 * @param mixed $pdf
 * @param mixed $c
 * @param mixed $xPos
 */
function disp_cand_eds($pdf, $c, $disp, $xPos = X_INDEX_POS)
{
	if (prop_has_value($c, 'education') && $disp) {
		$disp_education = get_disp_items($c->education, $disp);

		$pdf->SetFont(LABEL_FONT, 'B', LABEL_SIZE);
		$tmpY = $pdf->GetY();
		$pdf->MultiCell(32, LN_HEIGHT, "Education\n&Training", 0, 'R');
		$tmpY = ($tmpY > $pdf->GetY()) ? $pdf->GetY() - (LN_HEIGHT * 2) : $tmpY;

		$pdf->SetXY($xPos, $tmpY);

		foreach ($disp_education as $ed) {
			display_education($ed, $pdf);
		}
		disp_horiz_separator($pdf, false);
	}
}

function display_education($ed, $pdf)
{
	// Degree Name
	$pdf->SetFont('Arial', 'B', 12);
	$pdf->SetTextColor(255, 0, 0);
	$pdf->Cell(0, LN_HEIGHT, $ed->degreeName, 0, 2);

	if (prop_has_value($ed, 'schoolName')) {
		$pdf->SetFont('Arial', 'I', 12);
		$pdf->SetTextColor(0);
		$pdf->Cell(0, LN_HEIGHT, $ed->schoolName, 0, 2);
	}

	$pdf->Cell(PAGEWIDTH, 8, '', 0, 2);
}

/**
 * Certifications.
 *
 * @param mixed $pdf
 * @param mixed $c
 * @param mixed $xPos
 */
function disp_cand_cert($pdf, $c, $disp, $xPos = X_INDEX_POS)
{
	if (prop_has_value($c, 'certifications') && $disp) {
		$disp_certifications = get_disp_items($c->certifications, $disp);
		$pdf->SetFont(LABEL_FONT, 'B', LABEL_SIZE);
		$tmpY = $pdf->GetY();
		$pdf->MultiCell(32, LN_HEIGHT, 'Certifications', 0, 'R');
		$tmpY = ($tmpY > $pdf->GetY()) ? $pdf->GetY() - LN_HEIGHT : $tmpY;

		$yPos = $tmpY;
		foreach ($disp_certifications as $cert) {
			$pdf->setXY($xPos, $yPos);
			$pdf->Cell(5, LN_HEIGHT, chr(127), 0, 0, 'L');
			$pdf->MultiCell(160, LN_HEIGHT, trim($cert->name));
			$yPos = $pdf->getY() + 3;
		}
		disp_horiz_separator($pdf, false);
	}
}

/**
 * Main Resume build routine.
 *
 * @param mixed $pdf
 * @param mixed $c
 * @param mixed $tech_skills
 * @param mixed $layout
 */
function build_resume($pdf, $c, $tech_skills, $layout)
{
	setDefaults($pdf, $c);
	$pdf->AddPage();

	// loop through the layout def and build as defined
	foreach ($layout['sections'] as $section) {
		display_resume_section($pdf, $c, $tech_skills, $section);
	}
}

function display_resume_section($pdf, $c, $tech_skills, $section)
{
	$disp = array_key_exists('disp', $section)
		? $section['disp']
		: false;

	switch ($section['name']) {
		case 'hd':
			disp_cand_header($pdf, $c);
			disp_horiz_separator($pdf, true);

			break;
		case 'ob':
			disp_cand_objective($pdf, $c, X_INDEX_POS);

			break;
		case 'ps':
			disp_cand_summary($pdf, $c, X_INDEX_POS);

			break;
		case 'hi':
			// candidate highlights
			disp_cand_highlights($pdf, $c, $disp, X_INDEX_POS);

			break;
		case 'ts':
			// technical skills
			disp_tech_skills($pdf, $tech_skills, $disp, X_INDEX_POS);
			disp_horiz_separator($pdf, false);

			break;
		case 'ex':
			// Experience
			disp_cand_exp($pdf, $c, $disp, X_INDEX_POS);

			break;
		case 'ed':
			// Education & Training
			disp_cand_eds($pdf, $c, $disp, X_INDEX_POS);

			break;
		case 'ct':
			// Certifications
			disp_cand_cert($pdf, $c, $disp, X_INDEX_POS);

			break;
	}
}
