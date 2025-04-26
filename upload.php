<?php
session_start();

require __DIR__ . '/vendor/autoload.php';
use Smalot\PdfParser\Parser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;


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

                if (preg_match('/\bshift\W*manager\b/iu', $type)) {
   
    $type = preg_replace(
    '/\bShift\s+Manager\b/iu',
    'Supervisor',
    $type
);
    
}

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
        } elseif (strpos($t, 'supervisor') !== false || strpos($t, 'shift manager') !== false) {
          
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

    // build workbook & sheet
    $ss    = new Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('Payroll Memo');

    // 1) Top header: Payroll Memo 
       $storeId = trim($_GET['storeId'] ?? '0431');
    $date    = trim($_GET['date']    ?? date('n/j/Y'));

    $sheet->mergeCells('A1:G2');
    $sheet->setCellValue('A1', 'Payroll Memo from Store '.$storeId);
    // style font
    $sheet->getStyle('A1')->getFont()
          ->setBold(true)
          ->setSize(14);
    // align cell (not the font!)
    $sheet->getStyle('A1')->getAlignment()
          ->setHorizontal(Alignment::HORIZONTAL_LEFT);

    // 2) Second header: Week Ending 4/20/2025
    
    //$sheet->setCellValue('C3', '4/20/2025');
    // bold + vertical center
    $sheet->getStyle('A3:G3')->getFont()->setBold(true);
    $sheet->getStyle('A3:G3')->getAlignment()
          ->setVertical(Alignment::VERTICAL_CENTER);
          

          $font = $sheet->getStyle('A3')->getFont();
$font->getColor()->setARGB(Color::COLOR_RED);
$font->setSize(16);

$font = $sheet->getStyle('B3')->getFont();
$font->getColor()->setARGB(Color::COLOR_RED);
$font->setSize(16);

    // 3) Column headers on row 3
    $headerRow = 3;
    $cols   = ['A','B','C','D','E','F','G'];
    $labels = [
      'Week Ending',$date,'Hours Worked','Annual Leave',
      'Sick Leave','DIL Taken','LWOP Taken'
    ];
    foreach ($cols as $i => $col) {
      $sheet->setCellValue("{$col}{$headerRow}", $labels[$i]);
      $sheet->getStyle("{$col}{$headerRow}")->getFont()->setBold(true);
      $sheet->getStyle("{$col}{$headerRow}")
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFFFF');
    }

    // 4) Re-build buckets from session data
    $fullTimeCrew = $partTimeCrew = $supervisors = $managers = $others= [];
    foreach ($data as $r) {
        $t = strtolower($r['type']);


        $t = preg_replace('/\s+/u', ' ', trim($t));

        if (strpos($t, 'full time crew') !== false) {
            $fullTimeCrew[] = $r;
        } elseif (strpos($t, 'part time crew') !== false || strpos($t, 'part time maintenance') !== false) {
            $partTimeCrew[] = $r;
        } elseif (strpos($t, 'supervisor') !== false || strpos($t, 'shift manager') !== false) {
         
            $supervisors[] = $r;
        } elseif (strpos($t, 'manager') !== false) {
            $managers[] = $r;
        } else {
            $others[] = $r;
        }
    }

    // 5) Write each group in order
     $groups = [
  'Full Timers'               => $fullTimeCrew,
  'Part Timers'               => $partTimeCrew,
  'Level 2 Shift Supervisors' => $supervisors,
  'Level 4 Salaried Managers' => $managers,
  'Notes'                     => $others,
];


    $row = $headerRow + 1;
    foreach ($groups as $title => $bucket) {
      // category row
      $sheet->mergeCells("A{$row}:G{$row}");
      $sheet->setCellValue("A{$row}", $title);
      $sheet->getStyle("A{$row}")->getFont()->setBold(true);
      $sheet->getStyle("A{$row}")
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFCCEEFF');
      $row++;
      // rows
      foreach ($bucket as $r) {
        $sheet->setCellValue("A{$row}", $r['name']);
        $sheet->setCellValue("B{$row}", $r['type']);
        $sheet->setCellValue("C{$row}", $r['actual']);
        $row++;
      }
      $row++; // spacer
    }

    // 6) Auto-size & borders
    foreach ($cols as $col) {
      $sheet->getColumnDimension($col)->setAutoSize(true);
      $sheet
        ->getStyle("{$col}1:{$col}{$row}")
        ->getBorders()
        ->getAllBorders()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    }

    // 7) Send to browser
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Payroll_Memo.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($ss))->save('php://output');
    exit;
}


// 8) SHOW HTML VIEW
$results = $_SESSION['results'] ?? [];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    exit('No PDF uploaded.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Weekly Payroll Totals</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-5">
  <h2>Extracted Weekly Payroll Totals</h2>

  <?php if (empty($results)): ?>
    <div class="alert alert-warning">No records found.</div>
  <?php else: ?>
    <table class="table table-bordered">
      <thead>
        <tr><th>Name</th><th>Type</th><th>Actual Hours</th></tr>
      </thead>
      <tbody>
        <?php foreach ($results as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['name']) ?></td>
          <td><?= htmlspecialchars($r['type']) ?></td>
          <td><?= htmlspecialchars($r['actual']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <form method="get" class="d-inline">
  <input type="hidden" name="export" value="1">
  <div class="row gx-2 gy-1 align-items-center">
    <div class="col-auto">
      <label for="storeId" class="form-label mb-0">Store ID</label>
      <input
        type="text"
        id="storeId"
        name="storeId"
        class="form-control"
        required
        value="<?= htmlspecialchars($_GET['storeId'] ?? '') ?>"
      >
    </div>
    <div class="col-auto">
      <label for="date" class="form-label mb-0">Week Ending</label>
      <input
        type="text"
        id="date"
        name="date"
        class="form-control"
        placeholder="dd/mm/YYYY"
        required
        value="<?= htmlspecialchars($_GET['date'] ?? '') ?>"
      >
    </div>
    <div class="col-auto">
      <button type="submit" class="btn btn-success">Export to Excel</button>
    </div>
  </div>
</form>

  <?php endif; ?>
  <a href="index.html" class="btn btn-secondary mt-3">Upload Another PDF</a>
</body>
<footer class="mt-5 text-center">
  <small>
    Concept and development by
    <a href="https://www.linkedin.com/in/heeshala/" target="_blank" rel="noopener">
      Heeshala
    </a>
  </small>
</footer>

</html>
