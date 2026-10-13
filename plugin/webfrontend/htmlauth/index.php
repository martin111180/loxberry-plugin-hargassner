<?php
require_once "loxberry_system.php";
require_once "loxberry_web.php";

$hg_cfgfile    = LBPCONFIGDIR . "/hargassner.json";
$hg_statusfile = "/dev/shm/loxberry-plugin-hargassner/status.json";  // wie STATUS_FILE in hargassner_bridge.py
$hg_logfile    = LBPLOGDIR . "/hargassner.log";
$hg_service    = LBPBINDIR . "/service.sh";

const HG_BACKUP_FORMAT = 'loxberry-plugin-hargassner';
const HG_BAUDRATES = [2400, 4800, 9600, 19200, 38400, 57600, 115200];
const HG_FIELD_TYPES = ['value', 'hex'];  // hex = Bitmaske; Hargassner sendet Digitalwerte immer hexadezimal
const HG_MAX_FIELDS = 400;   // wie MAX_TOKENS in hargassner_protocol.py
const HG_BIT_COUNT = 32;     // wie BIT_COUNT in hargassner_protocol.py

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Standardwerte – wie DEFAULT_CONFIG in hargassner_bridge.py
function default_config() {
	return [
		'enabled' => true,
		'source' => 'serial',
		'serial' => ['device' => '/dev/ttyUSB0', 'baudrate' => 19200],
		'tcp' => ['host' => '', 'port' => 4196],
		'topic' => 'hargassner/kessel',
		'republish_seconds' => 300,
		'data_timeout_seconds' => 30,
		'expected_fields' => 0,
		'cleanup_retained' => true,
		'loglevel' => 'INFO',
		'mqtt' => ['use_loxberry' => true, 'host' => 'localhost', 'port' => 1883, 'user' => '', 'password' => ''],
		'fields' => [],
	];
}

function read_config($hg_file) {
	$hg_cfg = json_decode(@file_get_contents($hg_file), true);
	$hg_cfg = array_replace_recursive(default_config(), is_array($hg_cfg) ? $hg_cfg : []);
	$hg_cfg['fields'] = normalize_fields_raw($hg_cfg['fields']);
	return $hg_cfg;
}

