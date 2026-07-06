<?php
declare(strict_types=1);
session_start();

// Guard: must be logged in
if (empty($_SESSION['vf_token'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use Gvvt\Env;
use Gvvt\VereinsfliegerClient;
use Gvvt\FlightAssembler;

Env::load(__DIR__ . '/.env');

// Determine which month to show
$requestedMonth = $_GET['month'] ?? '';
if (!preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $requestedMonth)) {
    $requestedMonth = date('Y-m');
}

// Restore the session token
$vf = new VereinsfliegerClient();
if (!$vf->setToken($_SESSION['vf_token'])) {
    // Token expired — back to login
    session_destroy();
    header('Location: index.php?expired=1');
    exit;
}

// Fetch & assemble flights
$rawFlights   = $vf->getFlightsMonth($requestedMonth);
$assembler    = new FlightAssembler();
$assembled    = $assembler->assembleFlights($rawFlights);
$summary      = $assembler->prepareSummary($assembled);
$outputRows   = $assembler->prepareOutput($assembled);

// Store export data in session so download.php can pick it up without re-fetching
$_SESSION['export_rows']  = $outputRows;
$_SESSION['export_month'] = $requestedMonth;

// Build month nav list (current + 11 prior)
$months = [];
for ($i = 0; $i < 12; $i++) {
    $months[] = [
        'value' => date('Y-m', strtotime("-$i months")),
        'label' => date('M Y', strtotime("-$i months")),
    ];
}

// Summary field definitions
$summaryFields = ['GT','TS','TN','TP','TT','AS','AN','AP','AT','DG'];
$totals        = array_fill_keys($summaryFields, 0);

$displayMonth = date('F Y', strtotime($requestedMonth . '-01'));
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Statistica GVVT · <?= htmlspecialchars($displayMonth, ENT_QUOTES, 'UTF-8') ?></title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, "Segoe UI", system-ui, sans-serif;
      font-size: 14px;
      line-height: 1.55;
      color: #1a1f2e;
      background: #f0f2f7;
      min-height: 100vh;
    }

    /* ── Top bar ── */
    .topbar {
      background: #f0f2f7;
      border-bottom: 1px solid #dde1ec;
      color: #1a1f2e;
      padding: 0 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      height: 52px;
      gap: 12px;
    }
    .topbar-brand {
      display: flex;
      align-items: center;
      gap: 10px;
      font-weight: 700;
      font-size: 15px;
      letter-spacing: -.2px;
      text-decoration: none;
      color: #1a1f2e;
    }
    .topbar-brand img { width: 32px; height: auto; }
    .topbar-logout {
      font-size: 12px;
      color: #3b6fd4;
      text-decoration: none;
    }
    .topbar-logout:hover { color: #1a1f2e; }

    /* ── Layout ── */
    .page { max-width: 960px; margin: 0 auto; padding: 28px 20px 48px; }

    /* ── Month nav ── */
    .month-nav {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-bottom: 28px;
    }
    .month-btn {
      padding: 6px 14px;
      font-size: 13px;
      font-weight: 500;
      border-radius: 5px;
      border: 1px solid #dde1ec;
      background: #fff;
      color: #374151;
      text-decoration: none;
      transition: background .12s, border-color .12s;
    }
    .month-btn:hover { background: #eef1f8; border-color: #bfc6d9; }
    .month-btn.active {
      background: #1e3a8a;
      border-color: #1e3a8a;
      color: #fff;
    }

    /* ── Section heading ── */
    .section-title {
      font-size: 17px;
      font-weight: 700;
      color: #1a1f2e;
      margin-bottom: 14px;
      display: flex;
      align-items: baseline;
      gap: 10px;
    }
    .section-title span {
      font-size: 13px;
      font-weight: 400;
      color: #6b7280;
    }

    /* ── Table ── */
    .tbl-wrap { overflow-x: auto; margin-bottom: 28px; }
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
      background: #fff;
      border-radius: 8px;
      overflow: hidden;
      border: 1px solid #dde1ec;
    }
    th, td {
      padding: 8px 12px;
      border-bottom: 1px solid #edf0f7;
      text-align: center;
      white-space: nowrap;
    }
    th {
      background: #f4f6fb;
      font-weight: 600;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .5px;
      color: #4b5563;
    }
    td:first-child, th:first-child { text-align: left; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #f8f9fc; }

    /* column-group separators */
    .sep { background: #e8ecf5 !important; width: 8px; padding: 0; }

    /* total row */
    .row-total td {
      font-weight: 700;
      background: #f4f6fb;
      border-top: 2px solid #dde1ec;
    }
    .row-total:hover td { background: #f4f6fb; }

    /* ── Download button ── */
    .btn-download {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 9px 20px;
      font-size: 13px;
      font-weight: 600;
      color: #fff;
      background: #166534;
      border: none;
      border-radius: 6px;
      text-decoration: none;
      cursor: pointer;
      transition: background .14s;
    }
    .btn-download:hover { background: #15803d; }

    /* ── Empty state ── */
    .empty {
      text-align: center;
      padding: 40px 20px;
      color: #6b7280;
      background: #fff;
      border: 1px solid #dde1ec;
      border-radius: 8px;
      font-size: 14px;
    }

    /* ── Footer ── */
    .page-footer {
      text-align: center;
      padding-top: 32px;
      font-size: 11px;
      color: #c4c9d4;
      border-top: 1px solid #dde1ec;
      margin-top: 40px;
    }
  </style>
</head>
<body>

  <nav class="topbar">
    <a class="topbar-brand" href="report.php">
      <img src="https://gvvt.ch/wp-content/uploads/2017/03/gvvt_logo.png" alt="GVVT">
      Statistica GVVT
    </a>
    <a class="topbar-logout" href="logout.php">Esci</a>
  </nav>

  <div class="page">

    <!-- Month picker -->
    <nav class="month-nav">
      <?php foreach ($months as $m): ?>
        <a href="report.php?month=<?= urlencode($m['value']) ?>"
           class="month-btn<?= $m['value'] === $requestedMonth ? ' active' : '' ?>">
          <?= htmlspecialchars($m['label'], ENT_QUOTES, 'UTF-8') ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <?php if (empty($summary)): ?>
      <div class="empty">Nessun volo registrato per <?= htmlspecialchars($displayMonth, ENT_QUOTES, 'UTF-8') ?>.</div>
    <?php else: ?>

      <!-- Summary table -->
      <div class="section-title">
        Riepilogo
        <span><?= htmlspecialchars($displayMonth, ENT_QUOTES, 'UTF-8') ?></span>
      </div>

      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>Aereo</th>
              <th class="sep"></th>
              <th colspan="4">Voli di Traino</th>
              <th class="sep"></th>
              <th colspan="4">Voli Autonomi</th>
              <th class="sep"></th>
              <th>Dogana</th>
              <th>TOT</th>
            </tr>
            <tr>
              <th></th>
              <th class="sep"></th>
              <th>Scuola</th><th>Normale</th><th>PAX</th><th>TOT</th>
              <th class="sep"></th>
              <th>Scuola</th><th>Normale</th><th>PAX</th><th>TOT</th>
              <th class="sep"></th>
              <th></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($summary as $callsign => $row): ?>
              <?php foreach ($summaryFields as $f) { $totals[$f] += $row[$f]; } ?>
              <tr>
                <td><strong><?= htmlspecialchars($callsign, ENT_QUOTES, 'UTF-8') ?></strong></td>
                <td class="sep"></td>
                <td><?= $row['TS'] ?></td>
                <td><?= $row['TN'] ?></td>
                <td><?= $row['TP'] ?></td>
                <td><strong><?= $row['TT'] ?></strong></td>
                <td class="sep"></td>
                <td><?= $row['AS'] ?></td>
                <td><?= $row['AN'] ?></td>
                <td><?= $row['AP'] ?></td>
                <td><strong><?= $row['AT'] ?></strong></td>
                <td class="sep"></td>
                <td><?= $row['DG'] ?></td>
                <td><strong><?= $row['GT'] ?></strong></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="row-total">
              <td>TOTALE</td>
              <td class="sep"></td>
              <td><?= $totals['TS'] ?></td>
              <td><?= $totals['TN'] ?></td>
              <td><?= $totals['TP'] ?></td>
              <td><?= $totals['TT'] ?></td>
              <td class="sep"></td>
              <td><?= $totals['AS'] ?></td>
              <td><?= $totals['AN'] ?></td>
              <td><?= $totals['AP'] ?></td>
              <td><?= $totals['AT'] ?></td>
              <td class="sep"></td>
              <td><?= $totals['DG'] ?></td>
              <td><?= $totals['GT'] ?></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Download -->
      <a class="btn-download"
         href="download.php?month=<?= urlencode($requestedMonth) ?>">
        ↓&nbsp; Scarica statistica (.xlsx)
      </a>

    <?php endif; ?>

  </div>

</body>
</html>
