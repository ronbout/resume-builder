<?php


function prop_has_value($obj, $prop)
{
	return property_exists($obj, $prop) && $obj->$prop;
}

// for some reason, incorrectly getting a DEPRECATED error for FPDF construct
// it CORRECTLY uses __construct, but gets error that it is using function FPDF()
error_reporting(~E_DEPRECATED);

set_time_limit(10 * 60);

define('LN_HEIGHT', 6);
define('IMAGE_SIZE', 14);
define('LABEL_FONT', 'Signika');
define('LABEL_SIZE', 14);

require('fpdf2/fpdf_mc_table.php');

$http_host = $_SERVER['HTTP_HOST'];

// get id from GET if present, otherwise use 7 as default for practice
$id = (isset($_GET['id']) && $_GET['id'] && $_GET['id'] !== 'undefined') ? $_GET['id'] : 7;

// first retrieve the api info for the candidate
$candidate = get_candidate($id, $http_host);
// the skills listing is a separate api call
$tech_skills = build_tech_skills($id, $http_host);

// have to extend the fpdf class for the header functionality
class PDF extends FPDF_MC_Table
{
	function Header()
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

$pdf = new PDF();
build_globals($pdf);
build_resume($pdf, $candidate, $tech_skills);

$pdf->Output();

function build_globals($pdf)
{
	$GLOBALS['pagewidth'] = $pdf->GetPageWidth();
	$GLOBALS['pageheight'] = $pdf->GetPageHeight();
	$GLOBALS['xIndentPos'] = 38;
	define('PAGEWIDTH', $GLOBALS['pagewidth']);
	define('PAGEHEIGHT', $GLOBALS['pageheight']);
	define('X_INDEX_POS', $GLOBALS['xIndentPos']);
}

//****************************************************

function get_candidate($id, $http_host = "localhost")
{
	$url = "http://$http_host/3sixd/api/candidates/$id?api_cc=three&api_key=fj49fk390gfk3f50";
	$ret = curl_load_file($url, array(), 'GET');

	$tmp = json_decode($ret);
	if (!property_exists($tmp, 'data')) {
		echo 'That candidate could not be found';
		die();
	}
	return $tmp->data;
}

function get_candidate_skills($id, $http_host = "localhost")
{
	$url = "http://$http_host/3sixd/api/candidate_skills/candidate_id/$id?api_cc=three&api_key=fj49fk390gfk3f50";
	$ret = curl_load_file($url, array(), 'GET');

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

	if ($request_type == 'POST') {
		curl_setopt($ch, CURLOPT_POST, 1);
	} else {
		// request_type could be PUT or DELETE
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request_type);
	}

