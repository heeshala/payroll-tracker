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

    // Normalize NBSP → plain space
    $text = preg_replace('/\x{00A0}/u', ' ', $text);

    $text = preg_replace('/\R+(?=\s*Weekly\s+Totals)/iu', ' ', $text);


    // Split into lines
    $lines = preg_split('/\R/u', $text);

    $results     = [];
    $currentType = null;

    foreach ($lines as $line) {
        $line = trim($line);

        // Reset at each new employee block
        if (preg_match('/^Payroll\s+number:/i', $line)) {
            $currentType = null;
            continue;
        }

        // Detect crew type from the first "Shift" header
        if (preg_match(
    '/\(\s*      # open paren, optional space
     [^()]*      # anything until our keyword
     \b          # word‑boundary
     (           # capture group 1: entire type phrase
       (?:Casual|Part\s*Time|Full\s*Time)    # base
       (?:\s+[A-Za-z]+(?:\s+[A-Za-z]+)*)?    # optional extra words (e.g. Shift Manager, Maintenance)
     )
     \b           # end word
    /ix',
    $line,
    $m
)) {
    // m[1] is now "Casual", "Part Time Crew", "Part Time Maintenance",
    // "Part Time Shift Manager", "Casual Shift Manager", etc.
    $currentType = ucwords(strtolower($m[1]));
    continue;
}

        // Only lines with "Weekly Totals" matter now
        if (stripos($line, 'Weekly Totals') === false) {
            continue;
        }

        // Match: Name Weekly Totals <left> $<cost> <actual>
        if (preg_match('/^(.+?)\s+Weekly\s+Totals\s+\d+\.\d{2}\s+\$[\d,]+\.\d{2}\s+(\d+\.\d{2})$/i', $line, $m)) {
            $name  = ucwords(strtolower($m[1]));
            $hours = (float)$m[2];

            // skip casuals
            if ($currentType === 'Casual Crew') {
                continue;
            }

            $type = $currentType ?: 'Unknown';
            $results[] = compact('name','type','hours');
        }
    }

    $_SESSION['results'] = $results;
}

// ————————————————————————————————
// 2) EXPORT TO EXCEL (?export=1)
// ————————————————————————————————
if (isset($_GET['export'])) {
    $results = $_SESSION['results'] ?? [];
    if (empty($results)) {
        exit('Nothing to export.');
    }

    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['Name','Type','Total Hours'], null, 'A1');

    $row = 2;
    foreach ($results as $r) {
        $sheet
            ->setCellValue("A{$row}", $r['name'])
            ->setCellValue("B{$row}", $r['type'])
            ->setCellValue("C{$row}", $r['hours']);
        $row++;
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="weekly_totals.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

// ————————————————————————————————
// 3) BLOCK non‑POST
// ————————————————————————————————
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    exit('No PDF uploaded.');
}

// ————————————————————————————————
// 4) RENDER HTML VIEW
// ————————————————————————————————
$results = $_SESSION['results'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Extracted Weekly Payroll Totals</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="p-5">
  <h2>Extracted Weekly Payroll Totals</h2>

  <?php if (empty($results)): ?>
    <div class="alert alert-warning">No records found.</div>
  <?php else: ?>
    <table class="table table-bordered">
      <thead>
        <tr><th>Name</th><th>Type</th><th>Total Hours</th></tr>
      </thead>
      <tbody>
        <?php foreach ($results as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><?= htmlspecialchars($r['type']) ?></td>
            <td><?= number_format($r['hours'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <a href="?export=1" class="btn btn-success mt-3">Export to Excel</a>
  <?php endif; ?>

  <a href="index.html" class="btn btn-secondary mt-3">Upload Another PDF</a>
</body>
</html>
