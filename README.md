# PHP-Host-Terminal

## Installation auf Debian

Installiere Git, nginx, PHP-FPM mit den benötigten Erweiterungen sowie tmux und
Bubblewrap:

```sh
sudo apt update
sudo apt install -y git nginx php-fpm php-cli php-posix bubblewrap tmux
```

Prüfe, dass unprivilegierte User-Namespaces auf dem Host verfügbar sind. Die
Anwendung bricht den Start des Terminals ab, wenn Bubblewrap keine Sandbox
erstellen kann.

Checke die Anwendung unter `/var/www/html/terminal` aus:

```sh
sudo git clone https://github.com/hboers/terminal.git /var/www/html/terminal
sudo mkdir -p /var/www/html/terminal/home
sudo chown www-data:www-data /var/www/html/terminal/home
sudo chmod 0750 /var/www/html/terminal/home
```

Passe `nginx.sample.conf` an (insbesondere `server_name` und den PHP-FPM-Socket)
und installiere sie als nginx-Site. Die Vorlage gibt PHP-Dateien an PHP-FPM
weiter und weist direkte Anfragen an `/home/` sowie versteckte Projektdateien
ab. PHP-FPM muss als `www-data` laufen.
Kopiere `dotenv.example` nach `.env` und passe die Werte dort an. `.env` wird von
`index.php` geladen, sofern PHP-FPM die jeweilige Variable nicht bereits setzt.
Für HTTP Basic Auth müssen `TERMINAL_USER` und `TERMINAL_PASSWORD` gesetzt sein;
ist `TERMINAL_USER` leer, wird keine Basic-Auth-Abfrage gestellt. Das erlaubte
Clientnetzwerk steht in `TERMINAL_ALLOWED_NETWORK` als IPv4-CIDR, zum Beispiel
`192.168.1.0/24`. Ohne diesen Eintrag gilt `192.168.1.0/24`. Die lokale `.env`
ist von Git ausgeschlossen. Bei Änderungen an PHP-FPM-Variablen PHP-FPM und
nginx neu laden.

Die Chrome-77-Bundles sind bereits im Repository enthalten. Nur wenn du sie
neu erzeugen möchtest, installiere zusätzlich Node.js und npm und installiere
Babel global:

```sh
sudo apt install -y nodejs npm
sudo npm install --global @babel/cli @babel/core @babel/preset-env
./build-chrome77.sh
```
^
## Das Terminal

Das Browser-Terminal führt Bash direkt als `www-data` aus und startet im
Projektverzeichnis `home/`. Befehle und Skripte laufen mit den Berechtigungen
von `www-data`; eingefügte mehrzeilige Befehle werden der Reihe nach in
derselben Shell ausgeführt. Die Webanwendung verwendet kein `sudo`.

Die Shell läuft innerhalb von Bubblewrap. Für Systembefehle wird nur `/usr`
schreibgeschützt eingebunden; das beschreibbare Projektverzeichnis `home/`
wird unter `/home/terminal` eingebunden. Das `/var`-Verzeichnis des Hosts,
`/root`, andere Home-Verzeichnisse und das Host-Verzeichnis `/tmp` sind in der
Dateisystemansicht der Shell nicht verfügbar. Bash läuft außerdem im
eingeschränkten Modus, der `cd` und Änderungen am Befehlspfad blockiert.
Skripte in `home/` lassen sich mit `bash name.sh` ausführen. Bubblewrap und
unprivilegierte User-Namespaces müssen verfügbar sein; das Terminal darf nicht
starten, wenn sich die Sandbox nicht erstellen lässt.

In `tmux.conf` ist Bash als tmux-Befehlsshell festgelegt. Das ist nötig, weil
das Systemkonto `www-data` normalerweise `/usr/sbin/nologin` als Login-Shell
verwendet. Neue Bash-Sitzungen lesen `terminal.bashrc`, das den Prompt auf
`terminal$` setzt.

Das Verzeichnis `home/` ist für `www-data` beschreibbar, sodass dort erstellte
Skripte im Terminal ausgeführt werden können. Seine `.htaccess`-Datei
verhindert den direkten HTTP-Zugriff auf Dateien, wenn sie von Apache
bereitgestellt werden. Für nginx muss eine Location-Regel ergänzt werden, die
Anfragen an `/home/` verweigert.

Der aktuelle Arbeitsbereich kann den Verzeichnisbesitzer nicht ändern. Deshalb
hat `home/` das Sticky-Bit und ist für alle lokalen Benutzer beschreibbar. Auf
einem Host mit Administratorzugriff ist folgende restriktivere Konfiguration
geeignet: `chown www-data:www-data home && chmod 0750 home`.

Die Anwendung lädt Einstellungen aus `.env`. Kopiere `dotenv.example` nach
`.env`; fehlt `.env`, schreibt PHP eine Warnung ins PHP-Fehlerprotokoll. Die
Vorlage enthält die verfügbaren Einstellungen. Leere `TERMINAL_USER` deaktiviert HTTP Basic Auth. Das erlaubte
Clientnetzwerk wird über `TERMINAL_ALLOWED_NETWORK` als IPv4-CIDR festgelegt
(Standard: `192.168.1.0/24`). Prozessvariablen von PHP-FPM haben Vorrang vor
Werten aus `.env`.

## Für alte Chromebooks

Die Seite wählt anhand des User-Agent-Strings des Browsers Bundles aus, die mit
Chrome 77 kompatibel sind, wenn die Chrome-Version unter 80 liegt. Neuere
Browser verwenden die ursprünglichen xterm-Bundles. Die Kompatibilitäts-Bundles
sind nötig, weil das ursprüngliche xterm optionales Chaining verwendet, das
Chrome 77 nicht parsen kann. Um die kompatiblen Bundles neu zu erstellen, führe
`./build-chrome77.sh` aus. Dafür müssen Babel 7, `@babel/preset-env` und dessen
CLI unter dem Befehl `babeljs` verfügbar sein. Die Seite stellt außerdem einen
Polyfill für `Element.replaceChildren` in älteren Browsern bereit. xterm
verwendet diese Methode, Chrome 77 stellt sie jedoch nicht bereit.
