<?php

// for some reason, incorrectly getting a DEPRECATED error for FPDF construct
// it CORRECTLY uses __construct, but gets error that it is using function FPDF()

function prop_has_value($obj, $prop)
{
	return property_exists($obj, $prop) && $obj->$prop;
}

error_reporting(~E_DEPRECATED);

set_time_limit(10 * 60);

define('LN_HEIGHT', 6);
define('IMAGE_SIZE', 14);
define('LABEL_FONT', 'Signika');
define('LABEL_SIZE', 14);

require('fpdf2/fpdf_mc_table.php');

// get id from GET if present, otherwise use 7 as default for practice
$id = (isset($_GET['id']) && $_GET['id'] && $_GET['id'] !== 'undefined') ? $_GET['id'] : 7;

// first retrieve the api info for the candidate
$candidate = get_candidate($id);
// the skills listing is a separate api call
$tech_skills = build_tech_skills($id);

// have to extend the fpdf class for the header functionality

class PDF extends FPDF_MC_Table
{

	function Header()
	{
		$this->SetFont('');
		$this->Cell(0, 3, '', 0, 2, '', false);
		$this->SetFillColor(245, 30, 30);
		$this->Cell(0, 5, '', 0, 2, '', true);
		$this->SetFillColor(200);
		$this->Cell(0, 8, '', 0, 2, '', true);
		$this->Cell($this->w, 4, '', 0, 2);
	}
}

build_resume($candidate, $tech_skills);

//****************************************************

function get_candidate($id)
{
	//$url = "http://13.90.143.153/3sixd/api/candidates/$id?api_cc=three&api_key=fj49fk390gfk3f50";
	$url = "http://localhost/3sixd/api/candidates/$id?api_cc=three&api_key=fj49fk390gfk3f50";
	$ret = curl_load_file($url, array(), 'GET');

	// echo  var_dump($ret);

	$tmp = json_decode($ret);
	if (!property_exists($tmp, 'data')) {
		echo 'That candidate could not be found';
		die();
	}
	return $tmp->data;
}

