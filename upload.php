<?php
session_start();

require __DIR__ . '/vendor/autoload.php';
use Smalot\PdfParser\Parser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// 1) HANDLE UPLOAD (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['pdf']['tmp_name'])) {
    $parser = new Parser();
    $text   = $parser->parseFile($_FILES['pdf']['tmp_name'])->getText();

    // 1a) Remove “Hours paid drink Break” noise
    $text = preg_replace(
        '/(?:Hours[ \x{00A0}\r\n]+paid[ \x{00A0}\r\n]+drink[ \x{00A0}\r\n]+Break)[\s]*/iu',
        '',
        $text
    );
    // 1b) Glue names split across lines before "Weekly Totals"
    $text = preg_replace('/\R+(?=\s*Weekly\s+Totals)/u', ' ', $text);

    // 2) Extract “Name Weekly Totals H.M $C.C Actual”
    $wtPattern = '/
        (?P<name>[\p{L}\'’\-\x{00AD}]+
          (?:[ \x{00A0}\r\n]+[\p{L}\'’\-\x{00AD}]+)+
        )
        \s+Weekly\s+Totals\s+
        (?P<hours>\d+\.\d{2})\s+
        \$[\d,]+\.\d{2}\s+
        (?P<actual>\d+\.\d{2})
    /imux';
    preg_match_all($wtPattern, $text, $matches, PREG_SET_ORDER);

    // 3) Status+position regex
    $statusPosPattern = '/\b
        (Casual|Part\s*Time|Full\s*Time)\s+
        (Crew|Maintenance|Supervisor|Shift\s*Manager|
         Salaried\s*Manager|Restaurant\s*Manager)
    \b/ixu';

    // 4) GROUP INTO BUCKETS
    $fullTimeCrew  = [];
    $partTimeCrew  = [];
    $supervisors   = [];
    $managers      = [];
    $others        = [];

    $results = [];
    foreach ($matches as $m) {
        // 3a) Normalize name (collapse whitespace, keep first + last two parts)
        $clean = preg_replace('/\s+/', ' ', trim($m['name']));
        $parts = explode(' ', $clean);
        if (count($parts) > 2) {
            $parts = array_merge([$parts[0]], array_slice($parts, -2));
        }
        $name = ucwords(strtolower(implode(' ', $parts)));


        // 3b) Determine type via expanding look-back
        $offset = mb_strpos($text, $m[0]);
        $type   = 'Unknown';
        foreach ([200, 500, 1000, strlen($text)] as $len) {
            $start = max(0, $offset - $len);
            $ctx   = mb_substr($text, $start, $len);
            if (preg_match_all($statusPosPattern, $ctx, $all, PREG_SET_ORDER)) {
                $last  = end($all);
                $type  = ucwords(strtolower($last[1] . ' ' . $last[2]));
                break;
            }
        }

        // 3c) Skip pure Casual Crew
        if (strtolower($type) === 'casual crew') {
            continue;
        }

        //Customize name
        // collapse any stray NBSPs → spaces (just in case)
$name = str_replace("\xC2\xA0", ' ', $name);

// split on any run of whitespace
$parts = preg_split('/\s+/u', trim($name));

// the number of “names” (words) is:
$wordCount = count($parts);


  if ($wordCount > 3) {
    $first    = $parts[0];
    $lastTwo  = array_slice($parts, -2);       // returns an array of the last 2
    $lastTwoStr = implode(' ', $lastTwo);
    $name    = $first . ' ' . implode(' ', $lastTwo);



}
  

//uppercase name and type
$name = ucwords(strtolower($name));

$decimal = (float)$m['actual'];

// 1) split into whole hours and remainder
$hours   = floor($decimal);
$minutes = round(($decimal - $hours) * 60);

// 2) handle a possible 60→ carry
if ($minutes === 60) {
    $hours   += 1;
    $minutes = 0;
}

// 3) format as H:MM
$hms = sprintf('%d.%02d', $hours, $minutes);



        $results[] = [
            'name'   => $name,
            'type'   => $type,
            'actual' => $hms,
        ];


    }

    

    foreach ($results as $r) {
        $t = strtolower($r['type']);
        $t = preg_replace('/\s+/u', ' ', trim($t));

        if (strpos($t, 'full time crew') !== false) {
            $fullTimeCrew[] = $r;
        } elseif (strpos($t, 'part time crew') !== false || strpos($t, 'part time maintenance') !== false) {
            $partTimeCrew[] = $r;
        } elseif (strpos($t, 'supervisor') !== false) {
            $supervisors[] = $r;
        } elseif (strpos($t, 'manager') !== false) {
            $managers[] = $r;
        } else {
            $others[] = $r;
        }
    }


    // 5) SORT EACH BUCKET ALPHABETICALLY BY NAME
    $sortByName = function(array &$bucket) {
    usort($bucket, function($a, $b) {
        $aParts = explode(' ', $a['name']);
        $bParts = explode(' ', $b['name']);
        // use the second element if it exists, otherwise the first
        $aKey = isset($aParts[1]) ? $aParts[1] : $aParts[0];
        $bKey = isset($bParts[1]) ? $bParts[1] : $bParts[0];
        $cmp  = strcasecmp($aKey, $bKey);
        return $cmp !== 0
            ? $cmp
            : strcasecmp($a['name'], $b['name']);  // tie-breaker
    });
};


    $sortByName($fullTimeCrew);
    $sortByName($partTimeCrew);
    $sortByName($supervisors);
    $sortByName($managers);
    $sortByName($others);

    //Then partition them into non-casual vs casual
$nonCasualSup = [];
$casualSup    = [];

foreach ($supervisors as $r) {
    if (stripos($r['type'], 'Casual Supervisor') !== false) {
        $casualSup[] = $r;
    } else {
        $nonCasualSup[] = $r;
    }
}

// Re-assemble with all casuals at the bottom
$supervisors = array_merge($nonCasualSup, $casualSup);

    // 6) FLATTEN IN THE DESIRED ORDER
    $ordered = array_merge(
        $fullTimeCrew,
        $partTimeCrew,
        $supervisors,
        $managers,
        $others
    );

    $_SESSION['results'] = $ordered;
}

// 7) EXPORT TO EXCEL (?export=1)
if (isset($_GET['export'])) {
    $data = $_SESSION['results'] ?? [];
    if (empty($data)) {
        exit('Nothing to export.');
    }

    $ss = new Spreadsheet();
    $sh = $ss->getActiveSheet();
    $sh->fromArray(['Name','Type','Actual Hours'], null, 'A1');

    $r = 2;
    foreach ($data as $row) {
        $sh
            ->setCellValue("A{$r}", $row['name'])
            ->set