<?php
require_once 'config/config.php';
require_once __DIR__ . '/../includes/rate_limit.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $rateKey = ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . strtolower($username);
    $wait = rate_limit_check($rateKey);

    if (!csrf_valid()) {
        $error = "Invalid credential parameters. Please check username/password.";
    } elseif ($wait > 0) {
        $error = "Too many attempts. Please wait " . $wait . " seconds and try again.";
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.username = ? AND u.status = 'Active'");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // ప్లెయిన్ టెక్స్ట్ లేదా హ్యాష్... ఏది మ్యాచ్ అయినా లాగిన్ అనుమతిస్తుంది
        if ($user && ($password === $user['password'] || password_verify($password, $user['password']) || hash_equals($user['password'], crypt($password, $user['password'])))) {
            rate_limit_clear($rateKey);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            // Super Admin has every permission Admin has, plus one extra
            // capability (creating other Admin logins). Rather than teaching
            // every existing "role === 'Admin'" check in the codebase about a
            // second role name, a Super Admin's session role is stored as
            // 'Admin' so all existing admin-gated pages work unchanged — the
            // one extra capability is gated separately via is_super_admin.
            $_SESSION['role'] = ($user['role_name'] === 'Super Admin') ? 'Admin' : $user['role_name'];
            $_SESSION['is_super_admin'] = ($user['role_name'] === 'Super Admin');
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['avatar'] = !empty($user['avatar']) ? $user['avatar'] : (!empty($user['profile_pic']) ? $user['profile_pic'] : null);
            header("Location: index.php");
            exit;
        } else {
            rate_limit_record_failure($rateKey);
            $error = "Invalid credential parameters. Please check username/password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Login | YMR Survey Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-bg: #0b1e46;
            --app-bg: #f4f6fa;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --accent-purple: #3b32b3;
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html { overflow-x: hidden; }
        body {
            font-family: 'Lexend', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: radial-gradient(circle at 15% 10%, #12275c 0%, #06163a 45%, #030b21 100%);
            margin: 0; padding: 0;
            display: flex; justify-content: center; align-items: center;
            min-height: 100vh; min-height: 100dvh;
            overflow-x: hidden;
        }

        /* ✨ Ambient glow blobs for a premium glass atmosphere */
        .bg-glow { position: fixed; border-radius: 50%; filter: blur(90px); opacity: .35; z-index: 0; pointer-events: none; }
        .bg-glow.one { width: 340px; height: 340px; background: #3b32b3; top: -80px; left: -80px; }
        .bg-glow.two { width: 300px; height: 300px; background: #0ea5e9; bottom: -60px; right: -60px; }

        .auth-shell {
            position: relative; z-index: 1;
            width: 100%; max-width: 420px;
            margin: 24px auto;
            padding: 0 18px;
        }

        .brand-row {
            display: flex; align-items: center; justify-content: center; gap: 12px;
            margin-bottom: 26px; animation: fadeDown .6s ease;
        }
        .brand-mark {
            width: 52px; height: 52px; border-radius: 16px;
            background: linear-gradient(135deg, #4338ca, #0ea5e9);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 22px; box-shadow: 0 10px 25px rgba(59,50,179,.45);
            flex-shrink: 0;
        }
        .brand-text { color: #fff; line-height: 1.2; }
        .brand-text b { display: block; font-size: 17px; font-weight: 700; letter-spacing: .3px; }
        .brand-text span { display: block; font-size: 11px; font-weight: 500; color: rgba(255,255,255,.6); letter-spacing: .5px; text-transform: uppercase; }

        /* 🌟 Glass Card */
        .glass-card {
            background: rgba(255,255,255,0.07);
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
            border: 1px solid rgba(255,255,255,0.14);
            border-radius: 26px;
            padding: 32px 26px;
            box-shadow: 0 25px 60px rgba(0,0,0,.45), inset 0 1px 0 rgba(255,255,255,.08);
            animation: floatUp .55s cubic-bezier(.2,.8,.2,1);
        }

        h1.welcome-title { color: #fff; font-size: 21px; font-weight: 700; margin: 0 0 4px; text-align: center; }
        p.welcome-sub { color: rgba(255,255,255,.62); font-size: 13px; text-align: center; margin: 0 0 26px; }

        .field-group { margin-bottom: 16px; }
        .field-label { display: block; font-size: 11.5px; font-weight: 600; color: rgba(255,255,255,.72); margin-bottom: 7px; letter-spacing: .3px; text-transform: uppercase; }
        .field-wrap { position: relative; }
        .field-wrap i.field-icon { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: rgba(255,255,255,.5); font-size: 15px; }
        .field-wrap input {
            width: 100%; background: rgba(255,255,255,.08); border: 1.5px solid rgba(255,255,255,.16);
            border-radius: 13px; padding: 13px 14px 13px 42px; font-size: 15px; color: #fff; outline: none;
            transition: border-color .18s ease, background-color .18s ease, box-shadow .18s ease;
        }
        .field-wrap input::placeholder { color: rgba(255,255,255,.4); }
        .field-wrap input:focus { border-color: #7c72e0; background: rgba(255,255,255,.12); box-shadow: 0 0 0 4px rgba(124,114,224,.18); }
        .field-wrap .toggle-pass { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: rgba(255,255,255,.5); cursor: pointer; background: none; border: none; padding: 6px; font-size: 14px; }
        .field-wrap .toggle-pass:hover { color: #fff; }

        .options-row { display: flex; justify-content: space-between; align-items: center; margin: 4px 0 22px; font-size: 12.5px; }
        .remember-label { display: flex; align-items: center; gap: 7px; color: rgba(255,255,255,.7); cursor: pointer; user-select: none; }
        .remember-label input { accent-color: #7c72e0; width: 15px; height: 15px; }
        .forgot-link { color: #a5b4fc; text-decoration: none; font-weight: 600; }
        .forgot-link:hover { color: #fff; }

        .login-submit-btn {
            width: 100%; background: linear-gradient(135deg,#4338ca,#0ea5e9); color: #fff; border: none;
            padding: 14px; border-radius: 14px; font-size: 15.5px; font-weight: 700; letter-spacing: .2px;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            box-shadow: 0 12px 28px rgba(59,50,179,.4);
            transition: transform .18s ease, box-shadow .18s ease;
        }
        .login-submit-btn:hover { transform: translateY(-2px); box-shadow: 0 16px 34px rgba(59,50,179,.5); }
        .login-submit-btn:active { transform: translateY(0) scale(.98); }

        .auth-alert {
            border: 1px solid rgba(248,113,113,.35); background: rgba(248,113,113,.12); color: #fecaca;
            border-radius: 12px; padding: 10px 14px; font-size: 12.5px; margin-bottom: 18px;
            display: flex; align-items: center; gap: 8px;
        }

        /* 📷 Marine hero photo footer */
        .marine-hero {
            margin-top: 22px; border-radius: 20px; overflow: hidden; position: relative;
            height: 110px; box-shadow: 0 15px 35px rgba(0,0,0,.35);
            animation: fadeUp .7s ease;
        }
        .marine-hero img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .marine-hero .hero-overlay {
            position: absolute; inset: 0; background: linear-gradient(180deg, rgba(6,22,58,0) 30%, rgba(6,22,58,.85) 100%);
            display: flex; align-items: flex-end; padding: 10px 14px;
        }
        .marine-hero .hero-overlay span { color: rgba(255,255,255,.85); font-size: 10.5px; font-weight: 600; letter-spacing: .4px; text-transform: uppercase; }

        .footer-note { text-align: center; color: rgba(255,255,255,.4); font-size: 11px; margin-top: 18px; }

        @keyframes floatUp { from { opacity: 0; transform: translateY(22px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes fadeDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        @media (max-width: 380px) {
            .glass-card { padding: 26px 18px; border-radius: 22px; }
            .brand-mark { width: 46px; height: 46px; font-size: 19px; }
            .brand-text b { font-size: 15px; }
        }

        @media (min-width: 992px) {
            .auth-shell { max-width: 440px; }
            .glass-card { padding: 40px 34px; }
        }
    </style>
</head>
<body>
    <div class="bg-glow one"></div>
    <div class="bg-glow two"></div>

    <div class="auth-shell">
        <div class="brand-row">
            <div class="brand-mark"><i class="fa-solid fa-anchor"></i></div>
            <div class="brand-text">
                <b>YMR SURVEY</b>
                <span>Management System</span>
            </div>
        </div>

        <div class="glass-card">
            <h1 class="welcome-title">Welcome back</h1>
            <p class="welcome-sub">Sign in to continue to your dashboard</p>

            <?php if ($error): ?>
                <div class="auth-alert"><i class="fa-solid fa-circle-exclamation"></i><span><?= sanitize($error) ?></span></div>
            <?php endif; ?>

            <form action="login.php" method="POST" novalidate><?= csrf_field() ?>
                <div class="field-group">
                    <label class="field-label" for="username">Username</label>
                    <div class="field-wrap">
                        <i class="fa-regular fa-user field-icon"></i>
                        <input type="text" id="username" name="username" placeholder="Enter your username" autocomplete="username" required>
                    </div>
                </div>

                <div class="field-group">
                    <label class="field-label" for="passInput">Password</label>
                    <div class="field-wrap">
                        <i class="fa-solid fa-lock field-icon"></i>
                        <input type="password" name="password" id="passInput" placeholder="Enter your password" autocomplete="current-password" required>
                        <button type="button" class="toggle-pass" id="togglePassBtn" aria-label="Show password"><i class="fa-regular fa-eye"></i></button>
                    </div>
                </div>

                <div class="options-row">
                    <label class="remember-label">
                        <input type="checkbox" name="remember"> Remember me
                    </label>
                    <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
                </div>

                <button type="submit" class="login-submit-btn"><i class="fa-solid fa-right-to-bracket"></i> Sign In</button>
            </form>

            <div class="marine-hero">
                <img src="https://images.unsplash.com/photo-1568347877321-f8935c7dc5a3?auto=format&fit=crop&w=800&q=80" alt="Cargo vessel at sea" loading="lazy">
                <div class="hero-overlay"><span><i class="fa-solid fa-ship me-1"></i> Trusted Marine Survey Operations</span></div>
            </div>
        </div>

        <p class="footer-note">&copy; <?= date('Y') ?> YMR Marine Solutions LLP. All rights reserved.</p>
    </div>

    <script>
        document.getElementById('togglePassBtn').addEventListener('click', function () {
            var input = document.getElementById('passInput');
            var icon = this.querySelector('i');
            var isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            icon.classList.toggle('fa-eye', !isHidden);
            icon.classList.toggle('fa-eye-slash', isHidden);
            this.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });
    </script>
</body>
</html>