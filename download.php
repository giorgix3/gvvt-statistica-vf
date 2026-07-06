<?php
declare(strict_types=1);
session_start();

// Guard
if (empty($_SESSION['vf_token'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use Gvvt\Env;
use Gvvt\VereinsfliegerClient;
use Gvvt\FlightAssembler;

Env::load(__DIR__ . '/.env');
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$requestedMonth = $_GET['month'] ?? $_SESSION['export_month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $requestedMonth)) {
    $requestedMonth = date('Y-m');
}

// Re-use cached rows from session if available and for the same month
if (
    !empty($_SESSION['export_rows'])
    && isset($_SESSION['export_month'])
    && $_SESSION['export_month'] === $requestedMonth
) {
    $outputRows = $_SESSION['export_rows'];
} else {
    // Re-fetch (e.g. direct URL access)
    $vf = new VereinsfliegerClient();
    if (!$vf->setToken($_SESSION['vf_token'])) {
        session_destroy();
        header('Location: index.php?expired=1');
        exit;
    }
    $assembler  = new FlightAssembler();
    $rawFlights = $vf->getFlightsMonth($requestedMonth);
    $assembled  = $assembler->assembleFlights($rawFlights);
    $outputRows = $assembler->prepareOutput($assembled);
}

$fields = ['RWY','RWYPOS','DATUM','SP','SPPILOT','SF','SFPILOT',
           'SCHULUNG','PAX','SPSTART','SPLANDUNG','SFSTART','SFLANDUNG','GRUPPE','PRIVAT'];

// ── Build the spreadsheet ────────────────────────────────────────────────────
$ss  = new Spreadsheet();
$ws  = $ss->getActiveSheet();
$ws->setTitle('VoliGVVT');

// Header row
$ws->fromArray([$fields], null, 'A1');

// Style header
$headerStyle = [
    'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E3A8A']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
];
$ws->getStyle('A1:' . $ws->getHighestColumn() . '1')->applyFromArray($headerStyle);

// Data rows
foreach ($outputRows as $i => $row) {
    $values = array_map(fn($f) => $row[$f] ?? '', $fields);
    $ws->fromArray([$values], null, 'A' . ($i + 2));
}

// Auto-width columns
foreach (range('A', $ws->getHighestColumn()) as $col) {
    $ws->getColumnDimension($col)->setAutoSize(true);
}

// Freeze header row
$ws->freezePane('A2');

// ── Stream to browser ────────────────────────────────────────────────────────
$filename = 'Statistica_' . $requestedMonth . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($ss);
$writer->save('php://output');
exit;
