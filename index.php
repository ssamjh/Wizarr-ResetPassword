<?php

require_once __DIR__ . '/config.php';

$state   = 'form'; // form | sent | no_email | error
$message = '';
 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = trim($_POST['identifier'] ?? '');

    // Validate captcha token before doing anything else
    if (!verify_captcha(captcha_token_from_post())) {
        $state   = 'error';
        $message = 'Security check failed. Please try again.';
    } elseif ($input === '') {
        $state   = 'error';
        $message = 'Please enter your username or email address.';
    } else {
        // Look up user in Wizarr, try email first then username
        $user = null;
        foreach (['email', 'username'] as $field) {
            $url  = WIZARR_INTERNAL_URL . '/api/users?' . http_build_query([$field => $input]);
            $resp = wizarr_get($url);
            if ($resp && !empty($resp['users'])) {
                $user = $resp['users'][0];
                break;
            }
        }
 
        if (!$user) {
            // Don't reveal whether the account exists
            $state = 'sent';
        } elseif (empty($user['email'])) {
            $state = 'no_email';
        } else {
            $reset_url  = WIZARR_INTERNAL_URL . '/api/users/' . $user['id'] . '/reset-password';
            $reset_resp = wizarr_post($reset_url);

            if (!$reset_resp || empty($reset_resp['url'])) {
                $state   = 'error';
                $message = 'Could not generate a reset link. Please try again later.';
            } else {
                $reset_link = WIZARR_EXTERNAL_URL . $reset_resp['url'];

                $sent = send_email(
                    $user['email'],
                    $user['username'],
                    $reset_link
                );
 
                $state = $sent ? 'sent' : 'error';
                if (!$sent) {
                    $message = 'We found your account but could not send the email. Please contact the server admin.';
                }
            }
        }
    }
}
 
function wizarr_get(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'X-API-Key: ' . WIZARR_API_KEY,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $body) ? json_decode($body, true) : null;
}
 
function wizarr_post(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '{}',
        CURLOPT_HTTPHEADER     => [
            'X-API-Key: ' . WIZARR_API_KEY,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $body) ? json_decode($body, true) : null;
}
 
function captcha_token_from_post(): string {
    switch (CAPTCHA_PROVIDER) {
        case 'turnstile':    return $_POST['cf-turnstile-response'] ?? '';
        case 'hcaptcha':     return $_POST['h-captcha-response']    ?? '';
        case 'recaptcha_v2':
        case 'recaptcha_v3': return $_POST['g-recaptcha-response']  ?? '';
        case 'none':         return 'skip';
        default:             return '';
    }
}

function verify_captcha(string $token): bool {
    switch (CAPTCHA_PROVIDER) {
        case 'turnstile':    return verify_turnstile($token);
        case 'hcaptcha':     return verify_hcaptcha($token);
        case 'recaptcha_v2': return verify_recaptcha_v2($token);
        case 'recaptcha_v3': return verify_recaptcha_v3($token);
        case 'none':         return true;
        default:             return false;
    }
}

function captcha_siteverify(string $endpoint, array $fields): array {
    if (($fields['response'] ?? '') === '') {
        return [];
    }
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return ($body ? json_decode($body, true) : null) ?? [];
}

