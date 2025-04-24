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

    // glue names split across lines
    $text = preg_replace('/\R+(?=\s*Weekly\s+Totals)/u', ' ', $text);
    $text = preg_replace(
    '/(?:Hours[ \x{00A0}\r\n]+paid[ \x{00A0}\r\n]+drink[ \x{00A0}\r\n]+Break)[\s]*/iu',
    '',
    $text
);

    
    $pattern = '/
        (?P<name>[\p{L}\'’\-\x{00AD}]+(?:[ \x{00A0}\r\n]+[\p{L}\'’\-\x{00AD}]+)+)  # name (multi-part)
        \s+Weekly\s+Totals\s+
        (?P<hours>\d+\.\d{2})\s+
        \$[\d,]+\.\d{2}\s+
        (?P<actual>\d+\.\d{2})
    /imux';

    preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);

    $results = [];
    foreach ($matches as $m) {
        $name = ucwords(strtolower(preg_replace('/\s+/',' ',$m['name'])));


        $offset = mb_strpos($text, $m[0]);
        $context = mb_substr($text, max(0, $offset - 200), 200);

        // 2) IMPROVED TYPE DETECTION
        if (preg_match('/\b(Casual\s+Crew|Part\s*Time\s+Crew|Full\s*Time\s+Crew)\b/iu', $context, $t)) {
            $type = ucwords(strtolower($t[1]));
        }
        // fallback for just “Casual” / “Part Time” / “Full Time”
        elseif (preg_match('/\b(Casual|Part\s*Time|Full\s*Time)\b/iu', $context, $t2)) {
            $raw = strtolower($t2[1]);
            $type = $raw === 'casual'
                  ? 'Casual Crew'
                  : ($raw === 'part time'
                      ? 'Part Time Crew'
                      : 'Full Time Crew');
        } else {
            $type = 'Unknown';
        }

        // 3) SKIP ANY CASUAL CREW
        if (stripos($type, 'Casual') !== false) {
            continue;
        }

        $results[] = [
            'name'   => $name,
            'type'   => $type,
            'hours'  => (float)$m['hours'],
            'actual' => (float)$m['actual'],
        ];
    }

    $_SESSION['results'] = $results;
}

// 4) EXPORT TO EXCEL (?export=1)
if (isset($_GET['export'])) {
    $data = $_SESSION['results'] ?? [];
    if (empty($data)) {
        exit('Nothing to export.');
    }

    $ss = new Spreadsheet();
    $sh = $ss->getActiveSheet();
    $sh->fromArray(['Name','Type','Total Hours','Actual Hours'], null, 'A1');

    $r = 2;
    foreach ($data as $row) {
        $sh->setCellValue("A{$r}", $row['name'])
           ->setCellValue("B{$r}", $row['type'])
           ->setCellValue("C{$r}", $row['hours'])
           ->setCellValue("D{$r}", $row['actual']);
        $r++;
    }

    foreach (range('A','D') as $col) {
        $sh->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="weekly_totals.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($ss))->save('php://output');
    exit;
}

// 5) SHOW HTML VIEW
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
        <tr>
          <th>Name</th>
          <!--<th>Type</th><th>Total Hours</th>-->
          <th>Actual Hours</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($results as $r): ?>
        <tr>
          <td><?=htmlspecialchars($r['name'])?></td>
          <!--<td><?=htmlspecialchars($r['type'])?></td>
          <td><?=number_format($r['hours'],2)?></td>-->
          <td><?=number_format($r['actual'],2)?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <a href="?export=1" class="btn btn-success">Export to Excel</a>
  <?php endif; ?>
  <a href="index.html" class="btn btn-secondary mt-3">Upload Another PDF</a>
</body>
</html>
