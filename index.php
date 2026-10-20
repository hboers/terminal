<?php
session_start();
$remoteAddress = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
if (strpos($remoteAddress, '::ffff:') === 0) $remoteAddress = substr($remoteAddress, 7);
if (!preg_match('/^192\\.168\\.1\\.(?:[1-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-4])$/', $remoteAddress)) {
    http_response_code(403);
    exit('Client address is outside the allowed 192.168.1.0/24 network.');
}
$authUser = getenv('TERMINAL_USER') ?: 'hboers';
$authPassword = getenv('TERMINAL_PASSWORD') ?: 'ElCondor';
$providedUser = isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : '';
$providedPassword = isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';
if (!hash_equals($authUser, $providedUser) || !hash_equals(hash('sha256', $authPassword), hash('sha256', $providedPassword))) {
    header('WWW-Authenticate: Basic realm="Host Terminal", charset="UTF-8"');
    http_response_code(401);
    exit('Authentication required.');
}
$terminalUser = 'www-data';
$userInfo = function_exists('posix_getpwnam') ? posix_getpwnam($terminalUser) : false;
if (!is_array($userInfo)) {
    http_response_code(500);
    exit('Shell account not found: ' . htmlspecialchars($terminalUser, ENT_QUOTES, 'UTF-8'));
}
$startDir = __DIR__ . '/home';
define('TERMINAL_SHELL_USER', $terminalUser);
$terminalName = getenv('TERMINAL_NAME') ?: 'x230t';
$browserUserAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
$useChrome77Assets = preg_match('/Chrome\/(\d+)\./', $browserUserAgent, $chromeVersion)
    && (int)$chromeVersion[1] < 80;
$xtermScript = $useChrome77Assets ? 'vendor/xterm/xterm.chrome77.js' : 'vendor/xterm/xterm.js';
$fitAddonScript = $useChrome77Assets ? 'vendor/addon-fit/addon-fit.chrome77.js' : 'vendor/addon-fit/addon-fit.js';
if (!isset($_SESSION['terminal_tmux'])) $_SESSION['terminal_tmux'] = 'webterm_' . bin2hex(openssl_random_pseudo_bytes(12));
if (!isset($_SESSION['terminal_output_file'])) {
    $outputFile = sys_get_temp_dir() . '/webterm-' . bin2hex(openssl_random_pseudo_bytes(24));
    $outputHandle = @fopen($outputFile, 'x');
    if (!$outputHandle) { http_response_code(500); exit('Could not create terminal output file.'); }
    fclose($outputHandle);
    chmod($outputFile, 0600);
    $_SESSION['terminal_output_file'] = $outputFile;
}
if (!isset($_SESSION['terminal_offset'])) $_SESSION['terminal_offset'] = 0;
if (!isset($_SESSION['terminal_csrf'])) $_SESSION['terminal_csrf'] = bin2hex(openssl_random_pseudo_bytes(24));