function verify_turnstile(string $token): bool {
    $data = captcha_siteverify('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'secret'   => TURNSTILE_SECRET_KEY,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    return is_array($data) && ($data['success'] ?? false) === true;
}

function verify_hcaptcha(string $token): bool {
    $data = captcha_siteverify('https://hcaptcha.com/siteverify', [
        'secret'   => HCAPTCHA_SECRET_KEY,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    return is_array($data) && ($data['success'] ?? false) === true;
}

function verify_recaptcha_v2(string $token): bool {
    $data = captcha_siteverify('https://www.google.com/recaptcha/api/siteverify', [
        'secret'   => RECAPTCHA_V2_SECRET_KEY,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    return is_array($data) && ($data['success'] ?? false) === true;
}

function verify_recaptcha_v3(string $token): bool {
    $data = captcha_siteverify('https://www.google.com/recaptcha/api/siteverify', [
        'secret'   => RECAPTCHA_V3_SECRET_KEY,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    return is_array($data)
        && ($data['success'] ?? false) === true
        && ($data['score']   ?? 0.0)   >= RECAPTCHA_V3_THRESHOLD;
}

function email_html_body(string $to_name, string $reset_link): string {
    return '
<!DOCTYPE html>
<html>
<body style="margin:0;padding:0;background:#0f0f0f;font-family:\'Helvetica Neue\',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0">
    <tr>
      <td align="center" style="padding:48px 16px;">
        <table width="560" cellpadding="0" cellspacing="0"
               style="background:#1a1a1a;border-radius:12px;overflow:hidden;border:1px solid #2a2a2a;">
          <tr>
            <td style="padding:40px 48px 32px;border-bottom:1px solid #2a2a2a;">
              <p style="margin:0;font-size:13px;letter-spacing:0.1em;text-transform:uppercase;
                         color:#888;">Password Reset</p>
              <h1 style="margin:12px 0 0;font-size:26px;font-weight:600;color:#f0f0f0;
                          letter-spacing:-0.5px;">Reset your password</h1>
            </td>
          </tr>
          <tr>
            <td style="padding:36px 48px;">
              <p style="margin:0 0 24px;font-size:15px;line-height:1.7;color:#aaa;">
                Hi ' . htmlspecialchars($to_name) . ',<br><br>
                We received a request to reset the password for your account.
                Click the button below to choose a new one.
              </p>
              <table cellpadding="0" cellspacing="0">
                <tr>
                  <td style="border-radius:8px;background:#7c3aed;">
                    <a href="' . htmlspecialchars($reset_link) . '"
                       style="display:inline-block;padding:14px 32px;font-size:14px;
                              font-weight:600;color:#fff;text-decoration:none;
                              letter-spacing:0.02em;">
                      Reset Password
                    </a>
                  </td>
                </tr>
              </table>
              <p style="margin:28px 0 0;font-size:13px;color:#555;line-height:1.6;">
                This link expires in <strong style="color:#777;">24 hours</strong>.
                If you didn\'t request this, you can safely ignore this email —
                your password will not change.
              </p>
              <p style="margin:20px 0 0;font-size:12px;color:#444;line-height:1.6;">
                If the button above doesn\'t work, copy and paste this link into your browser:<br>
                <span style="font-family:monospace;color:#888;word-break:break-all;">' . htmlspecialchars($reset_link) . '</span>
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:20px 48px;border-top:1px solid #2a2a2a;">
              <p style="margin:0;font-size:12px;color:#444;">
                Sent by ' . MAIL_FROM_NAME . '
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
}

function send_email(string $to_email, string $to_name, string $reset_link): bool {
    switch (MAIL_PROVIDER) {
        case 'smtp':  return send_smtp_email($to_email, $to_name, $reset_link);
        case 'brevo': return send_brevo_email($to_email, $to_name, $reset_link);
        default:      return send_brevo_email($to_email, $to_name, $reset_link);
    }
}

function send_brevo_email(string $to_email, string $to_name, string $reset_link): bool {
    $payload = json_encode([
        'sender'      => ['name' => MAIL_FROM_NAME, 'email' => MAIL_FROM_EMAIL],
        'to'          => [['email' => $to_email, 'name' => $to_name]],
        'subject'     => MAIL_SUBJECT,
        'trackClicks' => false,
        'trackOpens'  => false,
        'htmlContent' => email_html_body($to_name, $reset_link),
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'api-key: ' . BREVO_API_KEY,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 300);
}

function send_smtp_email(string $to_email, string $to_name, string $reset_link): bool {
    $host       = SMTP_HOST;
    $port       = SMTP_PORT;
    $encryption = SMTP_ENCRYPTION; // 'tls' | 'ssl' | 'none'
    $username   = SMTP_USERNAME;
    $password   = SMTP_PASSWORD;

    // Build headers
    $subject  = '=?UTF-8?B?' . base64_encode(MAIL_SUBJECT) . '?=';
    $from_name = '=?UTF-8?B?' . base64_encode(MAIL_FROM_NAME) . '?=';
    $to_name_enc = '=?UTF-8?B?' . base64_encode($to_name) . '?=';
    $date     = date('r');
    $msg_id   = '<' . uniqid('', true) . '@' . ($host) . '>';

    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'From: ' . $from_name . ' <' . MAIL_FROM_EMAIL . '>',
        'To: ' . $to_name_enc . ' <' . $to_email . '>',
        'Subject: ' . $subject,
        'Date: ' . $date,
        'Message-ID: ' . $msg_id,
    ]);

    $body = chunk_split(base64_encode(email_html_body($to_name, $reset_link)));

    // Open socket
    $errno = 0; $errstr = '';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);

    if ($encryption === 'ssl') {
        $socket = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    } else {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 15);
    }

    if (!$socket) {
        return false;
    }

    stream_set_timeout($socket, 15);

    // Read one complete SMTP response (handles multi-line 250- responses)
    $read_response = function() use ($socket): string {
        $buf = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $buf .= $line;
            // Last line of a response has a space after the 3-digit code
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $buf;
    };

    $smtp_expect = function(string $code) use ($read_response): bool {
        $resp = $read_response();
        return strncmp($resp, $code, strlen($code)) === 0;
    };

    $smtp_send = function(string $cmd) use ($socket): void {
        fwrite($socket, $cmd . "\r\n");
    };

    $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');

    // Greeting
    if (!$smtp_expect('220')) { fclose($socket); return false; }

    // EHLO
    $smtp_send($ehlo);
    if (!$smtp_expect('250')) { fclose($socket); return false; }

    // STARTTLS upgrade
    if ($encryption === 'tls') {
        $smtp_send('STARTTLS');
        if (!$smtp_expect('220')) { fclose($socket); return false; }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return false;
        }
        // Re-EHLO after TLS handshake
        $smtp_send($ehlo);
        if (!$smtp_expect('250')) { fclose($socket); return false; }
    }

    // Authentication
    if ($username !== '') {
        $smtp_send('AUTH LOGIN');
        if (!$smtp_expect('334')) { fclose($socket); return false; }
        $smtp_send(base64_encode($username));
        if (!$smtp_expect('334')) { fclose($socket); return false; }
        $smtp_send(base64_encode($password));
        if (!$smtp_expect('235')) { fclose($socket); return false; }
    }

    // Envelope
    $smtp_send('MAIL FROM:<' . MAIL_FROM_EMAIL . '>');
    if (!$smtp_expect('250')) { fclose($socket); return false; }

    $smtp_send('RCPT TO:<' . $to_email . '>');
    if (!$smtp_expect('250')) { fclose($socket); return false; }

    // Message
    $smtp_send('DATA');
    if (!$smtp_expect('354')) { fclose($socket); return false; }

    fwrite($socket, $headers . "\r\n\r\n" . $body . "\r\n.\r\n");
    if (!$smtp_expect('250')) { fclose($socket); return false; }

    $smtp_send('QUIT');
    fclose($socket);
    return true;
}
 
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <?php if (CAPTCHA_PROVIDER === 'turnstile'): ?>
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
  <?php elseif (CAPTCHA_PROVIDER === 'hcaptcha'): ?>
  <script src="https://js.hcaptcha.com/1/api.js" async defer></script>
  <?php elseif (CAPTCHA_PROVIDER === 'recaptcha_v2'): ?>
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <?php elseif (CAPTCHA_PROVIDER === 'recaptcha_v3'): ?>
  <script src="https://www.google.com/recaptcha/api.js?render=<?= htmlspecialchars(RECAPTCHA_V3_SITE_KEY) ?>" async defer></script>
  <?php endif; ?>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
 
    :root {
      --bg:        #0c0c0e;
      --surface:   #141416;
      --border:    #222226;
      --accent:    #7c3aed;
      --accent-hi: #9d5cf0;
      --text:      #e8e8ec;
      --muted:     #6b6b78;
      --subtle:    #2a2a30;
    }
 
    body {
      min-height: 100vh;
      background: var(--bg);
      font-family: 'DM Sans', sans-serif;
      color: var(--text);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background-image:
        radial-gradient(ellipse 60% 50% at 50% -10%, rgba(124,58,237,0.12) 0%, transparent 70%);
    }
 
    .card {
      width: 100%;
      max-width: 420px;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 32px 80px rgba(0,0,0,0.5);
      animation: rise 0.4s cubic-bezier(0.16,1,0.3,1) both;
    }
 
    @keyframes rise {
      from { opacity:0; transform:translateY(16px); }
      to   { opacity:1; transform:translateY(0); }
    }
 
    .card-header {
      padding: 32px 36px 28px;
      border-bottom: 1px solid var(--border);
    }
 
    .eyebrow {
      font-size: 11px;
      font-weight: 500;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: var(--accent-hi);
      margin-bottom: 10px;
    }
 
    h1 {
      font-size: 22px;
      font-weight: 600;
      letter-spacing: -0.4px;
      color: var(--text);
      line-height: 1.2;
    }
 
    .card-body {
      padding: 28px 36px 32px;
    }
 
    .description {
      font-size: 14px;
      line-height: 1.7;
      color: var(--muted);
      margin-bottom: 24px;
    }
 
    label {
      display: block;
      font-size: 12px;
      font-weight: 500;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--muted);
      margin-bottom: 8px;
    }
 
    input[type="text"] {
      width: 100%;
      background: var(--subtle);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 12px 16px;
      font-family: 'DM Mono', monospace;
      font-size: 14px;
      color: var(--text);
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
    }
 
    input[type="text"]:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(124,58,237,0.15);
    }
 
    input[type="text"]::placeholder { color: #3a3a44; }
 
    button {
      width: 100%;
      margin-top: 16px;
      padding: 13px;
      background: var(--accent);
      border: none;
      border-radius: 8px;
      font-family: 'DM Sans', sans-serif;
      font-size: 14px;
      font-weight: 600;
      color: #fff;
      cursor: pointer;
      letter-spacing: 0.02em;
      transition: background 0.2s, transform 0.1s;
    }
 
    button:hover  { background: var(--accent-hi); }
    button:active { transform: scale(0.98); }
 
    /* State panels */
    .state-panel {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      padding: 12px 0 8px;
    }
 
    .state-icon {
      width: 52px;
      height: 52px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 20px;
      font-size: 22px;
    }
 
    .icon-ok     { background: rgba(34,197,94,0.12);  }
    .icon-warn   { background: rgba(234,179,8,0.12);  }
    .icon-error  { background: rgba(239,68,68,0.12);  }
 
    .state-title {
      font-size: 18px;
      font-weight: 600;
      color: var(--text);
      margin-bottom: 10px;
    }
 
    .state-body {
      font-size: 14px;
      line-height: 1.7;
      color: var(--muted);
      max-width: 300px;
    }
 
    .state-body strong { color: #aaa; }
 
    .back-link {
      display: inline-block;
      margin-top: 22px;
      font-size: 13px;
      color: var(--accent-hi);
      text-decoration: none;
      padding: 6px 0;
      border-bottom: 1px solid transparent;
      transition: border-color 0.2s;
    }
    .back-link:hover { border-bottom-color: var(--accent-hi); }
 
    .captcha-wrap {
      margin-top: 16px;
      display: flex;
      justify-content: center;
    }

    .error-inline {
      margin-bottom: 16px;
      padding: 10px 14px;
      background: rgba(239,68,68,0.08);
      border: 1px solid rgba(239,68,68,0.2);
      border-radius: 6px;
      font-size: 13px;
      color: #f87171;
    }
  </style>
</head>
<body>
<div class="card">
 
  <div class="card-header">
    <div class="eyebrow">Account</div>
    <h1>Reset Password</h1>
  </div>
 
  <div class="card-body">
 
  <?php if ($state === 'form' || $state === 'error'): ?>
 
    <?php if ($state === 'error' && $message): ?>
      <div class="error-inline"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
 
    <p class="description">
      Enter the username or email address associated with your account
      and we'll send you a reset link if we find a match.
    </p>
 
    <form method="post" action="">
      <label for="identifier">Username or Email</label>
      <input
        type="text"
        id="identifier"
        name="identifier"
        placeholder="e.g. jsmith or j@example.com"
        value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>"
        autocomplete="username"
        autofocus
      >
      <?php if (CAPTCHA_PROVIDER === 'turnstile'): ?>
      <div class="captcha-wrap">
        <div class="cf-turnstile"
             data-sitekey="<?= htmlspecialchars(TURNSTILE_SITE_KEY) ?>"
             data-theme="dark"></div>
      </div>
      <?php elseif (CAPTCHA_PROVIDER === 'hcaptcha'): ?>
      <div class="captcha-wrap">
        <div class="h-captcha"
             data-sitekey="<?= htmlspecialchars(HCAPTCHA_SITE_KEY) ?>"
             data-theme="dark"></div>
      </div>
      <?php elseif (CAPTCHA_PROVIDER === 'recaptcha_v2'): ?>
      <div class="captcha-wrap">
        <div class="g-recaptcha"
             data-sitekey="<?= htmlspecialchars(RECAPTCHA_V2_SITE_KEY) ?>"
             data-theme="dark"></div>
      </div>
      <?php elseif (CAPTCHA_PROVIDER === 'recaptcha_v3'): ?>
      <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">
      <?php endif; ?>
      <button type="submit">Send Reset Link</button>
    </form>
    <?php if (CAPTCHA_PROVIDER === 'recaptcha_v3'): ?>
    <script>
      document.querySelector('form').addEventListener('submit', function(e) {
        e.preventDefault();
        var form = this;
        grecaptcha.ready(function() {
          grecaptcha.execute(<?= json_encode(RECAPTCHA_V3_SITE_KEY) ?>, {action: 'reset_password'}).then(function(token) {
            document.getElementById('g-recaptcha-response').value = token;
            form.submit();
          });
        });
      });
    </script>
    <?php endif; ?>
 
  <?php elseif ($state === 'sent'): ?>
 
    <div class="state-panel">
      <div class="state-icon icon-ok">✉️</div>
      <div class="state-title">Check your inbox</div>
      <p class="state-body">
        If that username or email matches an account on our server,
        you'll receive a reset link shortly.<br><br>
        <strong>The link expires in 24 hours.</strong>
      </p>
      <a class="back-link" href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">← Try again</a>
    </div>
 
  <?php elseif ($state === 'no_email'): ?>
 
    <div class="state-panel">
      <div class="state-icon icon-warn">⚠️</div>
      <div class="state-title">No email on file</div>
      <p class="state-body">
        We found your account but there's no email address associated with it,
        so we can't send a reset link automatically.<br><br>
        Please get in touch with the server admin to have your password reset manually.
      </p>
      <a class="back-link" href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">← Go back</a>
    </div>
 
  <?php endif; ?>
 
  </div>
</div>
</body>
</html>