<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — PHP Reporting Engine</title>
    <link rel="stylesheet" href="/css/app.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="https://unpkg.com/phosphor-icons@1.4.2/src/css/icons.css">
    <script>document.documentElement.setAttribute('data-theme',localStorage.getItem('theme')||'light')</script>
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: var(--color-surface-2);
            font-family: var(--font-ui);
        }
        .login-card {
            background: var(--color-surface);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            padding: 40px;
            width: 100%;
            max-width: 400px;
        }
        .login-card h1 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--color-text);
        }
        .login-card .subtitle {
            font-size: 13px;
            color: var(--color-text-muted);
            margin-bottom: 28px;
        }
        .login-card .form-group {
            margin-bottom: 16px;
        }
        .login-card label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--color-text-muted);
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .login-card input {
            width: 100%;
            padding: 10px 12px;
            font-size: 14px;
            font-family: var(--font-ui);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-sm);
            outline: none;
            transition: border-color 0.15s;
        }
        .login-card input:focus {
            border-color: var(--color-primary);
        }
        .login-card .btn {
            width: 100%;
            padding: 10px;
            font-size: 14px;
            font-weight: 600;
            font-family: var(--font-ui);
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            background: var(--color-primary);
            color: #fff;
            transition: background 0.15s;
        }
        .login-card .btn:hover {
            background: var(--color-primary-dark);
        }
        .login-card .btn:disabled {
            opacity: 0.6;
            cursor: default;
        }
        .login-card .error {
            background: #FEF2F2;
            color: var(--color-danger);
            font-size: 13px;
            padding: 8px 12px;
            border-radius: var(--radius-sm);
            margin-bottom: 16px;
            display: none;
        }
        .theme-toggle-login {
            position: fixed;
            top: 16px;
            right: 16px;
            padding: 8px;
            font-size: 18px;
            background: none;
            border: 1px solid var(--color-border);
            border-radius: var(--radius-sm);
            cursor: pointer;
            color: var(--color-text-muted);
        }
        .theme-toggle-login:hover {
            color: var(--color-text);
        }
    </style>
</head>
<body>
    <button class="theme-toggle-login" onclick="toggleTheme()" title="Toggle theme"><i class="ph-moon"></i></button>
    <div class="login-card">
        <h1><i class="ph-file-text"></i> Reporting Engine</h1>
        <p class="subtitle">Sign in to continue</p>
        <div class="error" id="login-error"></div>
        <form id="login-form" onsubmit="handleLogin(event)">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" placeholder="admin" required autocomplete="username" autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn" id="login-btn">Sign In</button>
        </form>
    </div>
    <script src="/js/theme.js"></script>
    <script>
    async function handleLogin(e) {
        e.preventDefault();
        const btn = document.getElementById('login-btn');
        const errorEl = document.getElementById('login-error');
        const username = document.getElementById('username').value;
        const password = document.getElementById('password').value;
        btn.disabled = true;
        btn.textContent = 'Signing in...';
        errorEl.style.display = 'none';
        try {
            const res = await fetch('/api/auth/login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password }),
            });
            const json = await res.json();
            if (!json.success) {
                errorEl.textContent = json.message || 'Invalid credentials';
                errorEl.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Sign In';
                return;
            }
            localStorage.setItem('auth_token', json.data.token);
            document.cookie = 'auth_token=' + encodeURIComponent(json.data.token) + '; path=/; SameSite=Lax';
            window.location.href = '/';
        } catch (e) {
            errorEl.textContent = 'Connection error';
            errorEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Sign In';
        }
    }
    </script>
</body>
</html>
