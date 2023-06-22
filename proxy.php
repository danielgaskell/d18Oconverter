<!DOCTYPE html>
<!--
  Copyright (c) 2023, Daniel E. Gaskell and Pincelli M. Hull.

  Permission is hereby granted, free of charge, to any person obtaining a copy
  of this software and associated documentation files (the "Software"), to deal
  in the Software without restriction, including without limitation the rights
  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
  copies of the Software, and to permit persons to whom the Software is
  furnished to do so, subject to the following conditions:

    - The above copyright notice and this permission notice shall be included
      in all copies or substantial portions of the Software.
    - Any publications making use of the Software or any substantial portions
      thereof shall cite the Software's original publication:

          Gaskell, D.E., and Hull, P.M., 2023, Technical note: A new online tool
		  for δ18O–temperature conversions: Climate of the Past, v. 19, p.
		  1265–1274, doi:10.5194/cp-19-1265-2023.

  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
  SOFTWARE.
-->
<html>
<head>
	<meta charset="utf-8">
	<title>&delta;¹⁸O to temperature converter - results</title>
	<script src="https://polyfill.io/v3/polyfill.min.js?features=es6"></script>
	<script type="text/javascript" async src="https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.0/MathJax.js?config=MML_HTMLorMML"></script>
	<link rel="stylesheet" href="style.css">
</head>
<body>
	<h1>&delta;<sup>18</sup>O to temperature converter</h1>
	<ul class="tabbar">
		<li><a href="index.html" class="activetab">Convert data</a></li>
		<li><a href="about.html">Information</a></li>
	</ul>
	<div class="shadow"></div>
