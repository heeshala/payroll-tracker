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




        $results[] = [
            'name'   => $name,
            'type'   => $type,
            'actual' => (float)$m['actual'],
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
    $sortByName = function(&$bucket) {
        usort($bucket, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    };
    $sortByName($fullTimeCrew);
    $sortByName($partTimeCrew);
    $sortByName($supervisors);
    $sortByName($managers);
    $sortByName($others);

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
            ->setCellValue("B{$r}", $row['type'])
            ->setCellValue("C{$r}", $row['actual']);
        $r++;
    }
    foreach (range('A','C') as $col) {
        $sh->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="weekly_totals.xlsx"');
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
          <td><?= number_format($r['actual'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <a href="?export=1" class="btn btn-success">Export to Excel</a>
  <?php endif; ?>
  <a href="index.html" class="btn btn-secondary mt-3">Upload Another PDF</a>
</body>
</html>
