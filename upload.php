<?php
session_start();

require __DIR__ . '/vendor/autoload.php';
use Smalot\PdfParser\Parser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// ————————————————————————————————
// 1) HANDLE UPLOAD (POST)
// ————————————————————————————————
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['pdf']['tmp_name'])) {
    $parser = new Parser();
    $pdf    = $parser->parseFile($_FILES['pdf']['tmp_name']);
    $text   = $pdf->getText();

    // Your existing regex + results‑building logic:
    $pattern = '/
        (?:                                   # optionally strip header
          Hours[ \x{00A0}\r\n]+paid
          [ \x{00A0}\r\n]+drink
          [ \x{00A0}\r\n]+Break
          [ \x{00A0}\r\n]+
        )?
        (?P<name>[\p{L}\'’\-\x{00AD}]+
          (?:[ \x{00A0}\r\n]+[\p{L}\'’\-\x{00AD}]+)+
        )
        [ \x{00A0}\r\n]*Weekly[ \x{00A0}\r\n]*Totals
        [ \x{00A0}\r\n]+
        (?P<hours>\d+\.\d{2})
        [ \x{00A0}\r\n]+
        \$(?P<cost>[\d,]+\.\d{2})
    /imux';

    preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);

    $results = [];
    foreach ($matches as $m) {
        $name  = ucwords(strtolower($m['name']));
        $hours = (float)$m['hours'];
        $cost  = (float)str_replace(',', '', $m['cost']);

        // look for type in nearby context
        $offset  = mb_strpos($text, $m[0]);
        $context = mb_substr($text, max(0, $offset - 500), 1000);
        if (preg_match('/\b(Casual|Part\s*Time|Full\s*Time)\b/iu', $context, $t)) {
            $type = ucwords(strtolower($t[1]));
        } else {
            $type = 'Unknown';
        }

        $results[] = compact('name','type','hours','cost');
    }

    // persist for export & rendering
    $_SESSION['results'] = $results;
}

// ————————————————————————————————
// 2) HANDLE EXPORT (GET ?export=1)
// ————————————————————————————————
if (isset($_GET['export'])) {
    $results = $_SESSION['results'] ?? [];
    if (empty($results)) {
        exit('Nothing to export.');
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // write header
    $sheet->fromArray(
        ['Name','Type','Total Hours'],
        null,
        'A1'
    );

    // write data rows
    $rowNum = 2;
    foreach ($results as $r) {
        $sheet->setCellValue("A{$rowNum}", $r['name'])
              ->setCellValue("B{$rowNum}", $r['type'])
              ->setCellValue("C{$rowNum}", $r['hours']);
        $rowNum++;
    }

    // send to browser
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="weekly_totals.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// ————————————————————————————————
// 3) BLOCK any other request
// ————————————————————————————————
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    exit('No PDF uploaded.');
}

// ————————————————————————————————
// 4) RENDER HTML (after a successful POST)
// ————————————————————————————————
$results = $_SESSION['results'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Extracted Weekly Totals</title>
  <link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
  >
</head>
<body class="p-5">
  <h2>Extracted Weekly Totals</h2>

  <?php if (empty($results)): ?>
    <div class="alert alert-warning">No records found.</div>
  <?php else: ?>
    <table class="table table-bordered">
      <thead>
        <tr>
          <th>Name</th>
          <th>Type</th>
          <th>Total Hours</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($results as $row): ?>
        <tr>
          <td><?= htmlspecialchars($row['name']) ?></td>
          <td><?= htmlspecialchars($row['type']) ?></td>
          <td><?= number_format($row['hours'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- export button -->
    <a href="?export=1" class="btn btn-success mt-3">Export to Excel</a>
  <?php endif; ?>

  <a href="index.html" class="btn btn-secondary mt-3">Upload Another PDF</a>
</body>
</html>
