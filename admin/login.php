<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Security;

$error = '';
$success = '';

if (isset($_GET['reset']) && $_GET['reset'] === 'success') {
    $success = __('Password has been successfully updated. You can now log in.');
}

$needsTotp = Auth::pendingTotpUserId() > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';

    if ($action === 'verify_totp') {
        $code = trim($_POST['totp_code'] ?? '');
        if (Auth::verifyTotp($code)) {
            header('Location: index.php');
            exit;
        }
        $error = __('Invalid authentication code.');
        $needsTotp = Auth::pendingTotpUserId() > 0;
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $result = Auth::login($username, $password);
        if ($result === 'ok') {
            header('Location: index.php');
            exit;
        } elseif ($result === 'totp') {
            $needsTotp = true;
        } elseif ($result === 'locked') {
            $error = __('Too many failed login attempts. Please try again shortly.');
        } else {
            $error = __('Invalid username or password.');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CMSbyPHP &bull; Logowanie do panelu</title>

    <script>
        (function() {
            const savedTheme = localStorage.getItem('cmsbyphp_theme') || localStorage.getItem('clean_cms_theme');
            if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.setAttribute('data-theme', 'dark');
            } else {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>

    <link rel="stylesheet" href="assets/css/admin.css">
    <style>
        body { 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            min-height: 100vh; 
            background: var(--bg-main); 
            color: var(--text-main);
            margin: 0; 
            font-family: system-ui, -apple-system, sans-serif; 
            padding: 20px;
        }
        .login-card { 
            width: 100%; 
            max-width: 400px; 
            padding: 36px 32px; 
            background: var(--bg-card); 
            border: 1px solid var(--border-subtle); 
            border-radius: 16px; 
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.15); 
            color: var(--text-main);
        }
        @media (max-width: 480px) {
            body { padding: 12px; }
            .login-card { padding: 24px 18px; border-radius: 14px; }
        }
        .form-group { margin-bottom: 18px; }
        .form-label-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
        .form-label { font-size: 13px; font-weight: 600; color: var(--text-main); }
        .form-control { 
            width: 100%; 
            padding: 10px 14px; 
            border-radius: 8px; 
            border: 1px solid var(--input-border); 
            background: var(--input-bg); 
            color: var(--text-main); 
            font-size: 14px; 
            outline: none; 
            box-sizing: border-box;
            transition: border-color 0.15s ease;
        }
        .form-control:focus { border-color: var(--primary); }
        .forgot-link { font-size: 12px; color: var(--text-muted); text-decoration: none; }
        .forgot-link:hover { color: var(--primary); text-decoration: underline; }
        .btn-submit {
            display: block;
            width: 100%;
            padding: 12px;
            background: var(--primary); 
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.15s ease;
        }
        .btn-submit:hover { background: var(--primary-hover); }
    </style>
</head>
<body>
    <div class="login-card">
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 28px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <img src="assets/img/cmsbyphp-icon-64.png" alt="CMSbyPHP" style="width: 42px; height: 42px; border-radius: 12px; object-fit: contain; flex-shrink: 0;">
                <div>
                    <h1 style="font-size: 18px; font-weight: 800; color: var(--text-main); margin: 0; letter-spacing: -0.02em;">CMSbyPHP</h1>
                    <p style="font-size: 13px; color: var(--text-muted); margin: 2px 0 0 0;">Panel zarządzania blogiem</p>
                </div>
            </div>
            <button type="button" id="theme-toggle" class="theme-toggle-btn" title="Przełącz motyw" style="padding: 6px 10px;">
                <svg id="theme-icon-moon" style="width: 16px; height: 16px; display: none;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                <svg id="theme-icon-sun" style="width: 16px; height: 16px; display: none;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            </button>
        </div>

        <?php if ($success): ?>
            <div style="background: var(--success-subtle); color: var(--success); border: 1px solid var(--border-subtle); padding: 10px 14px; border-radius: 8px; margin-bottom: 20px; font-size: 13px;">
                <?= Security::sanitize($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div style="background: var(--danger-subtle); color: var(--danger); border: 1px solid var(--border-subtle); padding: 10px 14px; border-radius: 8px; margin-bottom: 20px; font-size: 13px;">
                <?= Security::sanitize($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($needsTotp): ?>
        <p style="font-size: 13px; color: var(--text-muted); margin: 0 0 16px 0;">
            Wprowadź 6-cyfrowy kod z Twojej aplikacji uwierzytelniającej.
        </p>
        <form method="POST" action="">
            <input type="hidden" name="action" value="verify_totp">
            <div class="form-group">
                <label class="form-label" for="totp_code">Kod 2FA</label>
                <input class="form-control" type="text" name="totp_code" id="totp_code" inputmode="numeric" pattern="[0-9]*" maxlength="6" required autofocus autocomplete="one-time-code" style="letter-spacing: 8px; font-size: 20px; text-align: center;">
            </div>
            <button type="submit" class="btn-submit">
                Zweryfikuj kod
            </button>
            <p style="text-align: center; margin: 16px 0 0 0;">
                <a href="login.php" class="forgot-link">&larr; Wróć do logowania</a>
            </p>
        </form>
        <?php else: ?>
        <form method="POST" action="">
            <div class="form-group">
                <label class="form-label" for="username"><?= _e('Username or Email') ?></label>
                <input class="form-control" type="text" name="username" id="username" required autofocus autocomplete="username">
            </div>
            
            <div class="form-group">
                <div class="form-label-row">
                    <label class="form-label" for="password"><?= _e('Password') ?></label>
                    <a href="forgot-password.php" class="forgot-link"><?= _e('Forgot Password?') ?></a>
                </div>
                <input class="form-control" type="password" name="password" id="password" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn-submit" style="margin-top: 10px;">
                <?= _e('Sign In') ?> &rarr;
            </button>
        </form>
        <?php endif; ?>

        <div style="margin-top: 24px; border-top: 1px solid var(--border-subtle); text-align: center; font-size: 12px; color: var(--text-muted); padding-top: 16px;">
            <a href="../" style="color: var(--text-muted); text-decoration: none;">&larr; <?= _e('Back to website') ?></a>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const toggleBtn = document.getElementById('theme-toggle');
        const sunIcon = document.getElementById('theme-icon-sun');
        const moonIcon = document.getElementById('theme-icon-moon');

        const updateThemeUI = () => {
            const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
            if (sunIcon && moonIcon) {
                if (currentTheme === 'dark') {
                    sunIcon.style.display = 'inline-block';
                    moonIcon.style.display = 'none';
                } else {
                    sunIcon.style.display = 'none';
                    moonIcon.style.display = 'inline-block';
                }
            }
        };

        if (toggleBtn) {
            updateThemeUI();

            toggleBtn.addEventListener('click', () => {
                const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
                const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';

                document.documentElement.setAttribute('data-theme', nextTheme);
                localStorage.setItem('cmsbyphp_theme', nextTheme);
                updateThemeUI();
            });
        }
    });
    </script>
</body>
</html>
