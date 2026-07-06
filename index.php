<?php
declare(strict_types=1);
session_start();

// Already logged in → skip to report
if (!empty($_SESSION['vf_token'])) {
    header('Location: report.php');
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use Gvvt\Env;
use Gvvt\VereinsfliegerClient;

Env::load(__DIR__ . '/.env');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !empty($_POST['username'])
    && !empty($_POST['password'])
) {
    $vf = new VereinsfliegerClient();
    if ($vf->signIn(
        trim($_POST['username']),
        $_POST['password']
    )) {
        $_SESSION['vf_token'] = $vf->getToken();
        header('Location: report.php');
        exit;
    }
    $error = 'Wrong username or password. Please try again.';
}

$selfUrl = htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Statistica GVVT</title>
  <style>
    /* ── Reset & base ── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { height: 100%; }
    body {
      min-height: 100%;
      font-family: -apple-system, "Segoe UI", system-ui, sans-serif;
      font-size: 15px;
      line-height: 1.6;
      color: #1a1f2e;
      background: #f0f2f7;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px 16px;
    }

    /* ── Card ── */
    .card {
      background: #ffffff;
      border-radius: 10px;
      border: 1px solid #dde1ec;
      box-shadow: 0 2px 12px rgba(0,0,0,.07);
      width: 100%;
      max-width: 380px;
      padding: 40px 36px 32px;
    }

    /* ── Header ── */
    .card-header {
      text-align: center;
      margin-bottom: 28px;
    }
    .card-header img {
      width: 72px;
      height: auto;
      margin-bottom: 14px;
    }
    .card-header h1 {
      font-size: 20px;
      font-weight: 700;
      letter-spacing: -.3px;
      color: #1a1f2e;
    }
    .card-header p {
      font-size: 13px;
      color: #6b7280;
      margin-top: 2px;
    }

    /* ── Form ── */
    .form-group { margin-bottom: 16px; }
    label {
      display: block;
      font-size: 12px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .6px;
      color: #4b5563;
      margin-bottom: 5px;
    }
    input[type="text"],
    input[type="email"],
    input[type="password"] {
      display: block;
      width: 100%;
      padding: 9px 12px;
      font-size: 14px;
      color: #1a1f2e;
      background: #f8f9fc;
      border: 1px solid #dde1ec;
      border-radius: 6px;
      outline: none;
      transition: border-color .15s, box-shadow .15s;
    }
    input:focus {
      border-color: #3b6fd4;
      box-shadow: 0 0 0 3px rgba(59,111,212,.12);
      background: #fff;
    }

    /* ── Error ── */
    .alert {
      background: #fef2f2;
      border: 1px solid #fecaca;
      color: #b91c1c;
      border-radius: 6px;
      padding: 10px 14px;
      font-size: 13px;
      margin-bottom: 18px;
    }

    /* ── Button ── */
    .btn-primary {
      display: block;
      width: 100%;
      padding: 10px;
      font-size: 14px;
      font-weight: 600;
      color: #fff;
      background: #1e3a8a;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      letter-spacing: .2px;
      transition: background .15s;
    }
    .btn-primary:hover { background: #1e40af; }
    .btn-primary:active { background: #1e3a8a; }

    /* ── Footer link ── */
    .card-footer {
      text-align: center;
      margin-top: 20px;
      font-size: 12px;
      color: #9ca3af;
    }
    .card-footer a { color: #3b6fd4; text-decoration: none; }
    .card-footer a:hover { text-decoration: underline; }

    /* ── Made-with footer ── */
    .page-footer {
      position: fixed;
      bottom: 14px;
      left: 0; right: 0;
      text-align: center;
      font-size: 11px;
      color: #c4c9d4;
    }
  </style>
</head>
<body>

  <div class="card">
    <div class="card-header">
      <img src="https://gvvt.ch/wp-content/uploads/2017/03/gvvt_logo.png"
           alt="GVVT logo">
      <h1>Statistica GVVT</h1>
      <p>Vereinsflieger · Locarno LSZL</p>
    </div>

    <?php if ($error !== ''): ?>
      <div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" action="<?= $selfUrl ?>" novalidate>
      <div class="form-group">
        <label for="username">Email</label>
        <input type="email" id="username" name="username"
               placeholder="nome@esempio.ch"
               autocomplete="username"
               required autofocus
               value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               placeholder="••••••••"
               autocomplete="current-password"
               required>
      </div>
      <button class="btn-primary" type="submit">Accedi</button>
    </form>

    <div class="card-footer">
      <a href="https://vereinsflieger.de/PasswortAnfordern" target="_blank" rel="noopener">
        Password dimenticata?
      </a>
    </div>
  </div>

</body>
</html>