<?php
	/* Note throughout that some calibrations use a simple offset of -0.27 per
	   mil to convert VSMOW to VPDB (Hut 1987), while others use the more
	   formally correct relationship 0.97001x - 29.99 (Brand et al. 2014). This
	   discrepancy relates to legacy differences in how d18O is measured and
	   compared (and is often handled incorrectly!), but this does not affect
	   the validity of the calibrations themselves so long as d18O values are
	   converted by the same method used to generate the calibration. */
	   
	include "spline.php";
	
	$version = "1.2";

	$errors = FALSE; // set to indicate non-fatal errors
	$age_error = FALSE;
	$lat_error = FALSE;
	$temp_error = FALSE;
	$explain_timescale = FALSE;

	// error out and exit
	function throw_error($errstr) {
		echo "<div class='errorbox'><b>ERROR:</b> $errstr</div></body></html>";
		exit();
	}

	// handle non-fatal/expected PHP errors
	function warning_handler($errno, $errstr) {
		switch ($errstr) {
			case "array_combine(): Both parameters should have an equal number of elements":
				throw_error("The datasheet is malformed; this is most likely due to missing or extra values in some rows, or to incorrect commas.");
				break;
			default:
				break; // suppress error
		}
	}
	
	// convert column names into keys
	function csv_headers(&$data) {
		set_error_handler("warning_handler", E_WARNING);
		array_walk($data, function(&$row) use ($data) {
			$row = array_combine($data[0], $row);
		});
		restore_error_handler();
		array_shift($data); # remove column header
	}
	
	// modify/create array column
	function mutate(&$data, $key, callable $routine) {
		array_walk($data, function(&$row) use ($key, $routine) {
			$row[$key] = $routine($row);
		});
	}
	
	// modify/create multiple array columns at once
	function mutate_poly(&$data, callable $routine) {
		array_walk($data, function(&$row) use ($routine) {
			$pairs = $routine($row);
			foreach (array_keys($pairs) as $key) {
				$row[$key] = $pairs[$key];
			}
		});
	}
	
	// strip Unicode BOM if present (Excel sometimes saves these)
	function strip_BOM($csv) {
		if (is_array($csv) and strlen($csv[0]) >= 3 and substr($csv[0], 0, 3) == chr(hexdec('EF')) . chr(hexdec('BB')) . chr(hexdec('BF'))) {
			return array(substr($csv[0], 3)) + array_slice($csv, 1);
		} else {
			return $csv;
		}
	}
	
	// get next lower value of $y[][$xkey] than $xval
	function next_lower($y, $xkey, $xval) {
		$lowest = -1e100;
		$index = -1;
		foreach ($y as $i => $row) {
			$x = floatval($row[$xkey]);
			if ($x > $lowest and $x <= $xval) {
				$lowest = $x;
				$index = $i;
			}
		}
		return $index;
	}
	
	// get next higher value of $y[][$xkey] than $xval
	function next_higher($y, $xkey, $xval) {
		$highest = 1e100;
		$index = -1;
		foreach ($y as $i => $row) {
			$x = floatval($row[$xkey]);
			if ($x < $highest and $x >= $xval) {
				$highest = $x;
				$index = $i;
			}
		}
		return $index;
	}
	
	// linear interpolation
	function interpolate($xval, $y, $xkey, $ykey) {
		global $errors;
		$xval = floatval($xval);
		$next_lower = next_lower($y, $xkey, $xval);
		$next_higher = next_higher($y, $xkey, $xval);
		if ($next_lower != -1 and $next_higher != -1) {
			if ($next_lower == $next_higher) {
				return $y[$next_lower][$ykey];
			} elseif ($y[$next_lower][$ykey] != "" and $y[$next_higher][$ykey] != "") {
				$xprop = ($xval - $y[$next_lower][$xkey]) / ($y[$next_higher][$xkey] - $y[$next_lower][$xkey]);
				return $y[$next_lower][$ykey] + (($y[$next_higher][$ykey] - $y[$next_lower][$ykey]) * $xprop);
			} else {
				$errors = TRUE;
				return NAN;
			}
		} else {
			$errors = TRUE;
			return NAN;
		}
	}
	
	// great circle distance between two points (in km)
	function sphere_dist($lat1, $long1, $lat2, $long2) {
		$phi1 = $lat1 * 0.017453;
		$phi2 = $lat2 * 0.017453;
		$delta_phi = ($lat2 - $lat1) * 0.017453;
		$delta_lambda = ($long2 - $long1) * 0.017453;
		$a = sin($delta_phi / 2) * sin($delta_phi / 2) + cos($phi1) * cos($phi2) * sin($delta_lambda / 2) * sin($delta_lambda / 2);
		return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
	}
	
	// calculate the standard deviation of an array
	function array_sd($arr) {
        $count = count($arr);
		if ($count === 0) {
			return NAN;
		} else {
			$variance = 0.0;
			$average = array_sum($arr) / $count; 
			foreach ($arr as $item) {
				$variance += pow(($item - $average), 2);
			}
			return (float)sqrt($variance / $count);
		}
    }
	
	// take mean of an area of d18Osw
	function patch_mean($dataset, $lat, $long, $size, $index) {
		global $avgmethod;
		$pairs = array();
		if ($size == 0) {
			// nearest point
			$nearest_index = -1;
			$nearest_dist = 1e100;
			foreach ($dataset as $row_index => $row) {
				$dist = sphere_dist($lat, $long, $row['lat'], $row['lon']);
				if ($dist < $nearest_dist) {
					$nearest_index = $row_index;
					$nearest_dist = $dist;
				}
			}
			$pairs["d18Osw_spatial"] = $dataset[$nearest_index][$index];
			$pairs["n"] = 1;
			$pairs["sd"] = NAN;
		} else if ($avgmethod == "degrees") {
			// area mean (degrees lat/long)
			$patch_data = array_filter($dataset, function(&$row) use ($lat, $long, $size) {
				return (abs($lat  - $row['lat']) <= $size) &&
					   (abs($long - $row['lon']) <= $size || abs($long - $row['lon'] + 360) <= $size);
			});
			if (count($patch_data) >= 1) {
				$pairs["d18Osw_spatial"] = array_sum(array_column($patch_data, $index)) / count($patch_data);
			} else {
				$pairs["d18Osw_spatial"] = NAN;
			}
			$pairs["n"] = count($patch_data);
			$pairs["sd"] = array_sd(array_column($patch_data, $index));
		} else {
			// area mean (radius in km)
			$patch_data = array_filter($dataset, function(&$row) use ($lat, $long, $size) {
				return sphere_dist($lat, $long, $row['lat'], $row['lon']) <= $size;
			});
			if (count($patch_data) >= 1) {
				$pairs["d18Osw_spatial"] = array_sum(array_column($patch_data, $index)) / count($patch_data);
			} else {
				$pairs["d18Osw_spatial"] = NAN;
			}
			$pairs["n"] = count($patch_data);
			$pairs["sd"] = array_sd(array_column($patch_data, $index));
		}
		return $pairs;
	}
	
	// format table for output
	function format_table($table) {
		global $digits, $needed;
		
		// omit unneeded columns
		$filter_out_keys = array_filter($needed, function ($val) {return !$val;});
		foreach (array_keys($table) as $rowkey) {
			$table[$rowkey] = array_diff_key($table[$rowkey], $filter_out_keys);
		}
		
		// output table
		$output = "<table class='resultstable'>\n";
		$output .= "<tr><th>" . implode("</th><th>", array_keys($table[0])) . "</th></tr>\n";
		foreach ($table as $row) {
			// round digits
			foreach ($digits as $roundkey => $roundnum) {
				if (array_key_exists($roundkey, $row)) {
					$row[$roundkey] = number_format($row[$roundkey], $roundnum, '.', '');
				}
			}
			// format rows with errors
			if (array_key_exists("notes", $row) and $row["notes"] == "Outside calibration range") {
				$output .= "<tr class='rowcaution'>";
			} elseif (array_key_exists("notes", $row) and $row["notes"]) {
				$output .= "<tr class='rowerror'>";
			} else {
				$output .= "<tr>";
			}
			// output rest of row
			$output .= "<td>" . implode("</td><td>", $row) . "</td></tr>\n";
		}
		$output .= "</table>";
		$output = str_ireplace("<td>NAN</td>", "<td class='tderror'>NaN</td>", $output);
		return $output;
	}
	
	// format definitions of listed columns
	function format_definitions($table) {
		global $needed, $definitions;
		
		foreach (array_keys($table[0]) as $key) {
			if (array_key_exists($key, $needed) and array_key_exists($key, $definitions) and $needed[$key] === TRUE) {
				echo "<li><b>$key</b>: " . $definitions[$key] . "</li>\n";
			}
		}
	}
	
	// read data
	$data = array_map('str_getcsv', explode("\n", strip_BOM($_POST['data'])));
	if ($_FILES['filename']['tmp_name']) $data = array_map('str_getcsv', strip_BOM(file($_FILES['filename']['tmp_name']))); // load file if present
	if (sizeof($data[0]) == 1) array_unshift($data, array("d18O")); // add header to single-column datasheets
	array_walk_recursive($data, function (&$item) {$item = trim($item);}); // trim whitespace
	$data = array_filter($data, function ($row) {return (sizeof($row) > 1 or $row[0] != '');}); // strip blank rows
	csv_headers($data);
	if (count($data) == 0) throw_error("no valid data provided");
	
	// read parameters
	if (array_key_exists("calibration", $_POST))$calibration = $_POST['calibration'];	else	$calibration = NAN;
	if (array_key_exists("timescale", $_POST))	$timescale   = $_POST['timescale'];		else	$timescale = NAN;
	if (array_key_exists("age", $_POST)) 		$ageraw      = $_POST['age'];			else	$ageraw = NAN;
	if (array_key_exists("lat", $_POST)) 		$latraw      = $_POST['lat'];			else	$latraw = NAN;
	if (array_key_exists("long", $_POST)) 		$longraw     = $_POST['long'];			else	$longraw = NAN;
	if (array_key_exists("latlong", $_POST)) 	$latlong     = $_POST['latlong'];		else	$latlong = NAN;
	if (array_key_exists("ice", $_POST)) 		$ice 		 = $_POST['ice'];			else	$ice = NAN;
	if (array_key_exists("d18Osw", $_POST)) 	$d18Osw      = $_POST['d18Osw'];		else	$d18Osw = NAN;
	if (array_key_exists("spatial", $_POST)) 	$spatial     = $_POST['spatial'];		else	$spatial = NAN;
	if (array_key_exists("benthic", $_POST)) 	$benthic     = $_POST['benthic'];		else	$benthic = NAN;
	if (array_key_exists("benthicraw", $_POST)) $benthicraw  = $_POST['benthicraw'];	else	$benthicraw = NAN;
	if (array_key_exists("gcm", $_POST)) 		$gcm	     = $_POST['gcm'];			else	$gcm = NAN;
	if (array_key_exists("square", $_POST)) 	$square	     = $_POST['square'];		else	$square = NAN;
	if (array_key_exists("co3", $_POST)) 		$co3         = $_POST['co3'];			else	$co3 = NAN;
	if (array_key_exists("co3record", $_POST)) 	$co3record   = $_POST['co3record'];		else	$co3record = NAN;
	if (array_key_exists("co3raw", $_POST)) 	$co3raw      = $_POST['co3raw'];		else	$co3raw = NAN;
	if (array_key_exists("avgmethod",$_POST))	$avgmethod   = $_POST["avgmethod"];
	
	// merge parameters as needed
	if (!is_numeric($d18Osw))     {$d18Osw = NAN;}
	if (!is_numeric($co3raw))     {$co3raw = NAN;}
	if (!is_numeric($benthicraw)) {$benthicraw = NAN;}
	if (!array_key_exists("age", $data[0]))  mutate($data, "age",  function($row) use ($ageraw)  {return $ageraw;});
	if (!array_key_exists("lat", $data[0]))  mutate($data, "lat",  function($row) use ($latraw)  {return $latraw;});
	if (!array_key_exists("long", $data[0])) mutate($data, "long", function($row) use ($longraw) {return $longraw;});
	
	// validate data
	function check_numeric(&$item) {
		global $errors;
		if (!is_numeric($item)) {
			$item = NAN;
		}
	}
	array_walk_recursive($data, "check_numeric");
	if (!in_array("d18O", array_keys($data[0]))) {
		throw_error("No column in the datasheet has the header <code>d18O</code>.");
	}
	foreach (array_keys($data[0]) as $key) {
		if (!in_array($key, array("d18O", "age", "lat", "long"))) {
			throw_error("Unexpected column header '<code>" . htmlentities($key) . "</code>'; headers should be one or more of <code>d18O</code>, <code>age</code>, <code>lat</code>, or <code>long</code>.");
		}
	}

	// set up output
	$description = "";
	$citations = array("<li class='citation'>Gaskell, D.E., Pincelli M. Hull, 2023. Technical note: A new online tool for &delta;<sup>18</sup>O-temperature conversions: Climate of the Past, v. 19, p. 1265–1274, doi:10.5194/cp-19-1265-2023.</li>");
	$cite_bibtex = array("@article{gaskell_technical_2023, title = {Technical note: {A} new online tool for δ{18O}–temperature conversions}, volume = {19}, issn = {1814-9324}, shorttitle = {Technical note}, url = {https://cp.copernicus.org/articles/19/1265/2023/}, doi = {10.5194/cp-19-1265-2023},	language = {English}, number = {6}, journal = {Climate of the Past}, author = {Gaskell, Daniel E. and Hull, Pincelli M.}, month = jun, year = {2023}, note = {Publisher: Copernicus GmbH}, pages = {1265--1274},}");
	$valid_age_start = 0;
	$valid_age_end = 1e100;
	$valid_lat_start = -90;
	$valid_lat_end = 90;
	$valid_temp_start = -1000;
	$valid_temp_end = 1000;
	$digits = array();
	$needed = array("d18O" => TRUE,
					"age" => FALSE,
					"lat" => FALSE,
					"long" => FALSE,
					"pallat" => FALSE,
					"pallong" => FALSE,
					"d18O_CO3" => FALSE,
					"temp_benthic" => FALSE,
					"d18Osw_global" => FALSE,
					"d18Osw_spatial" => FALSE,
					"n" => FALSE,
					"sd" => FALSE,
					"temp" => TRUE,
					"notes" => FALSE);
	$definitions = array("d18O" => "Sample carbonate &delta;<sup>18</sup>O (in &permil; VPDB)",
						 "age" => "Sample age (in millions of years)",
						 "lat" => "Sample latitude (in decimal degrees)",
						 "long" => "Sample longitude (in decimal degrees)",
						 "pallat" => "Sample paleolatitude (in decimal degrees)",
						 "pallong" => "Sample paleolongitude (in decimal degrees)",
						 "CO3" => "Seawater [CO<sub>3</sub><sup>2-</sup>] (in μmol/kg)",
						 "d18O_CO3" => "Sample carbonate &delta;<sup>18</sup>O after correcting for seawater carbonate chemistry (in &permil; VPDB)",
						 "temp_benthic" => "Bottom-water temperature (in &deg;C)",
						 "d18Osw_global" => "Global seawater &delta;<sup>18</sup>O (in &permil; VSMOW)",
						 "d18Osw_spatial" => "Local difference in seawater &delta;<sup>18</sup>O from <b>d18Osw_global</b> (in &permil; VSMOW)",
						 "n" => "Number of dataset points averaged to estimate <b>d18Osw_spatial</b>",
						 "sd" => "Standard deviation of dataset points averaged to estimate <b>d18Osw_spatial</b>",
						 "temp" => "Converted temperature (in &deg;C)",
						 "temp_2.5" => "2.5% quantile error on converted temperatures (bottom of 95% CI), according to bayfox (in &deg;C)",
						 "temp_97.5" => "97.5% quantile error on converted temperatures (top of 95% CI), according to bayfox (in &deg;C)");
	
	// process parameters
	if ($co3 != "none" and $co3record == "none") {
		throw_error("Must select both a [CO<sub>3</sub><sup>2-</sup>] correction and [CO<sub>3</sub><sup>2-</sup>] record to use [CO<sub>3</sub><sup>2-</sup>] correction.");
	}
	if ($co3 != "none") {
		switch ($co3record) {
			case "none":
				break;
			case "fixed":
				$co3value = floatval($co3raw);
				$description .= "&delta;<sup>18</sup>O data were corrected for seawater carbonate chemistry using a [CO<sub>3</sub><sup>2-</sup>] value of " . strval($co3value); " μmol/kg and ";
				mutate($data, "CO3", function($row) use ($co3value) {return $co3value;});
				$needed['CO3'] = TRUE;
				break;
			case "tyrrellzeebe":
				$description .= "&delta;<sup>18</sup>O data were corrected for seawater carbonate chemistry using the global [CO<sub>3</sub><sup>2-</sup>] reconstruction of Tyrrell & Zeebe (2004) and ";
				array_push($citations, "<li class='citation'>Tyrrell, T., and Zeebe, R.E., 2004, History of carbonate ion concentration over the last 100 million years: Geochimica et Cosmochimica Acta, v. 68, p. 3521–3530, doi:10.1016/j.gca.2004.02.018.</li>");
				array_push($cite_bibtex, "@article{tyrrell_history_2004,\n\ttitle = {History of carbonate ion concentration over the last 100 million years},\n\tvolume = {68},\n\tissn = {0016-7037},\n\turl = {http://www.sciencedirect.com/science/article/pii/S0016703704001681},\n\tdoi = {10.1016/j.gca.2004.02.018},\n\tnumber = {17},\n\turldate = {2018-06-14},\n\tjournal = {Geochimica et Cosmochimica Acta},\n\tauthor = {Tyrrell, Toby and Zeebe, Richard E.},\n\tmonth = sep,\n\tyear = {2004},\n\tpages = {3521--3530},\n}");
				$tyrrell_zeebe = array_map('str_getcsv', file("./data/tyrrell_zeebe_2004.csv")); csv_headers($tyrrell_zeebe);
				mutate($data, "CO3", function($row) use ($tyrrell_zeebe) {return interpolate($row['age'], $tyrrell_zeebe, 'age', 'CO3');});
				if ($valid_age_end > 100) $valid_age_end = 100;
				$digits['CO3'] = 2;
				$needed['CO3'] = TRUE;
				$needed['age'] = TRUE;
				break;
			case "zeebetyrrell":
				$description .= "&delta;<sup>18</sup>O data were corrected for seawater carbonate chemistry using the global [CO<sub>3</sub><sup>2-</sup>] reconstruction of Zeebe & Tyrrell (2019) and ";
				array_push($citations, "<li class='citation'>Zeebe, R.E., and Tyrrell, T., 2019, History of carbonate ion concentration over the last 100 million years II: Revised calculations and new data: Geochimica et Cosmochimica Acta, doi:10.1016/j.gca.2019.02.041.</li>");
				array_push($cite_bibtex, "@article{zeebe_history_2019,\n\ttitle = {History of carbonate ion concentration over the last 100 million years {II}: {Revised} calculations and new data},\n\tissn = {0016-7037},\n\tshorttitle = {History of carbonate ion concentration over the last 100 million years {II}},\n\turl = {http://www.sciencedirect.com/science/article/pii/S0016703719301383},\n\tdoi = {10.1016/j.gca.2019.02.041},\n\turldate = {2019-03-12},\n\tjournal = {Geochimica et Cosmochimica Acta},\n\tauthor = {Zeebe, Richard E. and Tyrrell, Toby},\n\tmonth = mar,\n\tyear = {2019},\n}");
				$zeebe_tyrrell = array_map('str_getcsv', file("./data/zeebe_tyrrell_2019.csv")); csv_headers($zeebe_tyrrell);
				mutate($data, "CO3", function($row) use ($zeebe_tyrrell) {return interpolate($row['age'], $zeebe_tyrrell, 'age', 'CO3');});
				if ($valid_age_end > 100) $valid_age_end = 100;
				$digits['CO3'] = 2;
				$needed['CO3'] = TRUE;
				$needed['age'] = TRUE;
				break;
		}
	}
	switch ($co3) {
		// intercepts produce 0 offset at current seawater [CO3] (200 umol/kg)
		case "none":
			mutate($data, "d18O_CO3", function($row) {return $row['d18O'];});
			break;
		case "ziveri_cocco":
			$description .= "the carbonate-ion effect of the coccolithophore <i>Calcidiscus leptoporus</i> (Ziveri et al. 2012): "
						 . "<div class='math'><math><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>corr</mn></msub><mo> = </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><mtext>O</mtext><mo> - </mo><mo>(</mo><mn>0.96</mn><mo> - </mo><mn>0.0048</mn><mo>[</mo><msubsup><mtext>CO</mtext><mn>3</mn><mtext>2-</mtext></msubsup><mo>]</mo><mo>)</mo></math></div>"
						 . "where &delta;<sup>18</sup>O<sub><i>corr</i></sub> is the corrected oxygen isotope value of the carbonate (&#8240; VPBD), &delta;<sup>18</sup>O is the raw oxygen isotope value of the carbonate (&#8240; VPBD), and [CO<sub>3</sub><sup>2-</sup>] is the carbonate-ion composition of the seawater (&mu;mol kg<sup>-1</sup>). ";
			array_push($citations, "<li class='citation'>Ziveri, P., Thoms, S., Probert, I., Geisen, M., and Langer, G., 2012, A universal carbonate ion effect on stable oxygen isotope ratios in unicellular planktonic calcifying organisms: Biogeosciences, v. 9, p. 1025–1032, doi:10.5194/bg-9-1025-2012.</li>");
			array_push($cite_bibtex, "@article{ziveri_universal_2012-1,\n\ttitle = {A universal carbonate ion effect on stable oxygen isotope ratios in unicellular planktonic calcifying organisms},\n\tvolume = {9},\n\tissn = {1726-4170},\n\turl = {https://www.biogeosciences.net/9/1025/2012/},\n\tdoi = {https://doi.org/10.5194/bg-9-1025-2012},\n\tlanguage = {English},\n\tnumber = {3},\n\turldate = {2019-11-11},\n\tjournal = {Biogeosciences},\n\tauthor = {Ziveri, P. and Thoms, S. and Probert, I. and Geisen, M. and Langer, G.},\n\tmonth = mar,\n\tyear = {2012},\n\tpages = {1025--1032},\n}");
			mutate($data, "d18O_CO3", function($row) {return $row['d18O'] - (-0.0048*$row['CO3'] + 0.96);});
			$digits['d18O_CO3'] = 2;
			$needed['d18O_CO3'] = TRUE;
			break;
		case "ziveri_dino":
			$description .= "the carbonate-ion effect of the calcareous dinoflagellate <i>Toracosphaera heimii</i> (Ziveri et al. 2012): "
						 . "<div class='math'><math><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>corr</mn></msub><mo> = </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><mtext>O</mtext><mo> - </mo><mo>(</mo><mn>4.8</mn><mo> - </mo><mn>0.024</mn><mo>[</mo><msubsup><mtext>CO</mtext><mn>3</mn><mtext>2-</mtext></msubsup><mo>]</mo><mo>)</mo></math></div>"
						 . "where &delta;<sup>18</sup>O<sub><i>corr</i></sub> is the corrected oxygen isotope value of the carbonate (&#8240; VPBD), &delta;<sup>18</sup>O is the raw oxygen isotope value of the carbonate (&#8240; VPBD), and [CO<sub>3</sub><sup>2-</sup>] is the carbonate-ion composition of the seawater (&mu;mol kg<sup>-1</sup>). ";
			array_push($citations, "<li class='citation'>Ziveri, P., Thoms, S., Probert, I., Geisen, M., and Langer, G., 2012, A universal carbonate ion effect on stable oxygen isotope ratios in unicellular planktonic calcifying organisms: Biogeosciences, v. 9, p. 1025–1032, doi:10.5194/bg-9-1025-2012.</li>");
			array_push($cite_bibtex, "@article{ziveri_universal_2012-1,\n\ttitle = {A universal carbonate ion effect on stable oxygen isotope ratios in unicellular planktonic calcifying organisms},\n\tvolume = {9},\n\tissn = {1726-4170},\n\turl = {https://www.biogeosciences.net/9/1025/2012/},\n\tdoi = {https://doi.org/10.5194/bg-9-1025-2012},\n\tlanguage = {English},\n\tnumber = {3},\n\turldate = {2019-11-11},\n\tjournal = {Biogeosciences},\n\tauthor = {Ziveri, P. and Thoms, S. and Probert, I. and Geisen, M. and Langer, G.},\n\tmonth = mar,\n\tyear = {2012},\n\tpages = {1025--1032},\n}");
			mutate($data, "d18O_CO3", function($row) {return $row['d18O'] - (-0.024*$row['CO3'] + 4.8);});
			$digits['d18O_CO3'] = 2;
			$needed['d18O_CO3'] = TRUE;
			break;
		case "mean":
			$description .= "the mean carbonate-ion effect of four species of planktonic foraminifera (<i>Orbulina universa</i>, <i>Globigerina bulloides</i>, <i>Trilobatus sacculifer</i>, and <i>Globigerinoides ruber</i>; Spero et al. 1999):"
						 . "<div class='math'><math><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>corr</mn></msub><mo> = </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><mtext>O</mtext><mo> - </mo><mo>(</mo><mn>0.505</mn><mo> - </mo><mn>0.002525</mn><mo>[</mo><msubsup><mtext>CO</mtext><mn>3</mn><mtext>2-</mtext></msubsup><mo>]</mo><mo>)</mo></math></div>"
						 . "where &delta;<sup>18</sup>O<sub><i>corr</i></sub> is the corrected oxygen isotope value of the carbonate (&#8240; VPBD), &delta;<sup>18</sup>O is the raw oxygen isotope value of the carbonate (&#8240; VPBD), and [CO<sub>3</sub><sup>2-</sup>] is the carbonate-ion composition of the seawater (&mu;mol kg<sup>-1</sup>). ";
			array_push($citations, "<li class='citation'>Spero, H.J., Bijma, J., Lea, D.W., and Russell, A.D., 1999, Deconvolving Glacial Ocean Carbonate Chemistry from the Planktonic Foraminifera Carbon Isotope Record, in Reconstructing Ocean History, Springer, Boston, MA, p. 329–342, doi:10.1007/978-1-4615-4197-4_19.</li>");
			array_push($cite_bibtex, "@incollection{spero_deconvolving_1999,\n\ttitle = {Deconvolving {Glacial} {Ocean} {Carbonate} {Chemistry} from the {Planktonic} {Foraminifera} {Carbon} {Isotope} {Record}},\n\tisbn = {978-1-4613-6883-0 978-1-4615-4197-4},\n\turl = {https://link.springer.com/chapter/10.1007/978-1-4615-4197-4_19},\n\tlanguage = {en},\n\turldate = {2018-03-29},\n\tbooktitle = {Reconstructing {Ocean} {History}},\n\tpublisher = {Springer, Boston, MA},\n\tauthor = {Spero, Howard J. and Bijma, Jelle and Lea, David W. and Russell, Ann D.},\n\tyear = {1999},\n\tdoi = {10.1007/978-1-4615-4197-4_19},\n\tpages = {329--342},\n}");
			mutate($data, "d18O_CO3", function($row) {return $row['d18O'] - (-0.002525*$row['CO3'] + 0.505);});
			$digits['d18O_CO3'] = 2;
			$needed['d18O_CO3'] = TRUE;
			break;
		case "spero_orb":
			$description .= "the carbonate-ion effect of <i>Orbulina universa</i> (Spero et al. 1997): "
						 . "<div class='math'><math><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>corr</mn></msub><mo> = </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><mtext>O</mtext><mo> - </mo><mo>(</mo><mn>0.4</mn><mo> - </mo><mn>0.0020</mn><mo>[</mo><msubsup><mtext>CO</mtext><mn>3</mn><mtext>2-</mtext></msubsup><mo>]</mo><mo>)</mo></math></div>"
						 . "where &delta;<sup>18</sup>O<sub><i>corr</i></sub> is the corrected oxygen isotope value of the carbonate (&#8240; VPBD), &delta;<sup>18</sup>O is the raw oxygen isotope value of the carbonate (&#8240; VPBD), and [CO<sub>3</sub><sup>2-</sup>] is the carbonate-ion composition of the seawater (&mu;mol kg<sup>-1</sup>). ";
			array_push($citations, "<li class='citation'>Spero, H.J., Bijma, J., Lea, D.W., and Bemis, B.E., 1997, Effect of seawater carbonate concentration on foraminiferal carbon and oxygen isotopes: Nature, v. 390, p. 497–500, doi:10.1038/37333.</li>");
			array_push($cite_bibtex, "@article{spero_effect_1997,\n\ttitle = {Effect of seawater carbonate concentration on foraminiferal carbon and oxygen isotopes},\n\tvolume = {390},\n\tissn = {0028-0836},\n\turl = {http://www.nature.com/nature/journal/v390/n6659/abs/390497a0.html},\n\tdoi = {10.1038/37333},\n\tlanguage = {en},\n\tnumber = {6659},\n\turldate = {2017-03-14},\n\tjournal = {Nature},\n\tauthor = {Spero, Howard J. and Bijma, Jelle and Lea, David W. and Bemis, Bryan E.},\n\tmonth = dec,\n\tyear = {1997},\n\tpages = {497--500},\n}");
			mutate($data, "d18O_CO3", function($row) {return $row['d18O'] - (-0.0020*$row['CO3'] + 0.4);});
			$digits['d18O_CO3'] = 2;
			$needed['d18O_CO3'] = TRUE;
			break;
		case "spero_bul":
			$description .= "the carbonate-ion effect of <i>Globigerina bulloides</i> (Spero et al. 1997): "
						 . "<div class='math'><math><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>corr</mn></msub><mo> = </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><mtext>O</mtext><mo> - </mo><mo>(</mo><mn>0.9</mn><mo> - </mo><mn>0.0045</mn><mo>[</mo><msubsup><mtext>CO</mtext><mn>3</mn><mtext>2-</mtext></msubsup><mo>]</mo><mo>)</mo></math></div>"
						 . "where &delta;<sup>18</sup>O<sub><i>corr</i></sub> is the corrected oxygen isotope value of the carbonate (&#8240; VPBD), &delta;<sup>18</sup>O is the raw oxygen isotope value of the carbonate (&#8240; VPBD), and [CO<sub>3</sub><sup>2-</sup>] is the carbonate-ion composition of the seawater (&mu;mol kg<sup>-1</sup>). ";
			array_push($citations, "<li class='citation'>Spero, H.J., Bijma, J., Lea, D.W., and Bemis, B.E., 1997, Effect of seawater carbonate concentration on foraminiferal carbon and oxygen isotopes: Nature, v. 390, p. 497–500, doi:10.1038/37333.</li>");
			array_push($cite_bibtex, "@article{spero_effect_1997,\n\ttitle = {Effect of seawater carbonate concentration on foraminiferal carbon and oxygen isotopes},\n\tvolume = {390},\n\tissn = {0028-0836},\n\turl = {http://www.nature.com/nature/journal/v390/n6659/abs/390497a0.html},\n\tdoi = {10.1038/37333},\n\tlanguage = {en},\n\tnumber = {6659},\n\turldate = {2017-03-14},\n\tjournal = {Nature},\n\tauthor = {Spero, Howard J. and Bijma, Jelle and Lea, David W. and Bemis, Bryan E.},\n\tmonth = dec,\n\tyear = {1997},\n\tpages = {497--500},\n}");
			mutate($data, "d18O_CO3", function($row) {return $row['d18O'] - (-0.0045*$row['CO3'] + 0.9);});
			$digits['d18O_CO3'] = 2;
			$needed['d18O_CO3'] = TRUE;
			break;
		case "spero_sac":
			$description .= "the carbonate-ion effect of <i>Trilobatus sacculifer</i> (Bijma et al. 1999): "
						 . "<div class='math'><math><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>corr</mn></msub><mo> = </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><mtext>O</mtext><mo> - </mo><mo>(</mo><mn>0.28</mn><mo> - </mo><mn>0.0014</mn><mo>[</mo><msubsup><mtext>CO</mtext><mn>3</mn><mtext>2-</mtext></msubsup><mo>]</mo><mo>)</mo></math></div>"
						 . "where &delta;<sup>18</sup>O<sub><i>corr</i></sub> is the corrected oxygen isotope value of the carbonate (&#8240; VPBD), &delta;<sup>18</sup>O is the raw oxygen isotope value of the carbonate (&#8240; VPBD), and [CO<sub>3</sub><sup>2-</sup>] is the carbonate-ion composition of the seawater (&mu;mol kg<sup>-1</sup>). ";
			array_push($citations, "<li class='citation'>Bijma, J., Spero, H.J., and Lea, D.W., 1999, Reassessing Foraminiferal Stable Isotope Geochemistry: Impact of the Oceanic Carbonate System (Experimental Results), in Fischer, D.G. and Wefer, P.D.G. eds., Use of Proxies in Paleoceanography, Springer Berlin Heidelberg, p. 489–512, doi:10.1007/978-3-642-58646-0_20.</li>");
			array_push($cite_bibtex, "@incollection{bijma_reassessing_1999-1,\n\ttitle = {Reassessing {Foraminiferal} {Stable} {Isotope} {Geochemistry}: {Impact} of the {Oceanic} {Carbonate} {System} ({Experimental} {Results})},isbn = {978-3-642-63681-3 978-3-642-58646-0},\n\tshorttitle = {Reassessing {Foraminiferal} {Stable} {Isotope} {Geochemistry}},\n\turl = {http://link.springer.com/chapter/10.1007/978-3-642-58646-0_20},\n\tlanguage = {en},\n\turldate = {2017-02-24},\n\tbooktitle = {Use of {Proxies} in {Paleoceanography}},\n\tpublisher = {Springer Berlin Heidelberg},\n\tauthor = {Bijma, J. and Spero, H. J. and Lea, D. W.},\n\teditor = {Fischer, Dr Gerhard and Wefer, Prof Dr Gerold},\n\tyear = {1999},\n\tdoi = {10.1007/978-3-642-58646-0_20},\n\tpages = {489--512},\n}");
			mutate($data, "d18O_CO3", function($row) {return $row['d18O'] - (-0.0014*$row['CO3'] + 0.28);});
			$digits['d18O_CO3'] = 2;
			$needed['d18O_CO3'] = TRUE;
			break;
		case "spero_rub":
			$description .= "the carbonate-ion effect of <i>Globigerinoides ruber</i> (Bijma et al. 1999): "
						 . "<div class='math'><math><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>corr</mn></msub><mo> = </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><mtext>O</mtext><mo> - </mo><mo>(</mo><mn>0.44</mn><mo> - </mo><mn>0.0022</mn><mo>[</mo><msubsup><mtext>CO</mtext><mn>3</mn><mtext>2-</mtext></msubsup><mo>]</mo><mo>)</mo></math></div>"
						 . "where &delta;<sup>18</sup>O<sub><i>corr</i></sub> is the corrected oxygen isotope value of the carbonate (&#8240; VPBD), &delta;<sup>18</sup>O is the raw oxygen isotope value of the carbonate (&#8240; VPBD), and [CO<sub>3</sub><sup>2-</sup>] is the carbonate-ion composition of the seawater (&mu;mol kg<sup>-1</sup>). ";
			array_push($citations, "<li class='citation'>Bijma, J., Spero, H.J., and Lea, D.W., 1999, Reassessing Foraminiferal Stable Isotope Geochemistry: Impact of the Oceanic Carbonate System (Experimental Results), in Fischer, D.G. and Wefer, P.D.G. eds., Use of Proxies in Paleoceanography, Springer Berlin Heidelberg, p. 489–512, doi:10.1007/978-3-642-58646-0_20.</li>");
			array_push($cite_bibtex, "@incollection{bijma_reassessing_1999-1,\n\ttitle = {Reassessing {Foraminiferal} {Stable} {Isotope} {Geochemistry}: {Impact} of the {Oceanic} {Carbonate} {System} ({Experimental} {Results})},isbn = {978-3-642-63681-3 978-3-642-58646-0},\n\tshorttitle = {Reassessing {Foraminiferal} {Stable} {Isotope} {Geochemistry}},\n\turl = {http://link.springer.com/chapter/10.1007/978-3-642-58646-0_20},\n\tlanguage = {en},\n\turldate = {2017-02-24},\n\tbooktitle = {Use of {Proxies} in {Paleoceanography}},\n\tpublisher = {Springer Berlin Heidelberg},\n\tauthor = {Bijma, J. and Spero, H. J. and Lea, D. W.},\n\teditor = {Fischer, Dr Gerhard and Wefer, Prof Dr Gerold},\n\tyear = {1999},\n\tdoi = {10.1007/978-3-642-58646-0_20},\n\tpages = {489--512},\n}");
			mutate($data, "d18O_CO3", function($row) {return $row['d18O'] - (-0.0022*$row['CO3'] + 0.44);});
			$digits['d18O_CO3'] = 2;
			$needed['d18O_CO3'] = TRUE;
			break;
	}
	switch ($latlong) {
		case "none":
			mutate($data, "pallat",  function($row) {return $row['lat'];});
			mutate($data, "pallong", function($row) {return $row['long'];});
			break;
		case "gplates":
			$description .= "Paleorotations were performed using GPlates (Müller et al. 2016, 2018). ";
			array_push($citations, "<li class='citation'>Müller, R.D. et al., 2016, Ocean Basin Evolution and Global-Scale Plate Reorganization Events Since Pangea Breakup: Annual Review of Earth and Planetary Sciences, v. 44, p. 107–138, doi:10.1146/annurev-earth-060115-012211.</li>");
			array_push($citations, "<li class='citation'>Müller, R.D., Cannon, J., Qin, X., Watson, R.J., Gurnis, M., Williams, S., Pfaffelmoser, T., Seton, M., Russell, S.H.J., and Zahirovic, S., 2018, GPlates: Building a Virtual Earth Through Deep Time: Geochemistry, Geophysics, Geosystems, v. 19, p. 2243–2261, doi:https://doi.org/10.1029/2018GC007584.</li>");
			array_push($cite_bibtex, "@article{muller_ocean_2016,\n\ttitle = {Ocean {Basin} {Evolution} and {Global}-{Scale} {Plate} {Reorganization} {Events} {Since} {Pangea} {Breakup}},\n\tvolume = {44},\n\turl = {https://doi.org/10.1146/annurev-earth-060115-012211},\n\tdoi = {10.1146/annurev-earth-060115-012211},\n\tnumber = {1},\n\turldate = {2021-06-25},\n\tjournal = {Annual Review of Earth and Planetary Sciences},\n\tauthor = {Müller, R. Dietmar and Seton, Maria and Zahirovic, Sabin and Williams, Simon E. and Matthews, Kara J. and Wright, Nicky M. and Shephard, Grace E. and Maloney, Kayla T. and Barnett-Moore, Nicholas and Hosseinpour, Maral and Bower, Dan J. and Cannon, John},\n\tyear = {2016},\n\tpages = {107--138},\n}");
			array_push($cite_bibtex, "@article{muller_gplates_2018,\n\ttitle = {{GPlates}: {Building} a {Virtual} {Earth} {Through} {Deep} {Time}},\n\tvolume = {19},\n\tissn = {1525-2027},\n\tshorttitle = {{GPlates}},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2018GC007584},\n\tdoi = {https://doi.org/10.1029/2018GC007584},\n\tlanguage = {en},\n\tnumber = {7},\n\turldate = {2021-01-18},\n\tjournal = {Geochemistry, Geophysics, Geosystems},\n\tauthor = {Müller, R. Dietmar and Cannon, John and Qin, Xiaodong and Watson, Robin J. and Gurnis, Michael and Williams, Simon and Pfaffelmoser, Tobias and Seton, Maria and Russell, Samuel H. J. and Zahirovic, Sabin},\n\tyear = {2018},\n\tpages = {2243--2261},\n}");
			if ($valid_age_end > 230) $valid_age_end = 230;
			
			// group by age for fewer calls
			$groups = array();
			foreach ($data as $rowkey => $row) {
				$groups[strval(round($row['age'], 1))][$rowkey] = $row;
			}

			// break into chunks (for GET limits) and make calls
			foreach ($groups as $agekey => $group) {
				$group_chunked = array_chunk($group, 25, TRUE);
				foreach ($group_chunked as $chunk) {
					$coords = array();
					foreach ($chunk as $row) {
						$coords[] = round(floatval($row['long']), 3);
						$coords[] = round(floatval($row['lat']),  3);
					}
					set_error_handler("warning_handler", E_WARNING);
					$arr_context_options = array("http" => array("method" => "GET",), "ssl"=>array("allow_self_signed"=>true, "verify_peer"=>false, "verify_peer_name"=>false,),);
					$gplates = file_get_contents('https://gws.gplates.org/reconstruct/reconstruct_points/?points=' . implode(',', $coords) . '&time=' . round(floatval($agekey), 3) . "&model=MULLER2016", false,  stream_context_create($arr_context_options));
					restore_error_handler();
					$json = json_decode($gplates);
					$i = 0;
					foreach ($chunk as $rowkey => $row) {
						if ($json) {
							$data[$rowkey]['pallong'] = $json->coordinates[$i][0];
							$data[$rowkey]['pallat']  = $json->coordinates[$i][1];
						} else {
							$data[$rowkey]['pallong'] = NAN;
							$data[$rowkey]['pallat']  = NAN;
							$errors = TRUE;
						}
						$i++;
					}
				}
			}
			$needed['age'] = TRUE;
			$needed['lat'] = TRUE;
			$needed['long'] = TRUE;
			$needed['pallat'] = TRUE;
			$needed['pallong'] = TRUE;
			break;
	}
	switch ($ice) {
		case "none":
			$description .= "Seawater &delta;<sup>18</sup>O was defined as 0&#8240; (VSMOW). ";
			mutate($data, "d18Osw_global", function($row) {return 0;});
			break;
		case "fixed":
			if (is_nan($d18Osw)) {
				throw_error("Specified fixed seawater &delta;<sup>18</sup>O is not a valid number.");
			}
			$d18Oswvalue = floatval($d18Osw);
			$description .= "Seawater &delta;<sup>18</sup>O was defined as " . strval($d18Oswvalue). "&#8240; (VSMOW). ";
			mutate($data, "d18Osw_global", function($row) use ($d18Oswvalue) {return $d18Oswvalue;});
			break;
		case "icefree":
			$description .= "Seawater &delta;<sup>18</sup>O in an ice-free world was assumed to be -1&#8240; (VSMOW). ";
			mutate($data, "d18Osw_global", function($row) {return -1;});
			break;
		case "henkes":
			$description .= "Seawater &delta;<sup>18</sup>O was assumed to be -0.8&#8240; (VSMOW) after the Phanerozoic mean estimated by Henkes et al. (2018). ";
			array_push($citations, "<li class='citation'>Henkes, G.A., Passey, B.H., Grossman, E.L., Shenton, B.J., Yancey, T.E., and Pérez-Huerta, A., 2018, Temperature evolution and the oxygen isotope composition of Phanerozoic oceans from carbonate clumped isotope thermometry: Earth and Planetary Science Letters, v. 490, p. 40–50, doi:10.1016/j.epsl.2018.02.001.</li>");
			array_push($cite_bibtex, "<li class='citation'>@article{henkes_temperature_2018,\n\ttitle = {Temperature evolution and the oxygen isotope composition of {Phanerozoic} oceans from carbonate clumped isotope thermometry},\n\tvolume = {490},\n\tissn = {0012-821X},\n\turl = {https://www.sciencedirect.com/science/article/pii/S0012821X18300578},\n\tdoi = {10.1016/j.epsl.2018.02.001},\n\tlanguage = {en},\n\turldate = {2022-06-10},\n\tjournal = {Earth and Planetary Science Letters},\n\tauthor = {Henkes, Gregory A. and Passey, Benjamin H. and Grossman, Ethan L. and Shenton, Brock J. and Yancey, Thomas E. and Pérez-Huerta, Alberto},\n\tmonth = may,\n\tyear = {2018},\n\tpages = {40--50},\n}");
			mutate($data, "d18Osw_global", function($row) {return -0.8;});
			if ($valid_age_end > 541) $valid_age_end = 541;
			break;
		case "miller":
			$description .= "Seawater &delta;<sup>18</sup>O was estimated using the reconstruction of Miller et al. (2020), making our temperatures partially dependent on the Mg/Ca record of bottom-water temperature. ";
			array_push($citations, "<li class='citation'>Miller, K.G., Browning, J.V., Schmelz, W.J., Kopp, R.E., Mountain, G.S., and Wright, J.D., 2020, Cenozoic sea-level and cryospheric evolution from deep-sea geochemical and continental margin records: Science Advances, v. 6, p. eaaz1346, doi:10.1126/sciadv.aaz1346.</li>");
			array_push($cite_bibtex, "<li class='citation'>@article{miller_cenozoic_2020,\n\ttitle = {Cenozoic sea-level and cryospheric evolution from deep-sea geochemical and continental margin records},\n\tvolume = {6},\n\tissn = {2375-2548},\n\turl = {https://advances.sciencemag.org/content/6/20/eaaz1346},\n\tdoi = {10.1126/sciadv.aaz1346},\n\tlanguage = {en},\n\tnumber = {20},\n\turldate = {2020-10-02},\n\tjournal = {Science Advances},\n\tauthor = {Miller, Kenneth G. and Browning, James V. and Schmelz, W. John and Kopp, Robert E. and Mountain, Gregory S. and Wright, James D.},\n\tmonth = may,\n\tyear = {2020},\n\tpages = {eaaz1346},\n}");
			$miller = array_map('str_getcsv', file("./data/miller_2020_sealevel.csv")); csv_headers($miller);
			mutate($data, "d18Osw_global", function($row) use ($miller, $timescale) {return interpolate($row['age'], $miller, 'age_' . $timescale, 'd18O_sw');});
			if ($valid_age_end > 66) $valid_age_end = 66;
			$digits['d18Osw_global'] = 2;
			$needed['d18Osw_global'] = TRUE;
			$needed['age'] = TRUE;
			$explain_timescale = TRUE;
			break;
		case "cramer1":
			$description .= "Seawater &delta;<sup>18</sup>O was estimated using the New Jersey Sea Level-based reconstruction of Cramer et al. (2011) Table S4. ";
			array_push($citations, "<li class='citation'>Cramer, B.S., Miller, K.G., Barrett, P.J., and Wright, J.D., 2011, Late Cretaceous–Neogene trends in deep ocean temperature and continental ice volume: Reconciling records of benthic foraminiferal geochemistry (&delta;<sup>18</sup>O and Mg/Ca) with sea level history: Journal of Geophysical Research: Oceans, v. 116, doi:10.1029/2011JC007255.</li>");
			array_push($cite_bibtex, "<li class='citation'>@article{cramer_late_2011,\n\ttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume: {Reconciling} records of benthic foraminiferal geochemistry (δ{18O} and {Mg}/{Ca}) with sea level history},\n\tvolume = {116},\n\tissn = {2156-2202},\n\tshorttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2011JC007255},\n\tdoi = {10.1029/2011JC007255},\n\tlanguage = {en},\n\tnumber = {C12},\n\turldate = {2020-10-05},\n\tjournal = {Journal of Geophysical Research: Oceans},\n\tauthor = {Cramer, B. S. and Miller, K. G. and Barrett, P. J. and Wright, J. D.},\n\tyear = {2011},\n}");
			$cramer1 = array_map('str_getcsv', file("./data/cramer_2011_sealevel1.csv")); csv_headers($cramer1);
			mutate($data, "d18Osw_global", function($row) use ($cramer1, $timescale) {return interpolate($row['age'], $cramer1, 'Age_' . $timescale, 'd18Osw');});
			if ($valid_age_end < 9.2) $valid_age_end = 9.2;
			if ($valid_age_end > 108) $valid_age_end = 108;
			$digits['d18Osw_global'] = 2;
			$needed['d18Osw_global'] = TRUE;
			$needed['age'] = TRUE;
			$explain_timescale = TRUE;
			break;
		case "cramer2":
			$description .= "Seawater &delta;<sup>18</sup>O was estimated using the Mg/Ca-based reconstruction of Cramer et al. (2011) Table S5, making our temperatures partially dependent on the Mg/Ca record of bottom-water temperature. ";
			array_push($citations, "<li class='citation'>Cramer, B.S., Miller, K.G., Barrett, P.J., and Wright, J.D., 2011, Late Cretaceous–Neogene trends in deep ocean temperature and continental ice volume: Reconciling records of benthic foraminiferal geochemistry (&delta;<sup>18</sup>O and Mg/Ca) with sea level history: Journal of Geophysical Research: Oceans, v. 116, doi:10.1029/2011JC007255.</li>");
			array_push($cite_bibtex, "<li class='citation'>@article{cramer_late_2011,\n\ttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume: {Reconciling} records of benthic foraminiferal geochemistry (δ{18O} and {Mg}/{Ca}) with sea level history},\n\tvolume = {116},\n\tissn = {2156-2202},\n\tshorttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2011JC007255},\n\tdoi = {10.1029/2011JC007255},\n\tlanguage = {en},\n\tnumber = {C12},\n\turldate = {2020-10-05},\n\tjournal = {Journal of Geophysical Research: Oceans},\n\tauthor = {Cramer, B. S. and Miller, K. G. and Barrett, P. J. and Wright, J. D.},\n\tyear = {2011},\n}");
			$cramer2 = array_map('str_getcsv', file("./data/cramer_2011_sealevel2.csv")); csv_headers($cramer2);
			mutate($data, "d18Osw_global", function($row) use ($cramer2, $timescale) {return interpolate($row['age'], $cramer2, 'Age_' . $timescale, 'd18Osw');});
			if ($valid_age_end > 62.88) $valid_age_end = 62.88;
			$digits['d18Osw_global'] = 3;
			$needed['d18Osw_global'] = TRUE;
			$needed['age'] = TRUE;
			$explain_timescale = TRUE;
			break;
		case "cramer3":
			$description .= "Seawater &delta;<sup>18</sup>O was estimated using the Mg/Ca-based reconstruction of Cramer et al. (2011) Table S6, making our temperatures partially dependent on the Mg/Ca record of bottom-water temperature. ";
			array_push($citations, "<li class='citation'>Cramer, B.S., Miller, K.G., Barrett, P.J., and Wright, J.D., 2011, Late Cretaceous–Neogene trends in deep ocean temperature and continental ice volume: Reconciling records of benthic foraminiferal geochemistry (&delta;<sup>18</sup>O and Mg/Ca) with sea level history: Journal of Geophysical Research: Oceans, v. 116, doi:10.1029/2011JC007255.</li>");
			array_push($cite_bibtex, "<li class='citation'>@article{cramer_late_2011,\n\ttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume: {Reconciling} records of benthic foraminiferal geochemistry (δ{18O} and {Mg}/{Ca}) with sea level history},\n\tvolume = {116},\n\tissn = {2156-2202},\n\tshorttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2011JC007255},\n\tdoi = {10.1029/2011JC007255},\n\tlanguage = {en},\n\tnumber = {C12},\n\turldate = {2020-10-05},\n\tjournal = {Journal of Geophysical Research: Oceans},\n\tauthor = {Cramer, B. S. and Miller, K. G. and Barrett, P. J. and Wright, J. D.},\n\tyear = {2011},\n}");
			$cramer3 = array_map('str_getcsv', file("./data/cramer_2011_sealevel3.csv")); csv_headers($cramer3);
			mutate($data, "d18Osw_global", function($row) use ($cramer3, $timescale) {return interpolate($row['age'], $cramer3, 'Age_' . $timescale, 'd18Osw');});
			if ($valid_age_end > 62.88) $valid_age_end = 62.88;
			$digits['d18Osw_global'] = 3;
			$needed['d18Osw_global'] = TRUE;
			$needed['age'] = TRUE;
			$explain_timescale = TRUE;
			break;
		case "cramer1s":
			$description .= "Seawater &delta;<sup>18</sup>O was estimated using the smoothed New Jersey Sea Level-based reconstruction of Cramer et al. (2011) Table S4. ";
			array_push($citations, "<li class='citation'>Cramer, B.S., Miller, K.G., Barrett, P.J., and Wright, J.D., 2011, Late Cretaceous–Neogene trends in deep ocean temperature and continental ice volume: Reconciling records of benthic foraminiferal geochemistry (&delta;<sup>18</sup>O and Mg/Ca) with sea level history: Journal of Geophysical Research: Oceans, v. 116, doi:10.1029/2011JC007255.</li>");
			array_push($cite_bibtex, "<li class='citation'>@article{cramer_late_2011,\n\ttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume: {Reconciling} records of benthic foraminiferal geochemistry (δ{18O} and {Mg}/{Ca}) with sea level history},\n\tvolume = {116},\n\tissn = {2156-2202},\n\tshorttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2011JC007255},\n\tdoi = {10.1029/2011JC007255},\n\tlanguage = {en},\n\tnumber = {C12},\n\turldate = {2020-10-05},\n\tjournal = {Journal of Geophysical Research: Oceans},\n\tauthor = {Cramer, B. S. and Miller, K. G. and Barrett, P. J. and Wright, J. D.},\n\tyear = {2011},\n}");
			$cramer1 = array_map('str_getcsv', file("./data/cramer_2011_sealevel1.csv")); csv_headers($cramer1);
			mutate($data, "d18Osw_global", function($row) use ($cramer1, $timescale) {return interpolate($row['age'], $cramer1, 'Age_' . $timescale, 'd18Osw (long)');});
			if ($valid_age_end < 9.2) $valid_age_end = 9.2;
			if ($valid_age_end > 108) $valid_age_end = 108;
			$digits['d18Osw_global'] = 2;
			$needed['d18Osw_global'] = TRUE;
			$needed['age'] = TRUE;
			$explain_timescale = TRUE;
			break;
		case "cramer2s":
			$description .= "Seawater &delta;<sup>18</sup>O was estimated using the smoothed Mg/Ca-based reconstruction of Cramer et al. (2011) Table S5, making our temperatures partially dependent on the Mg/Ca record of bottom-water temperature. ";
			array_push($citations, "<li class='citation'>Cramer, B.S., Miller, K.G., Barrett, P.J., and Wright, J.D., 2011, Late Cretaceous–Neogene trends in deep ocean temperature and continental ice volume: Reconciling records of benthic foraminiferal geochemistry (&delta;<sup>18</sup>O and Mg/Ca) with sea level history: Journal of Geophysical Research: Oceans, v. 116, doi:10.1029/2011JC007255.</li>");
			array_push($cite_bibtex, "<li class='citation'>@article{cramer_late_2011,\n\ttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume: {Reconciling} records of benthic foraminiferal geochemistry (δ{18O} and {Mg}/{Ca}) with sea level history},\n\tvolume = {116},\n\tissn = {2156-2202},\n\tshorttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2011JC007255},\n\tdoi = {10.1029/2011JC007255},\n\tlanguage = {en},\n\tnumber = {C12},\n\turldate = {2020-10-05},\n\tjournal = {Journal of Geophysical Research: Oceans},\n\tauthor = {Cramer, B. S. and Miller, K. G. and Barrett, P. J. and Wright, J. D.},\n\tyear = {2011},\n}");
			$cramer2 = array_map('str_getcsv', file("./data/cramer_2011_sealevel2.csv")); csv_headers($cramer2);
			mutate($data, "d18Osw_global", function($row) use ($cramer2, $timescale) {return interpolate($row['age'], $cramer2, 'Age_' . $timescale, 'd18Osw (long)');});
			if ($valid_age_end > 62.88) $valid_age_end = 62.88;
			$digits['d18Osw_global'] = 3;
			$needed['d18Osw_global'] = TRUE;
			$needed['age'] = TRUE;
			$explain_timescale = TRUE;
			break;
		case "cramer3s":
			$description .= "Seawater &delta;<sup>18</sup>O was estimated using the smoothed Mg/Ca-based reconstruction of Cramer et al. (2011) Table S6, making our temperatures partially dependent on the Mg/Ca record of bottom-water temperature. ";
			array_push($citations, "<li class='citation'>Cramer, B.S., Miller, K.G., Barrett, P.J., and Wright, J.D., 2011, Late Cretaceous–Neogene trends in deep ocean temperature and continental ice volume: Reconciling records of benthic foraminiferal geochemistry (&delta;<sup>18</sup>O and Mg/Ca) with sea level history: Journal of Geophysical Research: Oceans, v. 116, doi:10.1029/2011JC007255.</li>");
			array_push($cite_bibtex, "<li class='citation'>@article{cramer_late_2011,\n\ttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume: {Reconciling} records of benthic foraminiferal geochemistry (δ{18O} and {Mg}/{Ca}) with sea level history},\n\tvolume = {116},\n\tissn = {2156-2202},\n\tshorttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2011JC007255},\n\tdoi = {10.1029/2011JC007255},\n\tlanguage = {en},\n\tnumber = {C12},\n\turldate = {2020-10-05},\n\tjournal = {Journal of Geophysical Research: Oceans},\n\tauthor = {Cramer, B. S. and Miller, K. G. and Barrett, P. J. and Wright, J. D.},\n\tyear = {2011},\n}");
			$cramer3 = array_map('str_getcsv', file("./data/cramer_2011_sealevel3.csv")); csv_headers($cramer3);
			mutate($data, "d18Osw_global", function($row) use ($cramer3, $timescale) {return interpolate($row['age'], $cramer3, 'Age_' . $timescale, 'd18Osw (long)');});
			if ($valid_age_end > 62.88) $valid_age_end = 62.88;
			$digits['d18Osw_global'] = 3;
			$needed['d18Osw_global'] = TRUE;
			$needed['age'] = TRUE;
			$explain_timescale = TRUE;
			break;
		case "mecklerraw":
			$description .= "Seawater &delta;<sup>18</sup>O was estimated using the non-pH-corrected &Delta;<sub>47</sub>-based reconstruction of Meckler et al. (2022), interpolated to 0.1 Ma resolution using the same LOESS-smoothing method and parameters used by Meckler et al. (2022) to interpolate their temperature record. This makes our temperatures partially dependent on the &Delta;<sub>47</sub> record of bottom-water temperature. ";
			array_push($citations, "<li class='citation'>Meckler, A.N. et al., 2022, Cenozoic evolution of deep ocean temperature from clumped isotope thermometry: Science, v. 377, p. 86–90, doi:10.1126/science.abk0604.</li>");
			array_push($cite_bibtex, "@article{meckler_cenozoic_2022,\n\ttitle = {Cenozoic evolution of deep ocean temperature from clumped isotope thermometry},\n\tvolume = {377},\n\tdoi = {10.1126/science.abk0604},\n\tnumber = {6601},\n\tjournal = {Science},\n\tauthor = {Meckler, A. N. and Sexton, P. F. and Piasecki, A. M. and Leutert, T. J. and Marquardt, J. and Ziegler, M. and Agterhuis, T. and Lourens, L. J. and Rae, J. W. B. and Barnet, J. and Tripati, A. and Bernasconi, S. M.},\n\tmonth = jul,\n\tyear = {2022},\n\tpages = {86--90},\n}");
			$meckler = array_map('str_getcsv', file("./data/meckler_2022_clumped.csv")); csv_headers($meckler);
			mutate($data, "d18Osw_global", function($row) use ($meckler, $timescale) {return interpolate($row['age'], $meckler, 'age_' . $timescale, 'd18Osw_raw');});
			if ($valid_age_end > 62.06) $valid_age_end = 62.06;
			$digits['d18Osw_global'] = 3;
			$needed['d18Osw_global'] = TRUE;
			$needed['age'] = TRUE;
			$explain_timescale = TRUE;
			break;
		case "mecklerph":
			$description .= "Seawater &delta;<sup>18</sup>O was estimated using the pH-corrected &Delta;<sub>47</sub>-based reconstruction of Meckler et al. (2022), interpolated to 0.1 Ma resolution using the same LOESS-smoothing method and parameters used by Meckler et al. (2022) to interpolate their temperature record. This makes our temperatures partially dependent on the &Delta;<sub>47</sub> record of bottom-water temperature. ";
			array_push($citations, "<li class='citation'>Meckler, A.N. et al., 2022, Cenozoic evolution of deep ocean temperature from clumped isotope thermometry: Science, v. 377, p. 86–90, doi:10.1126/science.abk0604.</li>");
			array_push($cite_bibtex, "@article{meckler_cenozoic_2022,\n\ttitle = {Cenozoic evolution of deep ocean temperature from clumped isotope thermometry},\n\tvolume = {377},\n\tdoi = {10.1126/science.abk0604},\n\tnumber = {6601},\n\tjournal = {Science},\n\tauthor = {Meckler, A. N. and Sexton, P. F. and Piasecki, A. M. and Leutert, T. J. and Marquardt, J. and Ziegler, M. and Agterhuis, T. and Lourens, L. J. and Rae, J. W. B. and Barnet, J. and Tripati, A. and Bernasconi, S. M.},\n\tmonth = jul,\n\tyear = {2022},\n\tpages = {86--90},\n}");
			$meckler = array_map('str_getcsv', file("./data/meckler_2022_clumped.csv")); csv_headers($meckler);
			mutate($data, "d18Osw_global", function($row) use ($meckler, $timescale) {return interpolate($row['age'], $meckler, 'age_' . $timescale, 'd18Osw_pH');});
			if ($valid_age_end > 62.06) $valid_age_end = 62.06;
			$digits['d18Osw_global'] = 3;
			$needed['d18Osw_global'] = TRUE;
			$needed['age'] = TRUE;
			$explain_timescale = TRUE;
			break;
		case "modestou":
			$description .= "Seawater &delta;<sup>18</sup>O was estimated using the &Delta;<sub>47</sub>-based reconstruction of Modestou et al. (2020), making our temperatures partially dependent on the &Delta;<sub>47</sub> record of bottom-water temperature. ";
			array_push($citations, "<li class='citation'>Modestou, S.E., Leutert, T.J., Fernandez, A., Lear, C.H., and Meckler, A.N., 2020, Warm Middle Miocene Indian Ocean Bottom Water Temperatures: Comparison of Clumped Isotope and Mg/Ca-Based Estimates: Paleoceanography and Paleoclimatology, v. 35, p. e2020PA003927, doi:10.1029/2020PA003927.</li>");
			array_push($cite_bibtex, "@article{modestou_warm_2020,\n\ttitle = {Warm {Middle} {Miocene} {Indian} {Ocean} {Bottom} {Water} {Temperatures}: {Comparison} of {Clumped} {Isotope} and {Mg}/{Ca}-{Based} {Estimates}},\n\tvolume = {35},\n\tissn = {2572-4525},\n\tshorttitle = {Warm {Middle} {Miocene} {Indian} {Ocean} {Bottom} {Water} {Temperatures}},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2020PA003927},\n\tdoi = {10.1029/2020PA003927},\n\tlanguage = {en},\n\tnumber = {11},\n\turldate = {2021-06-30},\n\tjournal = {Paleoceanography and Paleoclimatology},\n\tauthor = {Modestou, S. E. and Leutert, T. J. and Fernandez, A. and Lear, C. H. and Meckler, A. N.},\n\tyear = {2020},\n\tpages = {e2020PA003927},\n}");
			$modestou = array_map('str_getcsv', file("./data/modestou_2020_sealevel.csv")); csv_headers($modestou);
			mutate($data, "d18Osw_global", function($row) use ($modestou, $timescale) {return interpolate($row['age'], $modestou, 'age_' . $timescale, 'd18O_sw');});
			if ($valid_age_start < 11.84) $valid_age_start = 11.84;
			if ($valid_age_end > 16.42) $valid_age_end = 16.42;
			$digits['d18Osw_global'] = 2;
			$needed['d18Osw_global'] = TRUE;
			$needed['age'] = TRUE;
			$explain_timescale = TRUE;
			break;
		case "rohling1":
			$description .= "Seawater &delta;<sup>18</sup>O was estimated from the CENOGRID benthic &delta;<sup>18</sup>O stack (Westerhold et al. 2020) via the multi-proxy sea level model of Rohling et al. (2021). ";
			array_push($citations, "<li class='citation'>Rohling, E.J., Yu, J., Heslop, D., Foster, G.L., Opdyke, B., and Roberts, A.P., 2021, Sea level and deep-sea temperature reconstructions suggest quasi-stable states and critical transitions over the past 40 million years: Science Advances, v. 7, p. eabf5326, doi:10.1126/sciadv.abf5326.</li>");
			array_push($citations, "<li class='citation'>Westerhold, T. et al., 2020, An astronomically dated record of Earth’s climate and its predictability over the last 66 million years: Science, v. 369, p. 1383–1387, doi:10.1126/science.aba6853.</li>");
			array_push($cite_bibtex, "@article{rohling_sea_2021,\n\ttitle = {Sea level and deep-sea temperature reconstructions suggest quasi-stable states and critical transitions over the past 40 million years},\n\tvolume = {7},\n\tissn = {2375-2548},\n\turl = {https://advances.sciencemag.org/content/7/26/eabf5326},\n\tdoi = {10.1126/sciadv.abf5326},\n\tlanguage = {en},\n\tnumber = {26},\n\turldate = {2021-07-08},\n\tjournal = {Science Advances},\n\tauthor = {Rohling, Eelco J. and Yu, Jimin and Heslop, David and Foster, Gavin L. and Opdyke, Bradley and Roberts, Andrew P.},\n\tmonth = jun,\n\tyear = {2021},\n\tpmid = {34172440},\n\tpages = {eabf5326},\n}");
			array_push($cite_bibtex, "@article{westerhold_astronomically_2020,\n\ttitle = {An astronomically dated record of {Earth}’s climate and its predictability over the last 66 million years},\n\tvolume = {369},\n\tissn = {0036-8075, 1095-9203},\n\turl = {https://science.sciencemag.org/content/369/6509/1383},\n\tdoi = {10.1126/science.aba6853},\n\tlanguage = {en},\n\tnumber = {6509},\n\turldate = {2021-07-08},\n\tjournal = {Science},\n\tauthor = {Westerhold, Thomas and Marwan, Norbert and Drury, Anna Joy and Liebrand, Diederik and Agnini, Claudia and Anagnostou, Eleni and Barnet, James S. K. and Bohaty, Steven M. and Vleeschouwer, David De and Florindo, Fabio and Frederichs, Thomas and Hodell, David A. and Holbourn, Ann E. and Kroon, Dick and Lauretano, Vittoria and Littler, Kate and Lourens, Lucas J. and Lyle, Mitchell and Pälike, Heiko and Röhl, Ursula and Tian, Jun and Wilkens, Roy H. and Wilson, Paul A. and Zachos, James C.},\n\tmonth = sep,\n\tyear = {2020},\n\tpmid = {32913105},\n\tpages = {1383--1387},\n}");
			$rohling1 = array_map('str_getcsv', file("./data/rohling_2021_sealevel1.csv")); csv_headers($rohling1);
			mutate($data, "d18Osw_global", function($row) use ($rohling1, $timescale) {return interpolate($row['age'], $rohling1, 'age_' . $timescale, 'd18Osw');});
			if ($valid_age_end > 40.195) $valid_age_end = 40.195;
			$digits['d18Osw_global'] = 3;
			$needed['d18Osw_global'] = TRUE;
			$needed['age'] = TRUE;
			$explain_timescale = TRUE;
			break;
		case "rohling2":
			$description .= "Seawater &delta;<sup>18</sup>O was estimated from the Lisiecki & Raymo (2005) benthic &delta;<sup>18</sup>O stack via the multi-proxy sea level model of Rohling et al. (2021). ";
			array_push($citations, "<li class='citation'>Rohling, E.J., Yu, J., Heslop, D., Foster, G.L., Opdyke, B., and Roberts, A.P., 2021, Sea level and deep-sea temperature reconstructions suggest quasi-stable states and critical transitions over the past 40 million years: Science Advances, v. 7, p. eabf5326, doi:10.1126/sciadv.abf5326.</li>");
			array_push($citations, "<li class='citation'>Lisiecki, L.E., and Raymo, M.E., 2005, A Pliocene-Pleistocene stack of 57 globally distributed benthic δ18O records: Paleoceanography, v. 20, p. PA1003, doi:10.1029/2004PA001071.</li>");
			array_push($cite_bibtex, "@article{rohling_sea_2021,\n\ttitle = {Sea level and deep-sea temperature reconstructions suggest quasi-stable states and critical transitions over the past 40 million years},\n\tvolume = {7},\n\tissn = {2375-2548},\n\turl = {https://advances.sciencemag.org/content/7/26/eabf5326},\n\tdoi = {10.1126/sciadv.abf5326},\n\tlanguage = {en},\n\tnumber = {26},\n\turldate = {2021-07-08},\n\tjournal = {Science Advances},\n\tauthor = {Rohling, Eelco J. and Yu, Jimin and Heslop, David and Foster, Gavin L. and Opdyke, Bradley and Roberts, Andrew P.},\n\tmonth = jun,\n\tyear = {2021},\n\tpmid = {34172440},\n\tpages = {eabf5326},\n}");
			array_push($cite_bibtex, "@article{lisiecki_pliocene-pleistocene_2005,\n\ttitle = {A {Pliocene}-{Pleistocene} stack of 57 globally distributed benthic δ{18O} records},\n\tvolume = {20},\n\tissn = {1944-9186},\n\turl = {http://onlinelibrary.wiley.com/doi/10.1029/2004PA001071/abstract},\n\tdoi = {10.1029/2004PA001071},\n\tlanguage = {en},\n\tnumber = {1},\n\turldate = {2018-01-04},\n\tjournal = {Paleoceanography},\n\tauthor = {Lisiecki, Lorraine E. and Raymo, Maureen E.},\n\tmonth = mar,\n\tyear = {2005},\n\tpages = {PA1003},\n}");
			$rohling2 = array_map('str_getcsv', file("./data/rohling_2021_sealevel2.csv")); csv_headers($rohling2);
			mutate($data, "d18Osw_global", function($row) use ($rohling2, $timescale) {return interpolate($row['age'], $rohling2, 'age_' . $timescale, 'd18Osw');});
			if ($valid_age_end > 5.235) $valid_age_end = 5.235;
			$digits['d18Osw_global'] = 3;
			$needed['d18Osw_global'] = TRUE;
			$needed['age'] = TRUE;
			$explain_timescale = TRUE;
			break;
		case "veizer":
			$description .= "Seawater &delta;<sup>18</sup>O was estimated from the Phanerozoic trend proposed by Veizer & Prokoph (2015): "
						 . "<div class='math'><math><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mi></msub><mo><mo> = </mo><mn>-0.00003</mn><msup><mi>t</mi><mn>2</mn></msup><mo> + </mo><mn>0.0046</mn><mi>t</mi></math></div>"
						 . "where &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of seawater (&#8240; VSMOW) and <i>t</i> is age (in Ma). ";
			array_push($citations, "<li class='citation'>Veizer, J., and Prokoph, A., 2015, Temperatures and oxygen isotopic composition of Phanerozoic oceans: Earth-Science Reviews, v. 146, p. 92–104, doi:10.1016/j.earscirev.2015.03.008.</li>");
			array_push($cite_bibtex, "@article{veizer_temperatures_2015,\n\ttitle = {Temperatures and oxygen isotopic composition of {Phanerozoic} oceans},\n\tvolume = {146},\n\tissn = {0012-8252},\n\turl = {https://www.sciencedirect.com/science/article/pii/S0012825215000604},\n\tdoi = {10.1016/j.earscirev.2015.03.008},\n\tlanguage = {en},\n\turldate = {2022-06-09},\n\tjournal = {Earth-Science Reviews},\n\tauthor = {Veizer, Jan and Prokoph, Andreas},\n\tmonth = jul,\n\tyear = {2015},\n\tpages = {92--104},\n}");
			mutate($data, "d18Osw_global", function($row) {return -0.00003 * $row['age']**2 + 0.0046 * $row['age'];});
			if ($valid_age_end > 541) $valid_age_end = 541;
			$digits['d18Osw_global'] = 2;
			$needed['d18Osw_global'] = TRUE;
			$needed['age'] = TRUE;
			$explain_timescale = TRUE;
			break;
	}
	if (in_array($spatial, array("gaskell_poly", "gaskell_cesm"))) {
		switch ($benthic) {
			case "none":
				throw_error("Must select a benthic temperature record to use Gaskell et al. (2021) seawater &delta;<sup>18</sup>O corrections.");
				break;
			case "fixed":
				if (is_nan($benthicraw)) {
					throw_error("Specified fixed benthic temperature is not a valid number.");
				}
				$benthicrawvalue = floatval($benthicraw);
				$description_temp = "a benthic temperature of " . strval($benthicrawvalue) . " °C";
				mutate($data, "temp_benthic", function($row) use ($benthicrawvalue) {return $benthicrawvalue;});
				break;
			case "miller":
				$description_temp = "the benthic temperature curve of Miller et al. (2020)";
				array_push($citations, "<li class='citation'>Miller, K.G., Browning, J.V., Schmelz, W.J., Kopp, R.E., Mountain, G.S., and Wright, J.D., 2020, Cenozoic sea-level and cryospheric evolution from deep-sea geochemical and continental margin records: Science Advances, v. 6, p. eaaz1346, doi:10.1126/sciadv.aaz1346.</li>");
				array_push($cite_bibtex, "<li class='citation'>@article{miller_cenozoic_2020,\n\ttitle = {Cenozoic sea-level and cryospheric evolution from deep-sea geochemical and continental margin records},\n\tvolume = {6},\n\tissn = {2375-2548},\n\turl = {https://advances.sciencemag.org/content/6/20/eaaz1346},\n\tdoi = {10.1126/sciadv.aaz1346},\n\tlanguage = {en},\n\tnumber = {20},\n\turldate = {2020-10-02},\n\tjournal = {Science Advances},\n\tauthor = {Miller, Kenneth G. and Browning, James V. and Schmelz, W. John and Kopp, Robert E. and Mountain, Gregory S. and Wright, James D.},\n\tmonth = may,\n\tyear = {2020},\n\tpages = {eaaz1346},\n}");
				if ($ice != "miller") {
					$miller = array_map('str_getcsv', file("./data/miller_2020_sealevel.csv")); csv_headers($miller);
				}
				mutate($data, "temp_benthic", function($row) use ($miller, $timescale) {return 16.1 - 4.76*(interpolate($row['age'], $miller, 'age_' . $timescale, 'd18O') - (interpolate($row['age'], $miller, 'age_' . $timescale, 'd18O_sw') - 0.27));}); # converted to temperature on the fly using the same calibration as Miller et al. (2020)
				if ($valid_age_end > 66) $valid_age_end = 66;
				$digits['temp_benthic'] = 2;
				$explain_timescale = TRUE;
				break;
			case "cramer1":
				$description_temp = "the unsmoothed benthic temperature curve of Cramer et al. (2011) Table S4";
				array_push($citations, "<li class='citation'>Cramer, B.S., Miller, K.G., Barrett, P.J., and Wright, J.D., 2011, Late Cretaceous–Neogene trends in deep ocean temperature and continental ice volume: Reconciling records of benthic foraminiferal geochemistry (&delta;<sup>18</sup>O and Mg/Ca) with sea level history: Journal of Geophysical Research: Oceans, v. 116, doi:10.1029/2011JC007255.</li>");
				array_push($cite_bibtex, "<li class='citation'>@article{cramer_late_2011,\n\ttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume: {Reconciling} records of benthic foraminiferal geochemistry (δ{18O} and {Mg}/{Ca}) with sea level history},\n\tvolume = {116},\n\tissn = {2156-2202},\n\tshorttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2011JC007255},\n\tdoi = {10.1029/2011JC007255},\n\tlanguage = {en},\n\tnumber = {C12},\n\turldate = {2020-10-05},\n\tjournal = {Journal of Geophysical Research: Oceans},\n\tauthor = {Cramer, B. S. and Miller, K. G. and Barrett, P. J. and Wright, J. D.},\n\tyear = {2011},\n}");
				if ($ice != "cramer1") {
					$cramer1 = array_map('str_getcsv', file("./data/cramer_2011_sealevel1.csv")); csv_headers($cramer1);
				}
				mutate($data, "temp_benthic", function($row) use ($cramer1, $timescale) {return interpolate($row['age'], $cramer1, 'Age_' . $timescale, 'Temperature');});
				if ($valid_age_end < 9.2) $valid_age_end = 9.2;
				if ($valid_age_end > 108) $valid_age_end = 108;
				$digits['temp_benthic'] = 2;
				$explain_timescale = TRUE;
				break;
			case "cramer2":
				$description_temp = "the unsmoothed benthic temperature curve of Cramer et al. (2011) Table S5";
				array_push($citations, "<li class='citation'>Cramer, B.S., Miller, K.G., Barrett, P.J., and Wright, J.D., 2011, Late Cretaceous–Neogene trends in deep ocean temperature and continental ice volume: Reconciling records of benthic foraminiferal geochemistry (&delta;<sup>18</sup>O and Mg/Ca) with sea level history: Journal of Geophysical Research: Oceans, v. 116, doi:10.1029/2011JC007255.</li>");
				array_push($cite_bibtex, "<li class='citation'>@article{cramer_late_2011,\n\ttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume: {Reconciling} records of benthic foraminiferal geochemistry (δ{18O} and {Mg}/{Ca}) with sea level history},\n\tvolume = {116},\n\tissn = {2156-2202},\n\tshorttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2011JC007255},\n\tdoi = {10.1029/2011JC007255},\n\tlanguage = {en},\n\tnumber = {C12},\n\turldate = {2020-10-05},\n\tjournal = {Journal of Geophysical Research: Oceans},\n\tauthor = {Cramer, B. S. and Miller, K. G. and Barrett, P. J. and Wright, J. D.},\n\tyear = {2011},\n}");
				if ($ice != "cramer2") {
					$cramer2 = array_map('str_getcsv', file("./data/cramer_2011_sealevel2.csv")); csv_headers($cramer2);
				}
				mutate($data, "temp_benthic", function($row) use ($cramer2, $timescale) {return interpolate($row['age'], $cramer2, 'Age_' . $timescale, 'Temperature');});
				if ($valid_age_end > 62.88) $valid_age_end = 62.88;
				$digits['temp_benthic'] = 2;
				$explain_timescale = TRUE;
				break;
			case "cramer3":
				$description_temp = "the unsmoothed benthic temperature curve of Cramer et al. (2011) Table S6";
				array_push($citations, "<li class='citation'>Cramer, B.S., Miller, K.G., Barrett, P.J., and Wright, J.D., 2011, Late Cretaceous–Neogene trends in deep ocean temperature and continental ice volume: Reconciling records of benthic foraminiferal geochemistry (&delta;<sup>18</sup>O and Mg/Ca) with sea level history: Journal of Geophysical Research: Oceans, v. 116, doi:10.1029/2011JC007255.</li>");
				array_push($cite_bibtex, "<li class='citation'>@article{cramer_late_2011,\n\ttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume: {Reconciling} records of benthic foraminiferal geochemistry (δ{18O} and {Mg}/{Ca}) with sea level history},\n\tvolume = {116},\n\tissn = {2156-2202},\n\tshorttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2011JC007255},\n\tdoi = {10.1029/2011JC007255},\n\tlanguage = {en},\n\tnumber = {C12},\n\turldate = {2020-10-05},\n\tjournal = {Journal of Geophysical Research: Oceans},\n\tauthor = {Cramer, B. S. and Miller, K. G. and Barrett, P. J. and Wright, J. D.},\n\tyear = {2011},\n}");
				if ($ice != "cramer3") {
					$cramer3 = array_map('str_getcsv', file("./data/cramer_2011_sealevel3.csv")); csv_headers($cramer3);
				}
				mutate($data, "temp_benthic", function($row) use ($cramer3, $timescale) {return interpolate($row['age'], $cramer3, 'Age_' . $timescale, 'Temperature');});
				if ($valid_age_end > 62.88) $valid_age_end = 62.88;
				$digits['temp_benthic'] = 2;
				$explain_timescale = TRUE;
				break;
			case "cramer1s":
				$description_temp = "the smoothed benthic temperature curve of Cramer et al. (2011) Table S4";
				array_push($citations, "<li class='citation'>Cramer, B.S., Miller, K.G., Barrett, P.J., and Wright, J.D., 2011, Late Cretaceous–Neogene trends in deep ocean temperature and continental ice volume: Reconciling records of benthic foraminiferal geochemistry (&delta;<sup>18</sup>O and Mg/Ca) with sea level history: Journal of Geophysical Research: Oceans, v. 116, doi:10.1029/2011JC007255.</li>");
				array_push($cite_bibtex, "<li class='citation'>@article{cramer_late_2011,\n\ttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume: {Reconciling} records of benthic foraminiferal geochemistry (δ{18O} and {Mg}/{Ca}) with sea level history},\n\tvolume = {116},\n\tissn = {2156-2202},\n\tshorttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2011JC007255},\n\tdoi = {10.1029/2011JC007255},\n\tlanguage = {en},\n\tnumber = {C12},\n\turldate = {2020-10-05},\n\tjournal = {Journal of Geophysical Research: Oceans},\n\tauthor = {Cramer, B. S. and Miller, K. G. and Barrett, P. J. and Wright, J. D.},\n\tyear = {2011},\n}");
				if ($ice != "cramer1") {
					$cramer1 = array_map('str_getcsv', file("./data/cramer_2011_sealevel1.csv")); csv_headers($cramer1);
				}
				mutate($data, "temp_benthic", function($row) use ($cramer1, $timescale) {return interpolate($row['age'], $cramer1, 'Age_' . $timescale, 'Temperature (long)');});
				if ($valid_age_end < 9.2) $valid_age_end = 9.2;
				if ($valid_age_end > 108) $valid_age_end = 108;
				$digits['temp_benthic'] = 2;
				$explain_timescale = TRUE;
				break;
			case "cramer2s":
				$description_temp = "the smoothed benthic temperature curve of Cramer et al. (2011) Table S5";
				array_push($citations, "<li class='citation'>Cramer, B.S., Miller, K.G., Barrett, P.J., and Wright, J.D., 2011, Late Cretaceous–Neogene trends in deep ocean temperature and continental ice volume: Reconciling records of benthic foraminiferal geochemistry (&delta;<sup>18</sup>O and Mg/Ca) with sea level history: Journal of Geophysical Research: Oceans, v. 116, doi:10.1029/2011JC007255.</li>");
				array_push($cite_bibtex, "<li class='citation'>@article{cramer_late_2011,\n\ttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume: {Reconciling} records of benthic foraminiferal geochemistry (δ{18O} and {Mg}/{Ca}) with sea level history},\n\tvolume = {116},\n\tissn = {2156-2202},\n\tshorttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2011JC007255},\n\tdoi = {10.1029/2011JC007255},\n\tlanguage = {en},\n\tnumber = {C12},\n\turldate = {2020-10-05},\n\tjournal = {Journal of Geophysical Research: Oceans},\n\tauthor = {Cramer, B. S. and Miller, K. G. and Barrett, P. J. and Wright, J. D.},\n\tyear = {2011},\n}");
				if ($ice != "cramer2") {
					$cramer2 = array_map('str_getcsv', file("./data/cramer_2011_sealevel2.csv")); csv_headers($cramer2);
				}
				mutate($data, "temp_benthic", function($row) use ($cramer2, $timescale) {return interpolate($row['age'], $cramer2, 'Age_' . $timescale, 'Temperature (long)');});
				if ($valid_age_end > 62.88) $valid_age_end = 62.88;
				$digits['temp_benthic'] = 2;
				$explain_timescale = TRUE;
				break;
			case "cramer3s":
				$description_temp = "the smoothed benthic temperature curve of Cramer et al. (2011) Table S6";
				array_push($citations, "<li class='citation'>Cramer, B.S., Miller, K.G., Barrett, P.J., and Wright, J.D., 2011, Late Cretaceous–Neogene trends in deep ocean temperature and continental ice volume: Reconciling records of benthic foraminiferal geochemistry (&delta;<sup>18</sup>O and Mg/Ca) with sea level history: Journal of Geophysical Research: Oceans, v. 116, doi:10.1029/2011JC007255.</li>");
				array_push($cite_bibtex, "<li class='citation'>@article{cramer_late_2011,\n\ttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume: {Reconciling} records of benthic foraminiferal geochemistry (δ{18O} and {Mg}/{Ca}) with sea level history},\n\tvolume = {116},\n\tissn = {2156-2202},\n\tshorttitle = {Late {Cretaceous}–{Neogene} trends in deep ocean temperature and continental ice volume},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2011JC007255},\n\tdoi = {10.1029/2011JC007255},\n\tlanguage = {en},\n\tnumber = {C12},\n\turldate = {2020-10-05},\n\tjournal = {Journal of Geophysical Research: Oceans},\n\tauthor = {Cramer, B. S. and Miller, K. G. and Barrett, P. J. and Wright, J. D.},\n\tyear = {2011},\n}");
				if ($ice != "cramer3") {
					$cramer3 = array_map('str_getcsv', file("./data/cramer_2011_sealevel3.csv")); csv_headers($cramer3);
				}
				mutate($data, "temp_benthic", function($row) use ($cramer3, $timescale) {return interpolate($row['age'], $cramer3, 'Age_' . $timescale, 'Temperature (long)');});
				if ($valid_age_end > 62.88) $valid_age_end = 62.88;
				$digits['temp_benthic'] = 2;
				$explain_timescale = TRUE;
				break;
			case "meckler":
				$description_temp = "the clumped isotope benthic temperature curve of Meckler et al. (2022) (interpolated to 0.1 Ma resolution using the LOESS smoothing method described in that paper)";
				array_push($citations, "<li class='citation'>Meckler, A.N. et al., 2022, Cenozoic evolution of deep ocean temperature from clumped isotope thermometry: Science, v. 377, p. 86–90, doi:10.1126/science.abk0604.</li>");
				array_push($cite_bibtex, "@article{meckler_cenozoic_2022,\n\ttitle = {Cenozoic evolution of deep ocean temperature from clumped isotope thermometry},\n\tvolume = {377},\n\tdoi = {10.1126/science.abk0604},\n\tnumber = {6601},\n\tjournal = {Science},\n\tauthor = {Meckler, A. N. and Sexton, P. F. and Piasecki, A. M. and Leutert, T. J. and Marquardt, J. and Ziegler, M. and Agterhuis, T. and Lourens, L. J. and Rae, J. W. B. and Barnet, J. and Tripati, A. and Bernasconi, S. M.},\n\tmonth = jul,\n\tyear = {2022},\n\tpages = {86--90},\n}");
				if ($ice != "mecklerraw" and $ice != "mecklerph") {
					$meckler = array_map('str_getcsv', file("./data/meckler_2022_clumped.csv")); csv_headers($meckler);
				}
				mutate($data, "temp_benthic", function($row) use ($meckler, $timescale) {return interpolate($row['age'], $meckler, 'age_' . $timescale, 'temp_benthic');});
				if ($valid_age_end > 62.06) $valid_age_end = 62.06;
				$digits['temp_benthic'] = 3;
				$explain_timescale = TRUE;
				break;
			case "rohling1":
				$description_temp = "the CENOGRID-based benthic temperature curve of Rohling et al. (2021)";
				array_push($citations, "<li class='citation'>Rohling, E.J., Yu, J., Heslop, D., Foster, G.L., Opdyke, B., and Roberts, A.P., 2021, Sea level and deep-sea temperature reconstructions suggest quasi-stable states and critical transitions over the past 40 million years: Science Advances, v. 7, p. eabf5326, doi:10.1126/sciadv.abf5326.</li>");
				array_push($cite_bibtex, "@article{rohling_sea_2021,\n\ttitle = {Sea level and deep-sea temperature reconstructions suggest quasi-stable states and critical transitions over the past 40 million years},\n\tvolume = {7},\n\tissn = {2375-2548},\n\turl = {https://advances.sciencemag.org/content/7/26/eabf5326},\n\tdoi = {10.1126/sciadv.abf5326},\n\tlanguage = {en},\n\tnumber = {26},\n\turldate = {2021-07-08},\n\tjournal = {Science Advances},\n\tauthor = {Rohling, Eelco J. and Yu, Jimin and Heslop, David and Foster, Gavin L. and Opdyke, Bradley and Roberts, Andrew P.},\n\tmonth = jun,\n\tyear = {2021},\n\tpmid = {34172440},\n\tpages = {eabf5326},\n}");
				if ($ice != "rohling1") {
					$rohling1 = array_map('str_getcsv', file("./data/rohling_2021_sealevel1.csv")); csv_headers($rohling1);
				}
				mutate($data, "temp_benthic", function($row) use ($rohling1, $timescale) {return interpolate($row['age'], $rohling1, 'age_' . $timescale, 'temp_benthic');});
				if ($valid_age_end > 40.195) $valid_age_end = 40.195;
				$digits['temp_benthic'] = 3;
				$explain_timescale = TRUE;
				break;
			case "rohling2":
				$description_temp = "the Lisiecki & Raymo (2005)-based benthic temperature curve of Rohling et al. (2021)";
				array_push($citations, "<li class='citation'>Rohling, E.J., Yu, J., Heslop, D., Foster, G.L., Opdyke, B., and Roberts, A.P., 2021, Sea level and deep-sea temperature reconstructions suggest quasi-stable states and critical transitions over the past 40 million years: Science Advances, v. 7, p. eabf5326, doi:10.1126/sciadv.abf5326.</li>");
				array_push($citations, "<li class='citation'>Lisiecki, L.E., and Raymo, M.E., 2005, A Pliocene-Pleistocene stack of 57 globally distributed benthic δ18O records: Paleoceanography, v. 20, p. PA1003, doi:10.1029/2004PA001071.</li>");
				array_push($cite_bibtex, "@article{rohling_sea_2021,\n\ttitle = {Sea level and deep-sea temperature reconstructions suggest quasi-stable states and critical transitions over the past 40 million years},\n\tvolume = {7},\n\tissn = {2375-2548},\n\turl = {https://advances.sciencemag.org/content/7/26/eabf5326},\n\tdoi = {10.1126/sciadv.abf5326},\n\tlanguage = {en},\n\tnumber = {26},\n\turldate = {2021-07-08},\n\tjournal = {Science Advances},\n\tauthor = {Rohling, Eelco J. and Yu, Jimin and Heslop, David and Foster, Gavin L. and Opdyke, Bradley and Roberts, Andrew P.},\n\tmonth = jun,\n\tyear = {2021},\n\tpmid = {34172440},\n\tpages = {eabf5326},\n}");
				array_push($cite_bibtex, "@article{lisiecki_pliocene-pleistocene_2005,\n\ttitle = {A {Pliocene}-{Pleistocene} stack of 57 globally distributed benthic δ{18O} records},\n\tvolume = {20},\n\tissn = {1944-9186},\n\turl = {http://onlinelibrary.wiley.com/doi/10.1029/2004PA001071/abstract},\n\tdoi = {10.1029/2004PA001071},\n\tlanguage = {en},\n\tnumber = {1},\n\turldate = {2018-01-04},\n\tjournal = {Paleoceanography},\n\tauthor = {Lisiecki, Lorraine E. and Raymo, Maureen E.},\n\tmonth = mar,\n\tyear = {2005},\n\tpages = {PA1003},\n}");
				if ($ice != "rohling2") {
					$rohling2 = array_map('str_getcsv', file("./data/rohling_2021_sealevel2.csv")); csv_headers($rohling2);
				}
				mutate($data, "temp_benthic", function($row) use ($rohling2, $timescale) {return interpolate($row['age'], $rohling2, 'age_' . $timescale, 'temp_benthic');});
				if ($valid_age_end > 5.3) $valid_age_end = 5.3;
				$digits['temp_benthic'] = 3;
				$explain_timescale = TRUE;
				break;
		}
	}
	switch ($spatial) {
		case "none":
			mutate($data, "d18Osw_spatial", function($row) {return 0;});
			break;
		case "legrandemixed":
			$description .= "Local variation in seawater &delta;<sup>18</sup>O was estimated using modern values from LeGrande & Schmidt (2006), averaging the uppermost 0&ndash;50 m to approximate the mixed layer and ";
			array_push($citations, "<li class='citation'>LeGrande, A.N., and Schmidt, G.A., 2006, Global gridded data set of the oxygen isotopic composition in seawater: Geophysical Research Letters, v. 33, doi:https://doi.org/10.1029/2006GL026011.</li>");
			array_push($cite_bibtex, "@article{legrande_global_2006,\n\ttitle = {Global gridded data set of the oxygen isotopic composition in seawater},\n\tvolume = {33},\n\tissn = {1944-8007},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2006GL026011},\n\tdoi = {https://doi.org/10.1029/2006GL026011},\n\tlanguage = {en},\n\tnumber = {12},\n\turldate = {2021-01-18},\n\tjournal = {Geophysical Research Letters},\n\tauthor = {LeGrande, Allegra N. and Schmidt, Gavin A.},\n\tyear = {2006},\n}");
			$legrande = json_decode(file_get_contents("./data/legrande_top50.json"), true);
			mutate_poly($data, function($row) use ($legrande, $square) {return patch_mean($legrande, $row['pallat'], $row['pallong'], $square, 'd18O');});
			$digits['d18Osw_spatial'] = 2;
			$digits['sd'] = 2;
			$needed['d18Osw_spatial'] = TRUE;
			$needed['n'] = TRUE;
			$needed['sd'] = TRUE;
			$needed['lat'] = TRUE;
			$needed['long'] = TRUE;
			break;
		case "legrande0":
			$description .= "Local variation in seawater &delta;<sup>18</sup>O was estimated using modern surface values from LeGrande & Schmidt (2006), ";
			array_push($citations, "<li class='citation'>LeGrande, A.N., and Schmidt, G.A., 2006, Global gridded data set of the oxygen isotopic composition in seawater: Geophysical Research Letters, v. 33, doi:https://doi.org/10.1029/2006GL026011.</li>");
			array_push($cite_bibtex, "@article{legrande_global_2006,\n\ttitle = {Global gridded data set of the oxygen isotopic composition in seawater},\n\tvolume = {33},\n\tissn = {1944-8007},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2006GL026011},\n\tdoi = {https://doi.org/10.1029/2006GL026011},\n\tlanguage = {en},\n\tnumber = {12},\n\turldate = {2021-01-18},\n\tjournal = {Geophysical Research Letters},\n\tauthor = {LeGrande, Allegra N. and Schmidt, Gavin A.},\n\tyear = {2006},\n}");
			$legrande = json_decode(file_get_contents("./data/legrande_0.json"), true);
			mutate_poly($data, function($row) use ($legrande, $square) {return patch_mean($legrande, $row['pallat'], $row['pallong'], $square, 'd18O');});
			$digits['d18Osw_spatial'] = 2;
			$digits['sd'] = 2;
			$needed['d18Osw_spatial'] = TRUE;
			$needed['n'] = TRUE;
			$needed['sd'] = TRUE;
			$needed['lat'] = TRUE;
			$needed['long'] = TRUE;
			break;
		case "legrande50":
			$description .= "Local variation in seawater &delta;<sup>18</sup>O was estimated using modern values at 50 m depth from LeGrande & Schmidt (2006), ";
			array_push($citations, "<li class='citation'>LeGrande, A.N., and Schmidt, G.A., 2006, Global gridded data set of the oxygen isotopic composition in seawater: Geophysical Research Letters, v. 33, doi:https://doi.org/10.1029/2006GL026011.</li>");
			array_push($cite_bibtex, "@article{legrande_global_2006,\n\ttitle = {Global gridded data set of the oxygen isotopic composition in seawater},\n\tvolume = {33},\n\tissn = {1944-8007},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2006GL026011},\n\tdoi = {https://doi.org/10.1029/2006GL026011},\n\tlanguage = {en},\n\tnumber = {12},\n\turldate = {2021-01-18},\n\tjournal = {Geophysical Research Letters},\n\tauthor = {LeGrande, Allegra N. and Schmidt, Gavin A.},\n\tyear = {2006},\n}");
			$legrande = json_decode(file_get_contents("./data/legrande_50.json"), true);
			mutate_poly($data, function($row) use ($legrande, $square) {return patch_mean($legrande, $row['pallat'], $row['pallong'], $square, 'd18O');});
			$digits['d18Osw_spatial'] = 2;
			$digits['sd'] = 2;
			$needed['d18Osw_spatial'] = TRUE;
			$needed['n'] = TRUE;
			$needed['sd'] = TRUE;
			$needed['lat'] = TRUE;
			$needed['long'] = TRUE;
			break;
		case "legrande100":
			$description .= "Local variation in seawater &delta;<sup>18</sup>O was estimated using modern values at 100 m depth from LeGrande & Schmidt (2006), ";
			array_push($citations, "<li class='citation'>LeGrande, A.N., and Schmidt, G.A., 2006, Global gridded data set of the oxygen isotopic composition in seawater: Geophysical Research Letters, v. 33, doi:https://doi.org/10.1029/2006GL026011.</li>");
			array_push($cite_bibtex, "@article{legrande_global_2006,\n\ttitle = {Global gridded data set of the oxygen isotopic composition in seawater},\n\tvolume = {33},\n\tissn = {1944-8007},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2006GL026011},\n\tdoi = {https://doi.org/10.1029/2006GL026011},\n\tlanguage = {en},\n\tnumber = {12},\n\turldate = {2021-01-18},\n\tjournal = {Geophysical Research Letters},\n\tauthor = {LeGrande, Allegra N. and Schmidt, Gavin A.},\n\tyear = {2006},\n}");
			$legrande = json_decode(file_get_contents("./data/legrande_100.json"), true);
			mutate_poly($data, function($row) use ($legrande, $square) {return patch_mean($legrande, $row['pallat'], $row['pallong'], $square, 'd18O');});
			$digits['d18Osw_spatial'] = 2;
			$digits['sd'] = 2;
			$needed['d18Osw_spatial'] = TRUE;
			$needed['n'] = TRUE;
			$needed['sd'] = TRUE;
			$needed['lat'] = TRUE;
			$needed['long'] = TRUE;
			break;
		case "legrande200":
			$description .= "Local variation in seawater &delta;<sup>18</sup>O was estimated using modern values at 200 m depth from LeGrande & Schmidt (2006), ";
			array_push($citations, "<li class='citation'>LeGrande, A.N., and Schmidt, G.A., 2006, Global gridded data set of the oxygen isotopic composition in seawater: Geophysical Research Letters, v. 33, doi:https://doi.org/10.1029/2006GL026011.</li>");
			array_push($cite_bibtex, "@article{legrande_global_2006,\n\ttitle = {Global gridded data set of the oxygen isotopic composition in seawater},\n\tvolume = {33},\n\tissn = {1944-8007},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2006GL026011},\n\tdoi = {https://doi.org/10.1029/2006GL026011},\n\tlanguage = {en},\n\tnumber = {12},\n\turldate = {2021-01-18},\n\tjournal = {Geophysical Research Letters},\n\tauthor = {LeGrande, Allegra N. and Schmidt, Gavin A.},\n\tyear = {2006},\n}");
			$legrande = json_decode(file_get_contents("./data/legrande_200.json"), true);
			mutate_poly($data, function($row) use ($legrande, $square) {return patch_mean($legrande, $row['pallat'], $row['pallong'], $square, 'd18O');});
			$digits['d18Osw_spatial'] = 2;
			$digits['sd'] = 2;
			$needed['d18Osw_spatial'] = TRUE;
			$needed['n'] = TRUE;
			$needed['sd'] = TRUE;
			$needed['lat'] = TRUE;
			$needed['long'] = TRUE;
			break;
		case "legrande500":
			$description .= "Local variation in seawater &delta;<sup>18</sup>O was estimated using modern values at 500 m depth from LeGrande & Schmidt (2006), ";
			array_push($citations, "<li class='citation'>LeGrande, A.N., and Schmidt, G.A., 2006, Global gridded data set of the oxygen isotopic composition in seawater: Geophysical Research Letters, v. 33, doi:https://doi.org/10.1029/2006GL026011.</li>");
			array_push($cite_bibtex, "@article{legrande_global_2006,\n\ttitle = {Global gridded data set of the oxygen isotopic composition in seawater},\n\tvolume = {33},\n\tissn = {1944-8007},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2006GL026011},\n\tdoi = {https://doi.org/10.1029/2006GL026011},\n\tlanguage = {en},\n\tnumber = {12},\n\turldate = {2021-01-18},\n\tjournal = {Geophysical Research Letters},\n\tauthor = {LeGrande, Allegra N. and Schmidt, Gavin A.},\n\tyear = {2006},\n}");
			$legrande = json_decode(file_get_contents("./data/legrande_500.json"), true);
			mutate_poly($data, function($row) use ($legrande, $square) {return patch_mean($legrande, $row['pallat'], $row['pallong'], $square, 'd18O');});
			$digits['d18Osw_spatial'] = 2;
			$digits['sd'] = 2;
			$needed['d18Osw_spatial'] = TRUE;
			$needed['n'] = TRUE;
			$needed['sd'] = TRUE;
			$needed['lat'] = TRUE;
			$needed['long'] = TRUE;
			break;
		case "legrande1000":
			$description .= "Local variation in seawater &delta;<sup>18</sup>O was estimated using modern values at 1000 m depth from LeGrande & Schmidt (2006), ";
			array_push($citations, "<li class='citation'>LeGrande, A.N., and Schmidt, G.A., 2006, Global gridded data set of the oxygen isotopic composition in seawater: Geophysical Research Letters, v. 33, doi:https://doi.org/10.1029/2006GL026011.</li>");
			array_push($cite_bibtex, "@article{legrande_global_2006,\n\ttitle = {Global gridded data set of the oxygen isotopic composition in seawater},\n\tvolume = {33},\n\tissn = {1944-8007},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2006GL026011},\n\tdoi = {https://doi.org/10.1029/2006GL026011},\n\tlanguage = {en},\n\tnumber = {12},\n\turldate = {2021-01-18},\n\tjournal = {Geophysical Research Letters},\n\tauthor = {LeGrande, Allegra N. and Schmidt, Gavin A.},\n\tyear = {2006},\n}");
			$legrande = json_decode(file_get_contents("./data/legrande_1000.json"), true);
			mutate_poly($data, function($row) use ($legrande, $square) {return patch_mean($legrande, $row['pallat'], $row['pallong'], $square, 'd18O');});
			$digits['d18Osw_spatial'] = 2;
			$digits['sd'] = 2;
			$needed['d18Osw_spatial'] = TRUE;
			$needed['n'] = TRUE;
			$needed['sd'] = TRUE;
			$needed['lat'] = TRUE;
			$needed['long'] = TRUE;
			break;
		case "legrande1500":
			$description .= "Local variation in seawater &delta;<sup>18</sup>O was estimated using modern values at 1500 m depth from LeGrande & Schmidt (2006), ";
			array_push($citations, "<li class='citation'>LeGrande, A.N., and Schmidt, G.A., 2006, Global gridded data set of the oxygen isotopic composition in seawater: Geophysical Research Letters, v. 33, doi:https://doi.org/10.1029/2006GL026011.</li>");
			array_push($cite_bibtex, "@article{legrande_global_2006,\n\ttitle = {Global gridded data set of the oxygen isotopic composition in seawater},\n\tvolume = {33},\n\tissn = {1944-8007},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2006GL026011},\n\tdoi = {https://doi.org/10.1029/2006GL026011},\n\tlanguage = {en},\n\tnumber = {12},\n\turldate = {2021-01-18},\n\tjournal = {Geophysical Research Letters},\n\tauthor = {LeGrande, Allegra N. and Schmidt, Gavin A.},\n\tyear = {2006},\n}");
			$legrande = json_decode(file_get_contents("./data/legrande_1500.json"), true);
			mutate_poly($data, function($row) use ($legrande, $square) {return patch_mean($legrande, $row['pallat'], $row['pallong'], $square, 'd18O');});
			$digits['d18Osw_spatial'] = 2;
			$digits['sd'] = 2;
			$needed['d18Osw_spatial'] = TRUE;
			$needed['n'] = TRUE;
			$needed['sd'] = TRUE;
			$needed['lat'] = TRUE;
			$needed['long'] = TRUE;
			break;
		case "legrande2000":
			$description .= "Local variation in seawater &delta;<sup>18</sup>O was estimated using modern values at 2000 m depth from LeGrande & Schmidt (2006), ";
			array_push($citations, "<li class='citation'>LeGrande, A.N., and Schmidt, G.A., 2006, Global gridded data set of the oxygen isotopic composition in seawater: Geophysical Research Letters, v. 33, doi:https://doi.org/10.1029/2006GL026011.</li>");
			array_push($cite_bibtex, "@article{legrande_global_2006,\n\ttitle = {Global gridded data set of the oxygen isotopic composition in seawater},\n\tvolume = {33},\n\tissn = {1944-8007},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2006GL026011},\n\tdoi = {https://doi.org/10.1029/2006GL026011},\n\tlanguage = {en},\n\tnumber = {12},\n\turldate = {2021-01-18},\n\tjournal = {Geophysical Research Letters},\n\tauthor = {LeGrande, Allegra N. and Schmidt, Gavin A.},\n\tyear = {2006},\n}");
			$legrande = json_decode(file_get_contents("./data/legrande_2000.json"), true);
			mutate_poly($data, function($row) use ($legrande, $square) {return patch_mean($legrande, $row['pallat'], $row['pallong'], $square, 'd18O');});
			$digits['d18Osw_spatial'] = 2;
			$digits['sd'] = 2;
			$needed['d18Osw_spatial'] = TRUE;
			$needed['n'] = TRUE;
			$needed['sd'] = TRUE;
			$needed['lat'] = TRUE;
			$needed['long'] = TRUE;
			break;
		case "legrande3000":
			$description .= "Local variation in seawater &delta;<sup>18</sup>O was estimated using modern values at 3000 m depth from LeGrande & Schmidt (2006), ";
			array_push($citations, "<li class='citation'>LeGrande, A.N., and Schmidt, G.A., 2006, Global gridded data set of the oxygen isotopic composition in seawater: Geophysical Research Letters, v. 33, doi:https://doi.org/10.1029/2006GL026011.</li>");
			array_push($cite_bibtex, "@article{legrande_global_2006,\n\ttitle = {Global gridded data set of the oxygen isotopic composition in seawater},\n\tvolume = {33},\n\tissn = {1944-8007},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2006GL026011},\n\tdoi = {https://doi.org/10.1029/2006GL026011},\n\tlanguage = {en},\n\tnumber = {12},\n\turldate = {2021-01-18},\n\tjournal = {Geophysical Research Letters},\n\tauthor = {LeGrande, Allegra N. and Schmidt, Gavin A.},\n\tyear = {2006},\n}");
			$legrande = json_decode(file_get_contents("./data/legrande_3000.json"), true);
			mutate_poly($data, function($row) use ($legrande, $square) {return patch_mean($legrande, $row['pallat'], $row['pallong'], $square, 'd18O');});
			$digits['d18Osw_spatial'] = 2;
			$digits['sd'] = 2;
			$needed['d18Osw_spatial'] = TRUE;
			$needed['n'] = TRUE;
			$needed['sd'] = TRUE;
			$needed['lat'] = TRUE;
			$needed['long'] = TRUE;
			break;
		case "legrande4000":
			$description .= "Local variation in seawater &delta;<sup>18</sup>O was estimated using modern values at 4000 m depth from LeGrande & Schmidt (2006), ";
			array_push($citations, "<li class='citation'>LeGrande, A.N., and Schmidt, G.A., 2006, Global gridded data set of the oxygen isotopic composition in seawater: Geophysical Research Letters, v. 33, doi:https://doi.org/10.1029/2006GL026011.</li>");
			array_push($cite_bibtex, "@article{legrande_global_2006,\n\ttitle = {Global gridded data set of the oxygen isotopic composition in seawater},\n\tvolume = {33},\n\tissn = {1944-8007},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2006GL026011},\n\tdoi = {https://doi.org/10.1029/2006GL026011},\n\tlanguage = {en},\n\tnumber = {12},\n\turldate = {2021-01-18},\n\tjournal = {Geophysical Research Letters},\n\tauthor = {LeGrande, Allegra N. and Schmidt, Gavin A.},\n\tyear = {2006},\n}");
			$legrande = json_decode(file_get_contents("./data/legrande_4000.json"), true);
			mutate_poly($data, function($row) use ($legrande, $square) {return patch_mean($legrande, $row['pallat'], $row['pallong'], $square, 'd18O');});
			$digits['d18Osw_spatial'] = 2;
			$digits['sd'] = 2;
			$needed['d18Osw_spatial'] = TRUE;
			$needed['n'] = TRUE;
			$needed['sd'] = TRUE;
			$needed['lat'] = TRUE;
			$needed['long'] = TRUE;
			break;
		case "legrande5000":
			$description .= "Local variation in seawater &delta;<sup>18</sup>O was estimated using modern values at 5000 m depth from LeGrande & Schmidt (2006), ";
			array_push($citations, "<li class='citation'>LeGrande, A.N., and Schmidt, G.A., 2006, Global gridded data set of the oxygen isotopic composition in seawater: Geophysical Research Letters, v. 33, doi:https://doi.org/10.1029/2006GL026011.</li>");
			array_push($cite_bibtex, "@article{legrande_global_2006,\n\ttitle = {Global gridded data set of the oxygen isotopic composition in seawater},\n\tvolume = {33},\n\tissn = {1944-8007},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2006GL026011},\n\tdoi = {https://doi.org/10.1029/2006GL026011},\n\tlanguage = {en},\n\tnumber = {12},\n\turldate = {2021-01-18},\n\tjournal = {Geophysical Research Letters},\n\tauthor = {LeGrande, Allegra N. and Schmidt, Gavin A.},\n\tyear = {2006},\n}");
			$legrande = json_decode(file_get_contents("./data/legrande_5000.json"), true);
			mutate_poly($data, function($row) use ($legrande, $square) {return patch_mean($legrande, $row['pallat'], $row['pallong'], $square, 'd18O');});
			$digits['d18Osw_spatial'] = 2;
			$digits['sd'] = 2;
			$needed['d18Osw_spatial'] = TRUE;
			$needed['n'] = TRUE;
			$needed['sd'] = TRUE;
			$needed['lat'] = TRUE;
			$needed['long'] = TRUE;
			break;
		case "tierney_hol":
			$description .= "Local variation in seawater &delta;<sup>18</sup>O was estimated using surface values from the Late Holocene data-assimilation product of Tierney et al. (2020), ";
			array_push($citations, "<li class='citation'>Tierney, J.E., Zhu, J., King, J., Malevich, S.B., Hakim, G.J., and Poulsen, C.J., 2020, Glacial cooling and climate sensitivity revisited: Nature, v. 584, p. 569–573, doi:10.1038/s41586-020-2617-x.</li>");
			array_push($cite_bibtex, "@article{tierney_glacial_2020,\n\ttitle = {Glacial cooling and climate sensitivity revisited},\n\tvolume = {584},\n\tissn = {1476-4687},\n\turl = {https://www.nature.com/articles/s41586-020-2617-x},\n\tdoi = {10.1038/s41586-020-2617-x},\n\tlanguage = {en},\n\tnumber = {7822},\n\turldate = {2022-06-15},\n\tjournal = {Nature},\n\tauthor = {Tierney, Jessica E. and Zhu, Jiang and King, Jonathan and Malevich, Steven B. and Hakim, Gregory J. and Poulsen, Christopher J.},\n\tmonth = aug,\n\tyear = {2020},\n\tpages = {569--573},\n}");
			$tierney = json_decode(file_get_contents("./data/tierney_hol.json"), true);
			mutate_poly($data, function($row) use ($tierney, $square) {return patch_mean($legrande, $row['pallat'], $row['pallong'], $square, 'd18O');});
			$digits['d18Osw_spatial'] = 2;
			$digits['sd'] = 2;
			$needed['d18Osw_spatial'] = TRUE;
			$needed['n'] = TRUE;
			$needed['sd'] = TRUE;
			$needed['lat'] = TRUE;
			$needed['long'] = TRUE;
			break;
		case "tierney_lgm":
			$description .= "Local variation in seawater &delta;<sup>18</sup>O was estimated using surface values from the Last Glacial Maximum data-assimilation product of Tierney et al. (2020), ";
			array_push($citations, "<li class='citation'>Tierney, J.E., Zhu, J., King, J., Malevich, S.B., Hakim, G.J., and Poulsen, C.J., 2020, Glacial cooling and climate sensitivity revisited: Nature, v. 584, p. 569–573, doi:10.1038/s41586-020-2617-x.</li>");
			array_push($cite_bibtex, "@article{tierney_glacial_2020,\n\ttitle = {Glacial cooling and climate sensitivity revisited},\n\tvolume = {584},\n\tissn = {1476-4687},\n\turl = {https://www.nature.com/articles/s41586-020-2617-x},\n\tdoi = {10.1038/s41586-020-2617-x},\n\tlanguage = {en},\n\tnumber = {7822},\n\turldate = {2022-06-15},\n\tjournal = {Nature},\n\tauthor = {Tierney, Jessica E. and Zhu, Jiang and King, Jonathan and Malevich, Steven B. and Hakim, Gregory J. and Poulsen, Christopher J.},\n\tmonth = aug,\n\tyear = {2020},\n\tpages = {569--573},\n}");
			$tierney = json_decode(file_get_contents("./data/tierney_lgm.json"), true);
			mutate_poly($data, function($row) use ($tierney, $square) {return patch_mean($legrande, $row['pallat'], $row['pallong'], $square, 'd18O');});
			$digits['d18Osw_spatial'] = 2;
			$digits['sd'] = 2;
			$needed['d18Osw_spatial'] = TRUE;
			$needed['n'] = TRUE;
			$needed['sd'] = TRUE;
			$needed['lat'] = TRUE;
			$needed['long'] = TRUE;
			break;
		case "zachos":
			$description .= "Local variation in seawater &delta;<sup>18</sup>O was estimated using the latitudinal relationship of Zachos et al. (1994). ";
			array_push($citations, "<li class='citation'>Zachos, J.C., Stott, L.D., and Lohmann, K.C., 1994, Evolution of Early Cenozoic marine temperatures: Paleoceanography, v. 9, p. 353–387, doi:10.1029/93PA03266.</li>");
			array_push($cite_bibtex, "@article{zachos_evolution_1994,\n\ttitle = {Evolution of {Early} {Cenozoic} marine temperatures},\n\tvolume = {9},\n\tissn = {1944-9186},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/10.1029/93PA03266},\n\tdoi = {10.1029/93PA03266},\n\tlanguage = {en},\n\tnumber = {2},\n\turldate = {2020-08-20},\n\tjournal = {Paleoceanography},\n\tauthor = {Zachos, James C. and Stott, Lowell D. and Lohmann, Kyger C.},\n\tmonth = apr,\n\tyear = {1994},\n\tpages = {353--387},\n}");
			mutate($data, "d18Osw_spatial", function($row) {return 0.576 + 0.041*abs($row['pallat']) - 0.0017*abs($row['pallat'])**2 + 1.35e-5*abs($row['pallat'])**3;});
			if ($valid_lat_start < -70) $valid_lat_start = -70;
			if ($valid_lat_end > 0) $valid_lat_end = 0;
			$digits['d18Osw_spatial'] = 2;
			$needed['d18Osw_spatial'] = TRUE;
			$needed['lat'] = TRUE;
			break;
		case "hollis":
			$description .= "Local variation in seawater &delta;<sup>18</sup>O was estimated using the median modern value from the upper 50m of seawater in the 10&deg; latitudinal band containing the site's paleolatitude, after the method of Hollis et al. (2019). ";
			array_push($citations, "<li class='citation'>Hollis, C.J. et al., 2019, The DeepMIP contribution to PMIP4: methodologies for selection, compilation and analysis of latest Paleocene and early Eocene climate proxy data, incorporating version 0.1 of the DeepMIP database: Geoscientific Model Development, v. 12, p. 3149–3206, doi:10.5194/gmd-12-3149-2019.</li>");
			array_push($cite_bibtex, "@article{hollis_deepmip_2019,\n\ttitle = {The {DeepMIP} contribution to {PMIP4}: methodologies for selection, compilation and analysis of latest {Paleocene} and early {Eocene} climate proxy data, incorporating version 0.1 of the {DeepMIP} database},\n\tvolume = {12},\n\tissn = {1991-959X},\n\tshorttitle = {The {DeepMIP} contribution to {PMIP4}},\n\turl = {https://gmd.copernicus.org/articles/12/3149/2019/},\n\tdoi = {10.5194/gmd-12-3149-2019},\n\tlanguage = {English},\n\tnumber = {7},\n\turldate = {2021-06-10},\n\tjournal = {Geoscientific Model Development},\n\tauthor = {Hollis, Christopher J. and Dunkley Jones, Tom and Anagnostou, Eleni and Bijl, Peter K. and Cramwinckel, Margot J. and Cui, Ying and Dickens, Gerald R. and Edgar, Kirsty M. and Eley, Yvette and Evans, David and Foster, Gavin L. and Frieling, Joost and Inglis, Gordon N. and Kennedy, Elizabeth M. and Kozdon, Reinhard and Lauretano, Vittoria and Lear, Caroline H. and Littler, Kate and Lourens, Lucas and Meckler, A. Nele and Naafs, B. David A. and Pälike, Heiko and Pancost, Richard D. and Pearson, Paul N. and Röhl, Ursula and Royer, Dana L. and Salzmann, Ulrich and Schubert, Brian A. and Seebeck, Hannu and Sluijs, Appy and Speijer, Robert P. and Stassen, Peter and Tierney, Jessica and Tripati, Aradhna and Wade, Bridget and Westerhold, Thomas and Witkowski, Caitlyn and Zachos, James C. and Zhang, Yi Ge and Huber, Matthew and Lunt, Daniel J.},\n\tmonth = jul,\n\tyear = {2019},\n\tpages = {3149--3206},\n}");
			mutate($data, "d18Osw_spatial", function($row) {return [-0.26, -0.28, -0.32, -0.31, -0.03, 0.47, 0.59, 0.47, 0.43, 0.28, 0.27, 0.38, 0.19, -0.47, -0.65, -0.11, -1.78, -1.75][floor($row['pallat'] / 10) + 9];});
			$digits['d18Osw_spatial'] = 2;
			$needed['d18Osw_spatial'] = TRUE;
			$needed['lat'] = TRUE;
			break;
		case "gaskell_poly":
			$description .= "Local variation in seawater &delta;<sup>18</sup>O was estimated from sample latitudes using Gaskell et al. (2022) Eq. S9 and " . $description_temp . ". ";
			array_push($citations, "<li class='citation'>Gaskell, D.E., Huber, M., O’Brien, C.L., Inglis, G.N., Acosta, R.P., Poulsen, C.J., and Hull, P.M., 2022, The latitudinal temperature gradient and its climate dependence as inferred from foraminiferal &delta;<sup>18</sup>O over the past 95 million years: Proceedings of the National Academy of Sciences, v. 119, p. e2111332119, doi:doi:10.1073/pnas.2111332119.</li>");
			array_push($cite_bibtex, "@article{gaskell_latitudinal_2022,\n\ttitle = {The latitudinal temperature gradient and its climate dependence as inferred from foraminiferal δ{18O} over the past 95 million years},\n\tvolume = {119},\n\tdoi = {doi:10.1073/pnas.2111332119},\n\tnumber = {11},\n\tjournal = {Proceedings of the National Academy of Sciences},\n\tauthor = {Gaskell, Daniel E. and Huber, Matthew and O'Brien, Charlotte L. and Inglis, Gordon N. and Acosta, R. Paul and Poulsen, Christopher J. and Hull, Pincelli M.},\n\tmonth = mar,\n\tyear = {2022},\n\tpages = {e2111332119},\n}");
			mutate($data, "d18Osw_spatial", function($row) {return 0.0105*$row['temp_benthic'] - 0.000531*$row['temp_benthic']**2 + 0.00139*$row['pallat'] - 0.000143*$row['pallat']**2 - 0.000439*$row['temp_benthic']*$row['pallat'] + 2.79e-5*$row['temp_benthic']**2*$row['pallat'] - 8.35e-6*$row['temp_benthic']*$row['pallat']**2 + 1.78e-7*$row['temp_benthic']**2*$row['pallat']**2 + 0.415;});
			if ($valid_lat_end > 30) $valid_lat_end = 30;
			$digits['d18Osw_spatial'] = 2;
			$needed['d18Osw_spatial'] = TRUE;
			$needed['temp_benthic'] = TRUE;
			$needed['lat'] = TRUE;
			$needed['age'] = TRUE;
			break;
		case "gaskell_cesm":
			$description .= "Local variation in seawater &delta;<sup>18</sup>O was estimated after the method of Gaskell et al. (2022), using ";
			array_push($citations, "<li class='citation'>Gaskell, D.E., Huber, M., O’Brien, C.L., Inglis, G.N., Acosta, R.P., Poulsen, C.J., and Hull, P.M., 2022, The latitudinal temperature gradient and its climate dependence as inferred from foraminiferal &delta;<sup>18</sup>O over the past 95 million years: Proceedings of the National Academy of Sciences, v. 119, p. e2111332119, doi:doi:10.1073/pnas.2111332119.</li>");
			array_push($cite_bibtex, "@article{gaskell_latitudinal_2022,\n\ttitle = {The latitudinal temperature gradient and its climate dependence as inferred from foraminiferal δ{18O} over the past 95 million years},\n\tvolume = {119},\n\tdoi = {doi:10.1073/pnas.2111332119},\n\tnumber = {11},\n\tjournal = {Proceedings of the National Academy of Sciences},\n\tauthor = {Gaskell, Daniel E. and Huber, Matthew and O'Brien, Charlotte L. and Inglis, Gordon N. and Acosta, R. Paul and Poulsen, Christopher J. and Hull, Pincelli M.},\n\tmonth = mar,\n\tyear = {2022},\n\tpages = {e2111332119},\n}");

			// group by unique latlong for speed
			$groups = array();
			foreach ($data as $rowkey => $row) {
				$groups[$row['pallat'] . '-' . $row['pallong']] = array('pallat' => $row['pallat'], 'pallong' => $row['pallong'], 'temp_benthic' => $row['temp_benthic']);
			}
			
			// load datasets
			switch ($gcm) {
				case "gaskell":
					$description .= "isotope-enabled runs of the Community Earth System Model (CESM) with Miocene paleogeography (Gaskell et al., 2022) and ";
					$gcmdata = json_decode(file_get_contents("./data/gaskell_2022_miocene.json"), true);
					$gcmbwts = array(4.2, 5.2, 8.0); // these points from Gaskell et al. (2022) supplement
					array_push($citations, "<li class='citation'>Gaskell, D.E., Huber, M., O’Brien, C.L., Inglis, G.N., Acosta, R.P., Poulsen, C.J., and Hull, P.M., 2022, The latitudinal temperature gradient and its climate dependence as inferred from foraminiferal &delta;<sup>18</sup>O over the past 95 million years: Proceedings of the National Academy of Sciences, v. 119, p. e2111332119, doi:doi:10.1073/pnas.2111332119.</li>");
					array_push($cite_bibtex, "@article{gaskell_latitudinal_2022,\n\ttitle = {The latitudinal temperature gradient and its climate dependence as inferred from foraminiferal δ{18O} over the past 95 million years},\n\tvolume = {119},\n\tdoi = {doi:10.1073/pnas.2111332119},\n\tnumber = {11},\n\tjournal = {Proceedings of the National Academy of Sciences},\n\tauthor = {Gaskell, Daniel E. and Huber, Matthew and O'Brien, Charlotte L. and Inglis, Gordon N. and Acosta, R. Paul and Poulsen, Christopher J. and Hull, Pincelli M.},\n\tmonth = mar,\n\tyear = {2022},\n\tpages = {e2111332119},\n}");
					break;
				case "zhu":
					$description .= "isotope-enabled runs of the Community Earth System Model (CESM) with Eocene paleogeography (Zhu et al., 2020) and ";
					$gcmdata = json_decode(file_get_contents("./data/zhu_2020_eocene.json"), true);
					$gcmbwts = array(5.6, 12.4, 17.3, 23.4); // these points from Gaskell et al. (2022) supplement
					array_push($citations, "<li class='citation'>Zhu, J., Poulsen, C.J., Otto-Bliesner, B.L., Liu, Z., Brady, E.C., and Noone, D.C., 2020, Simulation of early Eocene water isotopes using an Earth system model and its implication for past climate reconstruction: Earth and Planetary Science Letters, v. 537, p. 116164, doi:10.1016/j.epsl.2020.116164.</li>");					
					array_push($cite_bibtex, "@article{zhu_simulation_2020,\n\ttitle = {Simulation of early {Eocene} water isotopes using an {Earth} system model and its implication for past climate reconstruction},\n\tvolume = {537},\n\tissn = {0012-821X},\n\turl = {http://www.sciencedirect.com/science/article/pii/S0012821X20301072},\n\tdoi = {10.1016/j.epsl.2020.116164},\n\tlanguage = {en},\n\turldate = {2021-01-18},\n\tjournal = {Earth and Planetary Science Letters},\n\tauthor = {Zhu, Jiang and Poulsen, Christopher J. and Otto-Bliesner, Bette L. and Liu, Zhengyu and Brady, Esther C. and Noone, David C.},\n\tmonth = may,\n\tyear = {2020},\n\tpages = {116164},\n}");
					break;
			}
			$description .= $description_temp . ", interpolating between model runs using natural cubic splines and ";
			
			// get spline-interpolated d18Osw for each latlong
			foreach ($groups as &$group) {
				$spline_y = array();
				foreach ($gcmbwts as $bwtkey => $bwt) {
					$spline_y[] = patch_mean($gcmdata, $group['pallat'], $group['pallong'], $square, $bwtkey);
				}
				$group['d18Osw_spatial'] = spline_interpolate($gcmbwts, $spline_y, array($group['temp_benthic']))[0];
			}
			
			// merge groups back into dataset
			mutate($data, "d18Osw_spatial", function($row) use ($groups) {return $groups[$row['pallat'] . '-' . $row['pallong']]['d18Osw_spatial'];});
			
			$digits['d18Osw_spatial'] = 2;
			$digits['sd'] = 2;
			$needed['d18Osw_spatial'] = TRUE;
			$needed['n'] = TRUE;
			$needed['sd'] = TRUE;
			$needed['temp_benthic'] = TRUE;
			$needed['lat'] = TRUE;
			$needed['long'] = TRUE;
			$needed['age'] = TRUE;
			break;
	}
	if (strpos($spatial, "legrande") === 0 || strpos($spatial, "tierney") === 0) {
		if (is_nan($square) or $square == 0) {
			$description .= "taking seawater &delta;<sup>18</sup>O values from the nearest point (by great circle distance) for which &delta;<sup>18</sup>O is defined. ";
		} else {
			if ($avgmethod == "degrees") {
				$description .= "taking the mean of seawater &delta;<sup>18</sup>O values within ±" . strval($square) . "&deg; latitude/longitude of each sample's location. ";
			} else {
				$description .= "taking the mean of seawater &delta;<sup>18</sup>O values within " . strval($square) . " km of each sample's location (by great circle distance). ";
			}
		}
	}
	$digits['temp_2.5'] = 2;
	$digits['temp'] = 2;
	$digits['temp_97.5'] = 2;
	switch ($calibration) {
		case "mccrea":
			$description = "the inorganic calibration of McCrea (1950), as reformulated in Bemis et al. (1998): "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>16.0</mn><mo> - </mo><mn>5.17</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.20</mn><mo>)</mo><mo> + </mo><msup><mrow><mn>0.09</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.20</mn><mo>)</mo></mrow><mn>2</mn></msup></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.20&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration (see Bemis et al. 1998). "
						 . $description;
			array_push($citations, "<li class='citation'>Bemis, B.E., Spero, H.J., Bijma, J., and Lea, D.W., 1998, Reevaluation of the oxygen isotopic composition of planktonic foraminifera: Experimental results and revised paleotemperature equations: Paleoceanography, v. 13, p. 150–160, doi:10.1029/98PA00070.</li>");
			array_push($citations, "<li class='citation'>McCrea, J.M., 1950, On the Isotopic Chemistry of Carbonates and a Paleotemperature Scale: The Journal of Chemical Physics, v. 18, p. 849–857, doi:10.1063/1.1747785.</li>");
			array_push($cite_bibtex, "@article{bemis_reevaluation_1998,\n\ttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera: {Experimental} results and revised paleotemperature equations},\n\tvolume = {13},\n\tissn = {1944-9186},\n\tshorttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera},\n\turl = {http://onlinelibrary.wiley.com/doi/10.1029/98PA00070/abstract},\n\tdoi = {10.1029/98PA00070},\n\tlanguage = {en},\n\tnumber = {2},\n\turldate = {2017-01-30},\n\tjournal = {Paleoceanography},\n\tauthor = {Bemis, Bryan E. and Spero, Howard J. and Bijma, Jelle and Lea, David W.},\n\tmonth = apr,\n\tyear = {1998},\n\tpages = {150--160},\n}");
			array_push($cite_bibtex, "@article{mccrea_isotopic_1950,\n\ttitle = {On the {Isotopic} {Chemistry} of {Carbonates} and a {Paleotemperature} {Scale}},\n\tvolume = {18},\n\tissn = {0021-9606},\n\turl = {https://aip.scitation.org/doi/10.1063/1.1747785},\n\tdoi = {10.1063/1.1747785},\n\tnumber = {6},\n\turldate = {2021-06-07},\n\tjournal = {The Journal of Chemical Physics},\n\tauthor = {McCrea, J. M.},\n\tmonth = jun,\n\tyear = {1950},\n\tpages = {849--857},\n}");
			mutate($data, "temp", function($row) {return 16.0 - 5.17*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.20)) + 0.09*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.20))**2;});
			if ($valid_temp_start < 14) $valid_temp_start = 14;
			if ($valid_temp_end > 57) $valid_temp_end = 57;
			break;
		case "epstein": # this formulation from Bemis et al. (1998), though note per Grossman (2012) that -0.27 is used instead of -0.20 for the VSMOW conversion
			$description = "the calibration of Epstein et al. (1953), as reformulated by Bemis et al. (1998): "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>16.5</mn><mo> - </mo><mn>4.30</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo><mo> + </mo><msup><mrow><mn>0.14</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></mrow><mn>2</mn></msup></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.27&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration (see Grossman 2012). "
						 . $description;
			array_push($citations, "<li class='citation'>Bemis, B.E., Spero, H.J., Bijma, J., and Lea, D.W., 1998, Reevaluation of the oxygen isotopic composition of planktonic foraminifera: Experimental results and revised paleotemperature equations: Paleoceanography, v. 13, p. 150–160, doi:10.1029/98PA00070.</li>");
			array_push($citations, "<li class='citation'>Grossman, E.L., 2012, Chapter 10 - Oxygen Isotope Stratigraphy, in Gradstein, F.M., Ogg, J.G., Schmitz, M.D., and Ogg, G.M. eds., The Geologic Time Scale, Boston, Elsevier, p. 181–206, doi:10.1016/B978-0-444-59425-9.00010-X.</li>");
			array_push($citations, "<li class='citation'>Epstein, S., Buchsbaum, R., Lowenstam, H.A., and Urey, H.C., 1953, Revised carbonate-water isotopic temperature scale: Geological Society of America Bulletin, v. 64, p. 1315–1326.</li>");
			array_push($cite_bibtex, "@article{bemis_reevaluation_1998,\n\ttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera: {Experimental} results and revised paleotemperature equations},\n\tvolume = {13},\n\tissn = {1944-9186},\n\tshorttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera},\n\turl = {http://onlinelibrary.wiley.com/doi/10.1029/98PA00070/abstract},\n\tdoi = {10.1029/98PA00070},\n\tlanguage = {en},\n\tnumber = {2},\n\turldate = {2017-01-30},\n\tjournal = {Paleoceanography},\n\tauthor = {Bemis, Bryan E. and Spero, Howard J. and Bijma, Jelle and Lea, David W.},\n\tmonth = apr,\n\tyear = {1998},\n\tpages = {150--160},\n}");
			array_push($cite_bibtex, "@incollection{grossman_chapter_2012,\n\taddress = {Boston},\n\ttitle = {Chapter 10 - {Oxygen} {Isotope} {Stratigraphy}},\n\tisbn = {978-0-444-59425-9},\n\turl = {https://www.sciencedirect.com/science/article/pii/B978044459425900010X},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tbooktitle = {The {Geologic} {Time} {Scale}},\n\tpublisher = {Elsevier},\n\tauthor = {Grossman, E. L.},\n\teditor = {Gradstein, Felix M. and Ogg, James G. and Schmitz, Mark D. and Ogg, Gabi M.},\n\tmonth = jan,\n\tyear = {2012},\n\tdoi = {10.1016/B978-0-444-59425-9.00010-X},\n\tpages = {181--206},\n}");
			array_push($cite_bibtex, "@article{epstein_revised_1953,\n\ttitle = {Revised carbonate-water isotopic temperature scale},\n\tvolume = {64},\n\tissn = {0016-7606},\n\turl = {https://resolver.caltech.edu/CaltechAUTHORS:20140806-161145877},\n\tnumber = {11},\n\turldate = {2021-06-26},\n\tjournal = {Geological Society of America Bulletin},\n\tauthor = {Epstein, S. and Buchsbaum, R. and Lowenstam, H. A. and Urey, H. C.},\n\tmonth = nov,\n\tyear = {1953},\n\tnote = {Number: 11\n\tpages = {1315--1326},\n}");
			mutate($data, "temp", function($row) {return 16.5 - 4.30*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27)) + 0.14*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27))**2;});
			if ($valid_temp_start < 7) $valid_temp_start = 7;
			if ($valid_temp_end > 30) $valid_temp_end = 30;
			break;
		case "oneil":
			$description = "the inorganic calibration of O'Neil et al. (1969), as reformulated by Bemis et al. (1998): "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>16.9</mn><mo> - </mo><mn>4.38</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.20</mn><mo>)</mo><mo> + </mo><msup><mrow><mn>0.10</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.20</mn><mo>)</mo></mrow><mn>2</mn></msup></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.20&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration (see Bemis et al. 1998). "
						 . $description;
			array_push($citations, "<li class='citation'>Bemis, B.E., Spero, H.J., Bijma, J., and Lea, D.W., 1998, Reevaluation of the oxygen isotopic composition of planktonic foraminifera: Experimental results and revised paleotemperature equations: Paleoceanography, v. 13, p. 150–160, doi:10.1029/98PA00070.</li>");
			array_push($citations, "<li class='citation'>O’Neil, J.R., Clayton, R.N., and Mayeda, T.K., 1969, Oxygen Isotope Fractionation in Divalent Metal Carbonates: The Journal of Chemical Physics, v. 51, p. 5547–5558, doi:10.1063/1.1671982.</li>");
			array_push($cite_bibtex, "@article{bemis_reevaluation_1998,\n\ttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera: {Experimental} results and revised paleotemperature equations},\n\tvolume = {13},\n\tissn = {1944-9186},\n\tshorttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera},\n\turl = {http://onlinelibrary.wiley.com/doi/10.1029/98PA00070/abstract},\n\tdoi = {10.1029/98PA00070},\n\tlanguage = {en},\n\tnumber = {2},\n\turldate = {2017-01-30},\n\tjournal = {Paleoceanography},\n\tauthor = {Bemis, Bryan E. and Spero, Howard J. and Bijma, Jelle and Lea, David W.},\n\tmonth = apr,\n\tyear = {1998},\n\tpages = {150--160},\n}");
			array_push($cite_bibtex, "@article{oneil_oxygen_1969,\n\ttitle = {Oxygen {Isotope} {Fractionation} in {Divalent} {Metal} {Carbonates}},\n\tvolume = {51},\n\tissn = {0021-9606},\n\turl = {https://aip.scitation.org/doi/abs/10.1063/1.1671982},\n\tdoi = {10.1063/1.1671982},\n\tnumber = {12},\n\turldate = {2021-06-26},\n\tjournal = {The Journal of Chemical Physics},\n\tauthor = {O'Neil, James R. and Clayton, Robert N. and Mayeda, Toshiko K.},\n\tmonth = dec,\n\tyear = {1969},\n\tpages = {5547--5558},\n}");
			mutate($data, "temp", function($row) {return 16.9 - 4.38*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.20)) + 0.10*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.20))**2;});
			if ($valid_temp_start < 0) $valid_temp_start = 0;
			if ($valid_temp_end > 500) $valid_temp_end = 500;
			break;
		case "shackleton":
			$description = "the benthic calibration of Shackleton (1974) for <i>Uvigerina</i> spp., as reformulated by Bemis et al. (1998): "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>16.9</mn><mo> - </mo><mn>4.0</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.20</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.20&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration (see Bemis et al. 1998). "
						 . $description;
			array_push($citations, "<li class='citation'>Bemis, B.E., Spero, H.J., Bijma, J., and Lea, D.W., 1998, Reevaluation of the oxygen isotopic composition of planktonic foraminifera: Experimental results and revised paleotemperature equations: Paleoceanography, v. 13, p. 150–160, doi:10.1029/98PA00070.</li>");
			array_push($citations, "<li class='citation'>Shackleton, N.J., 1974, Attainment of isotopic equilibrium between ocean water and the benthonic foraminifera genus Uvigerina : isotopic changes in the ocean during the last glacial.: Centre Natl. Rech. Sci. Coll. Inter., v. 219, p. 203–209.</li>");
			array_push($cite_bibtex, "@article{bemis_reevaluation_1998,\n\ttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera: {Experimental} results and revised paleotemperature equations},\n\tvolume = {13},\n\tissn = {1944-9186},\n\tshorttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera},\n\turl = {http://onlinelibrary.wiley.com/doi/10.1029/98PA00070/abstract},\n\tdoi = {10.1029/98PA00070},\n\tlanguage = {en},\n\tnumber = {2},\n\turldate = {2017-01-30},\n\tjournal = {Paleoceanography},\n\tauthor = {Bemis, Bryan E. and Spero, Howard J. and Bijma, Jelle and Lea, David W.},\n\tmonth = apr,\n\tyear = {1998},\n\tpages = {150--160},\n}");
			array_push($cite_bibtex, "@article{shackleton_attainment_1974,\n\ttitle = {Attainment of isotopic equilibrium between ocean water and the benthonic foraminifera genus {Unigerina} : isotopic changes in the ocean during the last glacial.},\n\tvolume = {219},\n\tshorttitle = {Attainment of isotopic equilibrium between ocean water and the benthonic foraminifera genus {Unigerina}},\n\turl = {https://ci.nii.ac.jp/naid/10003857096/},\n\turldate = {2021-06-26},\n\tjournal = {Centre Natl. Rech. Sci. Coll. Inter.},\n\tauthor = {Shackleton, N. J.},\n\tyear = {1974},\n\tpages = {203--209},\n}");
			mutate($data, "temp", function($row) {return 16.9 - 4.0*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.20));});
			if ($valid_temp_start < 0.8) $valid_temp_start = 0.8;
			if ($valid_temp_end > 7) $valid_temp_end = 7;
			break;
		case "erezluz":
			$description = "the calibration of Erez & Luz (1983) for <i>Trilobatus sacculifer</i>, as reformulated by Bemis et al. (1998): "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>17.0</mn><mo> - </mo><mn>4.52</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.22</mn><mo>)</mo><mo> + </mo><msup><mrow><mn>0.03</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.22</mn><mo>)</mo></mrow><mn>2</mn></msup></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.22&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration (see Bemis et al. 1998). "
						 . $description;
			array_push($citations, "<li class='citation'>Bemis, B.E., Spero, H.J., Bijma, J., and Lea, D.W., 1998, Reevaluation of the oxygen isotopic composition of planktonic foraminifera: Experimental results and revised paleotemperature equations: Paleoceanography, v. 13, p. 150–160, doi:10.1029/98PA00070.</li>");
			array_push($citations, "<li class='citation'>Erez, J., and Luz, B., 1983, Experimental paleotemperature equation for planktonic foraminifera: Geochimica et Cosmochimica Acta, v. 47, p. 1025–1031, doi:10.1016/0016-7037(83)90232-6.</li>");
			array_push($cite_bibtex, "@article{bemis_reevaluation_1998,\n\ttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera: {Experimental} results and revised paleotemperature equations},\n\tvolume = {13},\n\tissn = {1944-9186},\n\tshorttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera},\n\turl = {http://onlinelibrary.wiley.com/doi/10.1029/98PA00070/abstract},\n\tdoi = {10.1029/98PA00070},\n\tlanguage = {en},\n\tnumber = {2},\n\turldate = {2017-01-30},\n\tjournal = {Paleoceanography},\n\tauthor = {Bemis, Bryan E. and Spero, Howard J. and Bijma, Jelle and Lea, David W.},\n\tmonth = apr,\n\tyear = {1998},\n\tpages = {150--160},\n}");
			array_push($cite_bibtex, "@article{erez_experimental_1983,\n\ttitle = {Experimental paleotemperature equation for planktonic foraminifera},\n\tvolume = {47},\n\tissn = {0016-7037},\n\turl = {https://www.sciencedirect.com/science/article/pii/0016703783902326},\n\tdoi = {10.1016/0016-7037(83)90232-6},\n\tlanguage = {en},\n\tnumber = {6},\n\turldate = {2021-06-26},\n\tjournal = {Geochimica et Cosmochimica Acta},\n\tauthor = {Erez, Jonathan and Luz, Boaz},\n\tmonth = jun,\n\tyear = {1983},\n\tpages = {1025--1031},\n}");
			mutate($data, "temp", function($row) {return 17.0 - 4.52*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.22)) + 0.03*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.22))**2;});
			if ($valid_temp_start < 14) $valid_temp_start = 14;
			if ($valid_temp_end > 30) $valid_temp_end = 30;
			break;
		case "lynch":
			$description = "the surface sediment calibration of Lynch-Stieglitz et al. (1999) for <i>Cibicidoides</i> spp. and <i>Planulina</i> spp., as reformulated by Gaskell & Hull (2023): "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>16.1</mn><mo> - </mo><mn>4.76</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.27&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration (Hut 1987). "
						 . $description;
			array_push($citations, "<li class='citation'>Hut, G., 1987, Consultants’ group meeting on stable isotope reference samples for geochemical and hydrological investigations: International Atomic Energy Agency, Vienna (Austria), http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746.</li>");
			array_push($citations, "<li class='citation'>Lynch-Stieglitz, J., Curry, W.B., and Slowey, N., 1999, A geostrophic transport estimate for the Florida Current from the oxygen isotope value of benthic foraminifera: Paleoceanography, v. 14, p. 360–373, doi:10.1029/1999PA900001.</li>");
			array_push($cite_bibtex, "@article{hut_consultants_1987,\n\ttitle = {Consultants' group meeting on stable isotope reference samples for geochemical and hydrological investigations},\n\turl = {http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tjournal = {International Atomic Energy Agency, Vienna (Austria)},\n\tauthor = {Hut, G.},\n\tmonth = apr,\n\tyear = {1987},\n}");
			array_push($cite_bibtex, "@article{lynch-stieglitz_geostrophic_1999,\n\ttitle = {A geostrophic transport estimate for the {Florida} {Current} from the oxygen isotope composition of benthic foraminifera},\n\tvolume = {14},\n\tissn = {1944-9186},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/1999PA900001},\n\tdoi = {10.1029/1999PA900001},\n\tlanguage = {en},\n\tnumber = {3},\n\turldate = {2021-06-30},\n\tjournal = {Paleoceanography},\n\tauthor = {Lynch-Stieglitz, Jean and Curry, William B. and Slowey, Niall},\n\tyear = {1999},\n\tpages = {360--373},\n}");
			mutate($data, "temp", function($row) {return 16.1 - 4.76*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27));});
			if ($valid_temp_start < 4.1) $valid_temp_start = 4.1;
			if ($valid_temp_end > 25.6) $valid_temp_end = 25.6;
			break;
		case "marchitto_cib":
			$description = "the global core-top calibration of Marchitto et al. (2014) for <i>Cibicidoides</i> spp. and <i>Planulina</i> spp.: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mfrac><mrow><mn>0.245</mn><mo> - </mo><msqrt><mn>0.045461</mn><mo> + </mo><mn>0.0044</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo>)</mo></mrow><mrow><mn>0.0022</mn></mrow></mfrac></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). "
						 . $description;
			array_push($citations, "<li class='citation'>Marchitto, T.M., Curry, W.B., Lynch-Stieglitz, J., Bryan, S.P., Cobb, K.M., and Lund, D.C., 2014, Improved oxygen isotope temperature calibrations for cosmopolitan benthic foraminifera: Geochimica et Cosmochimica Acta, v. 130, p. 1–11, doi:10.1016/j.gca.2013.12.034.</li>");
			array_push($cite_bibtex, "@article{marchitto_improved_2014,\n\ttitle = {Improved oxygen isotope temperature calibrations for cosmopolitan benthic foraminifera},\n\tvolume = {130},\n\tissn = {0016-7037},\n\turl = {https://www.sciencedirect.com/science/article/pii/S0016703714000179},\n\tdoi = {10.1016/j.gca.2013.12.034},\n\tlanguage = {en},\n\turldate = {2022-06-09},\n\tjournal = {Geochimica et Cosmochimica Acta},\n\tauthor = {Marchitto, T. M. and Curry, W. B. and Lynch-Stieglitz, J. and Bryan, S. P. and Cobb, K. M. and Lund, D. C.},\n\tmonth = apr,\n\tyear = {2014},\n\tpages = {1--11},\n}");
			mutate($data, "temp", function($row) {return (0.245 - sqrt(0.045461 + 0.0044*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'])))) / 0.0022;});
			if ($valid_temp_start < -0.6) $valid_temp_start = -0.6;
			if ($valid_temp_end > 25.6) $valid_temp_end = 25.6;
			break;
		case "marchitto_per_rec":
			$description = "the recommended method of Marchitto et al. (2014) for <i>Uvigerina</i> spp., where values are adjusted to the <i>Cibicidoides</i> scale by subtracting 0.47&permil; and then applying the following global core-top calibration for <i>Cibicidoides</i> spp. and <i>Planulina</i> spp.: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mfrac><mrow><mn>0.245</mn><mo> - </mo><msqrt><mn>0.045461</mn><mo> + </mo><mn>0.0044</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><mn>0.47</mn><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo>)</mo></mrow><mrow><mn>0.0022</mn></mrow></mfrac></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). "
						 . $description;
			array_push($citations, "<li class='citation'>Marchitto, T.M., Curry, W.B., Lynch-Stieglitz, J., Bryan, S.P., Cobb, K.M., and Lund, D.C., 2014, Improved oxygen isotope temperature calibrations for cosmopolitan benthic foraminifera: Geochimica et Cosmochimica Acta, v. 130, p. 1–11, doi:10.1016/j.gca.2013.12.034.</li>");
			array_push($cite_bibtex, "@article{marchitto_improved_2014,\n\ttitle = {Improved oxygen isotope temperature calibrations for cosmopolitan benthic foraminifera},\n\tvolume = {130},\n\tissn = {0016-7037},\n\turl = {https://www.sciencedirect.com/science/article/pii/S0016703714000179},\n\tdoi = {10.1016/j.gca.2013.12.034},\n\tlanguage = {en},\n\turldate = {2022-06-09},\n\tjournal = {Geochimica et Cosmochimica Acta},\n\tauthor = {Marchitto, T. M. and Curry, W. B. and Lynch-Stieglitz, J. and Bryan, S. P. and Cobb, K. M. and Lund, D. C.},\n\tmonth = apr,\n\tyear = {2014},\n\tpages = {1--11},\n}");
			mutate($data, "temp", function($row) {return (0.245 - sqrt(0.045461 + 0.0044*($row['d18O_CO3'] - 0.47 - ($row['d18Osw_global'] + $row['d18Osw_spatial'])))) / 0.0022;});
			if ($valid_temp_start < -0.6) $valid_temp_start = -0.6;
			if ($valid_temp_end > 25.6) $valid_temp_end = 25.6;
			break;
		case "marchitto_per":
			$description = "the global core-top calibration of Marchitto et al. (2014) for <i>U. peregrina</i>, as reformulated by Gaskell & Hull (2022): "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mfrac><mrow><mn>0.242</mn><mo> - </mo><msqrt><mn>0.046468</mn><mo> + </mo><mn>0.0032</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo>)</mo></mrow><mrow><mn>0.0016</mn></mrow></mfrac></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.27&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration (Hut 1987). "
						 . $description;
			array_push($citations, "<li class='citation'>Hut, G., 1987, Consultants’ group meeting on stable isotope reference samples for geochemical and hydrological investigations: International Atomic Energy Agency, Vienna (Austria), http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746.</li>");
			array_push($citations, "<li class='citation'>Marchitto, T.M., Curry, W.B., Lynch-Stieglitz, J., Bryan, S.P., Cobb, K.M., and Lund, D.C., 2014, Improved oxygen isotope temperature calibrations for cosmopolitan benthic foraminifera: Geochimica et Cosmochimica Acta, v. 130, p. 1–11, doi:10.1016/j.gca.2013.12.034.</li>");
			array_push($cite_bibtex, "@article{hut_consultants_1987,\n\ttitle = {Consultants' group meeting on stable isotope reference samples for geochemical and hydrological investigations},\n\turl = {http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tjournal = {International Atomic Energy Agency, Vienna (Austria)},\n\tauthor = {Hut, G.},\n\tmonth = apr,\n\tyear = {1987},\n}");
			array_push($cite_bibtex, "@article{marchitto_improved_2014,\n\ttitle = {Improved oxygen isotope temperature calibrations for cosmopolitan benthic foraminifera},\n\tvolume = {130},\n\tissn = {0016-7037},\n\turl = {https://www.sciencedirect.com/science/article/pii/S0016703714000179},\n\tdoi = {10.1016/j.gca.2013.12.034},\n\tlanguage = {en},\n\turldate = {2022-06-09},\n\tjournal = {Geochimica et Cosmochimica Acta},\n\tauthor = {Marchitto, T. M. and Curry, W. B. and Lynch-Stieglitz, J. and Bryan, S. P. and Cobb, K. M. and Lund, D. C.},\n\tmonth = apr,\n\tyear = {2014},\n\tpages = {1--11},\n}");
			mutate($data, "temp", function($row) {return (0.242 - sqrt(0.046468 + 0.0032*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'])))) / 0.0016;});
			if ($valid_temp_start < 1.5) $valid_temp_start = 1.5;
			if ($valid_temp_end > 16.9) $valid_temp_end = 16.9;
			break;
		case "marchitto_ele":
			$description = "the global core-top calibration of Marchitto et al. (2014) for <i>H. elegans</i>, as reformulated by Gaskell & Hull (2022): "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mfrac><mrow><mn>0.242</mn><mo> - </mo><msqrt><mn>0.053176</mn><mo> + </mo><mn>0.0012</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo>)</mo></mrow><mrow><mn>0.0006</mn></mrow></mfrac></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.27&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration (Hut 1987). "
						 . $description;
			array_push($citations, "<li class='citation'>Hut, G., 1987, Consultants’ group meeting on stable isotope reference samples for geochemical and hydrological investigations: International Atomic Energy Agency, Vienna (Austria), http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746.</li>");
			array_push($citations, "<li class='citation'>Marchitto, T.M., Curry, W.B., Lynch-Stieglitz, J., Bryan, S.P., Cobb, K.M., and Lund, D.C., 2014, Improved oxygen isotope temperature calibrations for cosmopolitan benthic foraminifera: Geochimica et Cosmochimica Acta, v. 130, p. 1–11, doi:10.1016/j.gca.2013.12.034.</li>");
			array_push($cite_bibtex, "@article{hut_consultants_1987,\n\ttitle = {Consultants' group meeting on stable isotope reference samples for geochemical and hydrological investigations},\n\turl = {http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tjournal = {International Atomic Energy Agency, Vienna (Austria)},\n\tauthor = {Hut, G.},\n\tmonth = apr,\n\tyear = {1987},\n}");
			array_push($cite_bibtex, "@article{marchitto_improved_2014,\n\ttitle = {Improved oxygen isotope temperature calibrations for cosmopolitan benthic foraminifera},\n\tvolume = {130},\n\tissn = {0016-7037},\n\turl = {https://www.sciencedirect.com/science/article/pii/S0016703714000179},\n\tdoi = {10.1016/j.gca.2013.12.034},\n\tlanguage = {en},\n\turldate = {2022-06-09},\n\tjournal = {Geochimica et Cosmochimica Acta},\n\tauthor = {Marchitto, T. M. and Curry, W. B. and Lynch-Stieglitz, J. and Bryan, S. P. and Cobb, K. M. and Lund, D. C.},\n\tmonth = apr,\n\tyear = {2014},\n\tpages = {1--11},\n}");
			mutate($data, "temp", function($row) {return (0.242 - sqrt(0.053176 + 0.0012*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'])))) / 0.0006;});
			if ($valid_temp_start < 2.6) $valid_temp_start = 2.6;
			if ($valid_temp_end > 25.6) $valid_temp_end = 25.6;
			break;
		case "bouvier_orb1":
			$description = "the culture calibration of Bouvier-Soumagnac & Duplessy (1985) for <i>Orbulina universa</i>, as reformulated by Bemis et al. (1998): "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>16.4</mn><mo> - </mo><mn>4.67</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.20</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.20&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration (see Bemis et al. 1998). "
						 . $description;
			array_push($citations, "<li class='citation'>Bemis, B.E., Spero, H.J., Bijma, J., and Lea, D.W., 1998, Reevaluation of the oxygen isotopic composition of planktonic foraminifera: Experimental results and revised paleotemperature equations: Paleoceanography, v. 13, p. 150–160, doi:10.1029/98PA00070.</li>");
			array_push($citations, "<li class='citation'>Bouvier-Soumagnac, Y., and Duplessy, J.-C., 1985, Carbon and oxygen isotopic composition of planktonic foraminifera from laboratory culture, plankton tows and Recent sediment; implications for the reconstruction of paleoclimatic conditions and of the global carbon cycle: Journal of Foraminiferal Research, v. 15, p. 302–320, doi:10.2113/gsjfr.15.4.302.</li>");
			array_push($cite_bibtex, "@article{bemis_reevaluation_1998,\n\ttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera: {Experimental} results and revised paleotemperature equations},\n\tvolume = {13},\n\tissn = {1944-9186},\n\tshorttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera},\n\turl = {http://onlinelibrary.wiley.com/doi/10.1029/98PA00070/abstract},\n\tdoi = {10.1029/98PA00070},\n\tlanguage = {en},\n\tnumber = {2},\n\turldate = {2017-01-30},\n\tjournal = {Paleoceanography},\n\tauthor = {Bemis, Bryan E. and Spero, Howard J. and Bijma, Jelle and Lea, David W.},\n\tmonth = apr,\n\tyear = {1998},\n\tpages = {150--160},\n}");
			array_push($cite_bibtex, "@article{bouvier-soumagnac_carbon_1985,\n\ttitle = {Carbon and oxygen isotopic composition of planktonic foraminifera from laboratory culture, plankton tows and {Recent} sediment; implications for the reconstruction of paleoclimatic conditions and of the global carbon cycle},\n\tvolume = {15},\n\tissn = {0096-1191},\n\turl = {https://pubs.geoscienceworld.org/cushmanfoundation/jfr/article-abstract/15/4/302/76289/Carbon-and-oxygen-isotopic-composition-of},\n\tdoi = {10.2113/gsjfr.15.4.302},\n\tlanguage = {en},\n\tnumber = {4},\n\turldate = {2021-05-17},\n\tjournal = {Journal of Foraminiferal Research},\n\tauthor = {Bouvier-Soumagnac, Yael and Duplessy, Jean-Claude},\n\tmonth = oct,\n\tyear = {1985},\n\tpages = {302--320},\n}");
			mutate($data, "temp", function($row) {return 16.4 - 4.67*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.20));});
			if ($valid_temp_start < 20) $valid_temp_start = 20;
			if ($valid_temp_end > 25.2) $valid_temp_end = 25.2;
			break;
		case "bouvier_orb2":
			$description = "the Indian Ocean calibration of Bouvier-Soumagnac & Duplessy (1985) for <i>Orbulina universa</i>, as reformulated by Bemis et al. (1998): "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>15.4</mn><mo> - </mo><mn>4.81</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.20</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.20&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration (see Bemis et al. 1998). "
						 . $description;
			array_push($citations, "<li class='citation'>Bemis, B.E., Spero, H.J., Bijma, J., and Lea, D.W., 1998, Reevaluation of the oxygen isotopic composition of planktonic foraminifera: Experimental results and revised paleotemperature equations: Paleoceanography, v. 13, p. 150–160, doi:10.1029/98PA00070.</li>");
			array_push($citations, "<li class='citation'>Bouvier-Soumagnac, Y., and Duplessy, J.-C., 1985, Carbon and oxygen isotopic composition of planktonic foraminifera from laboratory culture, plankton tows and Recent sediment; implications for the reconstruction of paleoclimatic conditions and of the global carbon cycle: Journal of Foraminiferal Research, v. 15, p. 302–320, doi:10.2113/gsjfr.15.4.302.</li>");
			array_push($cite_bibtex, "@article{bemis_reevaluation_1998,\n\ttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera: {Experimental} results and revised paleotemperature equations},\n\tvolume = {13},\n\tissn = {1944-9186},\n\tshorttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera},\n\turl = {http://onlinelibrary.wiley.com/doi/10.1029/98PA00070/abstract},\n\tdoi = {10.1029/98PA00070},\n\tlanguage = {en},\n\tnumber = {2},\n\turldate = {2017-01-30},\n\tjournal = {Paleoceanography},\n\tauthor = {Bemis, Bryan E. and Spero, Howard J. and Bijma, Jelle and Lea, David W.},\n\tmonth = apr,\n\tyear = {1998},\n\tpages = {150--160},\n}");
			array_push($cite_bibtex, "@article{bouvier-soumagnac_carbon_1985,\n\ttitle = {Carbon and oxygen isotopic composition of planktonic foraminifera from laboratory culture, plankton tows and {Recent} sediment; implications for the reconstruction of paleoclimatic conditions and of the global carbon cycle},\n\tvolume = {15},\n\tissn = {0096-1191},\n\turl = {https://pubs.geoscienceworld.org/cushmanfoundation/jfr/article-abstract/15/4/302/76289/Carbon-and-oxygen-isotopic-composition-of},\n\tdoi = {10.2113/gsjfr.15.4.302},\n\tlanguage = {en},\n\tnumber = {4},\n\turldate = {2021-05-17},\n\tjournal = {Journal of Foraminiferal Research},\n\tauthor = {Bouvier-Soumagnac, Yael and Duplessy, Jean-Claude},\n\tmonth = oct,\n\tyear = {1985},\n\tpages = {302--320},\n}");
			mutate($data, "temp", function($row) {return 15.4 - 4.81*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.20));});
			if ($valid_temp_start < 20) $valid_temp_start = 20;
			if ($valid_temp_end > 29.5) $valid_temp_end = 29.5;
			break;
		case "bouvier_men":
			$description = "the Indian Ocean calibration of Bouvier-Soumagnac & Duplessy (1985) for <i>Globorotalia menardii</i>: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>14.6</mn><mo> - </mo><mn>5.03</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.20</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.20&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration (see Bemis et al. 1998). "
						 . $description;
			array_push($citations, "<li class='citation'>Bemis, B.E., Spero, H.J., Bijma, J., and Lea, D.W., 1998, Reevaluation of the oxygen isotopic composition of planktonic foraminifera: Experimental results and revised paleotemperature equations: Paleoceanography, v. 13, p. 150–160, doi:10.1029/98PA00070.</li>");
			array_push($citations, "<li class='citation'>Bouvier-Soumagnac, Y., and Duplessy, J.-C., 1985, Carbon and oxygen isotopic composition of planktonic foraminifera from laboratory culture, plankton tows and Recent sediment; implications for the reconstruction of paleoclimatic conditions and of the global carbon cycle: Journal of Foraminiferal Research, v. 15, p. 302–320, doi:10.2113/gsjfr.15.4.302.</li>");
			array_push($cite_bibtex, "@article{bemis_reevaluation_1998,\n\ttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera: {Experimental} results and revised paleotemperature equations},\n\tvolume = {13},\n\tissn = {1944-9186},\n\tshorttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera},\n\turl = {http://onlinelibrary.wiley.com/doi/10.1029/98PA00070/abstract},\n\tdoi = {10.1029/98PA00070},\n\tlanguage = {en},\n\tnumber = {2},\n\turldate = {2017-01-30},\n\tjournal = {Paleoceanography},\n\tauthor = {Bemis, Bryan E. and Spero, Howard J. and Bijma, Jelle and Lea, David W.},\n\tmonth = apr,\n\tyear = {1998},\n\tpages = {150--160},\n}");
			array_push($cite_bibtex, "@article{bouvier-soumagnac_carbon_1985,\n\ttitle = {Carbon and oxygen isotopic composition of planktonic foraminifera from laboratory culture, plankton tows and {Recent} sediment; implications for the reconstruction of paleoclimatic conditions and of the global carbon cycle},\n\tvolume = {15},\n\tissn = {0096-1191},\n\turl = {https://pubs.geoscienceworld.org/cushmanfoundation/jfr/article-abstract/15/4/302/76289/Carbon-and-oxygen-isotopic-composition-of},\n\tdoi = {10.2113/gsjfr.15.4.302},\n\tlanguage = {en},\n\tnumber = {4},\n\turldate = {2021-05-17},\n\tjournal = {Journal of Foraminiferal Research},\n\tauthor = {Bouvier-Soumagnac, Yael and Duplessy, Jean-Claude},\n\tmonth = oct,\n\tyear = {1985},\n\tpages = {302--320},\n}");
			mutate($data, "temp", function($row) {return 14.6 - 5.03*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.20));});
			if ($valid_temp_start < 24.6) $valid_temp_start = 22.6;
			if ($valid_temp_end > 29.2) $valid_temp_end = 29.2;
			break;
		case "bouvier_dut":
			$description = "the Indian Ocean calibration of Bouvier-Soumagnac & Duplessy (1985) for <i>Neogloboquadrina dutertrei</i>: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>10.5</mn><mo> - </mo><mn>6.58</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.20</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.20&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration (see Bemis et al. 1998). "
						 . $description;
			array_push($citations, "<li class='citation'>Bemis, B.E., Spero, H.J., Bijma, J., and Lea, D.W., 1998, Reevaluation of the oxygen isotopic composition of planktonic foraminifera: Experimental results and revised paleotemperature equations: Paleoceanography, v. 13, p. 150–160, doi:10.1029/98PA00070.</li>");
			array_push($citations, "<li class='citation'>Bouvier-Soumagnac, Y., and Duplessy, J.-C., 1985, Carbon and oxygen isotopic composition of planktonic foraminifera from laboratory culture, plankton tows and Recent sediment; implications for the reconstruction of paleoclimatic conditions and of the global carbon cycle: Journal of Foraminiferal Research, v. 15, p. 302–320, doi:10.2113/gsjfr.15.4.302.</li>");
			array_push($cite_bibtex, "@article{bemis_reevaluation_1998,\n\ttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera: {Experimental} results and revised paleotemperature equations},\n\tvolume = {13},\n\tissn = {1944-9186},\n\tshorttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera},\n\turl = {http://onlinelibrary.wiley.com/doi/10.1029/98PA00070/abstract},\n\tdoi = {10.1029/98PA00070},\n\tlanguage = {en},\n\tnumber = {2},\n\turldate = {2017-01-30},\n\tjournal = {Paleoceanography},\n\tauthor = {Bemis, Bryan E. and Spero, Howard J. and Bijma, Jelle and Lea, David W.},\n\tmonth = apr,\n\tyear = {1998},\n\tpages = {150--160},\n}");
			array_push($cite_bibtex, "@article{bouvier-soumagnac_carbon_1985,\n\ttitle = {Carbon and oxygen isotopic composition of planktonic foraminifera from laboratory culture, plankton tows and {Recent} sediment; implications for the reconstruction of paleoclimatic conditions and of the global carbon cycle},\n\tvolume = {15},\n\tissn = {0096-1191},\n\turl = {https://pubs.geoscienceworld.org/cushmanfoundation/jfr/article-abstract/15/4/302/76289/Carbon-and-oxygen-isotopic-composition-of},\n\tdoi = {10.2113/gsjfr.15.4.302},\n\tlanguage = {en},\n\tnumber = {4},\n\turldate = {2021-05-17},\n\tjournal = {Journal of Foraminiferal Research},\n\tauthor = {Bouvier-Soumagnac, Yael and Duplessy, Jean-Claude},\n\tmonth = oct,\n\tyear = {1985},\n\tpages = {302--320},\n}");
			mutate($data, "temp", function($row) {return 10.5 - 6.58*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.20));});
			if ($valid_temp_start < 22.6) $valid_temp_start = 24.6;
			if ($valid_temp_end > 30.6) $valid_temp_end = 30.6;
			break;
		case "kimoneil":
			$description = "the inorganic calibration of Kim & O'Neil (1997), as reformulated by Bemis et al. (1998): "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>16.1</mn><mo> - </mo><mn>4.64</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo><mo> + </mo><msup><mrow><mn>0.09</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></mrow><mn>2</mn></msup></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.27&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration (Hut 1987). "
						 . $description;
			array_push($citations, "<li class='citation'>Bemis, B.E., Spero, H.J., Bijma, J., and Lea, D.W., 1998, Reevaluation of the oxygen isotopic composition of planktonic foraminifera: Experimental results and revised paleotemperature equations: Paleoceanography, v. 13, p. 150–160, doi:10.1029/98PA00070.</li>");
			array_push($citations, "<li class='citation'>Hut, G., 1987, Consultants’ group meeting on stable isotope reference samples for geochemical and hydrological investigations: International Atomic Energy Agency, Vienna (Austria), http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746.</li>");
			array_push($citations, "<li class='citation'>Kim, S.-T., and O’Neil, J.R., 1997, Equilibrium and nonequilibrium oxygen isotope effects in synthetic carbonates: Geochimica et Cosmochimica Acta, v. 61, p. 3461–3475, doi:10.1016/S0016-7037(97)00169-5.</li>");
			array_push($cite_bibtex, "@article{bemis_reevaluation_1998,\n\ttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera: {Experimental} results and revised paleotemperature equations},\n\tvolume = {13},\n\tissn = {1944-9186},\n\tshorttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera},\n\turl = {http://onlinelibrary.wiley.com/doi/10.1029/98PA00070/abstract},\n\tdoi = {10.1029/98PA00070},\n\tlanguage = {en},\n\tnumber = {2},\n\turldate = {2017-01-30},\n\tjournal = {Paleoceanography},\n\tauthor = {Bemis, Bryan E. and Spero, Howard J. and Bijma, Jelle and Lea, David W.},\n\tmonth = apr,\n\tyear = {1998},\n\tpages = {150--160},\n}");
			array_push($cite_bibtex, "@article{hut_consultants_1987,\n\ttitle = {Consultants' group meeting on stable isotope reference samples for geochemical and hydrological investigations},\n\turl = {http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tjournal = {International Atomic Energy Agency, Vienna (Austria)},\n\tauthor = {Hut, G.},\n\tmonth = apr,\n\tyear = {1987},\n}");
			array_push($cite_bibtex, "@article{kim_equilibrium_1997,\n\ttitle = {Equilibrium and nonequilibrium oxygen isotope effects in synthetic carbonates},\n\tvolume = {61},\n\tissn = {0016-7037},\n\turl = {http://www.sciencedirect.com/science/article/pii/S0016703797001695},\n\tdoi = {10.1016/S0016-7037(97)00169-5},\n\tlanguage = {en},\n\tnumber = {16},\n\turldate = {2020-10-12},\n\tjournal = {Geochimica et Cosmochimica Acta},\n\tauthor = {Kim, Sang-Tae and O'Neil, James R.},\n\tmonth = aug,\n\tyear = {1997},\n\tpages = {3461--3475},\n}");
			mutate($data, "temp", function($row) {return 16.1 - 4.64*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27)) + 0.09*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27))**2;});
			if ($valid_temp_start < 0) $valid_temp_start = 0;
			if ($valid_temp_end > 40) $valid_temp_end = 40;
			break;
		case "mulitza_pool":
			$description = "the pooled planktonic foraminifera calibration of Mulitza et al. (2003): "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>14.32</mn><mo> - </mo><mn>4.28</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo><mo> + </mo><msup><mrow><mn>0.07</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></mrow><mn>2</mn></msup></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.27&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration (Hut 1987). "
						 . $description;
			array_push($citations, "<li class='citation'>Hut, G., 1987, Consultants’ group meeting on stable isotope reference samples for geochemical and hydrological investigations: International Atomic Energy Agency, Vienna (Austria), http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746.</li>");
			array_push($citations, "<li class='citation'>Mulitza, S., Donner, B., Fischer, G., Paul, A., Pätzold, J., Rühlemann, C., and Segl, M., 2004, The South Atlantic Oxygen Isotope Record of Planktic Foraminifera, in Wefer, G., Mulitza, S., and Ratmeyer, V. eds., The South Atlantic in the Late Quaternary: Reconstruction of Material Budgets and Current Systems, Berlin, Heidelberg, Springer, p. 121–142, doi:10.1007/978-3-642-18917-3_7.</li>");
			array_push($cite_bibtex, "@article{hut_consultants_1987,\n\ttitle = {Consultants' group meeting on stable isotope reference samples for geochemical and hydrological investigations},\n\turl = {http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tjournal = {International Atomic Energy Agency, Vienna (Austria)},\n\tauthor = {Hut, G.},\n\tmonth = apr,\n\tyear = {1987},\n}");
			array_push($cite_bibtex, "@incollection{mulitza_south_2004,\n\taddress = {Berlin, Heidelberg},\n\ttitle = {The {South} {Atlantic} {Oxygen} {Isotope} {Record} of {Planktic} {Foraminifera}},\n\tisbn = {978-3-642-18917-3},\n\turl = {https://doi.org/10.1007/978-3-642-18917-3_7},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tbooktitle = {The {South} {Atlantic} in the {Late} {Quaternary}: {Reconstruction} of {Material} {Budgets} and {Current} {Systems}},\n\tpublisher = {Springer},\n\tauthor = {Mulitza, S. and Donner, B. and Fischer, G. and Paul, A. and Pätzold, J. and Rühlemann, C. and Segl, M.},\n\teditor = {Wefer, Gerold and Mulitza, Stefan and Ratmeyer, Volker},\n\tyear = {2004},\n\tdoi = {10.1007/978-3-642-18917-3_7},\n\tpages = {121--142},\n}");
			mutate($data, "temp", function($row) {return 14.32 - 4.28*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27)) + 0.07*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27))**2;});
			if ($valid_temp_start < -2) $valid_temp_start = -2;
			if ($valid_temp_end > 31) $valid_temp_end = 31;
			break;
		case "mulitza_sac":
			$description = "the tow calibration of Mulitza et al. (2003) for <i>T. sacculifer</i>: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>14.91</mn><mo> - </mo><mn>4.35</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.27&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration (Hut 1987). "
						 . $description;
			array_push($citations, "<li class='citation'>Hut, G., 1987, Consultants’ group meeting on stable isotope reference samples for geochemical and hydrological investigations: International Atomic Energy Agency, Vienna (Austria), http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746.</li>");
			array_push($citations, "<li class='citation'>Mulitza, S., Boltovskoy, D., Donner, B., Meggers, H., Paul, A., and Wefer, G., 2003, Temperature:&delta;<sup>18</sup>O relationships of planktonic foraminifera collected from surface waters: Palaeogeography, Palaeoclimatology, Palaeoecology, v. 202, p. 143–152, doi:10.1016/S0031-0182(03)00633-3.</li>");
			array_push($cite_bibtex, "@article{hut_consultants_1987,\n\ttitle = {Consultants' group meeting on stable isotope reference samples for geochemical and hydrological investigations},\n\turl = {http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tjournal = {International Atomic Energy Agency, Vienna (Austria)},\n\tauthor = {Hut, G.},\n\tmonth = apr,\n\tyear = {1987},\n}");
			array_push($cite_bibtex, "@incollection{mulitza_south_2004,\n\taddress = {Berlin, Heidelberg},\n\ttitle = {The {South} {Atlantic} {Oxygen} {Isotope} {Record} of {Planktic} {Foraminifera}},\n\tisbn = {978-3-642-18917-3},\n\turl = {https://doi.org/10.1007/978-3-642-18917-3_7},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tbooktitle = {The {South} {Atlantic} in the {Late} {Quaternary}: {Reconstruction} of {Material} {Budgets} and {Current} {Systems}},\n\tpublisher = {Springer},\n\tauthor = {Mulitza, S. and Donner, B. and Fischer, G. and Paul, A. and Pätzold, J. and Rühlemann, C. and Segl, M.},\n\teditor = {Wefer, Gerold and Mulitza, Stefan and Ratmeyer, Volker},\n\tyear = {2004},\n\tdoi = {10.1007/978-3-642-18917-3_7},\n\tpages = {121--142},\n}");
			mutate($data, "temp", function($row) {return 14.91 - 4.35*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27));});
			if ($valid_temp_start < 16) $valid_temp_start = 16;
			if ($valid_temp_end > 31) $valid_temp_end = 31;
			break;
		case "mulitza_rub":
			$description = "the tow calibration of Mulitza et al. (2003) for <i>G. ruber</i> (white): "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>14.20</mn><mo> - </mo><mn>4.44</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.27&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration (Hut 1987). "
						 . $description;
			array_push($citations, "<li class='citation'>Hut, G., 1987, Consultants’ group meeting on stable isotope reference samples for geochemical and hydrological investigations: International Atomic Energy Agency, Vienna (Austria), http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746.</li>");
			array_push($citations, "<li class='citation'>Mulitza, S., Boltovskoy, D., Donner, B., Meggers, H., Paul, A., and Wefer, G., 2003, Temperature:&delta;<sup>18</sup>O relationships of planktonic foraminifera collected from surface waters: Palaeogeography, Palaeoclimatology, Palaeoecology, v. 202, p. 143–152, doi:10.1016/S0031-0182(03)00633-3.</li>");
			array_push($cite_bibtex, "@article{hut_consultants_1987,\n\ttitle = {Consultants' group meeting on stable isotope reference samples for geochemical and hydrological investigations},\n\turl = {http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tjournal = {International Atomic Energy Agency, Vienna (Austria)},\n\tauthor = {Hut, G.},\n\tmonth = apr,\n\tyear = {1987},\n}");
			array_push($cite_bibtex, "@incollection{mulitza_south_2004,\n\taddress = {Berlin, Heidelberg},\n\ttitle = {The {South} {Atlantic} {Oxygen} {Isotope} {Record} of {Planktic} {Foraminifera}},\n\tisbn = {978-3-642-18917-3},\n\turl = {https://doi.org/10.1007/978-3-642-18917-3_7},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tbooktitle = {The {South} {Atlantic} in the {Late} {Quaternary}: {Reconstruction} of {Material} {Budgets} and {Current} {Systems}},\n\tpublisher = {Springer},\n\tauthor = {Mulitza, S. and Donner, B. and Fischer, G. and Paul, A. and Pätzold, J. and Rühlemann, C. and Segl, M.},\n\teditor = {Wefer, Gerold and Mulitza, Stefan and Ratmeyer, Volker},\n\tyear = {2004},\n\tdoi = {10.1007/978-3-642-18917-3_7},\n\tpages = {121--142},\n}");
			mutate($data, "temp", function($row) {return 14.20 - 4.44*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27));});
			if ($valid_temp_start < 16) $valid_temp_start = 16;
			if ($valid_temp_end > 31) $valid_temp_end = 31;
			break;
		case "mulitza_bul":
			$description = "the tow calibration of Mulitza et al. (2003) for <i>G. bulloides</i>: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>14.62</mn><mo> - </mo><mn>4.70</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.27&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration (Hut 1987). "
						 . $description;
			array_push($citations, "<li class='citation'>Hut, G., 1987, Consultants’ group meeting on stable isotope reference samples for geochemical and hydrological investigations: International Atomic Energy Agency, Vienna (Austria), http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746.</li>");
			array_push($citations, "<li class='citation'>Mulitza, S., Boltovskoy, D., Donner, B., Meggers, H., Paul, A., and Wefer, G., 2003, Temperature:&delta;<sup>18</sup>O relationships of planktonic foraminifera collected from surface waters: Palaeogeography, Palaeoclimatology, Palaeoecology, v. 202, p. 143–152, doi:10.1016/S0031-0182(03)00633-3.</li>");
			array_push($cite_bibtex, "@article{hut_consultants_1987,\n\ttitle = {Consultants' group meeting on stable isotope reference samples for geochemical and hydrological investigations},\n\turl = {http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tjournal = {International Atomic Energy Agency, Vienna (Austria)},\n\tauthor = {Hut, G.},\n\tmonth = apr,\n\tyear = {1987},\n}");
			array_push($cite_bibtex, "@incollection{mulitza_south_2004,\n\taddress = {Berlin, Heidelberg},\n\ttitle = {The {South} {Atlantic} {Oxygen} {Isotope} {Record} of {Planktic} {Foraminifera}},\n\tisbn = {978-3-642-18917-3},\n\turl = {https://doi.org/10.1007/978-3-642-18917-3_7},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tbooktitle = {The {South} {Atlantic} in the {Late} {Quaternary}: {Reconstruction} of {Material} {Budgets} and {Current} {Systems}},\n\tpublisher = {Springer},\n\tauthor = {Mulitza, S. and Donner, B. and Fischer, G. and Paul, A. and Pätzold, J. and Rühlemann, C. and Segl, M.},\n\teditor = {Wefer, Gerold and Mulitza, Stefan and Ratmeyer, Volker},\n\tyear = {2004},\n\tdoi = {10.1007/978-3-642-18917-3_7},\n\tpages = {121--142},\n}");
			mutate($data, "temp", function($row) {return 14.62 - 4.70*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27));});
			if ($valid_temp_start < 1) $valid_temp_start = 1;
			if ($valid_temp_end > 25) $valid_temp_end = 25;
			break;
		case "mulitza_pac":
			$description = "the tow calibration of Mulitza et al. (2003) for <i>N. pachyderma</i>: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>12.69</mn><mo> - </mo><mn>3.55</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.27&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration (Hut 1987). "
						 . $description;
			array_push($citations, "<li class='citation'>Hut, G., 1987, Consultants’ group meeting on stable isotope reference samples for geochemical and hydrological investigations: International Atomic Energy Agency, Vienna (Austria), http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746.</li>");
			array_push($citations, "<li class='citation'>Mulitza, S., Boltovskoy, D., Donner, B., Meggers, H., Paul, A., and Wefer, G., 2003, Temperature:&delta;<sup>18</sup>O relationships of planktonic foraminifera collected from surface waters: Palaeogeography, Palaeoclimatology, Palaeoecology, v. 202, p. 143–152, doi:10.1016/S0031-0182(03)00633-3.</li>");
			array_push($cite_bibtex, "@article{hut_consultants_1987,\n\ttitle = {Consultants' group meeting on stable isotope reference samples for geochemical and hydrological investigations},\n\turl = {http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tjournal = {International Atomic Energy Agency, Vienna (Austria)},\n\tauthor = {Hut, G.},\n\tmonth = apr,\n\tyear = {1987},\n}");
			array_push($cite_bibtex, "@incollection{mulitza_south_2004,\n\taddress = {Berlin, Heidelberg},\n\ttitle = {The {South} {Atlantic} {Oxygen} {Isotope} {Record} of {Planktic} {Foraminifera}},\n\tisbn = {978-3-642-18917-3},\n\turl = {https://doi.org/10.1007/978-3-642-18917-3_7},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tbooktitle = {The {South} {Atlantic} in the {Late} {Quaternary}: {Reconstruction} of {Material} {Budgets} and {Current} {Systems}},\n\tpublisher = {Springer},\n\tauthor = {Mulitza, S. and Donner, B. and Fischer, G. and Paul, A. and Pätzold, J. and Rühlemann, C. and Segl, M.},\n\teditor = {Wefer, Gerold and Mulitza, Stefan and Ratmeyer, Volker},\n\tyear = {2004},\n\tdoi = {10.1007/978-3-642-18917-3_7},\n\tpages = {121--142},\n}");
			mutate($data, "temp", function($row) {return 12.69 - 3.55*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27));});
			if ($valid_temp_start < -2) $valid_temp_start = -2;
			if ($valid_temp_end > 13) $valid_temp_end = 13;
			break;
		case "bemis_mean":
			$description = "the average of the high- and low-light calibrations of Bemis et al. (1998) for <i>Orbulina universa</i>: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mo>[</mo><mn>16.5</mn><mo> - </mo><mn>4.80</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo><mo>]</mo><mo> + </mo><mo>[</mo><mn>14.9</mn><mo> - </mo><mn>4.80</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo><mo>]</mo></mrow><mrow><mn>2</mn></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). "
						 . $description;
			array_push($citations, "<li class='citation'>Bemis, B.E., Spero, H.J., Bijma, J., and Lea, D.W., 1998, Reevaluation of the oxygen isotopic composition of planktonic foraminifera: Experimental results and revised paleotemperature equations: Paleoceanography, v. 13, p. 150–160, doi:10.1029/98PA00070.</li>");
			array_push($cite_bibtex, "@article{bemis_reevaluation_1998,\n\ttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera: {Experimental} results and revised paleotemperature equations},\n\tvolume = {13},\n\tissn = {1944-9186},\n\tshorttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera},\n\turl = {http://onlinelibrary.wiley.com/doi/10.1029/98PA00070/abstract},\n\tdoi = {10.1029/98PA00070},\n\tlanguage = {en},\n\tnumber = {2},\n\turldate = {2017-01-30},\n\tjournal = {Paleoceanography},\n\tauthor = {Bemis, Bryan E. and Spero, Howard J. and Bijma, Jelle and Lea, David W.},\n\tmonth = apr,\n\tyear = {1998},\n\tpages = {150--160},\n}");
			mutate($data, "temp", function($row) {return ((16.5 - 4.80*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27))) + (14.9 - 4.80*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27)))) / 2;});
			if ($valid_temp_start < 15) $valid_temp_start = 15;
			if ($valid_temp_end > 25) $valid_temp_end = 25;
			break;
		case "bemis_ll":
			$description = "the low-light calibration of Bemis et al. (1998) for <i>Orbulina universa</i>: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>16.5</mn><mo> - </mo><mn>4.80</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). "
						 . $description;
			array_push($citations, "<li class='citation'>Bemis, B.E., Spero, H.J., Bijma, J., and Lea, D.W., 1998, Reevaluation of the oxygen isotopic composition of planktonic foraminifera: Experimental results and revised paleotemperature equations: Paleoceanography, v. 13, p. 150–160, doi:10.1029/98PA00070.</li>");
			array_push($cite_bibtex, "@article{bemis_reevaluation_1998,\n\ttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera: {Experimental} results and revised paleotemperature equations},\n\tvolume = {13},\n\tissn = {1944-9186},\n\tshorttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera},\n\turl = {http://onlinelibrary.wiley.com/doi/10.1029/98PA00070/abstract},\n\tdoi = {10.1029/98PA00070},\n\tlanguage = {en},\n\tnumber = {2},\n\turldate = {2017-01-30},\n\tjournal = {Paleoceanography},\n\tauthor = {Bemis, Bryan E. and Spero, Howard J. and Bijma, Jelle and Lea, David W.},\n\tmonth = apr,\n\tyear = {1998},\n\tpages = {150--160},\n}");
			mutate($data, "temp", function($row) {return 16.5 - 4.80*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27));});
			if ($valid_temp_start < 15) $valid_temp_start = 15;
			if ($valid_temp_end > 25) $valid_temp_end = 25;
			break;
		case "bemis_hl":
			$description = "the high-light calibration of Bemis et al. (1998) for <i>Orbulina universa</i>: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>14.9</mn><mo> - </mo><mn>4.80/mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). "
						 . $description;
			array_push($citations, "<li class='citation'>Bemis, B.E., Spero, H.J., Bijma, J., and Lea, D.W., 1998, Reevaluation of the oxygen isotopic composition of planktonic foraminifera: Experimental results and revised paleotemperature equations: Paleoceanography, v. 13, p. 150–160, doi:10.1029/98PA00070.</li>");
			array_push($cite_bibtex, "@article{bemis_reevaluation_1998,\n\ttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera: {Experimental} results and revised paleotemperature equations},\n\tvolume = {13},\n\tissn = {1944-9186},\n\tshorttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera},\n\turl = {http://onlinelibrary.wiley.com/doi/10.1029/98PA00070/abstract},\n\tdoi = {10.1029/98PA00070},\n\tlanguage = {en},\n\tnumber = {2},\n\turldate = {2017-01-30},\n\tjournal = {Paleoceanography},\n\tauthor = {Bemis, Bryan E. and Spero, Howard J. and Bijma, Jelle and Lea, David W.},\n\tmonth = apr,\n\tyear = {1998},\n\tpages = {150--160},\n}");
			mutate($data, "temp", function($row) {return 14.9 - 4.80*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27));});
			if ($valid_temp_start < 15) $valid_temp_start = 15;
			if ($valid_temp_end > 25) $valid_temp_end = 25;
			break;
		case "bemis_bul11":
			$description = "the 11th-chamber calibration of Bemis et al. (1998) for <i>Globigerina bulloides</i>: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>12.6</mn><mo> - </mo><mn>5.07/mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). "
						 . $description;
			array_push($citations, "<li class='citation'>Bemis, B.E., Spero, H.J., Bijma, J., and Lea, D.W., 1998, Reevaluation of the oxygen isotopic composition of planktonic foraminifera: Experimental results and revised paleotemperature equations: Paleoceanography, v. 13, p. 150–160, doi:10.1029/98PA00070.</li>");
			array_push($cite_bibtex, "@article{bemis_reevaluation_1998,\n\ttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera: {Experimental} results and revised paleotemperature equations},\n\tvolume = {13},\n\tissn = {1944-9186},\n\tshorttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera},\n\turl = {http://onlinelibrary.wiley.com/doi/10.1029/98PA00070/abstract},\n\tdoi = {10.1029/98PA00070},\n\tlanguage = {en},\n\tnumber = {2},\n\turldate = {2017-01-30},\n\tjournal = {Paleoceanography},\n\tauthor = {Bemis, Bryan E. and Spero, Howard J. and Bijma, Jelle and Lea, David W.},\n\tmonth = apr,\n\tyear = {1998},\n\tpages = {150--160},\n}");
			mutate($data, "temp", function($row) {return 12.6 - 5.07*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27));});
			if ($valid_temp_start < 15) $valid_temp_start = 15;
			if ($valid_temp_end > 24) $valid_temp_end = 24;
			break;
		case "bemis_bul12":
			$description = "the 12th-chamber calibration of Bemis et al. (1998) for <i>Globigerina bulloides</i>: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>13.2</mn><mo> - </mo><mn>4.89/mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). "
						 . $description;
			array_push($citations, "<li class='citation'>Bemis, B.E., Spero, H.J., Bijma, J., and Lea, D.W., 1998, Reevaluation of the oxygen isotopic composition of planktonic foraminifera: Experimental results and revised paleotemperature equations: Paleoceanography, v. 13, p. 150–160, doi:10.1029/98PA00070.</li>");
			array_push($cite_bibtex, "@article{bemis_reevaluation_1998,\n\ttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera: {Experimental} results and revised paleotemperature equations},\n\tvolume = {13},\n\tissn = {1944-9186},\n\tshorttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera},\n\turl = {http://onlinelibrary.wiley.com/doi/10.1029/98PA00070/abstract},\n\tdoi = {10.1029/98PA00070},\n\tlanguage = {en},\n\tnumber = {2},\n\turldate = {2017-01-30},\n\tjournal = {Paleoceanography},\n\tauthor = {Bemis, Bryan E. and Spero, Howard J. and Bijma, Jelle and Lea, David W.},\n\tmonth = apr,\n\tyear = {1998},\n\tpages = {150--160},\n}");
			mutate($data, "temp", function($row) {return 13.2 - 4.89*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27));});
			if ($valid_temp_start < 15) $valid_temp_start = 15;
			if ($valid_temp_end > 24) $valid_temp_end = 24;
			break;
		case "bemis_bul13":
			$description = "the 13th-chamber calibration of Bemis et al. (1998) for <i>Globigerina bulloides</i>: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>13.6</mn><mo> - </mo><mn>4.77/mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). "
						 . $description;
			array_push($citations, "<li class='citation'>Bemis, B.E., Spero, H.J., Bijma, J., and Lea, D.W., 1998, Reevaluation of the oxygen isotopic composition of planktonic foraminifera: Experimental results and revised paleotemperature equations: Paleoceanography, v. 13, p. 150–160, doi:10.1029/98PA00070.</li>");
			array_push($cite_bibtex, "@article{bemis_reevaluation_1998,\n\ttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera: {Experimental} results and revised paleotemperature equations},\n\tvolume = {13},\n\tissn = {1944-9186},\n\tshorttitle = {Reevaluation of the oxygen isotopic composition of planktonic foraminifera},\n\turl = {http://onlinelibrary.wiley.com/doi/10.1029/98PA00070/abstract},\n\tdoi = {10.1029/98PA00070},\n\tlanguage = {en},\n\tnumber = {2},\n\turldate = {2017-01-30},\n\tjournal = {Paleoceanography},\n\tauthor = {Bemis, Bryan E. and Spero, Howard J. and Bijma, Jelle and Lea, David W.},\n\tmonth = apr,\n\tyear = {1998},\n\tpages = {150--160},\n}");
			mutate($data, "temp", function($row) {return 13.6 - 4.77*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27));});
			if ($valid_temp_start < 15) $valid_temp_start = 15;
			if ($valid_temp_end > 24) $valid_temp_end = 24;
			break;
		case "juillet":
			$description = "the coral calibration of Juillet-Leclerc & Schmidt (2001) for <i>Porites</i> spp.: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>9.25</mn><mo> - </mo><mn>4.00</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.27&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration (Hut 1987). "
						 . $description;
			array_push($citations, "<li class='citation'>Hut, G., 1987, Consultants’ group meeting on stable isotope reference samples for geochemical and hydrological investigations: International Atomic Energy Agency, Vienna (Austria), http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746.</li>");
			array_push($citations, "<li class='citation'>Juillet-Leclerc, A., and Schmidt, G., 2001, A calibration of the oxygen isotope paleothermometer of coral aragonite from Porites: Geophysical Research Letters, v. 28, doi:10.1029/2000GL012538.</li>");
			array_push($cite_bibtex, "@article{hut_consultants_1987,\n\ttitle = {Consultants' group meeting on stable isotope reference samples for geochemical and hydrological investigations},\n\turl = {http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tjournal = {International Atomic Energy Agency, Vienna (Austria)},\n\tauthor = {Hut, G.},\n\tmonth = apr,\n\tyear = {1987},\n}");
			array_push($cite_bibtex, "@article{juillet-leclerc_calibration_2001,\n\ttitle = {A calibration of the oxygen isotope paleothermometer of coral aragonite from {Porites}},\n\tvolume = {28},\n\tdoi = {10.1029/2000GL012538},\n\tjournal = {Geophysical Research Letters},\n\tauthor = {Juillet-Leclerc, Anne and Schmidt, Gavin},\n\tmonth = nov,\n\tyear = {2001},\n}");
			mutate($data, "temp", function($row) {return 9.25 - 4.00*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27));});
			if ($valid_temp_start < 20) $valid_temp_start = 20;
			if ($valid_temp_end > 30) $valid_temp_end = 30;
			break;
		case "duplessy":
			$description = "the core-top calibration of Duplessey et al. (2002) for <i>Cibicides</i> spp., as formulated by Mulitza et al. (2003): "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>12.75</mn><mo> - </mo><mn>3.60</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.27&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration (Hut 1987). "
						 . $description;
			array_push($citations, "<li class='citation'>Hut, G., 1987, Consultants’ group meeting on stable isotope reference samples for geochemical and hydrological investigations: International Atomic Energy Agency, Vienna (Austria), http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746.</li>");
			array_push($citations, "<li class='citation'>Mulitza, S., Boltovskoy, D., Donner, B., Meggers, H., Paul, A., and Wefer, G., 2003, Temperature:&delta;<sup>18</sup>O relationships of planktonic foraminifera collected from surface waters: Palaeogeography, Palaeoclimatology, Palaeoecology, v. 202, p. 143–152, doi:10.1016/S0031-0182(03)00633-3.</li>");
			array_push($citations, "<li class='citation'>Duplessy, J.-C., Labeyrie, L., and Waelbroeck, C., 2002, Constraints on the ocean oxygen isotopic enrichment between the Last Glacial Maximum and the Holocene: Paleoceanographic implications: Quaternary Science Reviews, v. 21, p. 315–330, doi:10.1016/S0277-3791(01)00107-X.</li>");
			array_push($cite_bibtex, "@article{hut_consultants_1987,\n\ttitle = {Consultants' group meeting on stable isotope reference samples for geochemical and hydrological investigations},\n\turl = {http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tjournal = {International Atomic Energy Agency, Vienna (Austria)},\n\tauthor = {Hut, G.},\n\tmonth = apr,\n\tyear = {1987},\n}");
			array_push($cite_bibtex, "@article{mulitza_temperature18o_2003,\n\ttitle = {Temperature:δ{18O} relationships of planktonic foraminifera collected from surface waters},\n\tvolume = {202},\n\tissn = {0031-0182},\n\tshorttitle = {Temperature},\n\tdoi = {10.1016/S0031-0182(03)00633-3},\n\tjournal = {Palaeogeography, Palaeoclimatology, Palaeoecology},\n\tauthor = {Mulitza, Stefan and Boltovskoy, Demetrio and Donner, Barbara and Meggers, Helge and Paul, André and Wefer, Gerold},\n\tmonth = dec,\n\tyear = {2003},\n\tpages = {143--152},\n}");
			array_push($cite_bibtex, "@article{duplessy_constraints_2002,\n\tseries = {{EPILOG}},\n\ttitle = {Constraints on the ocean oxygen isotopic enrichment between the {Last} {Glacial} {Maximum} and the {Holocene}: {Paleoceanographic} implications},\n\tvolume = {21},\n\tissn = {0277-3791},\n\tshorttitle = {Constraints on the ocean oxygen isotopic enrichment between the {Last} {Glacial} {Maximum} and the {Holocene}},\n\turl = {https://www.sciencedirect.com/science/article/pii/S027737910100107X},\n\tdoi = {10.1016/S0277-3791(01)00107-X},\n\tlanguage = {en},\n\tnumber = {1},\n\turldate = {2021-06-29},\n\tjournal = {Quaternary Science Reviews},\n\tauthor = {Duplessy, Jean-Claude and Labeyrie, Laurent and Waelbroeck, Claire},\n\tmonth = jan,\n\tyear = {2002},\n\tpages = {315--330},\n}");
			mutate($data, "temp", function($row) {return 12.75 - 3.60*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27));});
			if ($valid_temp_start < -2) $valid_temp_start = -2;
			if ($valid_temp_end > 13) $valid_temp_end = 13;
			break;
		case "farmer_rubw":
			$description = "the Bayesian core-top calibration of Farmer et al. (2007) for <i>Globigerinoides ruber</i> (white): "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>15.4</mn><mo> - </mo><mn>4.78</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.27&#8240; is applied to convert VSMOW to VPBD (Hut 1987). "
						 . $description;
			array_push($citations, "<li class='citation'>Hut, G., 1987, Consultants’ group meeting on stable isotope reference samples for geochemical and hydrological investigations: International Atomic Energy Agency, Vienna (Austria), http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746.</li>");
			array_push($citations, "<li class='citation'>Farmer, E.C., Kaplan, A., Menocal, P.B. de, and Lynch-Stieglitz, J., 2007, Corroborating ecological depth preferences of planktonic foraminifera in the tropical Atlantic with the stable oxygen isotope ratios of core top specimens: Paleoceanography, v. 22, doi:10.1029/2006PA001361.</li>");
			array_push($cite_bibtex, "@article{hut_consultants_1987,\n\ttitle = {Consultants' group meeting on stable isotope reference samples for geochemical and hydrological investigations},\n\turl = {http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tjournal = {International Atomic Energy Agency, Vienna (Austria)},\n\tauthor = {Hut, G.},\n\tmonth = apr,\n\tyear = {1987},\n}");
			array_push($cite_bibtex, "@article{farmer_corroborating_2007,\n\ttitle = {Corroborating ecological depth preferences of planktonic foraminifera in the tropical {Atlantic} with the stable oxygen isotope ratios of core top specimens},\n\tvolume = {22},\n\tissn = {1944-9186},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2006PA001361},\n\tdoi = {10.1029/2006PA001361},\n\tlanguage = {en},\n\tnumber = {3},\n\turldate = {2021-06-29},\n\tjournal = {Paleoceanography},\n\tauthor = {Farmer, E. Christa and Kaplan, Alexey and Menocal, Peter B. de and Lynch-Stieglitz, Jean},\n\tyear = {2007},\n}");
			mutate($data, "temp", function($row) {return 15.4 - 4.78*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27));});
			break;
		case "farmer_rubp":
			$description = "the Bayesian core-top calibration of Farmer et al. (2007) for <i>Globigerinoides ruber</i> (pink): "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>14.7</mn><mo> - </mo><mn>4.86</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.27&#8240; is applied to convert VSMOW to VPBD (Hut 1987). "
						 . $description;
			array_push($citations, "<li class='citation'>Hut, G., 1987, Consultants’ group meeting on stable isotope reference samples for geochemical and hydrological investigations: International Atomic Energy Agency, Vienna (Austria), http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746.</li>");
			array_push($citations, "<li class='citation'>Farmer, E.C., Kaplan, A., Menocal, P.B. de, and Lynch-Stieglitz, J., 2007, Corroborating ecological depth preferences of planktonic foraminifera in the tropical Atlantic with the stable oxygen isotope ratios of core top specimens: Paleoceanography, v. 22, doi:10.1029/2006PA001361.</li>");
			array_push($cite_bibtex, "@article{hut_consultants_1987,\n\ttitle = {Consultants' group meeting on stable isotope reference samples for geochemical and hydrological investigations},\n\turl = {http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tjournal = {International Atomic Energy Agency, Vienna (Austria)},\n\tauthor = {Hut, G.},\n\tmonth = apr,\n\tyear = {1987},\n}");
			array_push($cite_bibtex, "@article{farmer_corroborating_2007,\n\ttitle = {Corroborating ecological depth preferences of planktonic foraminifera in the tropical {Atlantic} with the stable oxygen isotope ratios of core top specimens},\n\tvolume = {22},\n\tissn = {1944-9186},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2006PA001361},\n\tdoi = {10.1029/2006PA001361},\n\tlanguage = {en},\n\tnumber = {3},\n\turldate = {2021-06-29},\n\tjournal = {Paleoceanography},\n\tauthor = {Farmer, E. Christa and Kaplan, Alexey and Menocal, Peter B. de and Lynch-Stieglitz, Jean},\n\tyear = {2007},\n}");
			mutate($data, "temp", function($row) {return 14.7 - 4.86*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27));});
			break;
		case "farmer_sac":
			$description = "the Bayesian core-top calibration of Farmer et al. (2007) for <i>Trilobatus sacculifer</i>: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>16.2</mn><mo> - </mo><mn>4.94</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.27&#8240; is applied to convert VSMOW to VPBD (Hut 1987). "
						 . $description;
			array_push($citations, "<li class='citation'>Hut, G., 1987, Consultants’ group meeting on stable isotope reference samples for geochemical and hydrological investigations: International Atomic Energy Agency, Vienna (Austria), http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746.</li>");
			array_push($citations, "<li class='citation'>Farmer, E.C., Kaplan, A., Menocal, P.B. de, and Lynch-Stieglitz, J., 2007, Corroborating ecological depth preferences of planktonic foraminifera in the tropical Atlantic with the stable oxygen isotope ratios of core top specimens: Paleoceanography, v. 22, doi:10.1029/2006PA001361.</li>");
			array_push($cite_bibtex, "@article{hut_consultants_1987,\n\ttitle = {Consultants' group meeting on stable isotope reference samples for geochemical and hydrological investigations},\n\turl = {http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tjournal = {International Atomic Energy Agency, Vienna (Austria)},\n\tauthor = {Hut, G.},\n\tmonth = apr,\n\tyear = {1987},\n}");
			array_push($cite_bibtex, "@article{farmer_corroborating_2007,\n\ttitle = {Corroborating ecological depth preferences of planktonic foraminifera in the tropical {Atlantic} with the stable oxygen isotope ratios of core top specimens},\n\tvolume = {22},\n\tissn = {1944-9186},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2006PA001361},\n\tdoi = {10.1029/2006PA001361},\n\tlanguage = {en},\n\tnumber = {3},\n\turldate = {2021-06-29},\n\tjournal = {Paleoceanography},\n\tauthor = {Farmer, E. Christa and Kaplan, Alexey and Menocal, Peter B. de and Lynch-Stieglitz, Jean},\n\tyear = {2007},\n}");
			mutate($data, "temp", function($row) {return 16.2 - 4.94*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27));});
			break;
		case "farmer_orb":
			$description = "the Bayesian core-top calibration of Farmer et al. (2007) for <i>Orbulina universa</i>: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>16.5</mn><mo> - </mo><mn>5.11</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.27&#8240; is applied to convert VSMOW to VPBD (Hut 1987). "
						 . $description;
			array_push($citations, "<li class='citation'>Hut, G., 1987, Consultants’ group meeting on stable isotope reference samples for geochemical and hydrological investigations: International Atomic Energy Agency, Vienna (Austria), http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746.</li>");
			array_push($citations, "<li class='citation'>Farmer, E.C., Kaplan, A., Menocal, P.B. de, and Lynch-Stieglitz, J., 2007, Corroborating ecological depth preferences of planktonic foraminifera in the tropical Atlantic with the stable oxygen isotope ratios of core top specimens: Paleoceanography, v. 22, doi:10.1029/2006PA001361.</li>");
			array_push($cite_bibtex, "@article{hut_consultants_1987,\n\ttitle = {Consultants' group meeting on stable isotope reference samples for geochemical and hydrological investigations},\n\turl = {http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tjournal = {International Atomic Energy Agency, Vienna (Austria)},\n\tauthor = {Hut, G.},\n\tmonth = apr,\n\tyear = {1987},\n}");
			array_push($cite_bibtex, "@article{farmer_corroborating_2007,\n\ttitle = {Corroborating ecological depth preferences of planktonic foraminifera in the tropical {Atlantic} with the stable oxygen isotope ratios of core top specimens},\n\tvolume = {22},\n\tissn = {1944-9186},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2006PA001361},\n\tdoi = {10.1029/2006PA001361},\n\tlanguage = {en},\n\tnumber = {3},\n\turldate = {2021-06-29},\n\tjournal = {Paleoceanography},\n\tauthor = {Farmer, E. Christa and Kaplan, Alexey and Menocal, Peter B. de and Lynch-Stieglitz, Jean},\n\tyear = {2007},\n}");
			mutate($data, "temp", function($row) {return 16.5 - 5.11*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27));});
			break;
		case "farmer_obl":
			$description = "the Bayesian core-top calibration of Farmer et al. (2007) for <i>Pulleniatina obliquiloculata</i>: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>16.8</mn><mo> - </mo><mn>5.22</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.27&#8240; is applied to convert VSMOW to VPBD (Hut 1987). "
						 . $description;
			array_push($citations, "<li class='citation'>Hut, G., 1987, Consultants’ group meeting on stable isotope reference samples for geochemical and hydrological investigations: International Atomic Energy Agency, Vienna (Austria), http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746.</li>");
			array_push($citations, "<li class='citation'>Farmer, E.C., Kaplan, A., Menocal, P.B. de, and Lynch-Stieglitz, J., 2007, Corroborating ecological depth preferences of planktonic foraminifera in the tropical Atlantic with the stable oxygen isotope ratios of core top specimens: Paleoceanography, v. 22, doi:10.1029/2006PA001361.</li>");
			array_push($cite_bibtex, "@article{hut_consultants_1987,\n\ttitle = {Consultants' group meeting on stable isotope reference samples for geochemical and hydrological investigations},\n\turl = {http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tjournal = {International Atomic Energy Agency, Vienna (Austria)},\n\tauthor = {Hut, G.},\n\tmonth = apr,\n\tyear = {1987},\n}");
			array_push($cite_bibtex, "@article{farmer_corroborating_2007,\n\ttitle = {Corroborating ecological depth preferences of planktonic foraminifera in the tropical {Atlantic} with the stable oxygen isotope ratios of core top specimens},\n\tvolume = {22},\n\tissn = {1944-9186},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2006PA001361},\n\tdoi = {10.1029/2006PA001361},\n\tlanguage = {en},\n\tnumber = {3},\n\turldate = {2021-06-29},\n\tjournal = {Paleoceanography},\n\tauthor = {Farmer, E. Christa and Kaplan, Alexey and Menocal, Peter B. de and Lynch-Stieglitz, Jean},\n\tyear = {2007},\n}");
			mutate($data, "temp", function($row) {return 16.8 - 5.22*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27));});
			break;
		case "farmer_men":
			$description = "the Bayesian core-top calibration of Farmer et al. (2007) for <i>Globorotalia menardii</i>: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>16.6</mn><mo> - </mo><mn>5.20</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.27&#8240; is applied to convert VSMOW to VPBD (Hut 1987). "
						 . $description;
			array_push($citations, "<li class='citation'>Hut, G., 1987, Consultants’ group meeting on stable isotope reference samples for geochemical and hydrological investigations: International Atomic Energy Agency, Vienna (Austria), http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746.</li>");
			array_push($citations, "<li class='citation'>Farmer, E.C., Kaplan, A., Menocal, P.B. de, and Lynch-Stieglitz, J., 2007, Corroborating ecological depth preferences of planktonic foraminifera in the tropical Atlantic with the stable oxygen isotope ratios of core top specimens: Paleoceanography, v. 22, doi:10.1029/2006PA001361.</li>");
			array_push($cite_bibtex, "@article{hut_consultants_1987,\n\ttitle = {Consultants' group meeting on stable isotope reference samples for geochemical and hydrological investigations},\n\turl = {http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tjournal = {International Atomic Energy Agency, Vienna (Austria)},\n\tauthor = {Hut, G.},\n\tmonth = apr,\n\tyear = {1987},\n}");
			array_push($cite_bibtex, "@article{farmer_corroborating_2007,\n\ttitle = {Corroborating ecological depth preferences of planktonic foraminifera in the tropical {Atlantic} with the stable oxygen isotope ratios of core top specimens},\n\tvolume = {22},\n\tissn = {1944-9186},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2006PA001361},\n\tdoi = {10.1029/2006PA001361},\n\tlanguage = {en},\n\tnumber = {3},\n\turldate = {2021-06-29},\n\tjournal = {Paleoceanography},\n\tauthor = {Farmer, E. Christa and Kaplan, Alexey and Menocal, Peter B. de and Lynch-Stieglitz, Jean},\n\tyear = {2007},\n}");
			mutate($data, "temp", function($row) {return 16.6 - 5.20*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27));});
			break;
		case "farmer_dut":
			$description = "the Bayesian core-top calibration of Farmer et al. (2007) for <i>Neogloboquadrina dutertrei</i>: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>14.6</mn><mo> - </mo><mn>5.09</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.27&#8240; is applied to convert VSMOW to VPBD (Hut 1987). "
						 . $description;
			array_push($citations, "<li class='citation'>Hut, G., 1987, Consultants’ group meeting on stable isotope reference samples for geochemical and hydrological investigations: International Atomic Energy Agency, Vienna (Austria), http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746.</li>");
			array_push($citations, "<li class='citation'>Farmer, E.C., Kaplan, A., Menocal, P.B. de, and Lynch-Stieglitz, J., 2007, Corroborating ecological depth preferences of planktonic foraminifera in the tropical Atlantic with the stable oxygen isotope ratios of core top specimens: Paleoceanography, v. 22, doi:10.1029/2006PA001361.</li>");
			array_push($cite_bibtex, "@article{hut_consultants_1987,\n\ttitle = {Consultants' group meeting on stable isotope reference samples for geochemical and hydrological investigations},\n\turl = {http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tjournal = {International Atomic Energy Agency, Vienna (Austria)},\n\tauthor = {Hut, G.},\n\tmonth = apr,\n\tyear = {1987},\n}");
			array_push($cite_bibtex, "@article{farmer_corroborating_2007,\n\ttitle = {Corroborating ecological depth preferences of planktonic foraminifera in the tropical {Atlantic} with the stable oxygen isotope ratios of core top specimens},\n\tvolume = {22},\n\tissn = {1944-9186},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2006PA001361},\n\tdoi = {10.1029/2006PA001361},\n\tlanguage = {en},\n\tnumber = {3},\n\turldate = {2021-06-29},\n\tjournal = {Paleoceanography},\n\tauthor = {Farmer, E. Christa and Kaplan, Alexey and Menocal, Peter B. de and Lynch-Stieglitz, Jean},\n\tyear = {2007},\n}");
			mutate($data, "temp", function($row) {return 14.6 - 5.09*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27));});
			break;
		case "farmer_tum":
			$description = "the Bayesian core-top calibration of Farmer et al. (2007) for <i>Neogloboquadrina tumida</i>: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>13.1</mn><mo> - </mo><mn>4.95</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.27</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.27&#8240; is applied to convert VSMOW to VPBD (Hut 1987). "
						 . $description;
			array_push($citations, "<li class='citation'>Hut, G., 1987, Consultants’ group meeting on stable isotope reference samples for geochemical and hydrological investigations: International Atomic Energy Agency, Vienna (Austria), http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746.</li>");
			array_push($citations, "<li class='citation'>Farmer, E.C., Kaplan, A., Menocal, P.B. de, and Lynch-Stieglitz, J., 2007, Corroborating ecological depth preferences of planktonic foraminifera in the tropical Atlantic with the stable oxygen isotope ratios of core top specimens: Paleoceanography, v. 22, doi:10.1029/2006PA001361.</li>");
			array_push($cite_bibtex, "@article{hut_consultants_1987,\n\ttitle = {Consultants' group meeting on stable isotope reference samples for geochemical and hydrological investigations},\n\turl = {http://inis.iaea.org/Search/search.aspx?orig_q=RN:18075746},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tjournal = {International Atomic Energy Agency, Vienna (Austria)},\n\tauthor = {Hut, G.},\n\tmonth = apr,\n\tyear = {1987},\n}");
			array_push($cite_bibtex, "@article{farmer_corroborating_2007,\n\ttitle = {Corroborating ecological depth preferences of planktonic foraminifera in the tropical {Atlantic} with the stable oxygen isotope ratios of core top specimens},\n\tvolume = {22},\n\tissn = {1944-9186},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2006PA001361},\n\tdoi = {10.1029/2006PA001361},\n\tlanguage = {en},\n\tnumber = {3},\n\turldate = {2021-06-29},\n\tjournal = {Paleoceanography},\n\tauthor = {Farmer, E. Christa and Kaplan, Alexey and Menocal, Peter B. de and Lynch-Stieglitz, Jean},\n\tyear = {2007},\n}");
			mutate($data, "temp", function($row) {return 13.1 - 4.95*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.27));});
			break;
		case "bayfox_pooled":
			# Because bayfox is computationally expensive, we pre-compute the linear relationships of the posteriors and use them directly.
			# This approach is valid because bayfox fits a linear model internally, with the consequence that the residuals of bayfox vs.
			# this linear version are indistinguishable from bayfox's own random scatter when running replicates of the same sample.
			$description = "the bayfox annual core-top calibration of Malevich et al. (2019), with pooled species, as implemented by Gaskell & Hull (2022). " . $description;
			array_push($citations, "<li class='citation'>Malevich, S.B., Vetter, L., and Tierney, J.E., 2019, Global Core Top Calibration of &delta;<sup>18</sup>O in Planktic Foraminifera to Sea Surface Temperature: Paleoceanography and Paleoclimatology, v. 34, p. 1292–1315, doi:10.1029/2019PA003576.</li>");
			array_push($cite_bibtex, "@article{malevich_global_2019,\n\ttitle = {Global {Core} {Top} {Calibration} of δ{18O} in {Planktic} {Foraminifera} to {Sea} {Surface} {Temperature}},\n\tvolume = {34},\n\tissn = {2572-4525},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2019PA003576},\n\tdoi = {10.1029/2019PA003576},\n\tlanguage = {en},\n\tnumber = {8},\n\turldate = {2020-08-12},\n\tjournal = {Paleoceanography and Paleoclimatology},\n\tauthor = {Malevich, Steven B. and Vetter, Lael and Tierney, Jessica E.},\n\tyear = {2019},\n\tpages = {1292--1315},\n}");
			mutate($data, "temp_2.5",  function($row) {return 11.8790 - 4.0562*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial']));});
			mutate($data, "temp",   function($row) {return 16.3524 - 4.0556*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial']));});
			mutate($data, "temp_97.5", function($row) {return 20.8243 - 4.0549*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial']));});
			if ($valid_temp_start < 0) $valid_temp_start = 0;
			if ($valid_temp_end > 29.5) $valid_temp_end = 29.5;
			$needed['temp_2.5'] = TRUE;
			$needed['temp_97.5'] = TRUE;
			break;
		case "bayfox_ruber":
			$description = "the bayfox annual core-top calibration of Malevich et al. (2019) for <i>Globigerinoides ruber</i> as implemented by Gaskell & Hull (2022). " . $description;
			array_push($citations, "<li class='citation'>Malevich, S.B., Vetter, L., and Tierney, J.E., 2019, Global Core Top Calibration of &delta;<sup>18</sup>O in Planktic Foraminifera to Sea Surface Temperature: Paleoceanography and Paleoclimatology, v. 34, p. 1292–1315, doi:10.1029/2019PA003576.</li>");
			array_push($cite_bibtex, "@article{malevich_global_2019,\n\ttitle = {Global {Core} {Top} {Calibration} of δ{18O} in {Planktic} {Foraminifera} to {Sea} {Surface} {Temperature}},\n\tvolume = {34},\n\tissn = {2572-4525},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2019PA003576},\n\tdoi = {10.1029/2019PA003576},\n\tlanguage = {en},\n\tnumber = {8},\n\turldate = {2020-08-12},\n\tjournal = {Paleoceanography and Paleoclimatology},\n\tauthor = {Malevich, Steven B. and Vetter, Lael and Tierney, Jessica E.},\n\tyear = {2019},\n\tpages = {1292--1315},\n}");
			mutate($data, "temp_2.5",  function($row) {return  8.6827 - 5.3030*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial']));});
			mutate($data, "temp",   function($row) {return 13.0681 - 5.2605*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial']));});
			mutate($data, "temp_97.5", function($row) {return 17.4007 - 5.2366*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial']));});
			if ($valid_temp_start < 0) $valid_temp_start = 0;
			if ($valid_temp_end > 29.5) $valid_temp_end = 29.5;
			$needed['temp_2.5'] = TRUE;
			$needed['temp_97.5'] = TRUE;
			break;
		case "bayfox_sac":
			$description = "the bayfox annual core-top calibration of Malevich et al. (2019) for <i>Trilobatus sacculifer</i> as implemented by Gaskell & Hull (2022). " . $description;
			array_push($citations, "<li class='citation'>Malevich, S.B., Vetter, L., and Tierney, J.E., 2019, Global Core Top Calibration of &delta;<sup>18</sup>O in Planktic Foraminifera to Sea Surface Temperature: Paleoceanography and Paleoclimatology, v. 34, p. 1292–1315, doi:10.1029/2019PA003576.</li>");
			array_push($cite_bibtex, "@article{malevich_global_2019,\n\ttitle = {Global {Core} {Top} {Calibration} of δ{18O} in {Planktic} {Foraminifera} to {Sea} {Surface} {Temperature}},\n\tvolume = {34},\n\tissn = {2572-4525},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2019PA003576},\n\tdoi = {10.1029/2019PA003576},\n\tlanguage = {en},\n\tnumber = {8},\n\turldate = {2020-08-12},\n\tjournal = {Paleoceanography and Paleoclimatology},\n\tauthor = {Malevich, Steven B. and Vetter, Lael and Tierney, Jessica E.},\n\tyear = {2019},\n\tpages = {1292--1315},\n}");
			mutate($data, "temp_2.5",  function($row) {return  6.8415 - 6.4534*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial']));});
			mutate($data, "temp",   function($row) {return 12.4053 - 6.3458*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial']));});
			mutate($data, "temp_97.5", function($row) {return 17.8395 - 6.2936*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial']));});
			if ($valid_temp_start < 0) $valid_temp_start = 0;
			if ($valid_temp_end > 29.5) $valid_temp_end = 29.5;
			$needed['temp_2.5'] = TRUE;
			$needed['temp_97.5'] = TRUE;
			break;
		case "bayfox_bul":
			$description = "the bayfox annual core-top calibration of Malevich et al. (2019) for <i>Globigerina bulloides</i> as implemented by Gaskell & Hull (2022). " . $description;
			array_push($citations, "<li class='citation'>Malevich, S.B., Vetter, L., and Tierney, J.E., 2019, Global Core Top Calibration of &delta;<sup>18</sup>O in Planktic Foraminifera to Sea Surface Temperature: Paleoceanography and Paleoclimatology, v. 34, p. 1292–1315, doi:10.1029/2019PA003576.</li>");
			array_push($cite_bibtex, "@article{malevich_global_2019,\n\ttitle = {Global {Core} {Top} {Calibration} of δ{18O} in {Planktic} {Foraminifera} to {Sea} {Surface} {Temperature}},\n\tvolume = {34},\n\tissn = {2572-4525},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2019PA003576},\n\tdoi = {10.1029/2019PA003576},\n\tlanguage = {en},\n\tnumber = {8},\n\turldate = {2020-08-12},\n\tjournal = {Paleoceanography and Paleoclimatology},\n\tauthor = {Malevich, Steven B. and Vetter, Lael and Tierney, Jessica E.},\n\tyear = {2019},\n\tpages = {1292--1315},\n}");
			mutate($data, "temp_2.5",  function($row) {return 11.6699 - 4.1260*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial']));});
			mutate($data, "temp",   function($row) {return 16.6159 - 4.1291*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial']));});
			mutate($data, "temp_97.5", function($row) {return 21.5757 - 4.1348*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial']));});
			if ($valid_temp_start < 0) $valid_temp_start = 0;
			if ($valid_temp_end > 29.5) $valid_temp_end = 29.5;
			$needed['temp_2.5'] = TRUE;
			$needed['temp_97.5'] = TRUE;
			break;
		case "bayfox_inc":
			$description = "the bayfox annual core-top calibration of Malevich et al. (2019) for <i>Neogloboquadrina incompta</i> as implemented by Gaskell & Hull (2022). " . $description;
			array_push($citations, "<li class='citation'>Malevich, S.B., Vetter, L., and Tierney, J.E., 2019, Global Core Top Calibration of &delta;<sup>18</sup>O in Planktic Foraminifera to Sea Surface Temperature: Paleoceanography and Paleoclimatology, v. 34, p. 1292–1315, doi:10.1029/2019PA003576.</li>");
			array_push($cite_bibtex, "@article{malevich_global_2019,\n\ttitle = {Global {Core} {Top} {Calibration} of δ{18O} in {Planktic} {Foraminifera} to {Sea} {Surface} {Temperature}},\n\tvolume = {34},\n\tissn = {2572-4525},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2019PA003576},\n\tdoi = {10.1029/2019PA003576},\n\tlanguage = {en},\n\tnumber = {8},\n\turldate = {2020-08-12},\n\tjournal = {Paleoceanography and Paleoclimatology},\n\tauthor = {Malevich, Steven B. and Vetter, Lael and Tierney, Jessica E.},\n\tyear = {2019},\n\tpages = {1292--1315},\n}");
			mutate($data, "temp_2.5",  function($row) {return 11.5827 - 5.7159*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial']));});
			mutate($data, "temp",   function($row) {return 17.9531 - 5.7401*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial']));});
			mutate($data, "temp_97.5", function($row) {return 24.5124 - 5.8647*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial']));});
			if ($valid_temp_start < 0) $valid_temp_start = 0;
			if ($valid_temp_end > 29.5) $valid_temp_end = 29.5;
			$needed['temp_2.5'] = TRUE;
			$needed['temp_97.5'] = TRUE;
			break;
		case "bayfox_pac":
			$description = "the bayfox annual core-top calibration of Malevich et al. (2019) for <i>Neogloboquadrina pachyderma</i> as implemented by Gaskell & Hull (2022). " . $description;
			array_push($citations, "<li class='citation'>Malevich, S.B., Vetter, L., and Tierney, J.E., 2019, Global Core Top Calibration of &delta;<sup>18</sup>O in Planktic Foraminifera to Sea Surface Temperature: Paleoceanography and Paleoclimatology, v. 34, p. 1292–1315, doi:10.1029/2019PA003576.</li>");
			array_push($cite_bibtex, "@article{malevich_global_2019,\n\ttitle = {Global {Core} {Top} {Calibration} of δ{18O} in {Planktic} {Foraminifera} to {Sea} {Surface} {Temperature}},\n\tvolume = {34},\n\tissn = {2572-4525},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/2019PA003576},\n\tdoi = {10.1029/2019PA003576},\n\tlanguage = {en},\n\tnumber = {8},\n\turldate = {2020-08-12},\n\tjournal = {Paleoceanography and Paleoclimatology},\n\tauthor = {Malevich, Steven B. and Vetter, Lael and Tierney, Jessica E.},\n\tyear = {2019},\n\tpages = {1292--1315},\n}");
			mutate($data, "temp_2.5",  function($row) {return 14.8492 - 4.9474*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial']));});
			mutate($data, "temp",   function($row) {return 19.8109 - 4.9853*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial']));});
			mutate($data, "temp_97.5", function($row) {return 24.8827 - 5.0491*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial']));});
			if ($valid_temp_start < 0) $valid_temp_start = 0;
			if ($valid_temp_end > 29.5) $valid_temp_end = 29.5;
			$needed['temp_2.5'] = TRUE;
			$needed['temp_97.5'] = TRUE;
			break;
		case "willmes":
			// Note for future features: Willmes et al. (2019) cite error of +/- 1 degree
			$description = "the otolith calibration of Willmes et al. (2019) for <i>Hypomesus transpacificus</i>: "
						 . "<div class='math'><math><mn>1000</mn><mo> ln </mo><mi>&alpha;</mi><mo> = </mo><mn>18.39</mn><mo>(</mo><msup><mn>10</mn><mn>3</mn></msup><msup><mi>TK</mi><mn>-1</mn></msup><mo>)</mo><mo> - </mo><mn>34.56</mn></math></div>"
						 . "<div class='math'><math><mi>&alpha;</mi><mo> = </mo><mfrac><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> + </mo><mn>1000</mn></mrow><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> + </mo><mn>1000</mn></mrow></mfrac></math></div>"
						 . "where <i>TK</i> is temperature (in Kelvin), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VPBD). The conversion <div class='math'><math><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VPBD)</mtext></msub><mo> = </mo><mn>0.97001</mn><mo> &times; </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VSMOW)</mtext></msub><mo> - </mo><mn>29.99</mn></math></div> was used to convert seawater &delta;<sup>18</sup>O from VSMOW to VPBD (Brand et al. 2014). "
						 . $description;
			array_push($citations, "<li class='citation'>Brand, W.A., Coplen, T.B., Vogl, J., Rosner, M., and Prohaska, T., 2014, Assessment of international reference materials for isotope-ratio analysis (IUPAC Technical Report): Pure and Applied Chemistry, v. 86, p. 425–467.</li>");
			array_push($citations, "<li class='citation'>Willmes, M. et al., 2019, Calibrating temperature reconstructions from fish otolith oxygen isotope analysis for California’s critically endangered Delta Smelt: Rapid Communications in Mass Spectrometry, v. 33, p. 1207–1220, doi:10.1002/rcm.8464.</li>");
			array_push($cite_bibtex, "@article{brand_assessment_2014,\n\ttitle = {Assessment of international reference materials for isotope-ratio analysis ({IUPAC} {Technical} {Report})},\n\tvolume = {86},\n\tnumber = {3},\n\tjournal = {Pure and Applied Chemistry},\n\tauthor = {Brand, Willi A and Coplen, Tyler B and Vogl, Jochen and Rosner, Martin and Prohaska, Thomas},\n\tyear = {2014},\n\tpages = {425--467},\n}");
			array_push($cite_bibtex, "@article{willmes_calibrating_2019,\n\ttitle = {Calibrating temperature reconstructions from fish otolith oxygen isotope analysis for {California}'s critically endangered {Delta} {Smelt}},\n\tvolume = {33},\n\tissn = {1097-0231},\n\turl = {https://analyticalsciencejournals.onlinelibrary.wiley.com/doi/abs/10.1002/rcm.8464},\n\tdoi = {10.1002/rcm.8464},\n\tlanguage = {en},\n\tnumber = {14},\n\turldate = {2021-06-25},\n\tjournal = {Rapid Communications in Mass Spectrometry},\n\tauthor = {Willmes, Malte and Lewis, Levi S. and Davis, Brittany E. and Loiselle, Liane and James, Hannah F. and Denny, Christian and Baxter, Randall and Conrad, J. Louise and Fangue, Nann A. and Hung, Tien-Chieh and Armstrong, Richard A. and Williams, Ian S. and Holden, Peter and Hobbs, James A.},\n\tyear = {2019},\n\tpages = {1207--1220},\n}");
			mutate($data, "temp", function($row) {return (18.39*1000 / (34.56 + 1000*log((1000 + $row['d18O_CO3'])/(1000 + (0.97001*($row['d18Osw_global'] + $row['d18Osw_spatial']) - 29.99)))) - 273.15);});
			if ($valid_temp_start < 16.4) $valid_temp_start = 16.4;
			if ($valid_temp_end > 20.5) $valid_temp_end = 20.5;
			break;
		case "thorrold":
			$description = "the otolith calibration of Thorrold et al. (1997) for <i>Micropogonias undulatus</i>, as standardized by Willmes et al. (2019): "
						 . "<div class='math'><math><mn>1000</mn><mo> ln </mo><mi>&alpha;</mi><mo> = </mo><mn>18.57</mn><mo>(</mo><msup><mn>10</mn><mn>3</mn></msup><msup><mi>TK</mi><mn>-1</mn></msup><mo>)</mo><mo> - </mo><mn>32.54</mn></math></div>"
						 . "<div class='math'><math><mi>&alpha;</mi><mo> = </mo><mfrac><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> + </mo><mn>1000</mn></mrow><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> + </mo><mn>1000</mn></mrow></mfrac></math></div>"
						 . "where <i>TK</i> is temperature (in Kelvin), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VPBD). The conversion <div class='math'><math><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VPBD)</mtext></msub><mo> = </mo><mn>0.97001</mn><mo> &times; </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VSMOW)</mtext></msub><mo> - </mo><mn>29.99</mn></math></div> was used to convert seawater &delta;<sup>18</sup>O from VSMOW to VPBD (Brand et al. 2014). "
						 . $description;
			array_push($citations, "<li class='citation'>Brand, W.A., Coplen, T.B., Vogl, J., Rosner, M., and Prohaska, T., 2014, Assessment of international reference materials for isotope-ratio analysis (IUPAC Technical Report): Pure and Applied Chemistry, v. 86, p. 425–467.</li>");
			array_push($citations, "<li class='citation'>Thorrold, S.R., Campana, S.E., Jones, C.M., and Swart, P.K., 1997, Factors determining δ13C and &delta;<sup>18</sup>O fractionation in aragonitic otoliths of marine fish: Geochimica et Cosmochimica Acta, v. 61, p. 2909–2919, doi:10.1016/S0016-7037(97)00141-5.</li>");
			array_push($citations, "<li class='citation'>Willmes, M. et al., 2019, Calibrating temperature reconstructions from fish otolith oxygen isotope analysis for California’s critically endangered Delta Smelt: Rapid Communications in Mass Spectrometry, v. 33, p. 1207–1220, doi:10.1002/rcm.8464.</li>");
			array_push($cite_bibtex, "@article{brand_assessment_2014,\n\ttitle = {Assessment of international reference materials for isotope-ratio analysis ({IUPAC} {Technical} {Report})},\n\tvolume = {86},\n\tnumber = {3},\n\tjournal = {Pure and Applied Chemistry},\n\tauthor = {Brand, Willi A and Coplen, Tyler B and Vogl, Jochen and Rosner, Martin and Prohaska, Thomas},\n\tyear = {2014},\n\tpages = {425--467},\n}");
			array_push($cite_bibtex, "@article{thorrold_factors_1997,\n\ttitle = {Factors determining δ{13C} and δ{18O} fractionation in aragonitic otoliths of marine fish},\n\tvolume = {61},\n\tissn = {0016-7037},\n\turl = {https://www.sciencedirect.com/science/article/pii/S0016703797001415},\n\tdoi = {10.1016/S0016-7037(97)00141-5},\n\tlanguage = {en},\n\tnumber = {14},\n\turldate = {2021-06-25},\n\tjournal = {Geochimica et Cosmochimica Acta},\n\tauthor = {Thorrold, Simon R. and Campana, Steven E. and Jones, Cynthia M. and Swart, Peter K.},\n\tmonth = jul,\n\tyear = {1997},\n\tpages = {2909--2919},\n}");
			array_push($cite_bibtex, "@article{willmes_calibrating_2019,\n\ttitle = {Calibrating temperature reconstructions from fish otolith oxygen isotope analysis for {California}'s critically endangered {Delta} {Smelt}},\n\tvolume = {33},\n\tissn = {1097-0231},\n\turl = {https://analyticalsciencejournals.onlinelibrary.wiley.com/doi/abs/10.1002/rcm.8464},\n\tdoi = {10.1002/rcm.8464},\n\tlanguage = {en},\n\tnumber = {14},\n\turldate = {2021-06-25},\n\tjournal = {Rapid Communications in Mass Spectrometry},\n\tauthor = {Willmes, Malte and Lewis, Levi S. and Davis, Brittany E. and Loiselle, Liane and James, Hannah F. and Denny, Christian and Baxter, Randall and Conrad, J. Louise and Fangue, Nann A. and Hung, Tien-Chieh and Armstrong, Richard A. and Williams, Ian S. and Holden, Peter and Hobbs, James A.},\n\tyear = {2019},\n\tpages = {1207--1220},\n}");
			mutate($data, "temp", function($row) {return (18.57*1000 / (32.54 + 1000*log((1000 + $row['d18O_CO3'])/(1000 + (0.97001*($row['d18Osw_global'] + $row['d18Osw_spatial']) - 29.99)))) - 273.15);});
			if ($valid_temp_start < 18.2) $valid_temp_start = 18.2;
			if ($valid_temp_end > 25) $valid_temp_end = 25;
			break;
		case "patterson":
			$description = "the otolith calibration of Patterson et al. (1993) for freshwater lake fish: "
						 . "<div class='math'><math><mn>1000</mn><mo> ln </mo><mi>&alpha;</mi><mo> = </mo><mn>18.56</mn><mo>(</mo><msup><mn>10</mn><mn>3</mn></msup><msup><mi>TK</mi><mn>-1</mn></msup><mo>)</mo><mo> - </mo><mn>33.49</mn></math></div>"
						 . "<div class='math'><math><mi>&alpha;</mi><mo> = </mo><mfrac><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> + </mo><mn>1000</mn></mrow><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> + </mo><mn>1000</mn></mrow></mfrac></math></div>"
						 . "where <i>TK</i> is temperature (in Kelvin), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VPBD). The conversion <div class='math'><math><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VPBD)</mtext></msub><mo> = </mo><mn>0.97001</mn><mo> &times; </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VSMOW)</mtext></msub><mo> - </mo><mn>29.99</mn></math></div> was used to convert seawater &delta;<sup>18</sup>O from VSMOW to VPBD (Brand et al. 2014). "
						 . $description;
			array_push($citations, "<li class='citation'>Brand, W.A., Coplen, T.B., Vogl, J., Rosner, M., and Prohaska, T., 2014, Assessment of international reference materials for isotope-ratio analysis (IUPAC Technical Report): Pure and Applied Chemistry, v. 86, p. 425–467.</li>");
			array_push($citations, "<li class='citation'>Patterson, W.P., Smith, G.R., and Lohmann, K.C., 1993, Continental Paleothermometry and Seasonality Using the Isotopic Composition of Aragonitic Otoliths of Freshwater Fishes, in Climate Change in Continental Isotopic Records, American Geophysical Union (AGU), p. 191–202, doi:10.1029/GM078p0191.</li>");
			array_push($cite_bibtex, "@article{brand_assessment_2014,\n\ttitle = {Assessment of international reference materials for isotope-ratio analysis ({IUPAC} {Technical} {Report})},\n\tvolume = {86},\n\tnumber = {3},\n\tjournal = {Pure and Applied Chemistry},\n\tauthor = {Brand, Willi A and Coplen, Tyler B and Vogl, Jochen and Rosner, Martin and Prohaska, Thomas},\n\tyear = {2014},\n\tpages = {425--467},\n}");
			array_push($cite_bibtex, "@incollection{patterson_continental_1993,\n\ttitle = {Continental {Paleothermometry} and {Seasonality} {Using} the {Isotopic} {Composition} of {Aragonitic} {Otoliths} of {Freshwater} {Fishes}},\n\tisbn = {978-1-118-66402-5},\n\turl = {https://agupubs.onlinelibrary.wiley.com/doi/abs/10.1029/GM078p0191},\n\tlanguage = {en},\n\turldate = {2021-06-25},\n\tbooktitle = {Climate {Change} in {Continental} {Isotopic} {Records}},\n\tpublisher = {American Geophysical Union (AGU)},\n\tauthor = {Patterson, William P. and Smith, Gerald R. and Lohmann, Kyger C.},\n\tyear = {1993},\n\tdoi = {10.1029/GM078p0191},\n\tpages = {191--202},\n}");
			mutate($data, "temp", function($row) {return (18.56*1000 / (33.49 + 1000*log((1000 + $row['d18O_CO3'])/(1000 + (0.97001*($row['d18Osw_global'] + $row['d18Osw_spatial']) - 29.99)))) - 273.15);});
			if ($valid_temp_start < 3.2) $valid_temp_start = 3.2;
			if ($valid_temp_end > 30.3) $valid_temp_end = 30.3;
			break;
		case "godiksen":
			$description = "the otolith calibration of Godiksen et al. (2010) for <i>Salvelinus alpinus</i>, as standardized by Willmes et al. (2019): "
						 . "<div class='math'><math><mn>1000</mn><mo> ln </mo><mi>&alpha;</mi><mo> = </mo><mn>20.43</mn><mo>(</mo><msup><mn>10</mn><mn>3</mn></msup><msup><mi>TK</mi><mn>-1</mn></msup><mo>)</mo><mo> - </mo><mn>41.14</mn></math></div>"
						 . "<div class='math'><math><mi>&alpha;</mi><mo> = </mo><mfrac><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> + </mo><mn>1000</mn></mrow><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> + </mo><mn>1000</mn></mrow></mfrac></math></div>"
						 . "where <i>TK</i> is temperature (in Kelvin), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VPBD). The conversion <div class='math'><math><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VPBD)</mtext></msub><mo> = </mo><mn>0.97001</mn><mo> &times; </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VSMOW)</mtext></msub><mo> - </mo><mn>29.99</mn></math></div> was used to convert seawater &delta;<sup>18</sup>O from VSMOW to VPBD (Brand et al. 2014). "
						 . $description;
			array_push($citations, "<li class='citation'>Brand, W.A., Coplen, T.B., Vogl, J., Rosner, M., and Prohaska, T., 2014, Assessment of international reference materials for isotope-ratio analysis (IUPAC Technical Report): Pure and Applied Chemistry, v. 86, p. 425–467.</li>");
			array_push($citations, "<li class='citation'>Godiksen, J.A., Svenning, M.-A., Dempson, J.B., Marttila, M., Storm-Suke, A., and Power, M., 2010, Development of a species-specific fractionation equation for Arctic charr (Salvelinus alpinus (L.)): an experimental approach: Hydrobiologia, v. 650, p. 67–77, doi:10.1007/s10750-009-0056-7.</li>");
			array_push($citations, "<li class='citation'>Willmes, M. et al., 2019, Calibrating temperature reconstructions from fish otolith oxygen isotope analysis for California’s critically endangered Delta Smelt: Rapid Communications in Mass Spectrometry, v. 33, p. 1207–1220, doi:10.1002/rcm.8464.</li>");
			array_push($cite_bibtex, "@article{brand_assessment_2014,\n\ttitle = {Assessment of international reference materials for isotope-ratio analysis ({IUPAC} {Technical} {Report})},\n\tvolume = {86},\n\tnumber = {3},\n\tjournal = {Pure and Applied Chemistry},\n\tauthor = {Brand, Willi A and Coplen, Tyler B and Vogl, Jochen and Rosner, Martin and Prohaska, Thomas},\n\tyear = {2014},\n\tpages = {425--467},\n}");
			array_push($cite_bibtex, "@article{godiksen_development_2010,\n\ttitle = {Development of a species-specific fractionation equation for {Arctic} charr ({Salvelinus} alpinus ({L}.)): an experimental approach},\n\tvolume = {650},\n\tissn = {1573-5117},\n\tshorttitle = {Development of a species-specific fractionation equation for {Arctic} charr ({Salvelinus} alpinus ({L}.))},\n\turl = {https://doi.org/10.1007/s10750-009-0056-7},\n\tdoi = {10.1007/s10750-009-0056-7},\n\tlanguage = {en},\n\tnumber = {1},\n\turldate = {2021-06-25},\n\tjournal = {Hydrobiologia},\n\tauthor = {Godiksen, Jane A. and Svenning, Martin-A. and Dempson, J. Brian and Marttila, Maare and Storm-Suke, Andrea and Power, Michael},\n\tmonth = aug,\n\tyear = {2010},\n\tpages = {67--77},\n}");
			array_push($cite_bibtex, "@article{willmes_calibrating_2019,\n\ttitle = {Calibrating temperature reconstructions from fish otolith oxygen isotope analysis for {California}'s critically endangered {Delta} {Smelt}},\n\tvolume = {33},\n\tissn = {1097-0231},\n\turl = {https://analyticalsciencejournals.onlinelibrary.wiley.com/doi/abs/10.1002/rcm.8464},\n\tdoi = {10.1002/rcm.8464},\n\tlanguage = {en},\n\tnumber = {14},\n\turldate = {2021-06-25},\n\tjournal = {Rapid Communications in Mass Spectrometry},\n\tauthor = {Willmes, Malte and Lewis, Levi S. and Davis, Brittany E. and Loiselle, Liane and James, Hannah F. and Denny, Christian and Baxter, Randall and Conrad, J. Louise and Fangue, Nann A. and Hung, Tien-Chieh and Armstrong, Richard A. and Williams, Ian S. and Holden, Peter and Hobbs, James A.},\n\tyear = {2019},\n\tpages = {1207--1220},\n}");
			mutate($data, "temp", function($row) {return (20.43*1000 / (41.14 + 1000*log((1000 + $row['d18O_CO3'])/(1000 + (0.97001*($row['d18Osw_global'] + $row['d18Osw_spatial']) - 29.99)))) - 273.15);});
			if ($valid_temp_start < 2) $valid_temp_start = 2;
			if ($valid_temp_end > 14) $valid_temp_end = 14;
			break;
		case "geffen":
			$description = "the otolith calibration of Geffen (2012) for <i>Pleuronectes platessa</i>: "
						 . "<div class='math'><math><mn>1000</mn><mo> ln </mo><mi>&alpha;</mi><mo> = </mo><mn>15.99</mn><mo>(</mo><msup><mn>10</mn><mn>3</mn></msup><msup><mi>TK</mi><mn>-1</mn></msup><mo>)</mo><mo> - </mo><mn>24.25</mn></math></div>"
						 . "<div class='math'><math><mi>&alpha;</mi><mo> = </mo><mfrac><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> + </mo><mn>1000</mn></mrow><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> + </mo><mn>1000</mn></mrow></mfrac></math></div>"
						 . "where <i>TK</i> is temperature (in Kelvin), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VPBD). The conversion <div class='math'><math><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VPBD)</mtext></msub><mo> = </mo><mn>0.97001</mn><mo> &times; </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VSMOW)</mtext></msub><mo> - </mo><mn>29.99</mn></math></div> was used to convert seawater &delta;<sup>18</sup>O from VSMOW to VPBD (Brand et al. 2014). "
						 . $description;
			array_push($citations, "<li class='citation'>Brand, W.A., Coplen, T.B., Vogl, J., Rosner, M., and Prohaska, T., 2014, Assessment of international reference materials for isotope-ratio analysis (IUPAC Technical Report): Pure and Applied Chemistry, v. 86, p. 425–467.</li>");
			array_push($citations, "<li class='citation'>Geffen, A.J., 2012, Otolith oxygen and carbon stable isotopes in wild and laboratory-reared plaice (Pleuronectes platessa): Environmental Biology of Fishes, v. 95, p. 419–430, doi:10.1007/s10641-012-0033-2.</li>");
			array_push($cite_bibtex, "@article{brand_assessment_2014,\n\ttitle = {Assessment of international reference materials for isotope-ratio analysis ({IUPAC} {Technical} {Report})},\n\tvolume = {86},\n\tnumber = {3},\n\tjournal = {Pure and Applied Chemistry},\n\tauthor = {Brand, Willi A and Coplen, Tyler B and Vogl, Jochen and Rosner, Martin and Prohaska, Thomas},\n\tyear = {2014},\n\tpages = {425--467},\n}");
			array_push($cite_bibtex, "@article{@article{geffen_otolith_2012,\n\ttitle = {Otolith oxygen and carbon stable isotopes in wild and laboratory-reared plaice ({Pleuronectes} platessa)},\n\tvolume = {95},\n\tissn = {1573-5133},\n\turl = {https://doi.org/10.1007/s10641-012-0033-2},\n\tdoi = {10.1007/s10641-012-0033-2},\n\tlanguage = {en},\n\tnumber = {4},\n\turldate = {2021-06-25},\n\tjournal = {Environmental Biology of Fishes},\n\tauthor = {Geffen, Audrey J.},\n\tmonth = dec,\n\tyear = {2012},\n\tpages = {419--430},\n}");
			mutate($data, "temp", function($row) {return (15.99*1000 / (24.25 + 1000*log((1000 + $row['d18O_CO3'])/(1000 + (0.97001*($row['d18Osw_global'] + $row['d18Osw_spatial']) - 29.99)))) - 273.15);});
			if ($valid_temp_start < 11) $valid_temp_start = 11;
			if ($valid_temp_end > 17) $valid_temp_end = 17;
			break;
		case "hoie":
			$description = "the otolith calibration of Høie et al. (2004) for Atlantic cod: "
						 . "<div class='math'><math><mn>1000</mn><mo> ln </mo><mi>&alpha;</mi><mo> = </mo><mn>16.75</mn><mo>(</mo><msup><mn>10</mn><mn>3</mn></msup><msup><mi>TK</mi><mn>-1</mn></msup><mo>)</mo><mo> - </mo><mn>27.09</mn></math></div>"
						 . "<div class='math'><math><mi>&alpha;</mi><mo> = </mo><mfrac><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> + </mo><mn>1000</mn></mrow><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> + </mo><mn>1000</mn></mrow></mfrac></math></div>"
						 . "where <i>TK</i> is temperature (in Kelvin), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VPBD). The conversion <div class='math'><math><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VPBD)</mtext></msub><mo> = </mo><mn>0.97001</mn><mo> &times; </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VSMOW)</mtext></msub><mo> - </mo><mn>29.99</mn></math></div> was used to convert seawater &delta;<sup>18</sup>O from VSMOW to VPBD (Brand et al. 2014). "
						 . $description;
			array_push($citations, "<li class='citation'>Brand, W.A., Coplen, T.B., Vogl, J., Rosner, M., and Prohaska, T., 2014, Assessment of international reference materials for isotope-ratio analysis (IUPAC Technical Report): Pure and Applied Chemistry, v. 86, p. 425–467.</li>");
			array_push($citations, "<li class='citation'>Høie, H., Otterlei, E., and Folkvord, A., 2004, Temperature-dependent fractionation of stable oxygen isotopes in otoliths of juvenile cod (Gadus morhua L.): ICES Journal of Marine Science, v. 61, p. 243–251, doi:10.1016/j.icesjms.2003.11.006.</li>");
			array_push($cite_bibtex, "@article{brand_assessment_2014,\n\ttitle = {Assessment of international reference materials for isotope-ratio analysis ({IUPAC} {Technical} {Report})},\n\tvolume = {86},\n\tnumber = {3},\n\tjournal = {Pure and Applied Chemistry},\n\tauthor = {Brand, Willi A and Coplen, Tyler B and Vogl, Jochen and Rosner, Martin and Prohaska, Thomas},\n\tyear = {2014},\n\tpages = {425--467},\n}");
			array_push($cite_bibtex, "@article{hoie_temperature_2004,\n\ttitle = {Temperature-dependent fractionation of stable oxygen isotopes in otoliths of juvenile cod ({Gadus} morhua {L}.)},\n\tvolume = {61},\n\tissn = {1054-3139},\n\turl = {https://doi.org/10.1016/j.icesjms.2003.11.006},\n\tdoi = {10.1016/j.icesjms.2003.11.006},\n\tnumber = {2},\n\turldate = {2021-06-25},\n\tjournal = {ICES Journal of Marine Science},\n\tauthor = {Høie, Hans and Otterlei, Erling and Folkvord, Arild},\n\tmonth = jan,\n\tyear = {2004},\n\tpages = {243--251},\n}");
			mutate($data, "temp", function($row) {return (16.75*1000 / (27.09 + 1000*log((1000 + $row['d18O_CO3'])/(1000 + (0.97001*($row['d18Osw_global'] + $row['d18Osw_spatial']) - 29.99)))) - 273.15);});
			if ($valid_temp_start < 6) $valid_temp_start = 6;
			if ($valid_temp_end > 20) $valid_temp_end = 20;
			break;
		case "stormsuke":
			$description = "the otolith calibration of Storm-Suke et al. (2007) for <i>Salvelinus</i> spp.: "
						 . "<div class='math'><math><mn>1000</mn><mo> ln </mo><mi>&alpha;</mi><mo> = </mo><mn>20.69</mn><mo>(</mo><msup><mn>10</mn><mn>3</mn></msup><msup><mi>TK</mi><mn>-1</mn></msup><mo>)</mo><mo> - </mo><mn>41.69</mn></math></div>"
						 . "<div class='math'><math><mi>&alpha;</mi><mo> = </mo><mfrac><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> + </mo><mn>1000</mn></mrow><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> + </mo><mn>1000</mn></mrow></mfrac></math></div>"
						 . "where <i>TK</i> is temperature (in Kelvin), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VPBD). The conversion <div class='math'><math><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VPBD)</mtext></msub><mo> = </mo><mn>0.97001</mn><mo> &times; </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VSMOW)</mtext></msub><mo> - </mo><mn>29.99</mn></math></div> was used to convert seawater &delta;<sup>18</sup>O from VSMOW to VPBD (Brand et al. 2014). "
						 . $description;
			array_push($citations, "<li class='citation'>Brand, W.A., Coplen, T.B., Vogl, J., Rosner, M., and Prohaska, T., 2014, Assessment of international reference materials for isotope-ratio analysis (IUPAC Technical Report): Pure and Applied Chemistry, v. 86, p. 425–467.</li>");
			array_push($citations, "<li class='citation'>Storm-Suke, A., Dempson, J.B., Reist, J.D., and Power, M., 2007, A field-derived oxygen isotope fractionation equation for Salvelinus species: Rapid Communications in Mass Spectrometry, v. 21, p. 4109–4116, doi:10.1002/rcm.3320.</li>");
			array_push($cite_bibtex, "@article{brand_assessment_2014,\n\ttitle = {Assessment of international reference materials for isotope-ratio analysis ({IUPAC} {Technical} {Report})},\n\tvolume = {86},\n\tnumber = {3},\n\tjournal = {Pure and Applied Chemistry},\n\tauthor = {Brand, Willi A and Coplen, Tyler B and Vogl, Jochen and Rosner, Martin and Prohaska, Thomas},\n\tyear = {2014},\n\tpages = {425--467},\n}");
			array_push($cite_bibtex, "@article{storm_suke_field_2007,\n\ttitle = {A field-derived oxygen isotope fractionation equation for {Salvelinus} species},\n\tvolume = {21},\n\tissn = {1097-0231},\n\turl = {https://analyticalsciencejournals.onlinelibrary.wiley.com/doi/abs/10.1002/rcm.3320},\n\tdoi = {10.1002/rcm.3320},\n\tlanguage = {en},\n\tnumber = {24},\n\turldate = {2021-06-25},\n\tjournal = {Rapid Communications in Mass Spectrometry},\n\tauthor = {Storm-Suke, Andrea and Dempson, J. Brian and Reist, James D. and Power, Michael},\n\tyear = {2007},\n\tpages = {4109--4116},\n}");
			mutate($data, "temp", function($row) {return (20.69*1000 / (41.69 + 1000*log((1000 + $row['d18O_CO3'])/(1000 + (0.97001*($row['d18Osw_global'] + $row['d18Osw_spatial']) - 29.99)))) - 273.15);});
			if ($valid_temp_start < 2.3) $valid_temp_start = 2.3;
			if ($valid_temp_end > 11.8) $valid_temp_end = 11.8;
			break;
		case "grossman_mol":
			$description = "the mixed mollusk calibration of Grossman & Hu (1986): "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>21.8</mn><mo> - </mo><mn>4.69</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.20</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.20&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration. "
						 . $description;
			array_push($citations, "<li class='citation'>Grossman, E.L., and Ku, T.-L., 1986, Oxygen and carbon isotope fractionation in biogenic aragonite: Temperature effects: Chemical Geology: Isotope Geoscience section, v. 59, p. 59–74, doi:10.1016/0168-9622(86)90057-6.</li>");
			array_push($cite_bibtex, "@article{grossman_oxygen_1986,\n\tseries = {Calibration of the {Phanerozoic} {Time} {Scale}},\n\ttitle = {Oxygen and carbon isotope fractionation in biogenic aragonite: {Temperature} effects},\n\tvolume = {59},\n\tissn = {0168-9622},\n\tshorttitle = {Oxygen and carbon isotope fractionation in biogenic aragonite},\n\turl = {https://www.sciencedirect.com/science/article/pii/0168962286900576},\n\tdoi = {10.1016/0168-9622(86)90057-6},\n\tlanguage = {en},\n\turldate = {2022-06-09},\n\tjournal = {Chemical Geology: Isotope Geoscience section},\n\tauthor = {Grossman, Ethan L and Ku, Teh-Lung},\n\tmonth = jan,\n\tyear = {1986},\n\tpages = {59--74},\n}");
			mutate($data, "temp", function($row) {return 21.8 - 4.69*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.20));});
			if ($valid_temp_start < 6) $valid_temp_start = 6;
			if ($valid_temp_end > 22) $valid_temp_end = 22;
			break;
		case "grossman_ele":
			$description = "the core-top calibration of Grossman & Hu (1986) for <i>H. elegans</i>: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>20.6</mn><mo> - </mo><mn>4.38</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> - </mo><mn>0.20</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of -0.20&#8240; is applied to convert VSMOW to VPBD, following the method used to construct the original calibration. "
						 . $description;
			array_push($citations, "<li class='citation'>Grossman, E.L., and Ku, T.-L., 1986, Oxygen and carbon isotope fractionation in biogenic aragonite: Temperature effects: Chemical Geology: Isotope Geoscience section, v. 59, p. 59–74, doi:10.1016/0168-9622(86)90057-6.</li>");
			array_push($cite_bibtex, "@article{grossman_oxygen_1986,\n\tseries = {Calibration of the {Phanerozoic} {Time} {Scale}},\n\ttitle = {Oxygen and carbon isotope fractionation in biogenic aragonite: {Temperature} effects},\n\tvolume = {59},\n\tissn = {0168-9622},\n\tshorttitle = {Oxygen and carbon isotope fractionation in biogenic aragonite},\n\turl = {https://www.sciencedirect.com/science/article/pii/0168962286900576},\n\tdoi = {10.1016/0168-9622(86)90057-6},\n\tlanguage = {en},\n\turldate = {2022-06-09},\n\tjournal = {Chemical Geology: Isotope Geoscience section},\n\tauthor = {Grossman, Ethan L and Ku, Teh-Lung},\n\tmonth = jan,\n\tyear = {1986},\n\tpages = {59--74},\n}");
			mutate($data, "temp", function($row) {return 20.6 - 4.38*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial'] - 0.20));});
			if ($valid_temp_start < 2.5) $valid_temp_start = 2.5;
			if ($valid_temp_end > 20) $valid_temp_end = 20;
			break;
		case "white":
			$description = "the calibration of White et al. (1999) for the freshwater snail <i>Lymnaea peregra</i>: "
						 . "<div class='math'><math><mn>1000</mn><mo> ln </mo><mi>&alpha;</mi><mo> = </mo><mn>16.74</mn><mo>(</mo><msup><mn>10</mn><mn>3</mn></msup><msup><mi>TK</mi><mn>-1</mn></msup><mo>)</mo><mo> - </mo><mn>26.39</mn></math></div>"
						 . "<div class='math'><math><mi>&alpha;</mi><mo> = </mo><mfrac><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> + </mo><mn>1000</mn></mrow><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> + </mo><mn>1000</mn></mrow></mfrac></math></div>"
						 . "where <i>TK</i> is temperature (in Kelvin), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VPBD). The conversion <div class='math'><math><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VPBD)</mtext></msub><mo> = </mo><mn>0.97001</mn><mo> &times; </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VSMOW)</mtext></msub><mo> - </mo><mn>29.99</mn></math></div> was used to convert seawater &delta;<sup>18</sup>O from VSMOW to VPBD (Brand et al. 2014). "
						 . $description;
			array_push($citations, "<li class='citation'>Brand, W.A., Coplen, T.B., Vogl, J., Rosner, M., and Prohaska, T., 2014, Assessment of international reference materials for isotope-ratio analysis (IUPAC Technical Report): Pure and Applied Chemistry, v. 86, p. 425–467.</li>");
			array_push($citations, "<li class='citation'>White, R.M.P., Dennis, P.F., and Atkinson, T.C., 1999, Experimental calibration and field investigation of the oxygen isotopic fractionation between biogenic aragonite and water: Rapid Communications in Mass Spectrometry, v. 13, p. 1242–1247, doi:10.1002/(SICI)1097-0231(19990715)13:13<1242::AID-RCM627>3.0.CO;2-F.</li>");
			array_push($cite_bibtex, "@article{brand_assessment_2014,\n\ttitle = {Assessment of international reference materials for isotope-ratio analysis ({IUPAC} {Technical} {Report})},\n\tvolume = {86},\n\tnumber = {3},\n\tjournal = {Pure and Applied Chemistry},\n\tauthor = {Brand, Willi A and Coplen, Tyler B and Vogl, Jochen and Rosner, Martin and Prohaska, Thomas},\n\tyear = {2014},\n\tpages = {425--467},\n}");
			array_push($cite_bibtex, "@article{white_experimental_1999,\n\ttitle = {Experimental calibration and field investigation of the oxygen isotopic fractionation between biogenic aragonite and water},\n\tvolume = {13},\n\tissn = {1097-0231},\n\turl = {https://analyticalsciencejournals.onlinelibrary.wiley.com/doi/abs/10.1002/%28SICI%291097-0231%2819990715%2913%3A13%3C1242%3A%3AAID-RCM627%3E3.0.CO%3B2-F},\n\tdoi = {10.1002/(SICI)1097-0231(19990715)13:13<1242::AID-RCM627>3.0.CO;2-F},\n\tlanguage = {en},\n\tnumber = {13},\n\turldate = {2021-06-25},\n\tjournal = {Rapid Communications in Mass Spectrometry},\n\tauthor = {White, R. M. P. and Dennis, P. F. and Atkinson, T. C.},\n\tyear = {1999},\n\tpages = {1242--1247},\n}");
			mutate($data, "temp", function($row) {return (16.74*1000 / (26.39 + 1000*log((1000 + $row['d18O_CO3'])/(1000 + (0.97001*($row['d18Osw_global'] + $row['d18Osw_spatial']) - 29.99)))) - 273.15);});
			if ($valid_temp_start < 8) $valid_temp_start = 8;
			if ($valid_temp_end > 24) $valid_temp_end = 24;
			break;
		case "bohm":
			$description = "the calibration of Böhm et al. (2000) for the sclerosponge <i>Ceratoporella nicholsoni</i>: "
						 . "<div class='math'><math><mn>1000</mn><mo> ln </mo><mi>&alpha;</mi><mo> = </mo><mn>18.45</mn><mo>(</mo><msup><mn>10</mn><mn>3</mn></msup><msup><mi>TK</mi><mn>-1</mn></msup><mo>)</mo><mo> - </mo><mn>32.54</mn></math></div>"
						 . "<div class='math'><math><mi>&alpha;</mi><mo> = </mo><mfrac><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> + </mo><mn>1000</mn></mrow><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> + </mo><mn>1000</mn></mrow></mfrac></math></div>"
						 . "where <i>TK</i> is temperature (in Kelvin), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VPBD). The conversion <div class='math'><math><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VPBD)</mtext></msub><mo> = </mo><mn>0.97001</mn><mo> &times; </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VSMOW)</mtext></msub><mo> - </mo><mn>29.99</mn></math></div> was used to convert seawater &delta;<sup>18</sup>O from VSMOW to VPBD (Brand et al. 2014). "
						 . $description;
			array_push($citations, "<li class='citation'>Brand, W.A., Coplen, T.B., Vogl, J., Rosner, M., and Prohaska, T., 2014, Assessment of international reference materials for isotope-ratio analysis (IUPAC Technical Report): Pure and Applied Chemistry, v. 86, p. 425–467.</li>");
			array_push($citations, "<li class='citation'>Böhm, F., Joachimski, M.M., Dullo, W.-C., Eisenhauer, A., Lehnert, H., Reitner, J., and Wörheide, G., 2000, Oxygen isotope fractionation in marine aragonite of coralline sponges: Geochimica et Cosmochimica Acta, v. 64, p. 1695–1703, doi:10.1016/S0016-7037(99)00408-1.</li>");
			array_push($cite_bibtex, "@article{brand_assessment_2014,\n\ttitle = {Assessment of international reference materials for isotope-ratio analysis ({IUPAC} {Technical} {Report})},\n\tvolume = {86},\n\tnumber = {3},\n\tjournal = {Pure and Applied Chemistry},\n\tauthor = {Brand, Willi A and Coplen, Tyler B and Vogl, Jochen and Rosner, Martin and Prohaska, Thomas},\n\tyear = {2014},\n\tpages = {425--467},\n}");
			array_push($cite_bibtex, "@article{bohm_oxygen_2000,\n\ttitle = {Oxygen isotope fractionation in marine aragonite of coralline sponges},\n\tvolume = {64},\n\tissn = {0016-7037},\n\turl = {https://www.sciencedirect.com/science/article/pii/S0016703799004081},\n\tdoi = {10.1016/S0016-7037(99)00408-1},\n\tlanguage = {en},\n\tnumber = {10},\n\turldate = {2021-06-25},\n\tjournal = {Geochimica et Cosmochimica Acta},\n\tauthor = {Böhm, Florian and Joachimski, Michael M. and Dullo, Wolf-Christian and Eisenhauer, Anton and Lehnert, Helmut and Reitner, Joachim and Wörheide, Gert},\n\tmonth = may,\n\tyear = {2000},\n\tpages = {1695--1703},\n}");
			mutate($data, "temp", function($row) {return (18.45*1000 / (32.54 + 1000*log((1000 + $row['d18O_CO3'])/(1000 + (0.97001*($row['d18Osw_global'] + $row['d18Osw_spatial']) - 29.99)))) - 273.15);});
			if ($valid_temp_start < 3) $valid_temp_start = 3;
			if ($valid_temp_end > 28) $valid_temp_end = 28;
			break;
		case "tremaine":
			$description = "the speleothem calibration of Tremaine et al. (2011): "
						 . "<div class='math'><math><mn>1000</mn><mo> ln </mo><mi>&alpha;</mi><mo> = </mo><mn>16.1</mn><mo>(</mo><msup><mn>10</mn><mn>3</mn></msup><msup><mi>TK</mi><mn>-1</mn></msup><mo>)</mo><mo> - </mo><mn>24.6</mn></math></div>"
						 . "<div class='math'><math><mi>&alpha;</mi><mo> = </mo><mfrac><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> + </mo><mn>1000</mn></mrow><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> + </mo><mn>1000</mn></mrow></mfrac></math></div>"
						 . "where <i>TK</i> is temperature (in Kelvin), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VPBD). The conversion <div class='math'><math><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VPBD)</mtext></msub><mo> = </mo><mn>0.97001</mn><mo> &times; </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VSMOW)</mtext></msub><mo> - </mo><mn>29.99</mn></math></div> was used to convert seawater &delta;<sup>18</sup>O from VSMOW to VPBD (Brand et al. 2014). "
						 . $description;
			array_push($citations, "<li class='citation'>Brand, W.A., Coplen, T.B., Vogl, J., Rosner, M., and Prohaska, T., 2014, Assessment of international reference materials for isotope-ratio analysis (IUPAC Technical Report): Pure and Applied Chemistry, v. 86, p. 425–467.</li>");
			array_push($citations, "<li class='citation'>Tremaine, D.M., Froelich, P.N., and Wang, Y., 2011, Speleothem calcite farmed in situ: Modern calibration of &delta;<sup>18</sup>O and δ13C paleoclimate proxies in a continuously-monitored natural cave system: Geochimica et Cosmochimica Acta, v. 75, p. 4929–4950, doi:10.1016/j.gca.2011.06.005.</li>");
			array_push($cite_bibtex, "@article{brand_assessment_2014,\n\ttitle = {Assessment of international reference materials for isotope-ratio analysis ({IUPAC} {Technical} {Report})},\n\tvolume = {86},\n\tnumber = {3},\n\tjournal = {Pure and Applied Chemistry},\n\tauthor = {Brand, Willi A and Coplen, Tyler B and Vogl, Jochen and Rosner, Martin and Prohaska, Thomas},\n\tyear = {2014},\n\tpages = {425--467},\n}");
			array_push($cite_bibtex, "@article{tremaine_speleothem_2011,\n\ttitle = {Speleothem calcite farmed in situ: {Modern} calibration of δ{18O} and δ{13C} paleoclimate proxies in a continuously-monitored natural cave system},\n\tvolume = {75},\n\tissn = {0016-7037},\n\tshorttitle = {Speleothem calcite farmed in situ},\n\turl = {https://www.sciencedirect.com/science/article/pii/S0016703711003231},\n\tdoi = {10.1016/j.gca.2011.06.005},\n\tlanguage = {en},\n\tnumber = {17},\n\turldate = {2021-06-28},\n\tjournal = {Geochimica et Cosmochimica Acta},\n\tauthor = {Tremaine, Darrel M. and Froelich, Philip N. and Wang, Yang},\n\tmonth = sep,\n\tyear = {2011},\n\tpages = {4929--4950},\n}");
			mutate($data, "temp", function($row) {return (16.1*1000 / (24.6 + 1000*log((1000 + $row['d18O_CO3'])/(1000 + (0.97001*($row['d18Osw_global'] + $row['d18Osw_spatial']) - 29.99)))) - 273.15);});
			if ($valid_temp_start < 16) $valid_temp_start = 16;
			if ($valid_temp_end > 21.5) $valid_temp_end = 21.5;
			break;
		case "kim_arag":
			$description = "the inorganic aragonite calibration of Kim et al. (2007): "
						 . "<div class='math'><math><mn>1000</mn><mo> ln </mo><mi>&alpha;</mi><mo> = </mo><mn>17.88</mn><mo>(</mo><msup><mn>10</mn><mn>3</mn></msup><msup><mi>TK</mi><mn>-1</mn></msup><mo>)</mo><mo> - </mo><mn>31.14</mn></math></div>"
						 . "<div class='math'><math><mi>&alpha;</mi><mo> = </mo><mfrac><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> + </mo><mn>1000</mn></mrow><mrow><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> + </mo><mn>1000</mn></mrow></mfrac></math></div>"
						 . "where <i>TK</i> is temperature (in Kelvin), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VSMOW), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). The conversion <div class='math'><math><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VPBD)</mtext></msub><mo> = </mo><mn>0.97001</mn><mo> &times; </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mtext>(VSMOW)</mtext></msub><mo> - </mo><mn>29.99</mn></math></div> was used to convert carbonate &delta;<sup>18</sup>O from VPDB to VSMOW (Brand et al. 2014), as required by the Kim et al. (2007) calibration. "
						 . $description;
			array_push($citations, "<li class='citation'>Brand, W.A., Coplen, T.B., Vogl, J., Rosner, M., and Prohaska, T., 2014, Assessment of international reference materials for isotope-ratio analysis (IUPAC Technical Report): Pure and Applied Chemistry, v. 86, p. 425–467.</li>");
			array_push($citations, "<li class='citation'>Kim, S.-T., O’Neil, J.R., Hillaire-Marcel, C., and Mucci, A., 2007, Oxygen isotope fractionation between synthetic aragonite and water: Influence of temperature and Mg2+ concentration: Geochimica et Cosmochimica Acta, v. 71, p. 4704–4715, doi:10.1016/j.gca.2007.04.019.</li>");
			array_push($cite_bibtex, "@article{brand_assessment_2014,\n\ttitle = {Assessment of international reference materials for isotope-ratio analysis ({IUPAC} {Technical} {Report})},\n\tvolume = {86},\n\tnumber = {3},\n\tjournal = {Pure and Applied Chemistry},\n\tauthor = {Brand, Willi A and Coplen, Tyler B and Vogl, Jochen and Rosner, Martin and Prohaska, Thomas},\n\tyear = {2014},\n\tpages = {425--467},\n}");
			array_push($cite_bibtex, "@article{kim_oxygen_2007,\n\ttitle = {Oxygen isotope fractionation between synthetic aragonite and water: {Influence} of temperature and {Mg2}+ concentration},\n\tvolume = {71},\n\tissn = {0016-7037},\n\tshorttitle = {Oxygen isotope fractionation between synthetic aragonite and water},\n\turl = {https://www.sciencedirect.com/science/article/pii/S0016703707002165},\n\tdoi = {10.1016/j.gca.2007.04.019},\n\tlanguage = {en},\n\tnumber = {19},\n\turldate = {2021-06-28},\n\tjournal = {Geochimica et Cosmochimica Acta},\n\tauthor = {Kim, Sang-Tae and O’Neil, James R. and Hillaire-Marcel, Claude and Mucci, Alfonso},\n\tmonth = oct,\n\tyear = {2007},\n\tpages = {4704--4715},\n}");
			mutate($data, "temp", function($row) {return 17.88*1000 / (1000 * log((($row['d18O_CO3'] + 29.99) / 0.97001 + 1000) / (($row['d18Osw_global'] + $row['d18Osw_spatial']) + 1000)) + 31.14) - 273.15;});
			if ($valid_temp_start < 0) $valid_temp_start = 0;
			if ($valid_temp_end > 40) $valid_temp_end = 40;
			break;
		case "rosenheim":
			$description = "the calibration of Rosenheim et al. (2009) for the sclerosponge <i>Ceratoporella nicholsoni</i>: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>16.1</mn><mo> - </mo><mn>6.5</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). "
						 . $description;
			array_push($citations, "<li class='citation'>Rosenheim, B.E., Swart, P.K., and Willenz, P., 2009, Calibration of sclerosponge oxygen isotope records to temperature using high-resolution &delta;<sup>18</sup>O data: Geochimica et Cosmochimica Acta, v. 73, p. 5308–5319, doi:10.1016/j.gca.2009.05.047.</li>");
			array_push($cite_bibtex, "@article{rosenheim_calibration_2009,\n\ttitle = {Calibration of sclerosponge oxygen isotope records to temperature using high-resolution δ{18O} data},\n\tvolume = {73},\n\tissn = {0016-7037},\n\turl = {https://www.sciencedirect.com/science/article/pii/S0016703709003196},\n\tdoi = {10.1016/j.gca.2009.05.047},\n\tlanguage = {en},\n\tnumber = {18},\n\turldate = {2021-06-28},\n\tjournal = {Geochimica et Cosmochimica Acta},\n\tauthor = {Rosenheim, Brad E. and Swart, Peter K. and Willenz, Philippe},\n\tmonth = sep,\n\tyear = {2009},\n\tpages = {5308--5319},\n}");
			mutate($data, "temp", function($row) {return 16.1 - 6.5*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial']));}); // strangely, Rosenheim et al. do not convert between VSMOW and VPBD!
			if ($valid_temp_start < 23) $valid_temp_start = 23;
			if ($valid_temp_end > 27.5) $valid_temp_end = 27.5;
			break;
		case "reynaud_sty":
			$description = "the coral calibration of Reynaud-Vaganay et al. (1999) for <i>Stylophora pistillata</i>: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>16.15</mn><mo> - </mo><mn>7.69</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> + </mo><mn>1.29</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of 1.29&#8240; is applied to account for the value of &delta;<sup>18</sup>O<sub><i>w</i></sub> used to create the calibration (Reynaud-Vaganay et al. 1999). "
						 . $description;
			array_push($citations, "<li class='citation'>Reynaud-Vaganay, S., Gattuso, J.-P., Cuif, J.-P., Jaubert, J., and Juillet-Leclerc, A., 1999, A novel culture technique for scleractinian corals: application to investigate changes in skeletal &delta;<sup>18</sup>O as a function of temperature: Marine Ecology Progress Series, v. 180, p. 121–130, doi:10.3354/meps180121.</li>");
			array_push($cite_bibtex, "@article{reynaud_vaganay_novel_1999,\n\ttitle = {A novel culture technique for scleractinian corals: application to investigate changes in skeletal δ{18O} as a function of temperature},\n\tvolume = {180},\n\tissn = {0171-8630, 1616-1599},\n\tshorttitle = {A novel culture technique for scleractinian corals},\n\turl = {https://www.int-res.com/abstracts/meps/v180/p121-130/},\n\tdoi = {10.3354/meps180121},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tjournal = {Marine Ecology Progress Series},\n\tauthor = {Reynaud-Vaganay, S. and Gattuso, J.-P. and Cuif, J.-P. and Jaubert, J. and Juillet-Leclerc, A.},\n\tmonth = may,\n\tyear = {1999},\n\tpages = {121--130},\n}");
			mutate($data, "temp", function($row) {return 16.15 - 7.69*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial']) + 1.29);});
			if ($valid_temp_start < 21) $valid_temp_start = 21;
			if ($valid_temp_end > 29) $valid_temp_end = 29;
			break;
		case "reynaud_acro":
			$description = "the coral calibration of Reynaud-Vaganay et al. (1999) for <i>Acropora</i> spp.: "
						 . "<div class='math'><math><mi>T</mi><mo> = </mo><mn>19.81</mn><mo> - </mo><mn>3.70</mn><mo> (</mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>c</mn></msub><mo> - </mo><msup><mtext>&delta;</mtext><mn>18</mn></msup><msub><mtext>O</mtext><mi>w</mn></msub><mo> + </mo><mn>1.29</mn><mo>)</mo></math></div>"
						 . "where <i>T</i> is temperature (°C), &delta;<sup>18</sup>O<sub><i>c</i></sub> is the oxygen isotope value of the carbonate (&#8240; VPBD), and &delta;<sup>18</sup>O<sub><i>w</i></sub> is the oxygen isotope value of the seawater (&#8240; VSMOW). An offset of 1.29&#8240; is applied to account for the value of &delta;<sup>18</sup>O<sub><i>w</i></sub> used to create the calibration (Reynaud-Vaganay et al. 1999). "
						 . $description;
			array_push($citations, "<li class='citation'>Reynaud-Vaganay, S., Gattuso, J.-P., Cuif, J.-P., Jaubert, J., and Juillet-Leclerc, A., 1999, A novel culture technique for scleractinian corals: application to investigate changes in skeletal &delta;<sup>18</sup>O as a function of temperature: Marine Ecology Progress Series, v. 180, p. 121–130, doi:10.3354/meps180121.</li>");
			array_push($cite_bibtex, "@article{reynaud_vaganay_novel_1999,\n\ttitle = {A novel culture technique for scleractinian corals: application to investigate changes in skeletal δ{18O} as a function of temperature},\n\tvolume = {180},\n\tissn = {0171-8630, 1616-1599},\n\tshorttitle = {A novel culture technique for scleractinian corals},\n\turl = {https://www.int-res.com/abstracts/meps/v180/p121-130/},\n\tdoi = {10.3354/meps180121},\n\tlanguage = {en},\n\turldate = {2021-06-29},\n\tjournal = {Marine Ecology Progress Series},\n\tauthor = {Reynaud-Vaganay, S. and Gattuso, J.-P. and Cuif, J.-P. and Jaubert, J. and Juillet-Leclerc, A.},\n\tmonth = may,\n\tyear = {1999},\n\tpages = {121--130},\n}");
			mutate($data, "temp", function($row) {return 19.81 - 3.70*($row['d18O_CO3'] - ($row['d18Osw_global'] + $row['d18Osw_spatial']) + 1.29);}); # Reynaud-Vaganay et al. (1999) calibrate using skeletal values only; an offset of 1.29 is applied here to normalize their calibration to their measured d18Osw.
			if ($valid_temp_start < 21) $valid_temp_start = 21;
			if ($valid_temp_end > 29) $valid_temp_end = 29;
			break;
	}
	
	// add timescale explanation if needed
	if ($explain_timescale) {
		$description .= "All ages are given on the ";
		switch ($timescale) {
			case "GTS2004":
				$description .= "GTS2004 timescale (Gradstein et al. 2005), ";
				array_push($citations, "<li class='citation'>Gradstein, F.M., Ogg, J.G., and Smith, A.G. (Eds.), 2005, A Geologic Time Scale 2004: Cambridge, Cambridge University Press, doi:10.1017/CBO9780511536045.</li>");
				array_push($cite_bibtex, "@book{gradstein_geologic_2005,\n\taddress = {Cambridge},\n\ttitle = {A {Geologic} {Time} {Scale} 2004},\n\tisbn = {978-0-521-78673-7},\n\turldate = {2021-05-17},\n\tpublisher = {Cambridge University Press},\n\teditor = {Gradstein, Felix M. and Ogg, James G. and Smith, Alan G.},\n\tyear = {2005},\n\tdoi = {10.1017/CBO9780511536045},\n}");
				break;
			case "GTS2012":
				$description .= "GTS2012 timescale (Gradstein et al. 2012), ";
				array_push($citations, "<li class='citation'>Gradstein, F.M., Ogg, J.G., Schmitz, M.D., and Ogg, G.M. (Eds.), 2012, The Geologic Time Scale: Elsevier, doi:10.1016/C2011-1-08249-8.</li>");
				array_push($cite_bibtex, "@book{gradstein_geologic_2012,\n\ttitle = {The {Geologic} {Time} {Scale}},\n\tisbn = {978-0-444-59425-9},\n\tlanguage = {en},\n\tpublisher = {Elsevier},\n\teditor = {Gradstein, Felix M. and Ogg, James G. and Schmitz, Mark D. and Ogg, Gabi M.},\n\tyear = {2012},\n\tdoi = {10.1016/C2011-1-08249-8},\n}");
				break;
			case "GTS2016":
				$description .= "GTS2016 timescale (Ogg et al. 2016), ";
				array_push($citations, "<li class='citation'>Ogg, J.G., Ogg, G.M., and Gradstein, F.M., 2016, A Concise Geologic Time Scale: Elsevier, doi:10.1016/C2009-0-64442-1.</li>");
				array_push($cite_bibtex, "@book{ogg_concise_2016,\n\ttitle = {A {Concise} {Geologic} {Time} {Scale}},\n\tisbn = {978-0-444-63771-0},\n\tlanguage = {en},\n\tpublisher = {Elsevier},\n\tauthor = {Ogg, James G. and Ogg, Gabi M. and Gradstein, Felix M.},\n\tyear = {2016},\n\tdoi = {10.1016/C2009-0-64442-1},\n}");
				break;
			case "GTS2020":
				$description .= "GTS2020 timescale (Gradstein et al. 2020), ";
				array_push($citations, "<li class='citation'>Gradstein, F.M., Ogg, J.G., Schmitz, M.D., and Ogg, G.M., 2020, Geologic Time Scale 2020: Elsevier, doi:10.1016/C2020-1-02369-3.</li>");
				array_push($cite_bibtex, "@book{gradstein_geologic_2020,\n\ttitle = {Geologic {Time} {Scale} 2020},\n\tisbn = {978-0-12-824361-9},\n\tpublisher = {Elsevier},\n\tauthor = {Gradstein, Felix M. and Ogg, James G. and Schmitz, Mark D. and Ogg, Gabi M.},\n\tyear = {2020},\n\tdoi = {10.1016/C2020-1-02369-3},\n}");
				break;
		}
		$description .= "with ages converted by linear interpolation between magnetochron boundary ages as necessary. ";
	}
	
	// validate ranges
	foreach ($data as $key => $row) {
		$data[$key]["notes"] = "";
		foreach ($row as $rowkey => $value) {
			if ($needed[$rowkey] and is_numeric($value) and is_nan($value)) {
				$data[$key]["notes"] = "Missing or malformed number(s)";
				$needed['notes'] = TRUE;
				$errors = TRUE;
			}
		}
		if ($row['temp'] < $valid_temp_start or $row['temp'] > $valid_temp_end) {
			$temp_error = TRUE;
			$data[$key]["notes"] = "Outside calibration range";
			$needed['notes'] = TRUE;
		}
		if ($row['pallat'] < $valid_lat_start or $row['pallat'] > $valid_lat_end) {
			$lat_error  = TRUE;
			$data[$key]["notes"] = "Outside latitude range";
			$needed['notes'] = TRUE;
		}
		if ($row['age'] < $valid_age_start or $row['age'] > $valid_age_end) {
			$age_error  = TRUE;
			$data[$key]["notes"] = "Outside age range";
			$needed['notes'] = TRUE;
		}
	}
	
	// sort and clean up citations
	$citations = array_unique($citations);
	$cite_bibtex = array_unique($cite_bibtex);
	asort($citations, SORT_STRING + SORT_FLAG_CASE);
	asort($cite_bibtex, SORT_STRING + SORT_FLAG_CASE);
?>
	<div class="content">
		<h2>Results</h2>
			<?php
				if ($errors     === TRUE) echo "<div class='errorbox'><b>WARNING:</b> Not all data converted correctly! See rows highlighted in red below. (The leftmost cell with red text is often where the problem started.)</div>";
				if ($temp_error === TRUE) echo "<div class='cautionbox'><b>NOTE:</b> Some temperatures are outside the data range of the selected calibration ($valid_temp_start &deg;C to $valid_temp_end &deg;C). This is not generally a problem given the linearity of most &delta;<sup>18</sup>O-temperature calibrations, but you should check the original calibration publication to be sure.</div>";
				if ($age_error  === TRUE) echo "<div class='errorbox'><b>WARNING:</b> Some ages were outside the range of valid ages for the methods or records being used ($valid_age_start&ndash;$valid_age_end Ma).</div>";
				if ($lat_error  === TRUE) echo "<div class='errorbox'><b>WARNING:</b> Some paleolatitudes were outside the range of valid latitudes for the methods or records being used ($valid_lat_start&deg; to $valid_lat_end&deg;).</div>";
				echo format_table($data);
			?>
		<h2>Column definitions</h2>
			<ul>
			<?php
				echo format_definitions($data);
			?>
			</ul>
		<h2>Methods & references</h2>
		<p>Methods for this conversion:</p>
		<blockquote>
			&delta;<sup>18</sup>O data (VPDB) were converted to temperature (°C) using an online tool (Gaskell & Hull 2022, version <?php echo $version; ?>), applying <?php echo $description; ?>
		</blockquote>
		<form action="bibtex.php" method="post" target="_blank">
		<p>References to cite for these methods 
			<input type="hidden" id="bibtex" name="bibtex" value="<?php echo addslashes(implode("\n", $cite_bibtex)); ?>" />
			(<button type="submit" class="button-link">download B<span style="font-size:75%">IB</span><span style="letter-spacing:-0.1em">T<span style="vertical-align:-20%;">E</span>X</span></button>):
		</p>
		</form>
		<ul>
			<?php echo implode("\n", $citations); ?>
		</ul>
		<p class="warning">CAUTION: As always, not all calibrations and corrections are appropriate for all applications. Refer to the original publications for details and caveats.</p>
	</div>
</body>
</html>