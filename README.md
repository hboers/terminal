# PHP host terminal

The browser terminal runs Bash directly as `www-data` and starts in the
project's `home/` directory. Commands and scripts run with the permissions of
`www-data`; pasted multiline commands run in order in the same shell. The web
application does not call `sudo`.

The shell runs inside Bubblewrap. Only `/usr` is mounted read-only for system
commands; the writable project `home/` is mounted at `/home/terminal`. The
host's `/var`, `/root`, other home directories, and host `/tmp` are outside the
shell's filesystem view. Bash also runs in restricted mode, which blocks `cd`
and changing the command path. Scripts in `home/` can be run as `bash name.sh`.
Bubblewrap and unprivileged user namespaces must be available; the terminal
must fail to start if the sandbox cannot be created.

`tmux.conf` sets tmux's command shell to Bash. This is needed because the
system account `www-data` normally uses `/usr/sbin/nologin` as its login shell.
New Bash sessions read `terminal.bashrc`, which sets the prompt to `terminal$`.

The `home/` directory is writable by `www-data`, so scripts created there can
be executed from the terminal. Its `.htaccess` blocks direct HTTP access to
files when served by Apache. For nginx, add a location rule denying requests
to `/home/`.

The current workspace cannot change directory ownership, so `home/` has the
sticky bit and is writable by all local users. For a host with administrator
access, the tighter setup is `chown www-data:www-data home && chmod 0750 home`.

Set `TERMINAL_USER` and `TERMINAL_PASSWORD` in the PHP/web-server environment
to replace the default HTTP Basic credentials. The allowed client network is
restricted to `192.168.1.0/24` in `index.php`.

The browser assets in `vendor/xterm/` and `vendor/addon-fit/` are also built
into Chrome 77-compatible bundles (`*.chrome77.js`), since the original xterm
bundle uses optional chaining, which Chrome 77 cannot parse. To regenerate
them, run `./build-chrome77.sh` with Babel 7, `@babel/preset-env`, and its CLI
available as `babeljs`. The page also provides a small `Element.replaceChildren`
polyfill, which xterm uses but Chrome 77 does not provide natively.