	if ($request_type != 'DELETE') {
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_string));
	}

	// set up http header fields

	$headers = array(
		'Accept: text/json',
		'Pragma: no-cache',
		'Content-Type: application/x-www-form-urlencoded',
		'Connection: keep-alive'
	);

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
	$tech_skills = array();

	foreach ($cand_skills->skills as $cSkill) {
		if (!$cSkill->resumeTechtagId) continue;
		if (!array_key_exists($cSkill->resumeTechtagId, $tech_skills)) {
			$tech_skills[$cSkill->resumeTechtagId] = array(
				'name' => $cSkill->resumeTechtagName,
				'skills' => array()
			);
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

function disp_horiz_separator($pdf, $marginTop = 1, $marginBottom = 4)
{
	$pdf->Ln();
	$pdf->Cell(PAGEWIDTH, $marginTop, '', 'B', 1);
	$pdf->Cell(PAGEWIDTH, $marginBottom, '', 0, 1);
}

function disp_cand_header($pdf, $c)
{
	// calc Name length
	$pdf->SetFont(LABEL_FONT, 'BI', 22);
	$nameWidth = $pdf->GetStringWidth($c->person->formattedName);

	// calc Title length
	$pdf->SetFont('Arial', '', 16);
	$titleWidth = $pdf->GetStringWidth($c->experience[0]->jobTitle);

	// determine number of cert images
	$imgCnt = 0;
	if (prop_has_value($c, 'certifications')) {
		foreach ($c->certifications as $cert) {
			if ($imgCnt < 2 && prop_has_value($cert, 'certificateImage')) {
				$imgCnt++;
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
	$pdf->Cell($titleWidth + 4, 16, $c->experience[0]->jobTitle, 0, 0, 'L', false);

	// certificate images, if any
	// max of 2 images
	$tmpY = $pdf->GetY() + 2;
	$tmpX = $pdf->GetX();

	$imgCnt = 0;

	if (prop_has_value($c, 'certifications')) {
		foreach ($c->certifications as $cert) {
			if ($imgCnt < 2 && prop_has_value($cert, 'certificateImage')) {
				$pdf->Image('images/' . $cert->certificateImage, $tmpX, $tmpY, IMAGE_SIZE);
				$imgCnt++;
				$tmpX += IMAGE_SIZE + 2;
			}
		}
	}
}

function disp_cand_highlights($pdf, $c, $xPos = X_INDEX_POS)
{
	$pdf->SetFont(LABEL_FONT, 'B', LABEL_SIZE);
	$pdf->MultiCell(32, 6, "Professional\nSummary", 0, 'R');

	$pdf->SetFont('Arial', '', 10);

	$yPos = 41;
	foreach ($c->candidateHighlights as $highlight) {
		$pdf->setXY($xPos, $yPos);
		$pdf->Cell(5, 5, chr(127), 0, 0, 'L');
		$pdf->MultiCell(160, 4, trim($highlight->highlight));
		$yPos = $pdf->getY() + 2;
	}
}

function disp_tech_skills($pdf, $tech_skills, $xPos = X_INDEX_POS)
{
	$pdf->Cell(PAGEWIDTH, 12, '', 0, 2);
	$pdf->SetFont(LABEL_FONT, 'B', LABEL_SIZE);
	$tmpY = $pdf->GetY();
	$pdf->MultiCell(32, LN_HEIGHT, "Technical\nSkills", 0, 'R');
	// in case the page break occurred while printing 'Technical Skills,
	// must reset the Y loc
	$tmpY = ($tmpY > $pdf->GetY()) ? $pdf->GetY() - (LN_HEIGHT * 2)  : $tmpY;

	$pdf->SetFont('Arial', '', 10);
	// using multi-cell table extension to fpdf
	// first set widths, then send data as array

	$pdf->SetWidths(array(40, 120));
	$pdf->SetXY($xPos, $tmpY);
	foreach ($tech_skills as $tech_skill) {
		$pdf->setX($xPos);
		$pdf->Row(array($tech_skill['name'], implode(', ', $tech_skill['skills'])), LN_HEIGHT);
	}
}

function disp_cand_exp($pdf, $c, $xPos = X_INDEX_POS)
{
	disp_horiz_separator($pdf, 12, 12);
	$pdf->SetFont(LABEL_FONT, 'B', LABEL_SIZE);
	$tmpY = $pdf->GetY();
	$pdf->MultiCell(32, LN_HEIGHT, "Experience", 0, 'R');
	$tmpY = ($tmpY > $pdf->GetY()) ? $pdf->GetY() - LN_HEIGHT : $tmpY;

	$pdf->SetXY($xPos, $tmpY);

	foreach ($c->experience as $job) {
		display_job($job, $pdf, $xPos);
		$pdf->Cell(0, 8, '', 0, 1);
	}
}

// Job
function display_job($job, $pdf, $xPos)
{
	// job title
	$pdf->setX($xPos);
	$pdf->SetFont(LABEL_FONT, 'B', LABEL_SIZE);
	$pdf->SetTextColor(255, 0, 0);
	$pdf->Cell(110, LN_HEIGHT, $job->jobTitle, 0, 0);

	$pdf->SetTextColor(255);
	$pdf->SetFillColor(255, 0, 0);
	$pdf->SetFont(LABEL_FONT, 'B', LABEL_SIZE);
	$pdf->Cell(50, LN_HEIGHT, build_job_dates($job), 0, 1, "C", true);

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
		$pdf->Cell(0, LN_HEIGHT, 'Responsibilities & Achievements', 0, 2);
		$pdf->Cell(PAGEWIDTH, 2, '', 0, 2);

		$yPos = $pdf->getY();
		$pdf->SetFont('Arial', '', 10);

		foreach ($job->highlights as $highlight) {
			$pdf->setXY($xPos, $yPos);
			$pdf->Cell(5, 5, chr(127), 0, 0, 'L');
			$pdf->MultiCell(160, 4, trim($highlight->highlight));
			$yPos = $pdf->getY() + 1;
		}
	}

	// Job Environment...skill list
	$job_skills = build_job_environment($job);
	if ($job_skills) {
		$pdf->SetFont('Arial', 'U', 10);
		$pdf->Cell(0, 5, '', 0, 1);
		$pdf->SetX($xPos);
		$pdf->Cell(26, LN_HEIGHT, 'Environment:', 0, 0);
		$pdf->SetFont('Arial', '', 10);
		$pdf->MultiCell(130, LN_HEIGHT, $job_skills);
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

function disp_cand_eds($pdf, $c, $xPos = X_INDEX_POS)
{
	if (prop_has_value($c, 'education')) {
		disp_horiz_separator($pdf, 12, 12);
		$pdf->SetFont(LABEL_FONT, 'B', LABEL_SIZE);
		$tmpY = $pdf->GetY();
		$pdf->MultiCell(32, LN_HEIGHT, "Education\n&Training", 0, 'R');
		$tmpY = ($tmpY > $pdf->GetY()) ? $pdf->GetY() - (LN_HEIGHT * 2) : $tmpY;

		$pdf->SetXY($xPos, $tmpY);

		foreach ($c->education as $ed) {
			display_education($ed, $pdf);
		}
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

function disp_cand_cert($pdf, $c, $xPos = X_INDEX_POS)
{
	if (prop_has_value($c, 'certifications')) {
		disp_horiz_separator($pdf, 4, 12);
		$pdf->SetFont(LABEL_FONT, 'B', LABEL_SIZE);
		$tmpY = $pdf->GetY();
		$pdf->MultiCell(32, LN_HEIGHT, "Certifications", 0, 'R');
		$tmpY = ($tmpY > $pdf->GetY()) ? $pdf->GetY() - LN_HEIGHT : $tmpY;

		$yPos = $tmpY;
		foreach ($c->certifications as $cert) {
			$pdf->setXY($xPos, $yPos);
			$pdf->Cell(5, LN_HEIGHT, chr(127), 0, 0, 'L');
			$pdf->MultiCell(160, LN_HEIGHT, trim($cert->name));
			$yPos = $pdf->getY() + 3;
		}
	}
}

function build_resume($pdf, $c, $tech_skills)
{
	setDefaults($pdf, $c);
	$pdf->AddPage();

	disp_cand_header($pdf, $c);

	disp_horiz_separator($pdf);

	// candidate highlights
	disp_cand_highlights($pdf, $c, X_INDEX_POS);

	// technical skills
	disp_tech_skills($pdf, $tech_skills, X_INDEX_POS);

	// Experience
	disp_cand_exp($pdf, $c, X_INDEX_POS);

	// Education & Training
	disp_cand_eds($pdf, $c, X_INDEX_POS);

	// Certifications
	disp_cand_cert($pdf, $c, X_INDEX_POS);
}