function terminal_escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function terminal_json($status, $data) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}
function terminal_tmux($args) {
    $command = '/usr/bin/tmux -f ' . escapeshellarg(__DIR__ . '/tmux.conf');
    foreach ($args as $arg) $command .= ' ' . escapeshellarg((string)$arg);
    $pipes = array();
    $process = proc_open($command, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('redirect', 1)), $pipes);
    if (!is_resource($process)) return array('code' => 127, 'output' => 'Could not start tmux.');
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($process);
    return array('code' => $code, 'output' => $output);
}
function terminal_shell_command($args) {
    $command = array();
    foreach ($args as $arg) $command[] = escapeshellarg((string)$arg);
    return implode(' ', $command);
}
function terminal_sandbox_args($startDir, $command) {
    return array_merge(array(
        '/usr/bin/bwrap', '--die-with-parent', '--unshare-all', '--share-net',
        '--ro-bind', '/usr', '/usr',
        '--symlink', 'usr/bin', '/bin', '--symlink', 'usr/sbin', '/sbin',
        '--symlink', 'usr/lib', '/lib', '--symlink', 'usr/lib64', '/lib64',
        '--dir', '/etc',
        '--ro-bind', '/etc/passwd', '/etc/passwd',
        '--ro-bind', '/etc/group', '/etc/group',
        '--ro-bind', '/etc/nsswitch.conf', '/etc/nsswitch.conf',
        '--ro-bind', '/etc/ld.so.cache', '/etc/ld.so.cache',
        '--ro-bind', '/etc/ssl/certs', '/etc/ssl/certs',
        '--ro-bind', __DIR__ . '/terminal.bashrc', '/etc/terminal.bashrc',
        '--dir', '/home', '--bind', $startDir, '/home/terminal',
        '--chdir', '/home/terminal', '--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp',
        '--setenv', 'HOME', '/home/terminal',
        '--setenv', 'USER', TERMINAL_SHELL_USER,
        '--setenv', 'LOGNAME', TERMINAL_SHELL_USER,
        '--'
    ), $command);
}
function terminal_require_token() {
    $token = isset($_POST['csrf']) ? $_POST['csrf'] : '';
    if (!hash_equals($_SESSION['terminal_csrf'], $token)) terminal_json(403, array('error' => 'Invalid terminal session token. Reload the page.'));
}
function terminal_send_data($name, $data) {
    $sequences = array(
        "\x1b[1;5D" => 'C-Left', "\x1b[1;5C" => 'C-Right',
        "\x1b[1;5A" => 'C-Up', "\x1b[1;5B" => 'C-Down',
        "\x1b[1;2D" => 'S-Left', "\x1b[1;2C" => 'S-Right',
        "\x1b[3~" => 'Delete', "\x1b[5~" => 'PageUp', "\x1b[6~" => 'PageDown',
        "\x1b[A" => 'Up', "\x1b[B" => 'Down', "\x1b[C" => 'Right', "\x1b[D" => 'Left',
        "\x1bOA" => 'Up', "\x1bOB" => 'Down', "\x1bOC" => 'Right', "\x1bOD" => 'Left',
        "\x1b[H" => 'Home', "\x1b[F" => 'End', "\x1bOH" => 'Home', "\x1bOF" => 'End',
        "\x1bOP" => 'F1', "\x1bOQ" => 'F2', "\x1bOR" => 'F3', "\x1bOS" => 'F4',
        "\r" => 'Enter', "\n" => 'Enter', "\x01" => 'C-a', "\x02" => 'C-b',
        "\x03" => 'C-c', "\x04" => 'C-d', "\x05" => 'C-e', "\x06" => 'C-f',
        "\x07" => 'C-g', "\x08" => 'C-h', "\x09" => 'Tab', "\x0b" => 'C-k',
        "\x0c" => 'C-l', "\x0e" => 'C-n', "\x10" => 'C-p', "\x11" => 'C-q',
        "\x12" => 'C-r', "\x13" => 'C-s', "\x14" => 'C-t', "\x15" => 'C-u',
        "\x16" => 'C-v', "\x17" => 'C-w', "\x18" => 'C-x', "\x19" => 'C-y',
        "\x1a" => 'C-z', "\x7f" => 'BSpace', "\x1b" => 'Escape'
    );
    $length = strlen($data);
    $i = 0;
    while ($i < $length) {
        $matched = false;
        foreach ($sequences as $sequence => $key) {
            if (substr($data, $i, strlen($sequence)) === $sequence) {
                $sent = terminal_tmux(array('send-keys', '-t', $name, $key));
                if ($sent['code'] !== 0) return $sent;
                $i += strlen($sequence);
                $matched = true;
                break;
            }
        }
        if ($matched) continue;
        $start = $i++;
        while ($i < $length) {
            $special = false;
            foreach ($sequences as $sequence => $key) {
                if (substr($data, $i, strlen($sequence)) === $sequence) { $special = true; break; }
            }
            if ($special) break;
            $i++;
        }
        $sent = terminal_tmux(array('send-keys', '-l', '-t', $name, substr($data, $start, $i - $start)));
        if ($sent['code'] !== 0) return $sent;
    }
    return array('code' => 0, 'output' => '');
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
if ($action !== '') {
    if (!function_exists('proc_open')) terminal_json(500, array('error' => 'PHP proc_open is disabled.'));
    if ($action !== 'poll') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') terminal_json(405, array('error' => 'Use POST for terminal actions.'));
        terminal_require_token();
    }
    $name = $_SESSION['terminal_tmux'];
    $outputFile = $_SESSION['terminal_output_file'];
    if ($action === 'start') {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== (int)$userInfo['uid']) {
            terminal_json(500, array('error' => 'PHP muss als Benutzer ' . TERMINAL_SHELL_USER . ' laufen, damit das Terminal ohne sudo starten kann.'));
        }
        if (!is_dir($startDir)) terminal_json(500, array('error' => 'Terminal start directory does not exist: ' . $startDir));
        if (!is_writable($startDir)) terminal_json(500, array('error' => 'Terminal start directory is not writable by ' . TERMINAL_SHELL_USER . ': ' . $startDir));
        $probePipes = array();
        $probe = @proc_open(terminal_sandbox_args($startDir, array('/usr/bin/true')), array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('redirect', 1)), $probePipes);
        if (!is_resource($probe)) terminal_json(500, array('error' => 'Could not start Bubblewrap sandbox.'));
        $probeOutput = stream_get_contents($probePipes[1]);
        fclose($probePipes[1]);
        $probeCode = proc_close($probe);
        if ($probeCode !== 0) terminal_json(500, array('error' => 'Bubblewrap sandbox could not start: ' . trim($probeOutput)));
        if (!isset($_SESSION['terminal_sandbox_version']) || $_SESSION['terminal_sandbox_version'] !== 1) {
            terminal_tmux(array('kill-session', '-t', $name));
            $name = 'webterm_' . bin2hex(openssl_random_pseudo_bytes(12));
            $_SESSION['terminal_tmux'] = $name;
            $_SESSION['terminal_sandbox_version'] = 1;
        }
        $alive = terminal_tmux(array('has-session', '-t', $name));
        if ($alive['code'] !== 0) {
            file_put_contents($outputFile, '');
            $cols = max(40, min(500, isset($_POST['cols']) ? (int)$_POST['cols'] : 100));
            $rows = max(10, min(300, isset($_POST['rows']) ? (int)$_POST['rows'] : 30));
            $sandboxCommand = terminal_shell_command(terminal_sandbox_args($startDir, array('/usr/bin/env', 'TERM=screen-256color', '/bin/bash', '--restricted', '--noprofile', '--rcfile', '/etc/terminal.bashrc', '-i')));
            $started = terminal_tmux(array('new-session', '-d', '-s', $name, '-x', $cols, '-y', $rows, '-c', $startDir, '-e', 'TERM=screen-256color', $sandboxCommand));
            if ($started['code'] !== 0) terminal_json(500, array('error' => trim($started['output']) ?: 'Could not start PTY session.'));
            terminal_tmux(array('pipe-pane', '-t', $name, 'cat >> ' . escapeshellarg($outputFile)));
            // The shell's first prompt is usually printed before pipe-pane is attached.
            // Submit an empty line now so the prompt is captured by the output stream.
            usleep(100000);
            terminal_tmux(array('send-keys', '-t', $name, 'Enter'));
            $_SESSION['terminal_offset'] = 0;
            terminal_json(200, array('ok' => true, 'snapshot' => ''));
        }
        terminal_tmux(array('pipe-pane', '-t', $name, 'cat >> ' . escapeshellarg($outputFile)));
        $snapshot = terminal_tmux(array('capture-pane', '-e', '-p', '-t', $name));
        clearstatcache(true, $outputFile);
        $_SESSION['terminal_offset'] = is_file($outputFile) ? filesize($outputFile) : 0;
        terminal_json(200, array('ok' => true, 'snapshot' => base64_encode($snapshot['output'])));
    }
    if ($action === 'poll') {
        $alive = terminal_tmux(array('has-session', '-t', $name));
        if ($alive['code'] !== 0) terminal_json(200, array('alive' => false, 'data' => '', 'error' => trim($alive['output']) ?: 'tmux session not found.'));
        clearstatcache(true, $outputFile);
        $data = '';
        if (is_file($outputFile) && ($file = fopen($outputFile, 'rb'))) {
            $size = filesize($outputFile);
            $offset = min((int)$_SESSION['terminal_offset'], $size);
            fseek($file, $offset);
            $data = fread($file, 262144);
            $_SESSION['terminal_offset'] = $offset + strlen($data);
            fclose($file);
        }
        terminal_json(200, array('alive' => true, 'data' => base64_encode($data)));
    }
    if ($action === 'input') {
        $data = isset($_POST['data']) ? (string)$_POST['data'] : '';
        if (strlen($data) > 8192) terminal_json(413, array('error' => 'Input chunk is too large.'));
        $sent = terminal_send_data($name, $data);
        if ($sent['code'] !== 0) terminal_json(500, array('error' => trim($sent['output']) ?: 'Could not send terminal input.'));
        terminal_json(200, array('ok' => true));
    }
    if ($action === 'resize') {
        $cols = max(2, min(500, isset($_POST['cols']) ? (int)$_POST['cols'] : 80));
        $rows = max(2, min(300, isset($_POST['rows']) ? (int)$_POST['rows'] : 24));
        $resized = terminal_tmux(array('resize-window', '-t', $name, '-x', $cols, '-y', $rows));
        if ($resized['code'] !== 0) terminal_json(500, array('error' => trim($resized['output']) ?: 'Could not resize terminal.'));
        terminal_json(200, array('ok' => true));
    }
    if ($action === 'close') {
        terminal_tmux(array('kill-session', '-t', $name));
        @unlink($outputFile);
        unset($_SESSION['terminal_tmux'], $_SESSION['terminal_output_file'], $_SESSION['terminal_offset']);
        terminal_json(200, array('ok' => true));
    }
    terminal_json(404, array('error' => 'Unknown terminal action.'));
}
header('Vary: User-Agent');
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#090d12">
  <title>Terminal — <?php echo terminal_escape($terminalName); ?></title>
  <link rel="stylesheet" href="vendor/xterm/xterm.css">
  <style>
    :root {
      color-scheme: dark;
      --bg: #090d12;
      --panel: rgba(16, 22, 30, .94);
      --line: #242e39;
      --muted: #82909f;
      --text: #d8e1e9;
      --green: #8bd49c;
      --blue: #8bb9ee;
      --purple: #c4a7e7;
      --mono: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
    }
    * { box-sizing: border-box; }
    html, body { width: 100%; height: 100%; }
    body {
      margin: 0; min-height: 100vh; display: grid; place-items: stretch; padding: 0; overflow: hidden;
      background: radial-gradient(ellipse at 50% 0%, #182433 0, #0d141d 36%, var(--bg) 76%);
      color: var(--text); font-family: var(--mono);
    }
    .window {
      width: 100%; height: 100%; min-height: 0;
      display: flex; flex-direction: column; overflow: hidden; border: 0;
      border-radius: 0; background: var(--panel); box-shadow: none;
    }
    .titlebar { height: 48px; flex: 0 0 48px; display: flex; align-items: center; padding: 0 16px; border-bottom: 1px solid var(--line); background: #111821; }
    .lights { display: flex; gap: 8px; }
    .light { width: 11px; height: 11px; border-radius: 50%; background: #f16b62; box-shadow: inset 0 0 0 1px #0002; }
    .light:nth-child(2) { background: #e9bd4f; } .light:nth-child(3) { background: #55c468; }
    .title { position: absolute; left: 50%; transform: translateX(-50%); display: flex; align-items: center; gap: 8px; color: #9aa7b5; font: 12px var(--mono); }
    .title svg { width: 14px; height: 14px; color: #697786; }
    .status { margin-left: auto; display: flex; gap: 7px; align-items: center; color: #8493a2; font: 11px var(--mono); }
    .status i { display: block; width: 7px; height: 7px; background: #69cd83; border-radius: 50%; box-shadow: 0 0 8px #69cd8377; }
    #screen { flex: 1; overflow: auto; padding: 24px 28px 12px; scrollbar-color: #33404d transparent; scrollbar-width: thin; font-size: 13px; line-height: 1.65; }
    .line { white-space: pre-wrap; overflow-wrap: anywhere; }
    .welcome { color: #aebbc8; margin-bottom: 14px; }
    .dim { color: var(--muted); } .green { color: var(--green); } .blue { color: var(--blue); } .purple { color: var(--purple); }
    .promptline { display: flex; gap: 8px; align-items: baseline; margin-top: 8px; }
    .prompt { flex: 0 0 auto; color: var(--green); }
    .prompt .at { color: #647382; } .prompt .host { color: var(--blue); } .prompt .path { color: var(--purple); }
    .command { color: #e3eaf1; }
    .inputrow { flex: 0 0 auto; display: flex; align-items: center; gap: 8px; padding: 10px 28px 21px; }
    #commandInput { min-width: 0; flex: 1; border: 0; outline: 0; padding: 0; color: #e3eaf1; background: transparent; caret-color: var(--green); font: 13px/1.65 var(--mono); }
    .cursor { display: none; width: 8px; height: 16px; background: var(--green); animation: blink 1.1s steps(2, start) infinite; }
    @keyframes blink { to { visibility: hidden; } }
    .footer { flex: 0 0 30px; display: flex; align-items: center; justify-content: space-between; padding: 0 18px; border-top: 1px solid #202a34; color: #64717f; font: 10px var(--mono); }
    .footer span { display:flex; align-items:center; gap:7px; }
    kbd { border: 1px solid #36414c; border-radius: 4px; padding: 1px 5px; color: #8b98a6; font: 10px var(--mono); }
    @media (max-width: 600px) { body { padding: 0; } .window { height: 100vh; min-height: 0; border: 0; border-radius: 0; } #screen { padding: 20px 16px 10px; font-size: 12px; } .inputrow { padding: 10px 16px 18px; } .title { font-size: 11px; } .footer { padding: 0 12px; } }
  
    .inputrow { margin: 0; }
    .inputrow button { border: 1px solid #36414c; border-radius: 4px; padding: 5px 9px; color: var(--text); background: #18222d; font: 12px var(--mono); }
    @media (max-width: 600px) { .inputrow button { padding: 5px; } }
  
    #screen { padding: 8px 12px; }
    #screen { display: flex; min-height: 0; overflow: hidden; }
    #terminal-container { flex: 1; min-width: 0; min-height: 0; }
    #terminal { width: 100%; height: 100%; }
    .xterm { height: 100%; }
    .xterm .xterm-viewport { background-color: transparent; }
    @media (max-width: 600px) { #screen { padding: 4px; } }
  </style>
</head>
<body>
  <main class="window" aria-label="Host terminal">
    <header class="titlebar">
      <div class="lights" aria-hidden="true"><i class="light"></i><i class="light"></i><i class="light"></i></div>
      <div class="title"><svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="m3 4 4 4-4 4m5 0h5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>terminal — <span><?php echo terminal_escape($terminalName); ?></span></div>
      <div class="status"><i></i> PTY · <?php echo terminal_escape($terminalUser); ?> <span id="terminal-size">-- × --</span></div>
    </header>
    <section id="screen" aria-label="Terminal output">
      <div id="terminal-container" data-endpoint="<?php echo terminal_escape($_SERVER['SCRIPT_NAME']); ?>" data-csrf="<?php echo terminal_escape($_SESSION['terminal_csrf']); ?>"></div>
    </section>
    <footer class="footer"><span><i class="green">●</i> PHP + tmux PTY</span><span>vim, Ctrl+C und ANSI-Steuerung</span></footer>
  </main>
  <script>
    (function () {
      if (window.Element && !Element.prototype.replaceChildren) {
        Element.prototype.replaceChildren = function () {
          while (this.firstChild) this.removeChild(this.firstChild);
          for (var i = 0; i < arguments.length; i++) this.appendChild(arguments[i]);
        };
      }
      window.addEventListener('error', function (event) {
        var mount = document.getElementById('terminal-container');
        if (!mount) return;
        var message = event.message || (event.target && event.target.src ? 'Skript konnte nicht geladen werden: ' + event.target.src : 'Unbekannter JavaScript-Fehler');
        mount.textContent = 'Terminal konnte nicht initialisiert werden: ' + message;
        mount.style.color = '#ff7777';
        mount.style.whiteSpace = 'pre-wrap';
      }, true);
    })();
  </script>
  <noscript>JavaScript ist erforderlich, um das Terminal zu starten.</noscript>
  <script src="<?php echo terminal_escape($xtermScript); ?>"></script>
  <script src="<?php echo terminal_escape($fitAddonScript); ?>"></script>
  <script>
    (function () {
      var mount = document.getElementById('terminal-container');
      mount.textContent = '';
      var endpoint = mount.getAttribute('data-endpoint');
      var csrf = mount.getAttribute('data-csrf');
      var terminal = new Terminal({allowTransparency: true, cursorBlink: true, cursorStyle: 'bar', cursorWidth: 2, fontFamily: 'Consolas, monospace', fontSize: 13, scrollback: 5000, convertEol: false, theme: {background: 'rgba(0, 0, 0, 0)', foreground: '#8bd49c', cursor: '#8bd49c'}});
      var fit = new FitAddon.FitAddon();
      var online = false;
      var inputQueue = [];
      var sending = false;
      var stopped = false;
      var resizeTimer = 0;
      terminal.loadAddon(fit);
      terminal.open(mount);
      terminal.focus();
      try { fit.fit(); } catch (error) {}

      function formBody(values) {
        var pairs = [], key;
        values.csrf = csrf;
        for (key in values) if (Object.prototype.hasOwnProperty.call(values, key)) pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(values[key]));
        return pairs.join('&');
      }
      function request(action, method, values, callback) {
        var xhr = new XMLHttpRequest();
        xhr.open(method, endpoint + '?action=' + encodeURIComponent(action) + (method === 'GET' ? '&_=' + new Date().getTime() : ''), true);
        if (method === 'POST') xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.onreadystatechange = function () {
          var result;
          if (xhr.readyState !== 4) return;
          try { result = JSON.parse(xhr.responseText); }
          catch (error) { callback(new Error('Ungültige Serverantwort (HTTP ' + xhr.status + ').')); return; }
          if (xhr.status < 200 || xhr.status >= 300) { callback(new Error(result.error || ('HTTP ' + xhr.status))); return; }
          callback(null, result);
        };
        xhr.onerror = function () { callback(new Error('Verbindung zum Terminalserver fehlgeschlagen.')); };
        xhr.send(method === 'POST' ? formBody(values || {}) : null);
      }
      function decodeBase64(value) {
        var bytes = window.atob(value || ''), encoded = '', index;
        for (index = 0; index < bytes.length; index++) encoded += '%' + ('0' + bytes.charCodeAt(index).toString(16)).slice(-2);
        try { return decodeURIComponent(encoded); } catch (error) { return bytes; }
      }
      function resize() {
        if (!online) return;
        try { fit.fit(); } catch (error) {}
      }
      function scheduleResize() {
        if (resizeTimer) window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(function () { resizeTimer = 0; resize(); }, 60);
      }
      function sendNext() {
        if (sending || !inputQueue.length || !online) return;
        sending = true;
        request('input', 'POST', {data: inputQueue.shift()}, function (error) {
          sending = false;
          if (error) terminal.write('\r\n\x1b[31m' + error.message + '\x1b[0m\r\n');
          sendNext();
        });
      }
      function poll() {
        if (stopped) return;
        request('poll', 'GET', {}, function (error, result) {
          if (error) {
            terminal.write('\r\n\x1b[31m' + error.message + '\x1b[0m\r\n');
            window.setTimeout(poll, 1500);
            return;
          }
          if (!result.alive) {
            stopped = true;
            terminal.write('\r\n\x1b[31m' + (result.error || 'Shell beendet.') + '\x1b[0m\r\n');
            terminal.write('\x1b[90mSeite neu laden, um eine neue Sitzung zu starten.\x1b[0m\r\n');
            return;
          }
          if (result.data) terminal.write(decodeBase64(result.data));
          window.setTimeout(poll, 80);
        });
      }
      terminal.onData(function (data) { inputQueue.push(data); sendNext(); });
      terminal.onResize(function (size) {
        var sizeLabel = document.getElementById('terminal-size');
        sizeLabel.textContent = size.cols + ' × ' + size.rows;
        if (online) request('resize', 'POST', {cols: size.cols, rows: size.rows}, function () {});
      });
      window.addEventListener('resize', scheduleResize);
      if (window.ResizeObserver) {
        var resizeObserver = new ResizeObserver(scheduleResize);
        resizeObserver.observe(document.getElementById('screen'));
      }
      request('start', 'POST', {cols: terminal.cols, rows: terminal.rows}, function (error, result) {
        if (error) { terminal.write('\x1b[31m' + error.message + '\x1b[0m\r\n'); return; }
        if (result.snapshot) terminal.write(decodeBase64(result.snapshot));
        terminal.write('\x1b[?25h');
        online = true;
        resize();
        sendNext();
        terminal.focus();
        poll();
      });
    })();
  </script>
</body>
</html>
