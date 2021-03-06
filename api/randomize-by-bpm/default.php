<?php
require '../../autoload.php';

function fail($msg) {
  throw new \Exception($msg);
}

try {

if (!hasSession()) {
  throw new NoSessionException();
}

// Parse JSON data
if (!isset($_POST['data'])) {
  fail("missing required POST field: data");
}
$json = fromJson($_POST['data'], true);
if (is_null($json)) {
  fail("POST field 'data' not in JSON format");
}

// Check data
if (!array_key_exists('trackIdList', $json)) {
  fail('trackIdList missing');
}
if (!array_key_exists('trackLengthList', $json)) {
  fail('trackLengthList missing');
}
if (!array_key_exists('trackBpmList', $json)) {
  fail('trackBpmList missing');
}
if (!array_key_exists('trackGenreList', $json)) {
  fail('trackGenreList missing');
}
if (!array_key_exists('bpmRangeList', $json)) {
  fail('bpmRangeList missing');
}
if (!array_key_exists('bpmDifferenceList', $json)) {
  fail('bpmDifferenceList missing');
}
if (!array_key_exists('danceSlotSameGenre', $json)) {
  fail('danceSlotSameGenre missing');
}
if (!array_key_exists('danceLengthRange', $json)) {
  fail('danceLengthRange missing');
}
$track_ids = $json['trackIdList'];
$track_lengths = $json['trackLengthList'];
$bpms = $json['trackBpmList'];
$genres = $json['trackGenreList'];
$dance_length_range = $json['danceLengthRange'];
if (count($track_ids) == 0) {
  fail('no track IDs');
}
if (count($track_lengths) == 0) {
  fail('no track lengths');
}
if (count($bpms) == 0) {
  fail('no BPMs');
}
if (count($genres) == 0) {
  fail('no genres');
}
if ( count($track_ids) != count($track_lengths) ||
     count($track_ids) != count($bpms) ||
     count($track_ids) != count($genres)
   )
{
  fail('inconsistent number of track IDs, lengths, BPMs and/or genres');
}
if (count($dance_length_range) != 2) {
  fail('unexpected number of values in danceLengthRange');
}
$ranges = $json['bpmRangeList'];
$diffs = $json['bpmDifferenceList'];
if (count($ranges) == 0) {
  fail('no BPM ranges');
}
if (count($diffs) == 0) {
  fail('no BPM differences');
}
if (count($ranges) != count($diffs)+1) {
  fail('inconsistent number of BPM ranges and differences');
}

// Randomize track order
$a = array_zip($track_ids, $track_lengths, $bpms, $genres);
shuffle($a);
$track_ids     = array_map(function ($l) { return $l[0]; }, $a);
$track_lengths = array_map(function ($l) { return $l[1]; }, $a);
$bpms          = array_map(function ($l) { return $l[2]; }, $a);
$genres        = array_map(function ($l) { return $l[3]; }, $a);

// Generate model input
$dzn_content = "";
$dzn_content .= "bpm = [" . implode(',', $bpms) . "];\n";
$dzn_content .= "lengths = [" . implode(',', $track_lengths) . "];\n";
$dzn_content .= "min_length = $dance_length_range[0];\n";
$dzn_content .= "max_length = $dance_length_range[1];\n";
$dzn_content .= "genres = [" . implode(',', $genres) . "];\n";
$dzn_content .= "ranges = [|" .
                 implode( '|'
                        , array_map( function ($l) { return implode(',', $l); }
                                   , $ranges
                                   )
                        ) .
                "|];\n";
$dzn_content .= "diffs = [|" .
                 implode( '|'
                        , array_map( function ($l) { return implode(',', $l); }
                                   , $diffs
                                   )
                        ) .
                "|];\n";
$dzn_content .= "dance_slot_same_genre = " .
                ($json['danceSlotSameGenre'] ? "true" : "false") .
                "\n;";
$dir = createTempDir();
$clean_up_fun = function () use ($dir) { shell_exec("rm -rf $dir"); };
if ($dir === false) {
  fail('failed to create temp dir');
}
$dzn_file = $dir . '/input.dzn';
$fh = fopen($dzn_file, 'w');
if ($fh === false) {
  $clean_up_fun();
  fail('failed to open model input file');
}
if (fwrite($fh, $dzn_content) === false) {
  $clean_up_fun();
  fail('failed to write model input file');
}

// Solve model and get output
$time_limit_s = 10;
$time_limit_ms = $time_limit_s*1000;
$res = shell_exec( "minizinc model.mzn $dzn_file " .
                   "--time-limit $time_limit_ms " .
                   "--unbounded-msg '' " .
                   "--unknown-msg '' " .
                   "--search-complete-msg '' " .
                   "--soln-sep '' " .
                   "2> /dev/null"
                 );
$json = fromJson($res);
if (is_null($json)) {
  $clean_up_fun();
  fail("no solution found");
}

// Build new track list
$new_track_list = [];
foreach ($json['trackOrder'] as $i) {
  $new_track_list[] = $i > 0 ? $track_ids[$i-1] : '';
}

$clean_up_fun();

echo(toJson(['status' => 'OK', 'trackOrder' => $new_track_list]));

} // End try
catch (NoSessionException $e) {
  echo(toJson(['status' => 'NOSESSION']));
}
catch (\Exception $e) {
  echo(toJson(['status' => 'FAILED', 'msg' => $e->getMessage()]));
}
?>