function write_config($hg_file, $hg_cfg) {
	// Objekte erzwingen: sonst schreibt PHP {"0": …, "1": …} als JSON-Liste
	$hg_fields = [];
	foreach ($hg_cfg['fields'] as $hg_idx => $hg_f) {
		$hg_f['bits'] = (object)($hg_f['bits'] ?? []);
		$hg_fields[(string)$hg_idx] = $hg_f;
	}
	$hg_cfg['fields'] = (object)$hg_fields;
	$hg_ok = file_put_contents($hg_file, json_encode($hg_cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
	@chmod($hg_file, 0600);
	return $hg_ok;
}

function service($hg_cmd) {
	global $hg_service;
	return trim((string)shell_exec(escapeshellarg($hg_service) . " " . escapeshellarg($hg_cmd) . " </dev/null 2>&1"));
}

function is_running() {
	return strpos(service('status'), 'running') === 0;
}

// Läuft noch der alte, von Hand eingerichtete Dienst aus der Gemini-Anleitung? Der blockiert die Schnittstelle.
function legacy_service_active() {
	return trim((string)shell_exec('systemctl is-active hargassner.service 2>/dev/null')) === 'active';
}

// Texte aus templates/lang/language_<sprache>.ini (Sprache des LoxBerry, Englisch als Rückfall)
$hg_L = LBSystem::readlanguage("language.ini");

function tr($hg_key, ...$hg_args) {
	global $hg_L;
	$hg_text = $hg_L[$hg_key] ?? $hg_key;
	return $hg_args ? vsprintf($hg_text, $hg_args) : $hg_text;
}

// Übersetzter Text, HTML-escaped; %s-Argumente werden unverändert eingesetzt (vorher selbst escapen)
function t($hg_key, ...$hg_args) {
	$hg_text = h(tr($hg_key));
	return $hg_args ? vsprintf($hg_text, $hg_args) : $hg_text;
}

function date_fmt($hg_ts) {
	return date(tr('UI.DATE_FORMAT'), (int)$hg_ts);
}

// Feldname -> Topic-Teil. Gleiche Regel wie slug() in hargassner_protocol.py
function slug($hg_name) {
	$hg_s = strtr((string)$hg_name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ß' => 'ss']);
	$hg_s = strtolower($hg_s);
	return trim(preg_replace('/[^a-z0-9]+/', '_', $hg_s), '_');
}

// Namen auf 60 Zeichen kürzen (mbstring ist nicht auf jedem System installiert)
function cut($hg_s) {
	return function_exists('mb_substr') ? mb_substr($hg_s, 0, 60, 'UTF-8') : substr($hg_s, 0, 60);
}

function slug_allowed($hg_slug) {
	return $hg_slug !== '' && $hg_slug !== 'bridge';
}

// ---------------------------------------------------------------- Felder (Namen, Typ, Bits)

// Wie normalize_fields() in hargassner_protocol.py: akzeptiert auch das alte Format {"4": "Kesseltemp"}
function normalize_fields_raw($hg_raw) {
	$hg_fields = [];
	if (!is_array($hg_raw)) {
		return $hg_fields;
	}
	foreach ($hg_raw as $hg_idx => $hg_f) {
		if (is_string($hg_f)) {
			$hg_f = ['name' => $hg_f];
		}
		if (!is_array($hg_f) || !preg_match('/^\d+$/', (string)$hg_idx)) {
			continue;
		}
		$hg_bits = [];
		foreach ((array)($hg_f['bits'] ?? []) as $hg_b => $hg_n) {
			if (preg_match('/^\d+$/', (string)$hg_b) && (int)$hg_b < HG_BIT_COUNT && trim((string)$hg_n) !== '') {
				$hg_bits[(string)(int)$hg_b] = cut(trim((string)$hg_n));
			}
		}
		$hg_type = $hg_f['type'] ?? 'value';
		if ($hg_type === 'dec') $hg_type = 'hex';  // früherer Typ "Bitmaske dezimal"
		if (!in_array($hg_type, HG_FIELD_TYPES, true)) $hg_type = 'value';
		$hg_fields[(string)(int)$hg_idx] = [
			'name' => cut(trim((string)($hg_f['name'] ?? ''))),
			'type' => $hg_type,
			'bits' => $hg_bits,
		];
	}
	ksort($hg_fields, SORT_NUMERIC);
	return $hg_fields;
}

// Felder prüfen: leere Einträge entfernen, doppelte/reservierte Namen melden. Ergebnis [felder, fehler]
function validate_fields($hg_fields) {
	$hg_clean = [];
	$hg_errors = [];
	$hg_seen = [];
	foreach ($hg_fields as $hg_idx => $hg_f) {
		if ((int)$hg_idx >= HG_MAX_FIELDS) {
			continue;
		}
		if ($hg_f['type'] === 'value') {
			$hg_f['bits'] = [];
		}
		if ($hg_f['name'] === '' && $hg_f['type'] === 'value') {
			continue;
		}
		$hg_names = [];
		if ($hg_f['name'] !== '') {
			$hg_names[] = [$hg_f['name'], "Index $hg_idx"];
		}
		foreach ($hg_f['bits'] as $hg_b => $hg_n) {
			$hg_names[] = [$hg_n, "Index $hg_idx, Bit $hg_b"];
		}
		foreach ($hg_names as [$hg_name, $hg_where]) {
			$hg_slug = slug($hg_name);
			if (!slug_allowed($hg_slug)) {
				$hg_errors[] = tr('MSG.NAME_INVALID', $hg_name, $hg_where);
			} elseif (isset($hg_seen[$hg_slug])) {
				$hg_errors[] = tr('MSG.NAME_DUPLICATE', $hg_name, $hg_where, $hg_seen[$hg_slug]);
			} else {
				$hg_seen[$hg_slug] = $hg_where;
			}
		}
		$hg_clean[(string)$hg_idx] = $hg_f;
	}
	return [$hg_clean, $hg_errors];
}

// ---------------------------------------------------------------- Einstellungen

// Übernimmt die vorhandenen Schlüssel aus $s (Formular oder Import) geprüft in die Konfiguration $c
function normalize_settings($s, $c) {
	if (!is_array($s)) {
		return $c;
	}
	foreach (['enabled', 'cleanup_retained'] as $hg_k) {
		if (array_key_exists($hg_k, $s)) {
			$c[$hg_k] = (bool)$s[$hg_k];
		}
	}
	if (isset($s['source'])) {
		$c['source'] = $s['source'] === 'tcp' ? 'tcp' : 'serial';
	}
	if (isset($s['serial']) && is_array($s['serial'])) {
		$hg_dev = trim((string)($s['serial']['device'] ?? ''));
		if (preg_match('#^/dev/[A-Za-z0-9_./:+-]+$#', $hg_dev) && strpos($hg_dev, '..') === false) {
			$c['serial']['device'] = $hg_dev;
		}
		if (isset($s['serial']['baudrate'])) {
			$hg_baud = (int)$s['serial']['baudrate'];
			$c['serial']['baudrate'] = in_array($hg_baud, HG_BAUDRATES, true) ? $hg_baud : 19200;
		}
	}
	if (isset($s['tcp']) && is_array($s['tcp'])) {
		if (isset($s['tcp']['host'])) {
			$c['tcp']['host'] = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', trim((string)$s['tcp']['host']));
		}
		if (isset($s['tcp']['port'])) {
			$hg_port = (int)$s['tcp']['port'];
			$c['tcp']['port'] = ($hg_port >= 1 && $hg_port <= 65535) ? $hg_port : 4196;
		}
	}
	if (isset($s['topic'])) {
		$hg_topic = trim(preg_replace('#[^A-Za-z0-9_/\-]#', '', (string)$s['topic']), '/');
		$c['topic'] = $hg_topic !== '' ? $hg_topic : 'hargassner/kessel';
	}
	if (isset($s['republish_seconds'])) {
		$hg_v = max(0, (int)$s['republish_seconds']);
		$c['republish_seconds'] = ($hg_v > 0 && $hg_v < 30) ? 30 : $hg_v;
	}
	if (isset($s['data_timeout_seconds'])) {
		$c['data_timeout_seconds'] = min(3600, max(5, (int)$s['data_timeout_seconds']));
	}
	if (isset($s['expected_fields'])) {
		$c['expected_fields'] = min(HG_MAX_FIELDS, max(0, (int)$s['expected_fields']));
	}
	if (isset($s['loglevel'])) {
		$c['loglevel'] = in_array($s['loglevel'], ['DEBUG', 'INFO', 'WARNING', 'ERROR'], true) ? $s['loglevel'] : 'INFO';
	}
	if (isset($s['mqtt']) && is_array($s['mqtt'])) {
		$hg_m = $s['mqtt'];
		if (array_key_exists('use_loxberry', $hg_m)) {
			$c['mqtt']['use_loxberry'] = (bool)$hg_m['use_loxberry'];
		}
		if (isset($hg_m['host'])) {
			$c['mqtt']['host'] = trim((string)$hg_m['host']) ?: 'localhost';
		}
		if (isset($hg_m['port'])) {
			$c['mqtt']['port'] = (int)$hg_m['port'] ?: 1883;
		}
		if (isset($hg_m['user'])) {
			$c['mqtt']['user'] = trim((string)$hg_m['user']);
		}
		if (isset($hg_m['password']) && (string)$hg_m['password'] !== '') {
			$c['mqtt']['password'] = (string)$hg_m['password'];
		}
	}
	return $c;
}

// Serielle Schnittstellen am LoxBerry; /dev/serial/by-id bleibt auch nach Umstecken gleich
function serial_devices() {
	$hg_list = [];
	foreach (['/dev/serial/by-id/*', '/dev/ttyUSB*', '/dev/ttyACM*', '/dev/ttyAMA*', '/dev/ttyS0'] as $hg_pattern) {
		foreach (glob($hg_pattern) ?: [] as $hg_dev) {
			$hg_list[] = $hg_dev;
		}
	}
	return array_values(array_unique($hg_list));
}

// ---------------------------------------------------------------- Sicherung

function backup_data($hg_cfg) {
	$hg_settings = $hg_cfg;
	unset($hg_settings['fields'], $hg_settings['mqtt']['password']);
	$hg_fields = [];
	foreach ($hg_cfg['fields'] as $hg_idx => $hg_f) {
		$hg_f['bits'] = (object)$hg_f['bits'];
		$hg_fields[(string)$hg_idx] = $hg_f;
	}
	$hg_plugin = LBSystem::plugindata();
	return [
		'format' => HG_BACKUP_FORMAT,
		'format_version' => 1,
		'plugin_version' => $hg_plugin['PLUGINDB_VERSION'] ?? '',
		'created' => date('c'),
		'host' => gethostname(),
		'settings' => $hg_settings,
		'fields' => (object)$hg_fields,
	];
}

// Hochgeladene Datei -> [einstellungen|null, felder|null] oder Fehlertext
function parse_backup($hg_data) {
	if (!is_array($hg_data)) {
		return tr('MSG.IMPORT_NO_JSON');
	}
	if (($hg_data['format'] ?? '') === HG_BACKUP_FORMAT) {
		if ((int)($hg_data['format_version'] ?? 0) > 1) {
			return tr('MSG.IMPORT_TOO_NEW');
		}
		return [$hg_data['settings'] ?? null, $hg_data['fields'] ?? null];
	}
	// Konfigurationsdatei hargassner.json direkt
	if (isset($hg_data['fields']) || isset($hg_data['source']) || isset($hg_data['topic'])) {
		$hg_fields = $hg_data['fields'] ?? null;
		unset($hg_data['fields']);
		return [$hg_data, $hg_fields];
	}
	// Altes Format der Skripte: hargassner_mapping.json {"4": "Kesseltemp", ...}
	if ($hg_data && count(array_filter(array_keys($hg_data), fn($k) => preg_match('/^\d+$/', (string)$k))) === count($hg_data)
		&& count(array_filter($hg_data, 'is_string')) === count($hg_data)) {
		return [null, $hg_data];
	}
	return tr('MSG.IMPORT_UNKNOWN');
}

// ---------------------------------------------------------------- Aktionen

$hg_cfg = read_config($hg_cfgfile);
$hg_pages = ['values', 'settings', 'backup'];
$hg_page = in_array($_GET['page'] ?? '', $hg_pages, true) ? $_GET['page'] : 'values';
$hg_message = "";
$hg_message_ok = true;

if (($_GET['action'] ?? '') === 'export') {
	header('Content-Type: application/json; charset=utf-8');
	header('Content-Disposition: attachment; filename="hargassner-sicherung-' . date('Y-m-d') . '.json"');
	header('Cache-Control: no-store');
	echo json_encode(backup_data($hg_cfg), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

// Speichern + Bridge neu starten; liefert [ok, Meldung]
function save_and_restart($hg_new, $hg_prefix) {
	global $hg_cfgfile, $hg_cfg;
	if (!write_config($hg_cfgfile, $hg_new)) {
		return [false, tr('MSG.SAVE_FAILED')];
	}
	$hg_cfg = read_config($hg_cfgfile);
	service('restart');
	if (is_running()) {
		return [true, $hg_prefix . ' ' . tr('MSG.RESTARTED')];
	}
	return [false, $hg_prefix . ' ' . tr(empty($hg_cfg['enabled']) ? 'MSG.REASON_DISABLED' : 'MSG.REASON_UNKNOWN')];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	$hg_action = $_POST['action'] ?? '';
	if ($hg_action === 'save_fields') {
		$hg_raw = [];
		foreach ((array)($_POST['fields'] ?? []) as $hg_idx => $hg_f) {
			// Bitnamen kommen aus den Bitzeilen (fields[i][bitnames][b]); fehlen sie (z. B. ohne JavaScript),
			// bleiben die gespeicherten erhalten
			$hg_bits = $hg_cfg['fields'][(string)$hg_idx]['bits'] ?? [];
			if (isset($hg_f['bitnames']) && is_array($hg_f['bitnames'])) {
				$hg_bits = array_filter(array_map('trim', $hg_f['bitnames']), 'strlen');
			}
			$hg_raw[$hg_idx] = [
				'name' => $hg_f['name'] ?? '',
				'type' => $hg_f['type'] ?? 'value',
				'bits' => $hg_bits,
			];
		}
		// Felder, die nicht im Formular standen (z. B. Index über der aktuellen Anzahl), bleiben erhalten
		$hg_merged = normalize_fields_raw(array_replace($hg_cfg['fields'], $hg_raw));
		[$hg_fields, $hg_errors] = validate_fields($hg_merged);
		if ($hg_errors) {
			$hg_cfg['fields'] = $hg_merged;   // Eingaben im Formular stehen lassen
			[$hg_message_ok, $hg_message] = [false, tr('MSG.NOT_SAVED') . ' ' . implode(' ', $hg_errors)];
		} else {
			$hg_new = $hg_cfg;
			$hg_new['fields'] = $hg_fields;
			[$hg_message_ok, $hg_message] = save_and_restart($hg_new, tr('MSG.FIELDS_SAVED'));
		}
	} elseif ($hg_action === 'save_settings') {
		$hg_page = 'settings';
		$hg_new = normalize_settings([
			'enabled' => isset($_POST['enabled']),
			'source' => $_POST['source'] ?? 'serial',
			'serial' => ['device' => $_POST['serial_device'] ?? '', 'baudrate' => $_POST['serial_baudrate'] ?? 19200],
			'tcp' => ['host' => $_POST['tcp_host'] ?? '', 'port' => $_POST['tcp_port'] ?? 4196],
			'topic' => $_POST['topic'] ?? '',
			'cleanup_retained' => isset($_POST['cleanup_retained']),
			'republish_seconds' => $_POST['republish_seconds'] ?? 300,
			'data_timeout_seconds' => $_POST['data_timeout_seconds'] ?? 30,
			'expected_fields' => $_POST['expected_fields'] ?? 0,
			'loglevel' => $_POST['loglevel'] ?? 'INFO',
			'mqtt' => [
				'use_loxberry' => isset($_POST['mqtt_use_loxberry']),
				'host' => $_POST['mqtt_host'] ?? 'localhost',
				'port' => $_POST['mqtt_port'] ?? 1883,
				'user' => $_POST['mqtt_user'] ?? '',
				'password' => $_POST['mqtt_password'] ?? '',
			],
		], $hg_cfg);
		if ($hg_new['source'] === 'tcp' && $hg_new['tcp']['host'] === '') {
			$hg_cfg = $hg_new;
			[$hg_message_ok, $hg_message] = [false, tr('MSG.NOT_SAVED') . ' ' . tr('MSG.TCP_HOST_MISSING')];
		} else {
			[$hg_message_ok, $hg_message] = save_and_restart($hg_new, tr('MSG.SETTINGS_SAVED'));
		}
	} elseif ($hg_action === 'import') {
		$hg_page = 'backup';
		$hg_file = $_FILES['backup'] ?? null;
		$hg_result = null;
		if (!$hg_file || ($hg_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			$hg_result = tr('MSG.IMPORT_NO_FILE');
		} elseif ($hg_file['size'] > 1024 * 1024) {
			$hg_result = tr('MSG.IMPORT_TOO_BIG');
		} else {
			$hg_result = parse_backup(json_decode((string)file_get_contents($hg_file['tmp_name']), true));
		}
		if (is_string($hg_result)) {
			[$hg_message_ok, $hg_message] = [false, $hg_result];
		} else {
			[$hg_settings, $hg_fields_raw] = $hg_result;
			$hg_new = $hg_cfg;
			$hg_done = [];
			$hg_errors = [];
			if (isset($_POST['import_fields']) && is_array($hg_fields_raw)) {
				[$hg_fields, $hg_errors] = validate_fields(normalize_fields_raw($hg_fields_raw));
				$hg_new['fields'] = $hg_fields;
				$hg_done[] = tr('MSG.IMPORT_FIELDS', count($hg_fields));
			}
			if (isset($_POST['import_settings']) && is_array($hg_settings)) {
				$hg_new = normalize_settings($hg_settings, $hg_new);
				$hg_done[] = tr('MSG.IMPORT_SETTINGS');
			}
			if ($hg_errors) {
				[$hg_message_ok, $hg_message] = [false, tr('MSG.NOT_SAVED') . ' ' . implode(' ', $hg_errors)];
			} elseif (!$hg_done) {
				[$hg_message_ok, $hg_message] = [false, tr('MSG.IMPORT_NOTHING')];
			} else {
				[$hg_message_ok, $hg_message] = save_and_restart($hg_new, tr('MSG.IMPORTED') . ' ' . implode(', ', $hg_done) . '.');
			}
		}
	} elseif (in_array($hg_action, ['start', 'stop', 'restart'], true)) {
		service($hg_action);
		$hg_up = is_running();
		if ($hg_action === 'stop') {
			[$hg_message_ok, $hg_message] = [!$hg_up, tr($hg_up ? 'MSG.STOP_FAILED' : 'MSG.STOPPED')];
		} else {
			[$hg_message_ok, $hg_message] = [$hg_up, tr($hg_up ? 'MSG.RESTARTED' : (empty($hg_cfg['enabled']) ? 'MSG.REASON_DISABLED' : 'MSG.REASON_UNKNOWN'))];
		}
	}
}

// ---------------------------------------------------------------- Live-Bereich

function read_status() {
	global $hg_statusfile;
	$hg_status = json_decode(@file_get_contents($hg_statusfile), true);
	return is_array($hg_status) ? $hg_status : [];
}

function age_text($hg_seconds) {
	$hg_seconds = max(0, (int)$hg_seconds);
	if ($hg_seconds < 120) return tr('UI.AGE_SECONDS', $hg_seconds);
	if ($hg_seconds < 7200) return tr('UI.AGE_MINUTES', intdiv($hg_seconds, 60));
	return tr('UI.AGE_HOURS', intdiv($hg_seconds, 3600));
}

function render_status_table($hg_status, $hg_running) {
	$hg_now = time();
	$hg_fresh = $hg_status && ($hg_now - (int)($hg_status['updated'] ?? 0)) < 15;
?>
<table class="hg-table hg-status">
	<tr><td><?= t('UI.BRIDGE_SERVICE') ?></td><td><?= $hg_running ? '<span class="hg-ok">' . t('UI.RUNNING') . '</span>' : '<span class="hg-bad">' . t('UI.STOPPED') . '</span>' ?></td></tr>
	<?php if ($hg_status && $hg_fresh): ?>
	<tr><td><?= t('UI.SOURCE') ?></td><td><?= h($hg_status['source'] ?? '') ?></td></tr>
	<tr><td><?= t('UI.HEATING') ?></td><td>
		<?= !empty($hg_status['connected']) ? '<span class="hg-ok">' . t('UI.DATA_OK') . '</span>' : '<span class="hg-bad">' . t('UI.NO_DATA') . '</span>' ?>
		<?php if (!empty($hg_status['error'])): ?><br><span class="hg-bad"><?= h($hg_status['error']) ?></span><?php endif; ?>
	</td></tr>
	<tr><td><?= t('UI.MQTT_BROKER') ?></td><td><?= !empty($hg_status['mqtt_connected']) ? '<span class="hg-ok">' . t('UI.CONNECTED') . '</span>' : '<span class="hg-bad">' . t('UI.NOT_CONNECTED') . '</span>' ?></td></tr>
	<tr><td><?= t('UI.LAST_FRAME') ?></td><td><?= !empty($hg_status['last_frame']) ? h(date_fmt($hg_status['last_frame']) . ' (' . age_text($hg_now - (int)$hg_status['last_frame']) . ')') : '–' ?></td></tr>
	<tr><td><?= t('UI.FIELD_COUNT') ?></td><td><?= (int)($hg_status['field_count'] ?? 0) ?: t('UI.FIELD_COUNT_LEARNING') ?></td></tr>
	<tr><td><?= t('UI.FRAMES') ?></td><td><?= t('UI.FRAMES_TEXT', (int)($hg_status['frames_ok'] ?? 0), (int)($hg_status['frames_rejected'] ?? 0)) ?>
		<?php if (!empty($hg_status['frames_rejected']) && !empty($hg_status['last_rejected_length'])): ?><br><span class="hg-hint"><?= t('UI.LAST_REJECTED', (int)$hg_status['last_rejected_length']) ?></span><?php endif; ?>
	</td></tr>
	<?php elseif ($hg_running): ?>
	<tr><td></td><td><?= t('UI.STARTING') ?></td></tr>
	<?php endif; ?>
</table>
<?php
}

function capture($hg_fn, ...$hg_args) {
	ob_start();
	$hg_fn(...$hg_args);
	return ob_get_clean();
}

if (($_GET['ajax'] ?? '') === 'live') {
	$hg_status = read_status();
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo json_encode([
		'status' => capture('render_status_table', $hg_status, is_running()),
		'values' => array_values($hg_status['values'] ?? []),
		'fresh'  => $hg_status && (time() - (int)($hg_status['updated'] ?? 0)) < 15,
	]);
	exit;
}

// ---------------------------------------------------------------- Seite

$hg_running = is_running();
$hg_status = read_status();
$hg_topic = $hg_cfg['topic'];

$navbar[1]['Name'] = tr('UI.NAV_VALUES');
$navbar[1]['URL'] = 'index.php';
$navbar[1]['active'] = $hg_page === 'values';
$navbar[2]['Name'] = tr('UI.NAV_SETTINGS');
$navbar[2]['URL'] = 'index.php?page=settings';
$navbar[2]['active'] = $hg_page === 'settings';
$navbar[3]['Name'] = tr('UI.NAV_BACKUP');
$navbar[3]['URL'] = 'index.php?page=backup';
$navbar[3]['active'] = $hg_page === 'backup';
$navbar[4]['Name'] = tr('UI.NAV_LOG');
$navbar[4]['URL'] = '/admin/system/tools/logfile.cgi?logfile=' . urlencode($hg_logfile) . '&header=html&format=template';
$navbar[4]['target'] = '_blank';

LBWeb::lbheader(tr('UI.TITLE'), "", "");
?>
<style>
	.hg-ok { color: #2e7d32; font-weight: bold; }
	.hg-bad { color: #c62828; font-weight: bold; }
	.hg-table { border-collapse: collapse; width: 100%; margin-bottom: 1em; }
	.hg-table td, .hg-table th { border-bottom: 1px solid #ddd; padding: 3px 6px; text-align: left; vertical-align: middle; }
	.hg-table code { font-size: 85%; }
	.hg-status td:first-child { width: 14em; color: #555; }
	.hg-hint { font-size: 90%; color: #666; }
	.hg-msg { padding: 8px 12px; margin-bottom: 1em; border-radius: 4px; }
	.hg-msg-ok { background: #e8f5e9; border: 1px solid #a5d6a7; color: #1b5e20; }
	.hg-msg-bad { background: #fdecea; border: 1px solid #f5c6cb; color: #8a1c1c; }
	.hg-msg-warn { background: #fff8e1; border: 1px solid #ffe082; color: #5d4300; }
	.hg-fields input[type=text] { width: 100%; box-sizing: border-box; padding: 3px 4px; }
	.hg-fields select { padding: 2px; }
	.hg-fields td.hg-idx { color: #555; text-align: right; width: 3em; }
	.hg-fields td.hg-val { font-family: monospace; white-space: nowrap; min-width: 7em; }
	.hg-fields td.hg-topic { font-size: 85%; color: #555; }
	.hg-bitview { font-family: monospace; font-size: 85%; color: #555; white-space: nowrap; }
	.hg-fields tr.hg-bitrow td { background: #f7f7f7; padding-top: 1px; padding-bottom: 1px; }
	.hg-fields tr.hg-bitrow td.hg-idx { font-size: 85%; white-space: nowrap; }
	.hg-fields tr.hg-bitrow b { color: #c62828; }
	.hg-bitview b { color: #c62828; }
	.hg-changes { display: inline-block; min-width: 1.6em; margin-left: 6px; padding: 0 4px; border-radius: 8px; background: #fff3cd; color: #7a5b00; font-size: 80%; text-align: center; }
	.hg-flash { animation: hg-flash 2.5s ease-out; }
	@keyframes hg-flash { from { background: #ffe082; } to { background: transparent; } }
	.hg-toolbar label { display: inline-block; margin-right: 1.5em; }
</style>

<?php if ($hg_message): ?><div class="hg-msg <?= $hg_message_ok ? 'hg-msg-ok' : 'hg-msg-bad' ?>"><?= h($hg_message) ?></div><?php endif; ?>
<?php if (legacy_service_active()): ?><div class="hg-msg hg-msg-warn"><?= t('UI.LEGACY_SERVICE') ?></div><?php endif; ?>

<?php if ($hg_page === 'values'): ?>
<h2><?= t('UI.STATUS') ?></h2>
<div id="hg-live-status"><?php render_status_table($hg_status, $hg_running); ?></div>
<form method="post" action="index.php" data-ajax="false" style="display:inline">
	<button type="submit" name="action" value="restart" data-inline="true" data-mini="true"><?= t('UI.BTN_RESTART') ?></button>
	<button type="submit" name="action" value="stop" data-inline="true" data-mini="true"><?= t('UI.BTN_STOP') ?></button>
</form>

<h2><?= t('UI.VALUES') ?></h2>
<p class="hg-hint"><?= t('UI.VALUES_HINT') ?></p>
<?php
$hg_values = array_values($hg_status['values'] ?? []);
$hg_rows = max(count($hg_values), (int)($hg_status['field_count'] ?? 0), $hg_cfg['fields'] ? max(array_keys($hg_cfg['fields'])) + 1 : 0);
?>
<?php if (!$hg_rows): ?>
<div class="hg-msg hg-msg-warn"><?= t('UI.NO_VALUES_YET') ?></div>
<?php else: ?>
<div class="hg-toolbar">
	<label><input type="checkbox" id="hg-only-named" data-role="none"> <?= t('UI.ONLY_NAMED') ?></label>
	<label><input type="checkbox" id="hg-only-changed" data-role="none"> <?= t('UI.ONLY_CHANGED') ?></label>
	<label><input type="checkbox" id="hg-all-bits" data-role="none"> <?= t('UI.SHOW_ALL_BITS') ?></label>
	<a href="#" id="hg-reset-changes"><?= t('UI.RESET_CHANGES') ?></a>
</div>
<form method="post" action="index.php" data-ajax="false">
	<input type="hidden" name="action" value="save_fields">
	<table class="hg-table hg-fields" data-topic="<?= h($hg_topic) ?>" data-not-sent="<?= t('UI.NOT_SENT') ?>" data-bit="<?= t('UI.BIT') ?>">
		<tr><th>#</th><th><?= t('UI.COL_VALUE') ?></th><th><?= t('UI.COL_NAME') ?></th><th><?= t('UI.COL_TYPE') ?></th><th><?= t('UI.COL_BITS') ?></th><th><?= t('UI.COL_TOPIC') ?></th></tr>
		<?php for ($hg_i = 0; $hg_i < $hg_rows; $hg_i++):
			$hg_f = $hg_cfg['fields'][(string)$hg_i] ?? ['name' => '', 'type' => 'value', 'bits' => []];
		?>
		<tr class="hg-row" data-idx="<?= $hg_i ?>" data-bits="<?= h(json_encode((object)$hg_f['bits'], JSON_UNESCAPED_UNICODE)) ?>">
			<td class="hg-idx"><?= $hg_i ?></td>
			<td class="hg-val"><span class="hg-v"><?= h($hg_values[$hg_i] ?? '') ?></span><span class="hg-changes" style="display:none"></span></td>
			<td><input type="text" data-role="none" class="hg-name" name="fields[<?= $hg_i ?>][name]" value="<?= h($hg_f['name']) ?>" maxlength="60"<?= $hg_i === 0 ? ' disabled' : '' ?>></td>
			<td><select data-role="none" class="hg-type" name="fields[<?= $hg_i ?>][type]"<?= $hg_i === 0 ? ' disabled' : '' ?>>
				<?php foreach (HG_FIELD_TYPES as $hg_type): ?>
				<option value="<?= $hg_type ?>" <?= $hg_f['type'] === $hg_type ? 'selected' : '' ?>><?= t('UI.TYPE_' . strtoupper($hg_type)) ?></option>
				<?php endforeach; ?>
			</select></td>
			<td class="hg-bits"><span class="hg-bitview"></span></td>
			<td class="hg-topic"></td>
		</tr>
		<?php endfor; ?>
	</table>
	<button type="submit" data-icon="check"><?= t('UI.SAVE_FIELDS') ?></button>
</form>
<p class="hg-hint"><?= t('UI.STATUS_TOPICS', '<code>' . h($hg_topic) . '/bridge/connected</code>', '<code>' . h($hg_topic) . '/bridge/error</code>') ?><br><?= t('UI.GATEWAY_HINT', '<code>' . h(explode('/', $hg_topic)[0]) . '/#</code>') ?></p>
<?php endif; ?>

<script>
// Live-Werte alle 2 s aktualisieren (Eingabefelder bleiben unberührt). Geänderte Werte blinken kurz und
// werden gezählt – so findet man heraus, welcher Index zu welcher Anzeige der Heizung gehört.
// Bitmasken bekommen je Bit eine eigene Zeile mit 0/1, Änderungszähler und Namensfeld.
(function () {
	var BIT_COUNT = 32;  // wie BIT_COUNT in hargassner_protocol.py
	var statusBox = document.getElementById('hg-live-status');
	var table = document.querySelector('table.hg-fields');
	var rows = Array.prototype.slice.call(document.querySelectorAll('tr.hg-row'));
	var onlyNamed = document.getElementById('hg-only-named');
	var onlyChanged = document.getElementById('hg-only-changed');
	var allBits = document.getElementById('hg-all-bits');
	var state = {};  // je Index: letzter Wert, Änderungen, Bitzeilen, Bitzähler, "war schon 1"

	function esc(s) {
		return String(s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; });
	}

	// gleiche Regel wie slug() in hargassner_protocol.py / index.php
	function slug(name) {
		var map = { 'ä': 'ae', 'ö': 'oe', 'ü': 'ue', 'Ä': 'ae', 'Ö': 'oe', 'Ü': 'ue', 'ß': 'ss' };
		return String(name).replace(/[äöüÄÖÜß]/g, function (c) { return map[c]; })
			.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
	}

	function topicHtml(name) {
		var s = slug(name);
		if (!s || s === 'bridge') return '<span class="hg-hint">' + esc(table.dataset.notSent) + '</span>';
		return '<code>' + esc(table.dataset.topic + '/' + s) + '</code>';
	}

	// Hex-Wert als Zahl oder null (Division statt >>, damit auch Bit 31 ohne Vorzeichenfehler klappt)
	function hexValue(raw) {
		return /^[0-9a-f]{1,8}$/i.test(raw) ? parseInt(raw, 16) : null;
	}
	function bitOf(n, b) { return Math.floor(n / Math.pow(2, b)) % 2; }

	function flash(el) {
		el.classList.remove('hg-flash');
		void el.offsetWidth;  // Animation neu starten
		el.classList.add('hg-flash');
	}

	function isHex(row) { return row.querySelector('.hg-type').value === 'hex'; }

	// Bitzeilen unter einer Bitmaske anlegen bzw. entfernen
	function syncBitRows(row) {
		var st = state[row.dataset.idx];
		if (!isHex(row)) {
			st.bitRows.forEach(function (r) { r.parentNode.removeChild(r); });
			st.bitRows = [];
			return;
		}
		if (st.bitRows.length) return;
		var idx = row.dataset.idx;
		var names = JSON.parse(row.dataset.bits || '{}');
		var after = row;
		for (var b = 0; b < BIT_COUNT; b++) {
			var tr = document.createElement('tr');
			tr.className = 'hg-bitrow';
			tr.dataset.bit = b;
			tr.innerHTML = '<td class="hg-idx">↳ ' + esc(table.dataset.bit) + ' ' + b + '</td>' +
				'<td class="hg-val"><span class="hg-bv"></span><span class="hg-changes" style="display:none"></span></td>' +
				'<td><input type="text" data-role="none" class="hg-bitname" maxlength="60" name="fields[' + idx + '][bitnames][' + b + ']" value="' + esc(names[b] || '') + '"></td>' +
				'<td></td><td></td><td class="hg-topic"></td>';
			tr.querySelector('.hg-bitname').addEventListener('input', function () { renderTopics(row); filter(); });
			after.parentNode.insertBefore(tr, after.nextSibling);
			after = tr;
			st.bitRows.push(tr);
		}
		st.bitChanges = st.bitChanges || {};
		st.everSet = st.everSet || {};
		renderValue(row, false);
		renderTopics(row);
	}

	function renderTopics(row) {
		var lines = [];
		var name = row.querySelector('.hg-name').value;
		if (slug(name)) lines.push(topicHtml(name));
		state[row.dataset.idx].bitRows.forEach(function (tr) {
			var bitName = tr.querySelector('.hg-bitname').value;
			tr.querySelector('.hg-topic').innerHTML = topicHtml(bitName);
			if (slug(bitName)) lines.push(topicHtml(bitName) + ' <span class="hg-hint">Bit ' + tr.dataset.bit + '</span>');
		});
		row.querySelector('.hg-topic').innerHTML = lines.length ? lines.join('<br>') : topicHtml('');
	}

	// Wert (und Bits) einer Zeile anzeigen; countChanges = Änderungen gegenüber dem letzten Wert zählen
	function renderValue(row, countChanges) {
		var st = state[row.dataset.idx];
		var raw = row.querySelector('.hg-v').textContent.trim();
		var view = row.querySelector('.hg-bitview');
		var n = isHex(row) ? hexValue(raw) : null;
		if (n === null) { view.textContent = isHex(row) && raw !== '' ? '–' : ''; }
		else {
			var width = n > 0xffff ? 32 : 16, html = '';
			for (var b = width - 1; b >= 0; b--) {
				html += bitOf(n, b) ? '<b>1</b>' : '0';
				if (b % 4 === 0 && b) html += ' ';
			}
			view.innerHTML = html;
		}
		st.bitRows.forEach(function (tr) {
			var b = +tr.dataset.bit;
			var cell = tr.querySelector('.hg-bv');
			var bit = n === null ? '' : String(bitOf(n, b));
			if (countChanges && st.lastNumber !== null && n !== null && bitOf(st.lastNumber, b) !== bitOf(n, b)) {
				st.bitChanges[b] = (st.bitChanges[b] || 0) + 1;
				var badge = tr.querySelector('.hg-changes');
				badge.textContent = '×' + st.bitChanges[b];
				badge.style.display = '';
				flash(tr);
			}
			if (bit === '1') st.everSet[b] = true;
			cell.innerHTML = bit === '1' ? '<b>1</b>' : bit;
		});
		st.lastNumber = n;
	}

	function filter() {
		rows.forEach(function (row) {
			var st = state[row.dataset.idx];
			var anyBitVisible = false;
			var anyBitNamed = false;
			st.bitRows.forEach(function (tr) {
				var b = tr.dataset.bit;
				var named = tr.querySelector('.hg-bitname').value.trim() !== '';
				var changed = !!(st.bitChanges && st.bitChanges[b]);
				anyBitNamed = anyBitNamed || named;
				var show = ((allBits && allBits.checked) || named || changed || (st.everSet && st.everSet[b])) &&
					!(onlyNamed && onlyNamed.checked && !named) &&
					!(onlyChanged && onlyChanged.checked && !changed);
				tr.style.display = show ? '' : 'none';
				anyBitVisible = anyBitVisible || show;
			});
			var named = row.querySelector('.hg-name').value.trim() !== '' || anyBitNamed;
			var hide = (onlyNamed && onlyNamed.checked && !named) || (onlyChanged && onlyChanged.checked && !st.changes);
			row.style.display = hide && !anyBitVisible ? 'none' : '';
		});
	}

	function update(values) {
		rows.forEach(function (row) {
			var st = state[row.dataset.idx];
			var cell = row.querySelector('.hg-v');
			var val = values[row.dataset.idx] !== undefined ? String(values[row.dataset.idx]) : '';
			if (val === st.last) return;
			var counted = st.last !== '' && val !== '';
			if (counted) {
				st.changes += 1;
				var badge = row.querySelector('.hg-changes');
				badge.textContent = '×' + st.changes;
				badge.style.display = '';
				flash(row);
			}
			st.last = val;
			cell.textContent = val;
			renderValue(row, counted);
		});
		filter();
	}

	rows.forEach(function (row) {
		state[row.dataset.idx] = { last: row.querySelector('.hg-v').textContent, changes: 0, bitRows: [], bitChanges: {}, everSet: {}, lastNumber: null };
		renderValue(row, false);
		syncBitRows(row);
		renderTopics(row);
		row.querySelector('.hg-type').addEventListener('change', function () { syncBitRows(row); renderValue(row, false); renderTopics(row); filter(); });
		row.querySelector('.hg-name').addEventListener('input', function () { renderTopics(row); filter(); });
	});
	filter();
	[onlyNamed, onlyChanged, allBits].forEach(function (cb) { if (cb) cb.addEventListener('change', filter); });
	var reset = document.getElementById('hg-reset-changes');
	if (reset) reset.addEventListener('click', function (e) {
		e.preventDefault();
		Object.keys(state).forEach(function (k) { state[k].changes = 0; state[k].bitChanges = {}; });
		document.querySelectorAll('.hg-changes').forEach(function (b) { b.style.display = 'none'; });
		filter();
	});

	if (!statusBox || !window.fetch) return;
	function refresh() {
		if (document.hidden) { setTimeout(refresh, 2000); return; }
		fetch('index.php?ajax=live', { credentials: 'same-origin', cache: 'no-store' })
			.then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
			.then(function (data) {
				statusBox.innerHTML = data.status;
				// Erste Daten nach dem Start: Tabelle gibt es noch nicht -> Seite neu laden
				if (data.fresh && !rows.length && (data.values || []).length) { location.reload(); return; }
				if (data.fresh) update(data.values || []);
			})
			.catch(function () { /* nächster Versuch beim nächsten Intervall */ })
			.then(function () { setTimeout(refresh, 2000); });
	}
	setTimeout(refresh, 2000);
})();
</script>

<?php elseif ($hg_page === 'settings'): ?>
<form method="post" action="index.php?page=settings" data-ajax="false">
	<input type="hidden" name="action" value="save_settings">

	<label><input type="checkbox" name="enabled" <?= $hg_cfg['enabled'] ? 'checked' : '' ?>> <?= t('UI.ENABLED') ?></label>

	<h3><?= t('UI.CONNECTION') ?></h3>
	<fieldset data-role="controlgroup" data-type="horizontal">
		<legend><?= t('UI.SOURCE_TYPE') ?></legend>
		<label><input type="radio" name="source" value="serial" class="hg-source" <?= $hg_cfg['source'] !== 'tcp' ? 'checked' : '' ?>> <?= t('UI.SOURCE_SERIAL') ?></label>
		<label><input type="radio" name="source" value="tcp" class="hg-source" <?= $hg_cfg['source'] === 'tcp' ? 'checked' : '' ?>> <?= t('UI.SOURCE_TCP') ?></label>
	</fieldset>

	<div id="hg-serial">
		<label for="serial_device"><?= t('UI.SERIAL_DEVICE') ?></label>
		<input type="text" id="serial_device" name="serial_device" list="hg-devices" value="<?= h($hg_cfg['serial']['device']) ?>">
		<datalist id="hg-devices"><?php foreach (serial_devices() as $hg_dev): ?><option value="<?= h($hg_dev) ?>"><?php endforeach; ?></datalist>
		<p class="hg-hint"><?= t('UI.SERIAL_DEVICE_HINT') ?>
			<?php $hg_found = serial_devices(); echo $hg_found ? '<br>' . t('UI.SERIAL_FOUND') . ' <code>' . implode('</code>, <code>', array_map('h', $hg_found)) . '</code>' : '<br>' . t('UI.SERIAL_NONE'); ?></p>
		<label for="serial_baudrate"><?= t('UI.BAUDRATE') ?></label>
		<select id="serial_baudrate" name="serial_baudrate">
			<?php foreach (HG_BAUDRATES as $hg_baud): ?>
			<option value="<?= $hg_baud ?>" <?= (int)$hg_cfg['serial']['baudrate'] === $hg_baud ? 'selected' : '' ?>><?= $hg_baud ?></option>
			<?php endforeach; ?>
		</select>
	</div>

	<div id="hg-tcp">
		<label for="tcp_host"><?= t('UI.TCP_HOST') ?></label>
		<input type="text" id="tcp_host" name="tcp_host" value="<?= h($hg_cfg['tcp']['host']) ?>" placeholder="192.168.1.200">
		<label for="tcp_port"><?= t('UI.TCP_PORT') ?></label>
		<input type="number" id="tcp_port" name="tcp_port" min="1" max="65535" value="<?= (int)$hg_cfg['tcp']['port'] ?>">
		<p class="hg-hint"><?= t('UI.TCP_HINT') ?></p>
	</div>

	<label for="data_timeout_seconds"><?= t('UI.DATA_TIMEOUT') ?></label>
	<input type="number" id="data_timeout_seconds" name="data_timeout_seconds" min="5" max="3600" value="<?= (int)$hg_cfg['data_timeout_seconds'] ?>">
	<label for="expected_fields"><?= t('UI.EXPECTED_FIELDS') ?></label>
	<input type="number" id="expected_fields" name="expected_fields" min="0" max="<?= HG_MAX_FIELDS ?>" value="<?= (int)$hg_cfg['expected_fields'] ?>">
	<p class="hg-hint"><?= t('UI.EXPECTED_FIELDS_HINT') ?></p>

	<h3><?= t('UI.MQTT') ?></h3>
	<label for="topic"><?= t('UI.BASE_TOPIC') ?></label>
	<input type="text" id="topic" name="topic" value="<?= h($hg_topic) ?>">
	<label><input type="checkbox" name="cleanup_retained" <?= $hg_cfg['cleanup_retained'] ? 'checked' : '' ?>> <?= t('UI.CLEANUP') ?></label>
	<label for="republish_seconds"><?= t('UI.REPUBLISH') ?></label>
	<input type="number" id="republish_seconds" name="republish_seconds" min="0" value="<?= (int)$hg_cfg['republish_seconds'] ?>">
	<p class="hg-hint"><?= t('UI.REPUBLISH_HINT') ?></p>
	<?php $hg_mqtt = $hg_cfg['mqtt']; ?>
	<label><input type="checkbox" id="mqtt_use_loxberry" name="mqtt_use_loxberry" <?= $hg_mqtt['use_loxberry'] ? 'checked' : '' ?>> <?= t('UI.USE_LOXBERRY') ?></label>
	<div id="hg-mqtt-manual"<?= $hg_mqtt['use_loxberry'] ? ' style="display:none"' : '' ?>>
		<label for="mqtt_host"><?= t('UI.BROKER_HOST') ?></label>
		<input type="text" id="mqtt_host" name="mqtt_host" value="<?= h($hg_mqtt['host']) ?>">
		<label for="mqtt_port"><?= t('UI.BROKER_PORT') ?></label>
		<input type="number" id="mqtt_port" name="mqtt_port" value="<?= (int)$hg_mqtt['port'] ?>">
		<label for="mqtt_user"><?= t('UI.BROKER_USER') ?></label>
		<input type="text" id="mqtt_user" name="mqtt_user" value="<?= h($hg_mqtt['user']) ?>" autocomplete="off">
		<label for="mqtt_password"><?= t('UI.BROKER_PASSWORD') ?> <?= $hg_mqtt['password'] !== '' ? t('UI.PASSWORD_SAVED') : '' ?></label>
		<input type="password" id="mqtt_password" name="mqtt_password" value="" autocomplete="new-password">
	</div>

	<h3><?= t('UI.LOGGING') ?></h3>
	<select id="loglevel" name="loglevel">
		<?php foreach (['ERROR', 'WARNING', 'INFO', 'DEBUG'] as $hg_lvl): ?>
		<option value="<?= $hg_lvl ?>" <?= $hg_cfg['loglevel'] === $hg_lvl ? 'selected' : '' ?>><?= $hg_lvl ?></option>
		<?php endforeach; ?>
	</select>

	<button type="submit" data-icon="check"><?= t('UI.SAVE_SETTINGS') ?></button>
</form>

<script>
// Nur die Felder der gewählten Verbindungsart bzw. des eigenen Brokers zeigen
(function () {
	var radios = document.querySelectorAll('input.hg-source');
	var serialBox = document.getElementById('hg-serial');
	var tcpBox = document.getElementById('hg-tcp');
	var cb = document.getElementById('mqtt_use_loxberry');
	var manual = document.getElementById('hg-mqtt-manual');
	function update() {
		var tcp = document.querySelector('input.hg-source:checked').value === 'tcp';
		serialBox.style.display = tcp ? 'none' : '';
		tcpBox.style.display = tcp ? '' : 'none';
		manual.style.display = cb.checked ? 'none' : '';
	}
	Array.prototype.forEach.call(radios, function (r) { r.addEventListener('change', update); });
	cb.addEventListener('change', update);
	if (window.jQuery) { window.jQuery(radios).add(cb).on('change', update); }  // jQuery Mobile löst change über jQuery aus
	update();
})();
</script>

<?php else: ?>
<h2><?= t('UI.BACKUP_EXPORT') ?></h2>
<p><?= t('UI.BACKUP_EXPORT_HINT') ?></p>
<a href="index.php?action=export" data-role="button" data-inline="true" data-icon="arrow-d" data-ajax="false"><?= t('UI.BTN_EXPORT') ?></a>
<p class="hg-hint"><?= t('UI.BACKUP_CONTENT', count($hg_cfg['fields'])) ?></p>

<h2><?= t('UI.BACKUP_IMPORT') ?></h2>
<p><?= t('UI.BACKUP_IMPORT_HINT') ?></p>
<form method="post" action="index.php?page=backup" enctype="multipart/form-data" data-ajax="false">
	<input type="hidden" name="action" value="import">
	<input type="file" name="backup" accept=".json,application/json" data-role="none" required>
	<label><input type="checkbox" name="import_fields" checked> <?= t('UI.IMPORT_FIELDS') ?></label>
	<label><input type="checkbox" name="import_settings" checked> <?= t('UI.IMPORT_SETTINGS') ?></label>
	<p class="hg-hint"><?= t('UI.IMPORT_SETTINGS_HINT') ?></p>
	<button type="submit" data-icon="arrow-u"><?= t('UI.BTN_IMPORT') ?></button>
</form>
<?php endif; ?>

<?php
LBWeb::lbfooter();