function get_candidate_skills($id)
{
	//$url = "http://13.90.143.153/3sixd/api/candidate_skills/candidate_id/$id?api_cc=three&api_key=fj49fk390gfk3f50";
	$url = "http://localhost/3sixd/api/candidate_skills/candidate_id/$id?api_cc=three&api_key=fj49fk390gfk3f50";
	$ret = curl_load_file($url, array(), 'GET');

	// echo  var_dump($ret);

	$tmp = json_decode($ret);
	if (!property_exists($tmp, 'data')) {
		echo 'That candidate could not be found';
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

function build_tech_skills($id)
{

	$cand_skills = get_candidate_skills(($id));
	// build the list of skills, grouped by tags for display
	// in the Technical Skills section

	$tech_skills = array();
	/**
	 * 
	 * loop through cand skills building $tech skills like below
	 * 
	 * 
	 */

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

function build_resume($c, $tech_skills)
{
	$pdf = new PDF();

	$pdf->SetTitle($c->person->formattedName);
	$pdf->SetMargins(0, 0, 0);
	$pdf->SetAutoPageBreak(true, 10);
	$pdf->AddFont('Signika', '', 'signika.php');
	$pdf->AddFont('Signika', 'B', 'signikab.php');
	$pdf->AddFont('Signika', 'BI', 'signikabi.php');
	$pdf->AddPage();

	// TODO:  CALCULATE THE WIDTH OF THE VARIOUS PARTS OF THE HEADING AND MAKE SURE
	// 		  THAT IT IS EVENLY SPACED!  use GetStringWidth and the # of images

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

	$leftMargin = ($pdf->GetPageWidth() - ($nameWidth + $titleWidth + $imageWidth + 12)) / 2;

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

	$pdf->Ln();
	$pdf->Cell($pdf->GetPageWidth(), 1, '', 'B', 2);
	$pdf->Cell($pdf->GetPageWidth(), 4, '', 0, 2);

	// candidate highlights
	$pdf->SetFont(LABEL_FONT, 'B', LABEL_SIZE);
	$pdf->MultiCell(32, 6, "Professional\nSummary", 0, 'R');

	$pdf->SetFont('Arial', '', 10);

	$yPos = 41;
	$xPos = 38;
	foreach ($c->candidateHighlights as $highlight) {
		$pdf->setXY($xPos, $yPos);
		$pdf->Cell(5, 5, chr(127), 0, 0, 'L');
		$pdf->MultiCell(160, 4, trim($highlight->highlight));
		$yPos = $pdf->getY() + 2;
	}

	// technical skills
	$pdf->Cell($pdf->GetPageWidth(), 12, '', 0, 2);
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

	// Experience
	$pdf->Ln();
	$pdf->Cell($pdf->GetPageWidth(), 12, '', 'B', 2);
	$pdf->Cell($pdf->GetPageWidth(), 12, '', 0, 2);
	$pdf->SetFont(LABEL_FONT, 'B', LABEL_SIZE);
	$tmpY = $pdf->GetY();
	$pdf->MultiCell(32, LN_HEIGHT, "Experience", 0, 'R');
	$tmpY = ($tmpY > $pdf->GetY()) ? $pdf->GetY() - LN_HEIGHT : $tmpY;

	$pdf->SetXY($xPos, $tmpY);

	foreach ($c->experience as $job) {
		display_job($job, $pdf, $xPos);
	}

	// Education & Training
	if (prop_has_value($c, 'education')) {
		$pdf->Ln();
		$pdf->Cell($pdf->GetPageWidth(), 12, '', 'B', 2);
		$pdf->Cell($pdf->GetPageWidth(), 12, '', 0, 2);
		$pdf->SetFont(LABEL_FONT, 'B', LABEL_SIZE);
		$tmpY = $pdf->GetY();
		$pdf->MultiCell(32, LN_HEIGHT, "Education\n&Training", 0, 'R');
		$tmpY = ($tmpY > $pdf->GetY()) ? $pdf->GetY() - (LN_HEIGHT * 2) : $tmpY;

		$pdf->SetXY($xPos, $tmpY);

		foreach ($c->education as $ed) {
			display_education($ed, $pdf);
		}
	}

	// Certifications

	if (prop_has_value($c, 'certifications')) {
		$pdf->Ln();
		$pdf->Cell($pdf->GetPageWidth(), 4, '', 'B', 2);
		$pdf->Cell($pdf->GetPageWidth(), 12, '', 0, 2);
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

	$pdf->Output();
}

// Job

function display_job($job, $pdf, $xPos)
{
	// job title
	$pdf->SetFont(LABEL_FONT, 'B', LABEL_SIZE);
	$pdf->SetTextColor(255, 0, 0);
	$pdf->Cell(0, LN_HEIGHT, $job->jobTitle, 0, 2);

	// location and dates
	$pdf->SetX($xPos);
	$pdf->SetTextColor(0);
	$pdf->SetFont(LABEL_FONT, '', LABEL_SIZE);
	$pdf->Cell(110, LN_HEIGHT, build_job_loc($job->company));

	$pdf->SetTextColor(255);
	$pdf->SetFillColor(255, 0, 0);
	$pdf->SetFont(LABEL_FONT, 'B', LABEL_SIZE);
	$pdf->Cell(50, LN_HEIGHT, build_job_dates($job), 0, 1, "C", true);

	// Job Highlights
	$pdf->SetFont('Arial', 'U', 10);
	$pdf->SetTextColor(0);
	$pdf->SetX($xPos);

	$pdf->Cell($pdf->GetPageWidth(), 4, '', 0, 2);

	if (prop_has_value($job, 'highlights')) {
		$pdf->Cell(0, LN_HEIGHT, 'Responsibilities & Achievements', 0, 2);
		$pdf->Cell($pdf->GetPageWidth(), 4, '', 0, 2);

		$yPos = $pdf->getY();
		$pdf->SetFont('Arial', '', 10);

		foreach ($job->highlights as $highlight) {
			$pdf->setXY($xPos, $yPos);
			$pdf->Cell(5, 5, chr(127), 0, 0, 'L');
			$pdf->MultiCell(160, 4, trim($highlight->highlight));
			$yPos = $pdf->getY() + 2;
		}
	}

	// Job Environment...skill list
	$pdf->SetFont('Arial', 'U', 10);
	$pdf->SetX($xPos - 8);
	$pdf->Cell($pdf->GetPageWidth(), 8, '', 0, 2);
	$pdf->Cell(26, LN_HEIGHT, 'Environment:', 0, 0);
	$pdf->SetFont('Arial', '', 10);
	$pdf->MultiCell(130, LN_HEIGHT - 2, build_job_environment($job));
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

	$pdf->Cell($pdf->GetPageWidth(), 8, '', 0, 2);
}
