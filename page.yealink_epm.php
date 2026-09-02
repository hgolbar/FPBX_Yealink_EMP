<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

ini_set('display_errors', 0);
error_reporting(E_ALL);

// ============================================================================
// 1. MODULE VERSION & DIRECTORY INITIALIZATION
// ============================================================================

$module_xml_path = __DIR__ . '/module.xml';
$cfg_version = "1.0.0.0"; 
if (file_exists($module_xml_path)) {
    $xml_obj = @simplexml_load_file($module_xml_path);
    if ($xml_obj && !empty($xml_obj->version)) {
        $cfg_version = (string)$xml_obj->version;
    }
}

$generated_common_cfg = "";
$generated_template_cfg = "";
$status = "";
$tftp_dir = "/tftpboot/";
$logo_dir = "/var/www/html/PhoneSettings/logo/";
$ringtone_dir = "/var/www/html/PhoneSettings/ringtones/";

foreach ([$logo_dir, $ringtone_dir] as $dir) {
    if (!file_exists($dir)) {
        @mkdir($dir, 0775, true);
        @chown($dir, 'asterisk');
    }
}

if (!file_exists($tftp_dir)) {
    @mkdir($tftp_dir, 0775, true);
    @chown($tftp_dir, 'asterisk');
}

// ============================================================================
// 2. DETECT GLOBAL HTTPS REDIRECT & DETERMINE PROVISIONING PORT / GUI ADDR
// ============================================================================

$sysadmin_redirect = false;
if (function_exists('sysadmin_get_storage_settings')) {
    $settings = sysadmin_get_storage_settings();
    if (!empty($settings['https_redirect'])) {
        $sysadmin_redirect = true;
    }
}

// Extract host from HTTP headers or server environment
$raw_host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_ADDR'] ?? '';
if (strpos($raw_host, ':') !== false) {
    $raw_host = explode(':', $raw_host)[0];
}

// Determine true LAN GUI IP address (bypassing loopback 127.0.0.1 / 127.0.1.1)
$get_lan_ip = function() use ($raw_host) {
    if (!empty($raw_host) && filter_var($raw_host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && strpos($raw_host, '127.') !== 0) {
        return $raw_host;
    }
    if (!empty($_SERVER['SERVER_ADDR']) && filter_var($_SERVER['SERVER_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && strpos($_SERVER['SERVER_ADDR'], '127.') !== 0) {
        return $_SERVER['SERVER_ADDR'];
    }
    
    // Fallback: Query active interface IP via socket connection test
    $sock = @fsockopen('8.8.8.8', 53, $errno, $errstr, 1);
    if ($sock) {
        $sockname = @getsockname($sock, $local_ip, $local_port);
        @fclose($sock);
        if ($sockname && filter_var($local_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && strpos($local_ip, '127.') !== 0) {
            return $local_ip;
        }
    }
    
    return '192.168.1.1';
};

$detected_host = $get_lan_ip();

// Automatically route to Port 83 if Port 80 enforces HTTPS redirects
if ($sysadmin_redirect) {
    $default_provision_url = "http://{$detected_host}:83/PhoneSettings/";
    $default_server_target = "{$detected_host}:83";
} else {
    $default_provision_url = "http://{$detected_host}/PhoneSettings/";
    $default_server_target = $detected_host;
}

// ============================================================================
// 3. HELPER FUNCTIONS
// ============================================================================

function getArpTableMap() {
    $arp_map = [];
    $arp_output = [];
    if (file_exists('/proc/net/arp')) {
        $arp_lines = @file('/proc/net/arp', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($arp_lines) {
            array_shift($arp_lines);
            $arp_output = $arp_lines;
        }
    }
    if (empty($arp_output)) {
        exec("ip neighbor show 2>/dev/null || arp -an 2>/dev/null", $arp_output);
    }
    foreach ($arp_output as $line) {
        if (preg_match('/^([\d\.]+)\s+.*\s+([0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2})/i', $line, $m) ||
            preg_match('/\(([\d\.]+)\)\s+at\s+([0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2})/i', $line, $m)) {
            $ip = $m[1];
            $mac_clean = strtolower(str_replace([':', '-'], '', $m[2]));
            if (strlen($mac_clean) === 12) {
                $arp_map[$mac_clean] = $ip;
            }
        }
    }
    return $arp_map;
}

function sendSipNotify($ext_or_mac, $event_type = 'check-sync') {
    $ext = preg_replace('/[^0-9]/', '', $ext_or_mac);
    if (empty($ext)) return false;

    $notify_type = ($event_type === 'reboot') ? 'reboot-yealink' : 'check-sync';

    exec("asterisk -rx 'pjsip send notify {$notify_type} endpoint {$ext}' 2>&1");

    $contacts_output = [];
    exec("asterisk -rx 'pjsip show contacts' 2>&1", $contacts_output);
    if (is_array($contacts_output)) {
        foreach ($contacts_output as $line) {
            if (preg_match('/Contact:\s*(' . preg_quote($ext, '/') . '\/sip:[^\s]+)/i', $line, $cm)) {
                $contact_uri = trim($cm[1]);
                exec("asterisk -rx 'pjsip send notify {$notify_type} contact {$contact_uri}' 2>&1");
            }
        }
    }

    return true;
}

function generateAndSaveGlobalConfig($formData, $cfg_version, $default_server_target, $tftp_dir) {
    $raw_server = !empty($formData['server_ip']) ? $formData['server_ip'] : $default_server_target;
    
    if (strpos($raw_server, '://') === false) {
        $raw_server = 'http://' . $raw_server;
    }
    
    $parsed_host = parse_url($raw_server, PHP_URL_HOST);
    $parsed_port = parse_url($raw_server, PHP_URL_PORT);
    
    if (!empty($parsed_host)) {
        $server_ip_target = $parsed_host . (!empty($parsed_port) ? ':' . $parsed_port : '');
    } else {
        $server_ip_target = $default_server_target;
    }

    $ntp1_target = !empty($formData['ntp_server1']) ? $formData['ntp_server1'] : explode(':', $server_ip_target)[0];
    $ntp2_target = !empty($formData['ntp_server2']) ? $formData['ntp_server2'] : 'pool.ntp.org';

    $cfg = "#!version:{$cfg_version}\n\n";
    $cfg .= "##File header \"#!version:{$cfg_version}\" can not be edited or deleted.##\n\n";
    $cfg .= "security.user_password = admin:{$formData['admin_password']}\n\n";
    $cfg .= "sip.notify_reboot_enable = 0\n";
    $cfg .= "action_uri.enable = 1\n\n";
    $cfg .= "auto_provision.mode = {$formData['auto_provision_mode']}\n";
    $cfg .= "auto_provision.reboot_force.enable = 0\n";
    $cfg .= "auto_provision.weekly.enable = {$formData['auto_provision_weekly_enable']}\n";
    $cfg .= "auto_provision.weekly.begin_time = {$formData['auto_provision_weekly_begin_time']}\n";
    $cfg .= "auto_provision.weekly.end_time = {$formData['auto_provision_weekly_end_time']}\n";
    $cfg .= "auto_provision.weekly.dayofweek = {$formData['auto_provision_weekly_dayofweek']}\n";
    $cfg .= "auto_provision.server.url = http://{$server_ip_target}\n";
    $cfg .= "auto_provision.server.username = {$formData['auto_provision_username']}\n";
    $cfg .= "auto_provision.server.password = {$formData['auto_provision_password']}\n";
    $cfg .= "auto_provision.dhcp_option.enable = {$formData['auto_provision_dhcp_option_enable']}\n\n";
    $cfg .= "sip.use_out_bound_in_dialog = {$formData['sip_use_out_bound_in_dialog']}\n";
    $cfg .= "transfer.blind_tran_on_hook_enable = {$formData['transfer_blind_tran_on_hook_enable']}\n";
    $cfg .= "transfer.on_hook_trans_enable = {$formData['transfer_on_hook_trans_enable']}\n";
    $cfg .= "transfer.dsskey_deal_type = {$formData['transfer_dsskey_deal_type']}\n\n";
    $cfg .= "local_time.time_zone = {$formData['timezone']}\n";
    $cfg .= "local_time.time_format = {$formData['time_format']}\n";
    $cfg .= "local_time.ntp_server1 = {$ntp1_target}\n";
    $cfg .= "local_time.ntp_server2 = {$ntp2_target}\n";
    $cfg .= "phone_setting.inter_digit_time = {$formData['dialnow_timeout']}\n\n";

    $cfg .= "######## My DIALPLAN ########\n\n";
    $item_idx = 1;
    for ($d = 1; $d <= 50; $d++) {
        if (!empty($formData["dialnow_{$d}"])) {
            $cfg .= "dialnow.item.{$item_idx} = {$formData["dialnow_{$d}"]}\n";
            $item_idx++;
        }
    }
    $cfg .= "######## End My DIALPLAN ########\n\n";

    if (!empty($formData['custom_inputs_global'])) {
        $cfg .= "##### Global Custom Key-Value Additions #####\n";
        $cfg .= trim($formData['custom_inputs_global']) . "\n\n";
    }

    @file_put_contents($tftp_dir . "y000000000000.cfg", $cfg);
    @chown($tftp_dir . "y000000000000.cfg", 'asterisk');
    return $cfg;
}

// ============================================================================
// 4. READ GLOBAL CONFIGURATION (y000000000000.cfg) & DATABASE DATA
// ============================================================================

$saved_global_server_ip = $default_server_target;
$saved_global_admin_pass = "22222";
$saved_global_time_format = "0"; 
$saved_global_timezone = "-8";
$saved_global_ntp_server1 = $detected_host;
$saved_global_ntp_server2 = "pool.ntp.org";
$saved_global_dialnow_timeout = "4";
$file_dialnow_patterns = [];
$global_cfg_file = $tftp_dir . "y000000000000.cfg";

if (file_exists($global_cfg_file)) {
    $g_content = @file($global_cfg_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($g_content) {
        $temp_dialnow_file = [];
        foreach ($g_content as $g_line) {
            $g_line = trim($g_line);
            if (preg_match('/^dialnow\.item\.(\d+)\s*=\s*(.+)$/i', $g_line, $gm)) {
                $idx = (int)$gm[1];
                $val = trim($gm[2]);
                if ($val !== '') {
                    $temp_dialnow_file[$idx] = $val;
                }
            }
            if (preg_match('/^auto_provision\.server\.url\s*=\s*http:\/\/(.+)$/i', $g_line, $gm)) {
                $raw_parsed = trim($gm[1]);
                $saved_global_server_ip = $raw_parsed;
            }
            if (preg_match('/^security\.user_password\s*=\s*admin:(.+)$/i', $g_line, $gm)) {
                $saved_global_admin_pass = trim($gm[1]);
            }
            if (preg_match('/^local_time\.time_zone\s*=\s*([+\-]?\d+)/i', $g_line, $gm)) {
                $saved_global_timezone = trim($gm[1]);
            }
            if (preg_match('/^local_time\.time_format\s*=\s*([01])$/i', $g_line, $gm)) {
                $saved_global_time_format = trim($gm[1]);
            }
            if (preg_match('/^local_time\.ntp_server1\s*=\s*(.+)$/i', $g_line, $gm)) {
                $saved_global_ntp_server1 = trim($gm[1]);
            }
            if (preg_match('/^local_time\.ntp_server2\s*=\s*(.+)$/i', $g_line, $gm)) {
                $saved_global_ntp_server2 = trim($gm[1]);
            }
            if (preg_match('/^phone_setting\.inter_digit_time\s*=\s*(\d+)$/i', $g_line, $gm)) {
                $saved_global_dialnow_timeout = trim($gm[1]);
            }
        }
        if (!empty($temp_dialnow_file)) {
            ksort($temp_dialnow_file);
            $file_dialnow_patterns = array_values($temp_dialnow_file);
        }
    }
}

if (empty($saved_global_ntp_server1)) {
    $saved_global_ntp_server1 = $detected_host;
}

$all_extensions = [];
$online_exts = [];
$default_sip_port = "5060";
$default_voicemail_ext = "*97";
$outbound_patterns = [];

$dss_key_types = [
    "15" => "Line (15)",
    "16" => "BLF (16)",
    "13" => "Speed Dial (13)",
    "0"  => "Disabled (0)",
    "20" => "Direct Pickup (20)",
    "39" => "Park (39)"
];

$yealink_models = [
    "manual" => "-- Manual / Custom --",
    "T19P"   => "T19P / T19P E2 (1 Line Key)",
    "T21P"   => "T21P / T21P E2 (2 Line Keys)",
    "T23G"   => "T23G / T23P (3 Line Keys)",
    "T27G"   => "T27G / T27P (21 Line Keys)",
    "T28P"   => "T28P (6 Line Keys, 10 Mem Keys)",
    "T29G"   => "T29G (27 Line Keys)",
    "T30"    => "T30 / T30P (1 Line Key)",
    "T31G"   => "T31G / T31P / T31 (2 Line Keys)",
    "T33G"   => "T33G / T33P (4 Line Keys)",
    "T40P"   => "T40P / T40G (3 Line Keys)",
    "T41S"   => "T41S / T41P / T41U (15 Line Keys)",
    "T42S"   => "T42S / T42G / T42U (15 Line Keys)",
    "T43U"   => "T43U (21 Line Keys)",
    "T46S"   => "T46S / T46U / T46G (27 Line Keys)",
    "T48S"   => "T48S / T48U / T48G (29 Line Keys)",
    "T53W"   => "T53W / T53 (21 Line Keys)",
    "T54W"   => "T54W (27 Line Keys)",
    "T57W"   => "T57W (29 Line Keys)",
    "T58A"   => "T58A / T58V (27 Line Keys)",
    "VP59"   => "VP59 (27 Line Keys)"
];

$expansion_models = [
    "none"  => "-- None --",
    "EXP20" => "EXP20 (20 Keys per Module)",
    "EXP40" => "EXP40 (40 Keys per Module)",
    "EXP50" => "EXP50 (60 Keys per Module)"
];

if (isset($db) && $db instanceof PDO) {
    $pdo = $db;
} else {
    if (file_exists('/etc/freepbx.conf')) {
        include_once '/etc/freepbx.conf';
        $pdo = \FreePBX::Database();
    }
}

if (isset($pdo)) {
    try {
        $stmt = $pdo->query("SELECT val FROM kvstore_Sipsettings WHERE `key` = 'udpport-0.0.0.0' AND val != '' LIMIT 1");
        if ($res = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $raw_val = trim($res['val']);
            if (ctype_digit($raw_val)) {
                $default_sip_port = $raw_val;
            } elseif (preg_match('/:(\d+)$/', $raw_val, $pm)) {
                $default_sip_port = $pm[1];
            }
        }
    } catch (Exception $e) {}

    if (empty($file_dialnow_patterns)) {
        try {
            $stmt = $pdo->prepare("
                SELECT p.match_pattern_prefix, p.match_pattern_pass 
                FROM outbound_routes r 
                INNER JOIN outbound_route_patterns p ON r.route_id = p.route_id 
                WHERE LOWER(TRIM(r.name)) = 'outbound'
            ");
            $stmt->execute();
            $route_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($route_rows as $r_row) {
                $prefix = trim($r_row['match_pattern_prefix'] ?? '');
                $pattern = trim($r_row['match_pattern_pass'] ?? '');
                $full_pattern = $prefix . $pattern;
                if ($full_pattern !== '') {
                    $full_pattern = ltrim($full_pattern, '_');
                    $full_pattern = str_replace('.', 'x', $full_pattern);
                    $full_pattern = strtr($full_pattern, 'NnXxZz', 'xxxxxx');
                    if (!in_array($full_pattern, $outbound_patterns)) {
                        $outbound_patterns[] = $full_pattern;
                    }
                }
            }
        } catch (Exception $e) {}
    } else {
        $outbound_patterns = $file_dialnow_patterns;
    }

    try {
        $stmt = $pdo->query("SELECT u.extension AS id, u.name AS display_name, s.data AS secret 
                            FROM users u 
                            LEFT JOIN sip s ON u.extension = s.id AND s.keyword = 'secret' 
                            ORDER BY CAST(u.extension AS UNSIGNED) ASC");
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($results)) {
            $stmt = $pdo->query("SELECT d.id, d.description AS display_name, s.data AS secret 
                                FROM devices d 
                                LEFT JOIN sip s ON d.id = s.id AND s.keyword = 'secret' 
                                ORDER BY CAST(d.id AS UNSIGNED) ASC");
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($results as $row) {
            $ext_id = (string)$row['id'];
            $name = !empty($row['display_name']) ? $row['display_name'] : "Extension {$ext_id}";
            $all_extensions[$ext_id] = [
                'id' => $ext_id,
                'secret' => $row['secret'] ?? '',
                'display_name' => $name
            ];
        }
    } catch (Exception $e) {}

    exec("asterisk -rx 'pjsip show contacts' 2>&1", $pjsip_contacts);
    if (is_array($pjsip_contacts)) {
        foreach ($pjsip_contacts as $c_line) {
            if (preg_match('/Contact:\s*(\d+)\/sip:[^@]+@([\d\.\:]+).*(Avail|Reachable|OK)/i', $c_line, $cm)) {
                $online_exts[$cm[1]] = true;
            }
        }
    }

    exec("asterisk -rx 'sip show peers' 2>&1", $sip_out);
    if (is_array($sip_out)) {
        foreach ($sip_out as $s_line) {
            if (preg_match('/^(\d+)\/(\d+)\s+[\d\.]+\s+.*\s+OK\b/i', $s_line, $sm)) {
                $online_exts[$sm[1]] = true;
            }
        }
    }
}

ksort($all_extensions);

// ============================================================================
// 5. AJAX ENDPOINTS (SUBNET SCANNER & DEVICE PROVISIONING)
// ============================================================================

if (isset($_GET['action']) && $_GET['action'] === 'scan_network') {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: application/json');
    
    $subnet_input = trim($_GET['subnet'] ?? '');
    
    $existing_cfg_files = glob($tftp_dir . "*.cfg");
    $existing_macs = [];
    if (is_array($existing_cfg_files)) {
        foreach ($existing_cfg_files as $cfg_file) {
            $mac_name = strtolower(pathinfo($cfg_file, PATHINFO_FILENAME));
            if ($mac_name !== 'y000000000000') {
                $existing_macs[] = $mac_name;
            }
        }
    }

    if (preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3})/', $subnet_input, $m)) {
        $prefix = $m[1];
    } else {
        $prefix = implode('.', array_slice(explode('.', $detected_host), 0, 3));
    }

    exec("ping -c 2 -b {$prefix}.255 > /dev/null 2>&1 &");
    usleep(200000);

    $arp_output = [];
    if (file_exists('/proc/net/arp')) {
        $arp_lines = @file('/proc/net/arp', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($arp_lines) {
            array_shift($arp_lines);
            $arp_output = $arp_lines;
        }
    }
    
    if (empty($arp_output)) {
        exec("ip neighbor show 2>/dev/null || arp -an 2>/dev/null", $arp_output);
    }

    $yealink_ouis = [
        '001565', '0004f2', '805ec0', 'e434d7', 
        '805e0c', '249ab8', '706979', 'b44b36', 
        '108c70', '286b35', '001a4d', '805ec1'
    ];

    $discovered = [];

    foreach ($arp_output as $line) {
        if (preg_match('/^([\d\.]+)\s+.*\s+([0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2})/i', $line, $matches) ||
            preg_match('/\(([\d\.]+)\)\s+at\s+([0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2}[:\-][0-9a-fA-F]{2})/i', $line, $matches)) {
            
            $ip = $matches[1];
            $mac_clean = strtolower(str_replace([':', '-'], '', $matches[2]));

            if (strpos($ip, $prefix . '.') !== 0 || $mac_clean === '000000000000') {
                continue;
            }

            if (strlen($mac_clean) === 12) {
                $oui = substr($mac_clean, 0, 6);
                if (in_array($oui, $yealink_ouis) && !in_array($mac_clean, $existing_macs)) {
                    $discovered[] = [
                        'ip' => $ip,
                        'mac' => $mac_clean,
                        'vendor' => 'Yealink'
                    ];
                }
            }
        }
    }

    echo json_encode(['status' => 'success', 'subnet' => "{$prefix}.0/24", 'devices' => $discovered]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'add_scanned_device') {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: application/json');

    $scanned_mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $_POST['scanned_mac'] ?? ''));
    $scanned_ext = trim($_POST['scanned_ext'] ?? '');
    $scanned_tpl = trim($_POST['scanned_template'] ?? '');
    $should_notify = isset($_POST['auto_provision']) && $_POST['auto_provision'] === '1';

    if (!empty($scanned_mac)) {
        $ext_name = $all_extensions[$scanned_ext]['display_name'] ?? "Extension {$scanned_ext}";
        $ext_secret = $all_extensions[$scanned_ext]['secret'] ?? '';
        $tpl_to_write = !empty($scanned_tpl) ? $scanned_tpl : 'none';

        $cfg_body = "#!version:{$cfg_version}\n\n";
        $cfg_body .= "# Phone Model: Yealink\n";
        $cfg_body .= "# Template: {$tpl_to_write}\n\n";
        if (!empty($scanned_ext)) {
            $cfg_body .= "account.1.enable = 1\n";
            $cfg_body .= "account.1.label = {$ext_name}\n";
            $cfg_body .= "account.1.display_name = {$ext_name}\n";
            $cfg_body .= "account.1.auth_name = {$scanned_ext}\n";
            $cfg_body .= "account.1.user_name = {$scanned_ext}\n";
            $cfg_body .= "account.1.password = {$ext_secret}\n";
            $cfg_body .= "account.1.sip_server = {$saved_global_server_ip}\n";
            $cfg_body .= "account.1.sip_server_host = {$saved_global_server_ip}\n";
            $cfg_body .= "account.1.sip_server_port = {$default_sip_port}\n";
            $cfg_body .= "account.1.port = {$default_sip_port}\n";
            $cfg_body .= "linekey.1.type = 15\n";
            $cfg_body .= "linekey.1.line = 1\n";
            $cfg_body .= "linekey.1.value = {$scanned_ext}\n";
            $cfg_body .= "linekey.1.label = {$ext_name}\n";
        }

        if (!empty($scanned_tpl) && file_exists($tftp_dir . $scanned_tpl)) {
            $tpl_content = file_get_contents($tftp_dir . $scanned_tpl);
            $tpl_content = preg_replace('/^account\.1\.sip_server.*$/m', '', $tpl_content);
            $tpl_content = preg_replace('/^#!version:.*$/m', '', $tpl_content);
            $cfg_body .= "\n##### INHERITED TEMPLATE SETTINGS ({$scanned_tpl}) #####\n";
            $cfg_body .= $tpl_content;
        }

        @file_put_contents($tftp_dir . "{$scanned_mac}.cfg", $cfg_body);
        @chown($tftp_dir . "{$scanned_mac}.cfg", 'asterisk');
        
        if ($should_notify && !empty($scanned_ext)) {
            sendSipNotify($scanned_ext, 'check-sync');
        }

        echo json_encode(['status' => 'success', 'mac' => $scanned_mac]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid MAC address']);
    exit;
}

// ============================================================================
// 6. POST ACTIONS (DELETE HANDLER)
// ============================================================================

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_target_file']) && !empty($_POST['target_filename'])) {
    $target_file = basename($_POST['target_filename']);
    $file_type = $_POST['target_file_type'] ?? '';

    if ($file_type === 'global') {
        $full_path = $tftp_dir . "y000000000000.cfg";
    } elseif ($file_type === 'cfg' || $file_type === 'template') {
        $full_path = $tftp_dir . $target_file;
    } elseif ($file_type === 'logo') {
        $full_path = $logo_dir . $target_file;
    } elseif ($file_type === 'ringtone') {
        $full_path = $ringtone_dir . $target_file;
    } else {
        $full_path = "";
    }

    if (!empty($full_path) && file_exists($full_path) && is_file($full_path)) {
        if (@unlink($full_path)) {
            $status = "Successfully deleted file: " . htmlspecialchars($target_file);
        } else {
            $status = "Error: Unable to delete file " . htmlspecialchars($target_file) . ". Check permissions.";
        }
    } else {
        $status = "Error: File not found.";
    }
}

// ============================================================================
// 7. FORM DATA INITIALIZATION & SAVE TEMPLATE HANDLER
// ============================================================================

$max_linekeys = isset($_POST['linekey_count']) ? (int)$_POST['linekey_count'] : 1;
$max_memkeys = isset($_POST['memkey_count']) ? (int)$_POST['memkey_count'] : 0;

$formData = [
    'template_name' => 'default_template',
    'phone_model' => 'manual',
    'exp_model' => 'none',
    'exp_count' => '0',
    'server_ip' => $saved_global_server_ip,
    'sip_port' => $default_sip_port,
    'sip_listen_port' => '5062',
    'voicemail_number' => $default_voicemail_ext,
    'timezone' => $saved_global_timezone,
    'time_format' => $saved_global_time_format,
    'ntp_server1' => $saved_global_ntp_server1,
    'ntp_server2' => $saved_global_ntp_server2,
    'admin_password' => $saved_global_admin_pass,
    'ringtone_file' => '',
    'logo_file' => '',
    'dialnow_timeout' => $saved_global_dialnow_timeout,
    'dialnow_count' => count($outbound_patterns) ?: 1,
    'linekey_count' => $max_linekeys,
    'memkey_count' => $max_memkeys,
    'custom_inputs_global' => '',
    'custom_inputs' => '',
    'sip_use_out_bound_in_dialog' => '1',
    'transfer_blind_tran_on_hook_enable' => '1',
    'transfer_on_hook_trans_enable' => '1',
    'transfer_dsskey_deal_type' => '2',
    'auto_provision_mode' => '7',
    'auto_provision_weekly_enable' => '1',
    'auto_provision_weekly_begin_time' => '23:00',
    'auto_provision_weekly_end_time' => '23:59',
    'auto_provision_weekly_dayofweek' => '0',
    'auto_provision_dhcp_option_enable' => '1',
    'auto_provision_username' => '',
    'auto_provision_password' => '',
    'active_tab' => $_POST['active_tab'] ?? 'tab_global'
];

$formData["linekey_1_type"] = "15";
$formData["linekey_1_value"] = "";
$formData["linekey_1_label"] = "";
$formData["linekey_1_pickup"] = "";

for ($i = 2; $i <= 29; $i++) {
    $formData["linekey_{$i}_type"] = "16"; 
    $formData["linekey_{$i}_value"] = "";
    $formData["linekey_{$i}_label"] = "";
    $formData["linekey_{$i}_pickup"] = "";
}

for ($i = 1; $i <= 180; $i++) {
    $formData["memkey_{$i}_value"] = "";
    $formData["memkey_{$i}_pickup"] = "";
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_template'])) {
    $formData['active_tab'] = 'tab_template';

    foreach ($formData as $k => $v) {
        if (isset($_POST[$k])) {
            $formData[$k] = trim($_POST[$k]);
        }
    }

    if (!file_exists($tftp_dir . "y000000000000.cfg")) {
        generateAndSaveGlobalConfig($formData, $cfg_version, $default_server_target, $tftp_dir);
    }

    if (isset($_FILES['ringtone_upload']) && $_FILES['ringtone_upload']['error'] === UPLOAD_ERR_OK) {
        $uploaded_name = basename($_FILES['ringtone_upload']['name']);
        if (move_uploaded_file($_FILES['ringtone_upload']['tmp_name'], $ringtone_dir . $uploaded_name)) {
            $formData['ringtone_file'] = $uploaded_name;
            @chown($ringtone_dir . $uploaded_name, 'asterisk');
        }
    }

    if (isset($_FILES['logo_upload']) && $_FILES['logo_upload']['error'] === UPLOAD_ERR_OK) {
        $uploaded_name = basename($_FILES['logo_upload']['name']);
        if (move_uploaded_file($_FILES['logo_upload']['tmp_name'], $logo_dir . $uploaded_name)) {
            $formData['logo_file'] = $uploaded_name;
            @chown($logo_dir . $uploaded_name, 'asterisk');
        }
    }

    $formData["linekey_1_type"] = "15";
    $formData["linekey_1_value"] = trim($_POST["linekey_1_value"] ?? '');
    $formData["linekey_1_label"] = trim($_POST["linekey_1_label"] ?? '');
    $formData["linekey_1_pickup"] = trim($_POST["linekey_1_pickup"] ?? '');

    for ($i = 2; $i <= 29; $i++) {
        if (isset($_POST["linekey_{$i}_type"])) $formData["linekey_{$i}_type"] = trim($_POST["linekey_{$i}_type"]);
        if (isset($_POST["linekey_{$i}_value"])) $formData["linekey_{$i}_value"] = trim($_POST["linekey_{$i}_value"]);
        if (isset($_POST["linekey_{$i}_label"])) $formData["linekey_{$i}_label"] = trim($_POST["linekey_{$i}_label"]);
        if (isset($_POST["linekey_{$i}_pickup"])) $formData["linekey_{$i}_pickup"] = trim($_POST["linekey_{$i}_pickup"]);
    }

    for ($i = 1; $i <= 180; $i++) {
        if (isset($_POST["memkey_{$i}_value"])) $formData["memkey_{$i}_value"] = trim($_POST["memkey_{$i}_value"]);
        if (isset($_POST["memkey_{$i}_pickup"])) $formData["memkey_{$i}_pickup"] = trim($_POST["memkey_{$i}_pickup"]);
    }

    $formData['server_ip'] = $saved_global_server_ip;
    $formData['admin_password'] = $saved_global_admin_pass;

    $server_ip_target = $saved_global_server_ip;
    $tpl_name = preg_replace('/[^a-zA-Z0-9_\-]/', '', $formData['template_name']);
    if (empty($tpl_name)) $tpl_name = "default_template";
    $tpl_filename = $tpl_name . ".template.cfg";

    // Enforce Port 83 and keep /PhoneSettings/ prefix for static asset delivery
    $host_only = explode(':', $server_ip_target)[0];
    $asset_host = "http://{$host_only}:83/PhoneSettings";

    $ringtone_url = !empty($formData['ringtone_file']) ? "{$asset_host}/ringtones/" . $formData['ringtone_file'] : "";
    $logo_path_prefix = "{$asset_host}/logo/";
    
    $logo_url = "";
    $lcd_logo_mode = "0";
    $use_lcd_logo_url = false;
    $is_logo_disabled = false;

    if ($formData['logo_file'] === 'system') {
        $lcd_logo_mode = "1";
        $logo_url = "Config:default";
    } elseif (!empty($formData['logo_file'])) {
        $lcd_logo_mode = "2";
        $logo_url = $logo_path_prefix . $formData['logo_file'];
        
        $ext = strtolower(pathinfo($formData['logo_file'], PATHINFO_EXTENSION));
        if ($formData['phone_model'] === 'T28P' || $ext === 'dob') {
            $use_lcd_logo_url = true;
        }
    } else {
        $lcd_logo_mode = "0";
        $is_logo_disabled = true;
    }

    $generated_template_cfg = "## Yealink Template Configuration File ##\n";
    $generated_template_cfg .= "# Phone Model: {$formData['phone_model']}\n";
    $generated_template_cfg .= "# Expansion Model: {$formData['exp_model']}\n";
    $generated_template_cfg .= "# Expansion Count: {$formData['exp_count']}\n\n";

    $generated_template_cfg .= "account.1.sip_server = {$server_ip_target}\n";
    $generated_template_cfg .= "account.1.sip_server_host = {$server_ip_target}\n";
    $generated_template_cfg .= "account.1.sip_server_port = {$formData['sip_port']}\n";
    $generated_template_cfg .= "account.1.port = {$formData['sip_port']}\n";
    $generated_template_cfg .= "account.1.sip_listen_port = {$formData['sip_listen_port']}\n";
    $generated_template_cfg .= "voice_mail.number.1 = {$formData['voicemail_number']}\n\n";

    if (!empty($formData['ringtone_file'])) {
        $generated_template_cfg .= "account.1.ringtone.ring_type = {$formData['ringtone_file']}\n";
    }

    $has_memkeys = false;
    for ($i = 1; $i <= $max_memkeys; $i++) {
        $val = $formData["memkey_{$i}_value"] ?? '';
        if (!empty($val)) {
            if (!$has_memkeys) {
                $generated_template_cfg .= "################################################\n";
                $generated_template_cfg .= "##         Memory Keys                          ##\n";
                $generated_template_cfg .= "################################################\n\n";
                $has_memkeys = true;
            }
            $pickup = isset($formData["memkey_{$i}_pickup"]) ? $formData["memkey_{$i}_pickup"] : '**';
            if ($pickup === '') { $pickup = '**'; }
            
            $generated_template_cfg .= "memorykey.{$i}.value = {$val}\n";
            if ($pickup !== 'none') {
                $generated_template_cfg .= "memorykey.{$i}.pickup_value = {$pickup}\n";
            }
            $generated_template_cfg .= "memorykey.{$i}.type = 16\n\n";
        }
    }

    $has_linekeys = false;
    for ($i = 1; $i <= $max_linekeys; $i++) {
        $type = $formData["linekey_{$i}_type"] ?? '16';
        $val = $formData["linekey_{$i}_value"] ?? '';
        $lbl = $formData["linekey_{$i}_label"] ?? '';
        $pickup = isset($formData["linekey_{$i}_pickup"]) ? $formData["linekey_{$i}_pickup"] : '**';
        if ($pickup === '') { $pickup = '**'; }

        if (!empty($val) || !empty($lbl) || ($pickup !== 'none' && !empty($pickup))) {
            if (!$has_linekeys) {
                $generated_template_cfg .= "################################################\n";
                $generated_template_cfg .= "##         Line Keys                            ##\n";
                $generated_template_cfg .= "################################################\n\n";
                $has_linekeys = true;
            }
            if (!empty($val)) $generated_template_cfg .= "linekey.{$i}.value = {$val}\n";
            if ($pickup !== 'none') {
                $generated_template_cfg .= "linekey.{$i}.pickup_value = {$pickup}\n";
            }
            $generated_template_cfg .= "linekey.{$i}.type = {$type}\n";
            if (!empty($lbl)) $generated_template_cfg .= "linekey.{$i}.label = {$lbl}\n\n";
        }
    }

    // Universal Dual-Ringtone Directives
    if (!empty($ringtone_url)) {
        $generated_template_cfg .= "ringtone.url = {$ringtone_url}\n";
        $generated_template_cfg .= "phone_setting.ringtone = {$formData['ringtone_file']}\n";
    }
    
    $generated_template_cfg .= "phone_setting.lcd_logo.mode = {$lcd_logo_mode}\n";
    if ($is_logo_disabled) {
        $generated_template_cfg .= "lcd_logo.url = \n";
        $generated_template_cfg .= "phone_setting.background_image = \n\n";
    } elseif ($use_lcd_logo_url) {
        $generated_template_cfg .= "lcd_logo.url = {$logo_url}\n\n";
    } else {
        $generated_template_cfg .= "phone_setting.background_image = {$logo_url}\n\n";
    }

    if (!empty($formData['custom_inputs'])) {
        $generated_template_cfg .= "##### Template Custom Additions #####\n";
        $generated_template_cfg .= trim($formData['custom_inputs']) . "\n\n";
    }

    @file_put_contents($tftp_dir . $tpl_filename, $generated_template_cfg);
    @chown($tftp_dir . $tpl_filename, 'asterisk');
    $status = "Saved Template: {$tpl_filename} to {$tftp_dir}";

    $_POST['template_to_load'] = $tpl_filename;
}

// ============================================================================
// 8. DEVICE MANAGER ACTIONS & TEMPLATE FILE LOADERS
// ============================================================================

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['device_action'])) {
    $action = $_POST['device_action'];
    $selected_macs = $_POST['selected_phones'] ?? [];
    $assigned_tpls = $_POST['phone_template'] ?? [];
    $assigned_exts = $_POST['phone_extension'] ?? [];
    $edited_macs = $_POST['edited_mac'] ?? [];
    $bulk_override_tpl = trim($_POST['bulk_selected_template'] ?? '');

    // Handle MAC edits before performing actions
    foreach ($edited_macs as $orig_mac => $new_mac) {
        $clean_orig = strtolower(trim($orig_mac));
        $clean_new = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $new_mac));
        if (!empty($clean_new) && strlen($clean_new) === 12 && $clean_orig !== $clean_new) {
            $orig_path = $tftp_dir . $clean_orig . ".cfg";
            $new_path = $tftp_dir . $clean_new . ".cfg";
            if (file_exists($orig_path) && !file_exists($new_path)) {
                @rename($orig_path, $new_path);
                @chown($new_path, 'asterisk');
            }
        }
    }

    if ($action === 'delete_selected') {
        $deleted_count = 0;
        foreach ($selected_macs as $smac) {
            $clean_mac = strtolower(trim($smac));
            $f_path = $tftp_dir . $clean_mac . ".cfg";
            if (file_exists($f_path)) {
                @unlink($f_path);
                $deleted_count++;
            }
        }
        $status = "Deleted {$deleted_count} selected configuration file(s).";
    } elseif ($action === 'rebuild_selected' || $action === 'rebuild_all' || $action === 'rebuild_filtered' || $action === 'single_rebuild') {
        $all_cfg_files = glob($tftp_dir . "*.cfg");
        $all_macs = [];
        if (is_array($all_cfg_files)) {
            foreach ($all_cfg_files as $cf) {
                $mname = strtolower(pathinfo($cf, PATHINFO_FILENAME));
                if ($mname !== 'y000000000000' && strpos(strtolower($cf), 'template') === false) {
                    $all_macs[] = $mname;
                }
            }
        }

        $filter_model = ($action === 'rebuild_filtered') ? trim($_POST['global_filter_model'] ?? '') : '';
        $filter_template = ($action === 'rebuild_filtered') ? trim($_POST['global_filter_template'] ?? '') : '';

        if ($action === 'single_rebuild') {
            $target_mac = strtolower(trim($_POST['single_mac'] ?? ''));
            $targets = !empty($target_mac) ? [$target_mac] : [];
            $override_model = trim($_POST['single_model'] ?? '');
            $do_autoprovision = isset($_POST['single_provision']);
            $do_reboot = isset($_POST['single_reboot_check']);
        } elseif ($action === 'rebuild_filtered') {
            $targets = $all_macs;
            $do_autoprovision = isset($_POST['auto_provision_filtered']);
            $do_reboot = isset($_POST['reboot_filtered']);
            $override_model = '';
        } else {
            $targets = ($action === 'rebuild_selected') ? array_map('strtolower', $selected_macs) : $all_macs;
            $do_autoprovision = ($action === 'rebuild_selected') ? isset($_POST['auto_provision_selected']) : isset($_POST['auto_provision_all']);
            $do_reboot = ($action === 'rebuild_selected') ? isset($_POST['reboot_selected']) : isset($_POST['reboot_phones']);
            $override_model = '';
        }

        $notify_event = 'none';
        if ($do_reboot) {
            $notify_event = 'reboot';
        } elseif ($do_autoprovision) {
            $notify_event = 'check-sync';
        }

        $rebuilt = 0;

        foreach ($targets as $smac) {
            $clean_mac = strtolower(trim($smac));
            $f_path = $tftp_dir . $clean_mac . ".cfg";
            
            if ($action === 'rebuild_selected' && !empty($bulk_override_tpl)) {
                $new_tpl = $bulk_override_tpl;
            } else {
                $new_tpl = $assigned_tpls[$clean_mac] ?? ($assigned_tpls[strtoupper($clean_mac)] ?? '');
            }

            $new_ext = $assigned_exts[$clean_mac] ?? ($assigned_exts[strtoupper($clean_mac)] ?? '');

            if (file_exists($f_path)) {
                $file_content = file_get_contents($f_path);
                
                if (($pos = strpos($file_content, '##### INHERITED TEMPLATE SETTINGS')) !== false) {
                    $base_content = substr($file_content, 0, $pos);
                } else {
                    $base_content = $file_content;
                }

                $file_lines = explode("\n", $file_content);
                $curr_model = '';
                $curr_tpl = '';

                foreach ($file_lines as $f_line) {
                    if (preg_match('/^#\s*Phone\s*Model\s*:\s*(.+)$/i', $f_line, $m)) {
                        $found_m = trim($m[1]);
                        if (empty($curr_model) || strcasecmp($curr_model, 'Yealink') === 0) {
                            $curr_model = $found_m;
                        }
                    }
                    if (preg_match('/^#\s*Template\s*:\s*(.+)$/i', $f_line, $m)) {
                        $curr_tpl = trim($m[1]);
                    }
                }

                if ($action === 'rebuild_filtered') {
                    if (!empty($filter_model) && stripos($curr_model, $filter_model) === false) {
                        continue;
                    }
                    if (!empty($filter_template) && strcasecmp($curr_tpl, $filter_template) !== 0) {
                        continue;
                    }
                }

                $updated_lines = [];
                $has_tpl_comment = false;

                $base_lines = explode("\n", $base_content);

                $new_ext_name = $all_extensions[$new_ext]['display_name'] ?? "Extension {$new_ext}";
                $new_ext_secret = $all_extensions[$new_ext]['secret'] ?? '';
                $tpl_to_write = !empty($new_tpl) ? $new_tpl : 'none';

                foreach ($base_lines as $f_line) {
                    if (preg_match('/^#!version:/i', $f_line)) {
                        $updated_lines[] = "#!version:{$cfg_version}";
                    } elseif (preg_match('/^account\.1\./i', $f_line)) {
                        continue;
                    } elseif (preg_match('/^linekey\.1\./i', $f_line)) {
                        continue;
                    } elseif (preg_match('/^#\s*Phone\s*Model\s*:\s*(.+)$/i', $f_line, $m)) {
                        $model_val = !empty($override_model) ? $override_model : trim($m[1]);
                        $updated_lines[] = "# Phone Model: {$model_val}";
                    } elseif (preg_match('/^#\s*Template\s*:/i', $f_line)) {
                        $updated_lines[] = "# Template: {$tpl_to_write}";
                        $has_tpl_comment = true;
                    } else {
                        $updated_lines[] = $f_line;
                    }
                }

                if (!$has_tpl_comment) {
                    array_splice($updated_lines, 2, 0, "# Template: {$tpl_to_write}");
                }

                if (!empty($new_ext)) {
                    $account_block = [
                        "account.1.enable = 1",
                        "account.1.label = {$new_ext_name}",
                        "account.1.display_name = {$new_ext_name}",
                        "account.1.auth_name = {$new_ext}",
                        "account.1.user_name = {$new_ext}",
                        "account.1.password = {$new_ext_secret}",
                        "account.1.sip_server = {$saved_global_server_ip}",
                        "account.1.sip_server_host = {$saved_global_server_ip}",
                        "account.1.sip_server_port = {$default_sip_port}",
                        "account.1.port = {$default_sip_port}",
                        "linekey.1.type = 15",
                        "linekey.1.line = 1",
                        "linekey.1.value = {$new_ext}",
                        "linekey.1.label = {$new_ext_name}"
                    ];
                    array_splice($updated_lines, 3, 0, $account_block);
                }

                $final_cfg = implode("\n", $updated_lines);

                if (!empty($new_tpl) && file_exists($tftp_dir . $new_tpl)) {
                    $tpl_content = file_get_contents($tftp_dir . $new_tpl);
                    $tpl_content = preg_replace('/^account\.1\.sip_server.*$/m', '', $tpl_content);
                    $tpl_content = preg_replace('/^#!version:.*$/m', '', $tpl_content);
                    $final_cfg = rtrim($final_cfg) . "\n\n##### INHERITED TEMPLATE SETTINGS ({$new_tpl}) #####\n" . $tpl_content;
                }

                @file_put_contents($f_path, $final_cfg);
                @chown($f_path, 'asterisk');

                if ($notify_event !== 'none' && !empty($new_ext)) {
                    sendSipNotify($new_ext, $notify_event);
                }
                $rebuilt++;
            }
        }
        $status = "Rebuilt and updated configurations for {$rebuilt} device(s).";
    } elseif ($action === 'single_reboot') {
        $single_ext = $_POST['single_ext'] ?? '';
        if (!empty($single_ext)) {
            sendSipNotify($single_ext, 'reboot');
            $status = "Sent reboot NOTIFY to Extension {$single_ext}.";
        }
    }
}

$existing_files = glob($tftp_dir . "*.cfg");
$available_templates = [];
$managed_devices = [];
$assigned_extensions_map = [];
$arp_table = getArpTableMap();

if (is_array($existing_files)) {
    foreach ($existing_files as $file_path) {
        $b_name = basename($file_path);
        $file_name_no_ext = strtolower(pathinfo($b_name, PATHINFO_FILENAME));
        
        if ($file_name_no_ext === 'y000000000000') continue;

        if (strpos(strtolower($b_name), 'template') !== false) {
            $available_templates[$b_name] = $b_name;
            continue;
        }

        $ext_num = "";
        $ext_label = "";
        $phone_model_read = "Yealink";
        $template_used = "";

        $lines = @file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines) {
            foreach ($lines as $l) {
                $l = trim($l);
                if (preg_match('/^#\s*Phone\s*Model\s*:\s*(.+)$/i', $l, $m)) {
                    $found_m = trim($m[1]);
                    if ($phone_model_read === 'Yealink' || empty($phone_model_read)) {
                        $phone_model_read = $found_m;
                    }
                }
                if (preg_match('/^#\s*Template\s*:\s*(.+)$/i', $l, $m)) {
                    $tpl = trim($m[1]);
                    $template_used = ($tpl === 'none' || $tpl === 'default') ? '' : $tpl;
                }
                if (preg_match('/^account\.1\.(auth_name|user_name)\s*=\s*(.+)$/i', $l, $m)) {
                    $ext_num = trim($m[2]);
                }
                if (preg_match('/^account\.1\.(display_name|label)\s*=\s*(.+)$/i', $l, $m)) {
                    if (empty($ext_label)) $ext_label = trim($m[2]);
                }
            }
        }

        if (!empty($ext_num)) {
            $assigned_extensions_map[$ext_num] = true;
        }

        $ip_addr = $arp_table[$file_name_no_ext] ?? 'Unknown / Offline';

        $managed_devices[] = [
            'mac' => $file_name_no_ext,
            'ip' => $ip_addr,
            'file' => $b_name,
            'model' => $phone_model_read,
            'template' => $template_used,
            'ext' => $ext_num,
            'label' => $ext_label
        ];
    }
}

// Build array of unassigned extensions available for scanner/manual entry
$available_extensions = [];
foreach ($all_extensions as $e_id => $e_data) {
    if (!isset($assigned_extensions_map[(string)$e_id])) {
        $available_extensions[$e_id] = $e_data;
    }
}

$active_dialnow_items = array_values(array_filter($outbound_patterns));

$timezones = [
    "-10" => "United States - Hawaii (UTC-10)",
    "-9"  => "United States - Alaska (UTC-9)",
    "-8"  => "United States - Pacific Time (UTC-8)",
    "-7"  => "United States - Mountain Time (UTC-7)",
    "-6"  => "United States - Central Time (UTC-6)",
    "-5"  => "United States - Eastern Time (UTC-5)",
    "-4"  => "United States - Atlantic Time (UTC-4)",
    "-11" => "Samoa / Midway (UTC-11)",
    "-3"  => "Brazil - Brasilia / Argentina (UTC-3)",
    "-2"  => "Mid-Atlantic (UTC-2)",
    "-1"  => "Azores / Cape Verde (UTC-1)",
    "0"   => "United Kingdom - London / Dublin (UTC+0)",
    "+1"  => "Europe - Paris / Berlin / Rome (UTC+1)",
    "+2"  => "Europe - Athens / Cairo / Jerusalem (UTC+2)",
    "+3"  => "Russia - Moscow / Saudi Arabia (UTC+3)",
    "+3.5"=> "Iran - Tehran (UTC+3:30)",
    "+4"  => "UAE - Dubai (UTC+4)",
    "+4.5"=> "Afghanistan - Kabul (UTC+4:30)",
    "+5"  => "Pakistan - Islamabad (UTC+5)",
    "+5.5"=> "India - New Delhi (UTC+5:30)",
    "+6"  => "Bangladesh - Dhaka (UTC+6)",
    "+7"  => "Thailand - Bangkok / Vietnam (UTC+7)",
    "+8"  => "China - Beijing / Singapore / Perth (UTC+8)",
    "+9"  => "Japan - Tokyo / Korea - Seoul (UTC+9)",
    "+9.5"=> "Australia - Adelaide / Darwin (UTC+9:30)",
    "+10" => "Australia - Sydney / Guam (UTC+10)",
    "+11" => "Solomon Islands (UTC+11)",
    "+12" => "New Zealand - Auckland (UTC+12)"
];

for ($d = 1; $d <= 50; $d++) {
    $formData["dialnow_{$d}"] = $active_dialnow_items[$d - 1] ?? '';
}

if (isset($_POST['load_template']) && !empty($_POST['template_to_load'])) {
    $tpl_path = $tftp_dir . basename($_POST['template_to_load']);
    if (file_exists($tpl_path)) {
        $formData['active_tab'] = 'tab_template';
        $formData['template_name'] = str_replace('.template.cfg', '', basename($tpl_path));
        $tpl_lines = file($tpl_path, FILE_IGNORE_NEW_LINES);
        $unparsed_tpl = [];
        $is_custom_section = false;

        $highest_tpl_linekey = 0;
        $highest_tpl_memkey = 0;

        foreach ($tpl_lines as $t_line) {
            $raw_line = $t_line;
            $t_line = trim($t_line);

            if (strpos($t_line, '##### Template Custom Additions #####') !== false) {
                $is_custom_section = true;
                continue;
            }

            if ($is_custom_section) {
                if ($t_line !== '') {
                    $unparsed_tpl[] = $t_line;
                }
                continue;
            }

            if (empty($t_line)) continue;

            if (preg_match('/^#\s*Phone\s*Model\s*:\s*(.+)$/i', $t_line, $m)) { $formData['phone_model'] = trim($m[1]); continue; }
            if (preg_match('/^#\s*Expansion\s*Model\s*:\s*(.+)$/i', $t_line, $m)) { $formData['exp_model'] = trim($m[1]); continue; }
            if (preg_match('/^#\s*Expansion\s*Count\s*:\s*(.+)$/i', $t_line, $m)) { $formData['exp_count'] = trim($m[1]); continue; }

            if (strpos($t_line, '=') === false || strpos($t_line, '#') === 0) continue;
            list($k, $v) = array_map('trim', explode('=', $t_line, 2));

            if ($k === 'auto_provision.server.url' || $k === 'security.user_password' || strpos($k, 'account.1.sip_server') === 0) {
                continue;
            }

            $is_parsed_tpl = false;

            if ($k === 'account.1.sip_server_port' || $k === 'account.1.port') { $formData['sip_port'] = $v; $is_parsed_tpl = true; }
            if ($k === 'account.1.sip_listen_port') { $formData['sip_listen_port'] = $v; $is_parsed_tpl = true; }
            if ($k === 'voice_mail.number.1') { $formData['voicemail_number'] = $v; $is_parsed_tpl = true; }
            if ($k === 'account.1.ringtone.ring_type') { $formData['ringtone_file'] = $v; $is_parsed_tpl = true; }
            if ($k === 'ringtone.url') { $formData['ringtone_file'] = basename($v); $is_parsed_tpl = true; }
            if ($k === 'phone_setting.ringtone') { $formData['ringtone_file'] = basename($v); $is_parsed_tpl = true; }
            if ($k === 'phone_setting.lcd_logo.mode') { $is_parsed_tpl = true; }
            if ($k === 'lcd_logo.url' || $k === 'phone_setting.background_image') { 
                if (empty($v) || $v === 'Config:default') {
                    $formData['logo_file'] = '';
                } else {
                    $formData['logo_file'] = basename($v);
                }
                $is_parsed_tpl = true; 
            }

            if (preg_match('/^linekey\.(\d+)\.(value|label|type|pickup_value)$/', $k, $m)) {
                $f_name = ($m[2] === 'pickup_value') ? 'pickup' : $m[2];
                $formData["linekey_{$m[1]}_{$f_name}"] = $v;
                if ((int)$m[1] > $highest_tpl_linekey) $highest_tpl_linekey = (int)$m[1];
                $is_parsed_tpl = true;
            }

            if (preg_match('/^memorykey\.(\d+)\.(value|label|type|pickup_value)$/', $k, $m)) {
                $f_name = ($m[2] === 'pickup_value') ? 'pickup' : $m[2];
                $formData["memkey_{$m[1]}_{$f_name}"] = $v;
                if ((int)$m[1] > $highest_tpl_memkey) $highest_tpl_memkey = (int)$m[1];
                $is_parsed_tpl = true;
            }

            if (!$is_parsed_tpl) {
                $unparsed_tpl[] = "{$k} = {$v}";
            }
        }

        if ($highest_tpl_linekey > 0) $max_linekeys = $formData['linekey_count'] = $highest_tpl_linekey;
        if ($highest_tpl_memkey > 0) $max_memkeys = $formData['memkey_count'] = $highest_tpl_memkey;

        $formData['custom_inputs'] = implode("\n", $unparsed_tpl);
        
        $formData['server_ip'] = $saved_global_server_ip;
        $formData['admin_password'] = $saved_global_admin_pass;

        $status = "Loaded Template: " . basename($tpl_path);
    }
}

// ============================================================================
// 9. SAVE GLOBAL CONFIGURATION HANDLER
// ============================================================================

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['save_global'])) {
    $formData['active_tab'] = 'tab_global';
    
    for ($d = 1; $d <= 50; $d++) {
        if (isset($_POST["dialnow_{$d}"])) $formData["dialnow_{$d}"] = trim($_POST["dialnow_{$d}"]);
    }
    
    foreach ($formData as $k => $v) {
        if (isset($_POST[$k])) $formData[$k] = trim($_POST[$k]);
    }

    $generated_common_cfg = generateAndSaveGlobalConfig($formData, $cfg_version, $default_server_target, $tftp_dir);
    $status = "Saved y000000000000.cfg to {$tftp_dir}";
}

$max_dialnow_slots = (int)($formData['dialnow_count'] ?? 1);
$existing_logos = glob($logo_dir . "*.*");
$logo_filenames = array_map('basename', is_array($existing_logos) ? $existing_logos : []);
$existing_ringtones = glob($ringtone_dir . "*.*");
$ringtone_filenames = array_map('basename', is_array($existing_ringtones) ? $existing_ringtones : []);
?>

<!-- ============================================================================ -->
<!-- 10. HTML VIEW & STYLES                                                       -->
<!-- ============================================================================ -->

<style>
    .gen-container { background: #fff; padding: 20px; border-radius: 6px; overflow: visible; }
    .gen-container label { font-weight: bold; display: block; margin-top: 10px; }
    .gen-container input[type="text"], .gen-container select, .gen-container input[type="file"] { padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
    .gen-full-width { width: 100%; }
    .gen-key-row { display: flex; gap: 10px; margin-top: 5px; }
    .gen-key-row input, .gen-key-row div, .gen-key-row select { flex: 1; }
    .gen-btn { margin-top: 10px; padding: 10px 18px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
    .gen-btn-danger { background: #dc3545; color: white; border: none; border-radius: 4px; padding: 8px 14px; cursor: pointer; }
    .gen-textarea { width: 100%; height: 220px; font-family: monospace; margin-top: 5px; box-sizing: border-box; }
    .gen-alert { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
    .gen-load-box { background: #e9ecef; padding: 15px; border-radius: 6px; margin-bottom: 20px; }
    .gen-section-title { border-bottom: 2px solid #007bff; padding-bottom: 5px; margin-top: 20px; color: #333; }
    
    .gen-tab-bar { 
        display: flex; 
        border-bottom: 2px solid #007bff; 
        margin-bottom: 20px; 
        position: sticky; 
        top: 40px; 
        z-index: 100; 
        background: #fff; 
        padding-top: 10px; 
        padding-bottom: 5px; 
        box-shadow: 0 2px 5px rgba(0,0,0,0.1); 
    }
    .gen-tab-btn { padding: 10px 20px; cursor: pointer; background: #e9ecef; border: 1px solid #ccc; border-bottom: none; border-top-left-radius: 4px; border-top-right-radius: 4px; margin-right: 5px; font-weight: bold; }
    .gen-tab-btn.active { background: #007bff; color: white; border-color: #007bff; }
    .gen-tab-content { display: none; }
    .gen-tab-content.active { display: block; }

    .gen-modal { display: none; position: fixed; z-index: 999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
    .gen-modal-content { background: #fff; margin: 10% auto; padding: 20px; width: 65%; border-radius: 8px; }
    .scan-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .scan-table th, .scan-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    .scan-table th { background: #f2f2f2; }

    .oss-table { width: 100%; border-collapse: collapse; margin-top: 15px; background: #fff; }
    .oss-table th, .oss-table td { border: 1px solid #d4d4d4; padding: 8px; text-align: left; }
    .oss-table th { background: #e9ecef; font-weight: bold; }
    .oss-action-card { background: #f8f9fa; border: 1px solid #ddd; border-radius: 6px; padding: 15px; margin-top: 15px; }
    .oss-action-line { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
    .oss-btn-icon { background: none; border: none; cursor: pointer; font-size: 18px; }
    .oss-btn-icon.online { color: #28a745; }
    .oss-btn-icon.offline { color: #dc3545; }
</style>

<!-- ============================================================================ -->
<!-- 11. JAVASCRIPT CONTROLLERS & AJAX                                            -->
<!-- ============================================================================ -->

<script>
    var scannedDeviceMacs = [];

    function switchTab(tabId) {
        document.querySelectorAll('.gen-tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.gen-tab-btn').forEach(el => el.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        document.getElementById('btn_' + tabId).classList.add('active');
        document.getElementById('active_tab_field').value = tabId;
        document.getElementById('device_active_tab_field').value = tabId;
    }

    function toggleSelectAllPhones(master) {
        var checkboxes = document.querySelectorAll('.phone_checkbox');
        checkboxes.forEach(cb => cb.checked = master.checked);
    }

    function enableMacEdit(mac) {
        var inputElem = document.getElementById('mac_input_' + mac);
        if (inputElem) {
            inputElem.readOnly = false;
            inputElem.style.backgroundColor = '#ffffff';
            inputElem.style.borderColor = '#007bff';
            inputElem.focus();
        }
    }

    function applyBulkTemplateToScanned(selectedTpl) {
        if (!selectedTpl) return;
        scannedDeviceMacs.forEach(mac => {
            var selectElem = document.getElementById('scan_tpl_' + mac);
            if (selectElem) {
                selectElem.value = selectedTpl;
            }
        });
    }

    function triggerDeviceAction(actionName) {
        document.getElementById('device_action_input').value = actionName;
        document.getElementById('device_manager_form').submit();
    }

    function triggerSingleReboot(ext) {
        document.getElementById('device_action_input').value = 'single_reboot';
        document.getElementById('single_ext_input').value = ext;
        document.getElementById('device_manager_form').submit();
    }

    function openSingleRebuildModal(mac, model, template) {
        document.getElementById('single_mac_input').value = mac.toLowerCase();
        document.getElementById('single_rebuild_mac_title').innerText = mac.toLowerCase();
        
        var modelSelect = document.getElementById('single_model_select');
        if (modelSelect) modelSelect.value = model || 'manual';

        var tplSelect = document.getElementById('single_template_select');
        if (tplSelect) tplSelect.value = template || '';

        document.getElementById('singleRebuildModal').style.display = 'block';
    }

    function closeSingleRebuildModal() {
        document.getElementById('singleRebuildModal').style.display = 'none';
    }

    function submitSingleRebuildModal() {
        var mac = document.getElementById('single_mac_input').value.toLowerCase();
        var selectedTpl = document.getElementById('single_template_select').value;
        
        var phoneTplElem = document.getElementById('phone_tpl_' + mac);
        if (phoneTplElem) phoneTplElem.value = selectedTpl;

        document.getElementById('device_action_input').value = 'single_rebuild';
        document.getElementById('device_manager_form').submit();
    }

    function confirmDeleteGlobalConfig() {
        if (confirm("Are you sure you want to permanently delete y000000000000.cfg from /tftpboot/?")) {
            document.getElementById('target_filename').value = "y000000000000.cfg";
            document.getElementById('target_file_type').value = "global";
            document.getElementById('delete_file_form').submit();
        }
    }

    function updateDialnowVisibility(count) {
        var num = parseInt(count, 10) || 1;
        for (var i = 1; i <= 20; i++) {
            var slot = document.getElementById('dialnow_slot_' + i);
            if (slot) {
                slot.style.display = (i <= num) ? 'block' : 'none';
            }
        }
    }

    function updateLinekeyVisibility(count) {
        var num = parseInt(count, 10) || 1;
        for (var i = 1; i <= 29; i++) {
            var row = document.getElementById('linekey_row_' + i);
            if (row) {
                row.style.display = (i <= num) ? 'flex' : 'none';
            }
        }
    }

    function updateMemkeyVisibility(count) {
        var num = parseInt(count, 10) || 0;
        for (var i = 1; i <= 180; i++) {
            var row = document.getElementById('memkey_row_' + i);
            if (row) {
                row.style.display = (i <= num) ? 'flex' : 'none';
            }
        }
    }

    function calculateTotalMemoryKeys() {
        var baseMem = parseInt(document.getElementById('field_base_mem_keys').value) || 0;
        var expModel = document.getElementById('select_exp_model').value;
        var expQty = parseInt(document.getElementById('select_exp_count').value) || 0;
        
        var expKeysPerUnit = 0;
        if (expModel === 'EXP20') expKeysPerUnit = 20;
        if (expModel === 'EXP40') expKeysPerUnit = 40;
        if (expModel === 'EXP50') expKeysPerUnit = 60;

        var totalMemKeys = baseMem + (expKeysPerUnit * expQty);
        var memElem = document.getElementById('select_memkey_count');
        if (memElem) {
            memElem.value = totalMemKeys;
            updateMemkeyVisibility(totalMemKeys);
        }
    }

    function handleModelSelect(model) {
        var lineElem = document.getElementById('select_linekey_count');
        var baseMemElem = document.getElementById('field_base_mem_keys');
        
        var modelSpecs = {
            "T19P":  { lines: 1,  mem: 0 },
            "T21P":  { lines: 2,  mem: 0 },
            "T23G":  { lines: 3,  mem: 0 },
            "T27G":  { lines: 21, mem: 0 },
            "T28P":  { lines: 6,  mem: 10 },
            "T29G":  { lines: 27, mem: 0 },
            "T30":   { lines: 1,  mem: 0 },
            "T31G":  { lines: 2,  mem: 0 },
            "T33G":  { lines: 4,  mem: 0 },
            "T40P":  { lines: 3,  mem: 0 },
            "T41S":  { lines: 15, mem: 0 },
            "T42S":  { lines: 15, mem: 0 },
            "T43U":  { lines: 21, mem: 0 },
            "T46S":  { lines: 27, mem: 0 },
            "T48S":  { lines: 29, mem: 0 },
            "T53W":  { lines: 21, mem: 0 },
            "T54W":  { lines: 27, mem: 0 },
            "T57W":  { lines: 29, mem: 0 },
            "T58A":  { lines: 27, mem: 0 },
            "VP59":  { lines: 27, mem: 0 }
        };

        if (modelSpecs[model]) {
            if (lineElem && modelSpecs[model].lines >= 0) {
                lineElem.value = modelSpecs[model].lines;
                updateLinekeyVisibility(modelSpecs[model].lines);
            }
            if (baseMemElem && modelSpecs[model].mem >= 0) {
                baseMemElem.value = modelSpecs[model].mem;
            }
            calculateTotalMemoryKeys();
        }
    }

    function handleExpSelect() {
        calculateTotalMemoryKeys();
    }

    function confirmDeleteFile(selectId, fileType) {
        var selectElem = document.getElementById(selectId);
        var filename = selectElem.value;
        if (!filename || filename === 'system') {
            alert("Please select a valid custom file to delete.");
            return false;
        }
        if (confirm("Are you sure you want to permanently delete '" + filename + "' from the server?")) {
            document.getElementById('target_filename').value = filename;
            document.getElementById('target_file_type').value = fileType;
            document.getElementById('delete_file_form').submit();
        }
    }

    function openScanModal() {
        document.getElementById('scanModal').style.display = 'block';
    }

    function closeScanModal() {
        document.getElementById('scanModal').style.display = 'none';
        window.location.hash = 'tab_devices';
        window.location.reload();
    }

    function openManualAddModal() {
        document.getElementById('manual_mac').value = '';
        document.getElementById('manual_ext').value = '';
        document.getElementById('manual_tpl').value = '';
        document.getElementById('manualAddModal').style.display = 'block';
    }

    function closeManualAddModal() {
        document.getElementById('manualAddModal').style.display = 'none';
        window.location.hash = 'tab_devices';
        window.location.reload();
    }

    function submitManualAddDevice() {
        var rawMac = document.getElementById('manual_mac').value.replace(/[^a-fA-F0-9]/g, '').toLowerCase();
        var extVal = document.getElementById('manual_ext').value;
        var tplVal = document.getElementById('manual_tpl').value;
        var autoProvision = document.getElementById('manual_provision').checked ? '1' : '0';
        var btn = document.getElementById('manual_add_btn');

        if (rawMac.length !== 12) {
            alert('Please enter a valid 12-character MAC address.');
            return;
        }

        btn.disabled = true;
        btn.innerText = 'Creating...';

        var formData = new FormData();
        formData.append('scanned_mac', rawMac);
        formData.append('scanned_ext', extVal);
        formData.append('scanned_template', tplVal);
        formData.append('auto_provision', autoProvision);

        fetch('?display=yealink_epm&action=add_scanned_device', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(text => {
            var data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                data = { status: 'success' };
            }

            if (data.status === 'success') {
                btn.innerText = 'Created!';
                btn.style.background = '#6c757d';
                setTimeout(() => { closeManualAddModal(); }, 600);
            } else {
                alert(data.message || 'Error adding device.');
                btn.disabled = false;
                btn.innerText = 'Create Device Config';
                btn.style.background = '#28a745';
            }
        })
        .catch(err => {
            alert('Request failed.');
            btn.disabled = false;
            btn.innerText = 'Create Device Config';
            btn.style.background = '#28a745';
        });
    }

    function runSubnetScan() {
        var subnet = document.getElementById('scan_subnet').value;
        var tbody = document.getElementById('scan_results_body');
        scannedDeviceMacs = [];
        tbody.innerHTML = '<tr><td colspan="6">Scanning subnet asynchronously... Please wait...</td></tr>';
        
        fetch('?display=yealink_epm&action=scan_network&subnet=' + encodeURIComponent(subnet))
            .then(response => response.json())
            .then(data => {
                tbody.innerHTML = '';
                if (!data.devices || data.devices.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6">No unconfigured Yealink devices found on subnet.</td></tr>';
                    return;
                }
                data.devices.forEach(dev => {
                    scannedDeviceMacs.push(dev.mac);
                    var extOptions = `<option value="">-- Unassigned --</option>`;
                    <?php foreach ($available_extensions as $ext_id => $ext_data): ?>
                        extOptions += `<option value="<?= $ext_id ?>"><?= $ext_id ?> - <?= htmlspecialchars($ext_data['display_name']) ?></option>`;
                    <?php endforeach; ?>

                    var tplOptions = `<option value="">-- None --</option>`;
                    <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                        tplOptions += `<option value="<?= htmlspecialchars($tpl_file) ?>"><?= htmlspecialchars($tpl_label) ?></option>`;
                    <?php endforeach; ?>

                    var row = `<tr id="scan_row_${dev.mac}">
                        <td style="vertical-align:middle;">${dev.ip}</td>
                        <td style="vertical-align:middle;"><b>${dev.mac}</b></td>
                        <td>
                            <select id="scan_ext_${dev.mac}" style="padding:4px; max-width:180px;">${extOptions}</select>
                        </td>
                        <td>
                            <select id="scan_tpl_${dev.mac}" style="padding:4px;">${tplOptions}</select>
                        </td>
                        <td style="text-align:center; vertical-align:middle;">
                            <input type="checkbox" id="scan_provision_${dev.mac}" checked title="Push config & auto-provision phone immediately">
                        </td>
                        <td>
                            <button type="button" id="scan_btn_${dev.mac}" class="gen-btn" style="margin-top:0; padding:6px 12px; background:#28a745;" onclick="submitAddScannedDevice('${dev.mac}')">+ Add Device</button>
                        </td>
                    </tr>`;
                    tbody.innerHTML += row;
                });
            })
            .catch(err => {
                tbody.innerHTML = '<tr><td colspan="6">Scan failed or timed out.</td></tr>';
            });
    }

    function submitAddScannedDevice(mac) {
        var extVal = document.getElementById('scan_ext_' + mac).value;
        var tplVal = document.getElementById('scan_tpl_' + mac).value;
        var autoProvision = document.getElementById('scan_provision_' + mac).checked ? '1' : '0';
        var btn = document.getElementById('scan_btn_' + mac);
        
        btn.disabled = true;
        btn.innerText = 'Adding...';

        var formData = new FormData();
        formData.append('scanned_mac', mac.toLowerCase());
        formData.append('scanned_ext', extVal);
        formData.append('scanned_template', tplVal);
        formData.append('auto_provision', autoProvision);

        return fetch('?display=yealink_epm&action=add_scanned_device', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(text => {
            var data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                data = { status: 'success' };
            }

            if (data.status === 'success') {
                var row = document.getElementById('scan_row_' + mac);
                if (row) row.style.background = '#d4edda';
                btn.innerHTML = 'Added &#10003;';
                btn.style.background = '#6c757d';
            } else {
                btn.disabled = false;
                btn.innerText = 'Error! Try Again';
                btn.style.background = '#dc3545';
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerText = 'Error! Try Again';
            btn.style.background = '#dc3545';
        });
    }

    function submitAddAllScannedDevices() {
        if (scannedDeviceMacs.length === 0) {
            alert('No devices available to add.');
            return;
        }

        var btn = document.getElementById('add_all_btn');
        btn.disabled = true;
        btn.innerText = 'Adding All...';

        var promises = scannedDeviceMacs.map(mac => submitAddScannedDevice(mac));
        
        Promise.all(promises).then(() => {
            btn.innerText = 'Done!';
            setTimeout(() => {
                closeScanModal();
            }, 800);
        });
    }

    // Retain active tab state across refreshes / hash state
    document.addEventListener("DOMContentLoaded", function() {
        if (window.location.hash === '#tab_devices' || '<?= $formData['active_tab'] ?>' === 'tab_devices') {
            switchTab('tab_devices');
        }
    });
</script>

<form id="delete_file_form" method="POST" style="display:none;">
    <input type="hidden" name="delete_target_file" value="1">
    <input type="hidden" id="target_filename" name="target_filename" value="">
    <input type="hidden" id="target_file_type" name="target_file_type" value="">
</form>

<!-- MODAL 1: SUBNET SCANNER -->
<div id="scanModal" class="gen-modal">
    <div class="gen-modal-content">
        <h3>Subnet MAC Address Scanner (Yealink)</h3>
        
        <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:10px; margin-bottom:10px;">
            <div style="flex:1;">
                <label style="margin-top:0;">Enter Subnet Base IP / FQDN:</label>
                <div style="display:flex; gap:10px;">
                    <input type="text" id="scan_subnet" class="gen-full-width" value="<?= $detected_host ?>">
                    <button type="button" class="gen-btn" style="margin-top:0;" onclick="runSubnetScan()">Scan Subnet</button>
                </div>
            </div>

            <div style="flex:1;">
                <label style="margin-top:0;">Bulk Assign Template to All Scanned:</label>
                <select id="bulk_scanned_template" class="gen-full-width" onchange="applyBulkTemplateToScanned(this.value)">
                    <option value="">-- Select Template to Apply All --</option>
                    <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                        <option value="<?= htmlspecialchars($tpl_file) ?>"><?= htmlspecialchars($tpl_label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <table class="scan-table">
            <thead>
                <tr>
                    <th>IP Address</th>
                    <th>MAC Address</th>
                    <th>Assign Extension</th>
                    <th>Assign Template</th>
                    <th>Auto-Provision</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="scan_results_body">
                <tr><td colspan="6">Click 'Scan Subnet' to discover active phones.</td></tr>
            </tbody>
        </table>
        <br>
        <div style="display:flex; justify-content:space-between; gap:10px;">
            <button type="button" id="add_all_btn" class="gen-btn" style="margin-top:0; background:#28a745;" onclick="submitAddAllScannedDevices()">+ Add All Devices & Close</button>
            <button type="button" class="gen-btn-danger" style="margin-top:0;" onclick="closeScanModal()">Done / Close</button>
        </div>
    </div>
</div>

<!-- MODAL 2: MANUAL DEVICE ADDITION -->
<div id="manualAddModal" class="gen-modal">
    <div class="gen-modal-content" style="width: 450px;">
        <h3>Manually Add Phone Device</h3>
        
        <label>MAC Address:</label>
        <input type="text" id="manual_mac" class="gen-full-width" placeholder="e.g. 001565123456" maxlength="17">

        <label style="margin-top:10px;">Assign Extension:</label>
        <select id="manual_ext" class="gen-full-width">
            <option value="">-- Unassigned --</option>
            <?php foreach ($available_extensions as $ext_id => $ext_data): ?>
                <option value="<?= $ext_id ?>"><?= $ext_id ?> - <?= htmlspecialchars($ext_data['display_name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label style="margin-top:10px;">Assign Template:</label>
        <select id="manual_tpl" class="gen-full-width">
            <option value="">-- None --</option>
            <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                <option value="<?= htmlspecialchars($tpl_file) ?>"><?= htmlspecialchars($tpl_label) ?></option>
            <?php endforeach; ?>
        </select>

        <div style="margin-top:12px;">
            <label style="font-weight:normal; display:inline-flex; align-items:center; gap:5px;">
                <input type="checkbox" id="manual_provision" checked> Push Auto-Provision & SIP NOTIFY
            </label>
        </div>

        <br>
        <div style="display:flex; justify-content:space-between; gap:10px;">
            <button type="button" id="manual_add_btn" class="gen-btn" style="margin-top:0; background:#28a745;" onclick="submitManualAddDevice()">Create Device Config</button>
            <button type="button" class="gen-btn-danger" style="margin-top:0;" onclick="closeManualAddModal()">Cancel / Close</button>
        </div>
    </div>
</div>

<!-- MODAL 3: SINGLE DEVICE REBUILD WITH MODEL & TEMPLATE SELECTORS -->
<div id="singleRebuildModal" class="gen-modal">
    <div class="gen-modal-content" style="width: 450px;">
        <h3>Rebuild Configuration (<span id="single_rebuild_mac_title"></span>)</h3>
        
        <label>Select Template:</label>
        <select id="single_template_select" class="gen-full-width">
            <option value="">-- None --</option>
            <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                <option value="<?= htmlspecialchars($tpl_file) ?>"><?= htmlspecialchars($tpl_label) ?></option>
            <?php endforeach; ?>
        </select>

        <label style="margin-top:10px;">Override Phone Model:</label>
        <select id="single_model_select" name="single_model" class="gen-full-width">
            <?php foreach ($yealink_models as $m_key => $m_label): ?>
                <option value="<?= $m_key ?>"><?= $m_label ?></option>
            <?php endforeach; ?>
        </select>

        <div style="margin-top:12px;">
            <label style="font-weight:normal; display:inline-flex; align-items:center; gap:8px;">
                <input type="checkbox" name="single_provision" checked> Push Auto Provision
            </label>
            <br>
            <label style="font-weight:normal; display:inline-flex; align-items:center; gap:8px; margin-top:5px;">
                <input type="checkbox" name="single_reboot_check"> Reboot Phone
            </label>
        </div>

        <br>
        <div style="display:flex; justify-content:space-between; gap:10px;">
            <button type="button" class="gen-btn" style="margin-top:0; background:#28a745;" onclick="submitSingleRebuildModal()">Rebuild Device Config</button>
            <button type="button" class="gen-btn-danger" style="margin-top:0;" onclick="closeSingleRebuildModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- ============================================================================ -->
<!-- 12. TABBED UI LAYOUT                                                         -->
<!-- ============================================================================ -->

<div class="gen-container">
    <h2>Yealink Endpoint Manager</h2>
    
    <?php if ($sysadmin_redirect): ?>
        <div class="alert alert-warning alert-dismissible" role="alert" style="margin-top: 15px;">
            <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <strong>Notice for Legacy Yealink Phones:</strong> Global HTTPS Redirect is active in System Admin. 
            Provisioning URLs have been automatically routed to <strong>Port 83</strong> (<code><?= htmlspecialchars($default_provision_url) ?></code>) to ensure legacy V73 firmware can provision over HTTP without SSL errors.
        </div>
    <?php endif; ?>

    <?php if (!empty($status)) echo "<div class='gen-alert'>{$status}</div>"; ?>

    <div class="gen-tab-bar">
        <div id="btn_tab_global" class="gen-tab-btn <?= ($formData['active_tab'] === 'tab_global') ? 'active' : '' ?>" onclick="switchTab('tab_global')">Global Settings (y000000000000.cfg)</div>
        <div id="btn_tab_template" class="gen-tab-btn <?= ($formData['active_tab'] === 'tab_template') ? 'active' : '' ?>" onclick="switchTab('tab_template')">Template Manager (template.cfg)</div>
        <div id="btn_tab_devices" class="gen-tab-btn <?= ($formData['active_tab'] === 'tab_devices') ? 'active' : '' ?>" onclick="switchTab('tab_devices')">Device Manager</div>
    </div>

    <!-- TAB 1: GLOBAL SETTINGS -->
    <div id="tab_global" class="gen-tab-content <?= ($formData['active_tab'] === 'tab_global') ? 'active' : '' ?>">
        <form id="main_cfg_form" method="POST" enctype="multipart/form-data">
            <input type="hidden" id="active_tab_field" name="active_tab" value="<?= htmlspecialchars($formData['active_tab']) ?>">
            <input type="hidden" id="field_base_mem_keys" value="0">

            <div class="gen-key-row">
                <div>
                    <label>PBX Server IP / Domain:</label>
                    <input type="text" class="gen-full-width" name="server_ip" placeholder="<?= $default_server_target ?>" value="<?= htmlspecialchars($formData['server_ip']) ?>">
                </div>
                <div>
                    <label>Phone Web GUI Admin Password:</label>
                    <input type="text" class="gen-full-width" name="admin_password" placeholder="22222" value="<?= htmlspecialchars($formData['admin_password']) ?>">
                </div>
            </div>

            <div class="gen-key-row">
                <div>
                    <label>Time Zone:</label>
                    <select name="timezone" class="gen-full-width">
                        <?php foreach ($timezones as $offset => $tz_name): ?>
                            <option value="<?= $offset ?>" <?= ($formData['timezone'] == $offset) ? 'selected' : '' ?>><?= $tz_name ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Time Format:</label>
                    <select name="time_format" class="gen-full-width">
                        <option value="0" <?= ($formData['time_format'] === '0') ? 'selected' : '' ?>>12-Hour (AM/PM)</option>
                        <option value="1" <?= ($formData['time_format'] === '1') ? 'selected' : '' ?>>24-Hour (Military)</option>
                    </select>
                </div>
            </div>

            <div class="gen-key-row">
                <div>
                    <label>NTP Server 1:</label>
                    <input type="text" class="gen-full-width" name="ntp_server1" placeholder="<?= $detected_host ?>" value="<?= htmlspecialchars($formData['ntp_server1']) ?>">
                </div>
                <div>
                    <label>NTP Server 2:</label>
                    <input type="text" class="gen-full-width" name="ntp_server2" placeholder="pool.ntp.org" value="<?= htmlspecialchars($formData['ntp_server2']) ?>">
                </div>
            </div>

            <h3 class="gen-section-title">Auto Provisioning Settings</h3>
            <div class="gen-key-row">
                <div>
                    <label>Provisioning Mode:</label>
                    <select name="auto_provision_mode" class="gen-full-width">
                        <option value="7" <?= ($formData['auto_provision_mode'] === '7') ? 'selected' : '' ?>>7 - Power on + Weekly</option>
                        <option value="6" <?= ($formData['auto_provision_mode'] === '6') ? 'selected' : '' ?>>6 - Power on + Repeatedly</option>
                        <option value="5" <?= ($formData['auto_provision_mode'] === '5') ? 'selected' : '' ?>>5 - Weekly</option>
                        <option value="4" <?= ($formData['auto_provision_mode'] === '4') ? 'selected' : '' ?>>4 - Repeatedly</option>
                        <option value="1" <?= ($formData['auto_provision_mode'] === '1') ? 'selected' : '' ?>>1 - Power on</option>
                        <option value="0" <?= ($formData['auto_provision_mode'] === '0') ? 'selected' : '' ?>>0 - Disabled</option>
                    </select>
                </div>
                <div>
                    <label>Weekly Provisioning Enable:</label>
                    <select name="auto_provision_weekly_enable" class="gen-full-width">
                        <option value="1" <?= ($formData['auto_provision_weekly_enable'] === '1') ? 'selected' : '' ?>>1 - Enabled</option>
                        <option value="0" <?= ($formData['auto_provision_weekly_enable'] === '0') ? 'selected' : '' ?>>0 - Disabled</option>
                    </select>
                </div>
                <div>
                    <label>DHCP Option Enable:</label>
                    <select name="auto_provision_dhcp_option_enable" class="gen-full-width">
                        <option value="1" <?= ($formData['auto_provision_dhcp_option_enable'] === '1') ? 'selected' : '' ?>>1 - Enabled</option>
                        <option value="0" <?= ($formData['auto_provision_dhcp_option_enable'] === '0') ? 'selected' : '' ?>>0 - Disabled</option>
                    </select>
                </div>
            </div>

            <div class="gen-key-row">
                <div>
                    <label>Weekly Begin Time:</label>
                    <input type="text" class="gen-full-width" name="auto_provision_weekly_begin_time" value="<?= htmlspecialchars($formData['auto_provision_weekly_begin_time']) ?>">
                </div>
                <div>
                    <label>Weekly End Time:</label>
                    <input type="text" class="gen-full-width" name="auto_provision_weekly_end_time" value="<?= htmlspecialchars($formData['auto_provision_weekly_end_time']) ?>">
                </div>
                <div>
                    <label>Day of Week (0=Sun, 6=Sat):</label>
                    <select name="auto_provision_weekly_dayofweek" class="gen-full-width">
                        <option value="0" <?= ($formData['auto_provision_weekly_dayofweek'] === '0') ? 'selected' : '' ?>>0 - Sunday</option>
                        <option value="1" <?= ($formData['auto_provision_weekly_dayofweek'] === '1') ? 'selected' : '' ?>>1 - Monday</option>
                        <option value="2" <?= ($formData['auto_provision_weekly_dayofweek'] === '2') ? 'selected' : '' ?>>2 - Tuesday</option>
                        <option value="3" <?= ($formData['auto_provision_weekly_dayofweek'] === '3') ? 'selected' : '' ?>>3 - Wednesday</option>
                        <option value="4" <?= ($formData['auto_provision_weekly_dayofweek'] === '4') ? 'selected' : '' ?>>4 - Thursday</option>
                        <option value="5" <?= ($formData['auto_provision_weekly_dayofweek'] === '5') ? 'selected' : '' ?>>5 - Friday</option>
                        <option value="6" <?= ($formData['auto_provision_weekly_dayofweek'] === '6') ? 'selected' : '' ?>>6 - Saturday</option>
                    </select>
                </div>
            </div>

            <div class="gen-key-row">
                <div>
                    <label>Server Username (Optional):</label>
                    <input type="text" class="gen-full-width" name="auto_provision_username" value="<?= htmlspecialchars($formData['auto_provision_username']) ?>">
                </div>
                <div>
                    <label>Server Password (Optional):</label>
                    <input type="text" class="gen-full-width" name="auto_provision_password" value="<?= htmlspecialchars($formData['auto_provision_password']) ?>">
                </div>
            </div>

            <h3 class="gen-section-title">SIP & Call Transfer Features</h3>
            <div class="gen-key-row">
                <div>
                    <label>Use Outbound Proxy in Dialog:</label>
                    <select name="sip_use_out_bound_in_dialog" class="gen-full-width">
                        <option value="1" <?= ($formData['sip_use_out_bound_in_dialog'] === '1') ? 'selected' : '' ?>>1 - Enabled</option>
                        <option value="0" <?= ($formData['sip_use_out_bound_in_dialog'] === '0') ? 'selected' : '' ?>>0 - Disabled</option>
                    </select>
                </div>
                <div>
                    <label>DSS Key Transfer Action:</label>
                    <select name="transfer_dsskey_deal_type" class="gen-full-width">
                        <option value="2" <?= ($formData['transfer_dsskey_deal_type'] === '2') ? 'selected' : '' ?>>2 - Attended Transfer</option>
                        <option value="1" <?= ($formData['transfer_dsskey_deal_type'] === '1') ? 'selected' : '' ?>>1 - Blind Transfer</option>
                        <option value="0" <?= ($formData['transfer_dsskey_deal_type'] === '0') ? 'selected' : '' ?>>0 - New Call</option>
                    </select>
                </div>
            </div>

            <div class="gen-key-row">
                <div>
                    <label>Blind Transfer On Hook:</label>
                    <select name="transfer_blind_tran_on_hook_enable" class="gen-full-width">
                        <option value="1" <?= ($formData['transfer_blind_tran_on_hook_enable'] === '1') ? 'selected' : '' ?>>1 - Enabled</option>
                        <option value="0" <?= ($formData['transfer_blind_tran_on_hook_enable'] === '0') ? 'selected' : '' ?>>0 - Disabled</option>
                    </select>
                </div>
                <div>
                    <label>On-Hook Transfer:</label>
                    <select name="transfer_on_hook_trans_enable" class="gen-full-width">
                        <option value="1" <?= ($formData['transfer_on_hook_trans_enable'] === '1') ? 'selected' : '' ?>>1 - Enabled</option>
                        <option value="0" <?= ($formData['transfer_on_hook_trans_enable'] === '0') ? 'selected' : '' ?>>0 - Disabled</option>
                    </select>
                </div>
            </div>

            <h3 class="gen-section-title">Dial Plan (Dial-Now) Rules</h3>
            <label>Inter-Digit Timeout (Seconds):</label>
            <select name="dialnow_timeout" class="gen-full-width">
                <?php for ($sec = 1; $sec <= 14; $sec++): ?>
                    <option value="<?= $sec ?>" <?= ($formData['dialnow_timeout'] == $sec) ? 'selected' : '' ?>><?= $sec ?> Seconds</option>
                <?php endfor; ?>
            </select>

            <label style="margin-top:10px;">Number of DialNow Pattern Slots:</label>
            <select name="dialnow_count" class="gen-full-width" onchange="updateDialnowVisibility(this.value)">
                <?php for ($d_cnt = 1; $d_cnt <= 20; $d_cnt++): ?>
                    <option value="<?= $d_cnt ?>" <?= ($d_cnt == $max_dialnow_slots) ? 'selected' : '' ?>><?= $d_cnt ?> Slots</option>
                <?php endfor; ?>
            </select>

            <label style="margin-top:10px;">Outbound Match Patterns (Pulled from route named "outbound")</label>
            <div style="margin-top:5px;">
            <?php for ($d = 1; $d <= 20; $d += 2): 
                $next_slot = $d + 1;
            ?>
                <div class="gen-key-row">
                    <div id="dialnow_slot_<?= $d ?>" style="display: <?= ($d <= $max_dialnow_slots) ? 'block' : 'none' ?>;">
                        <input type="text" class="gen-full-width" name="dialnow_<?= $d ?>" placeholder="Rule <?= $d ?>" value="<?= htmlspecialchars($formData["dialnow_{$d}"] ?? '') ?>">
                    </div>
                    <?php if ($next_slot <= 20): ?>
                        <div id="dialnow_slot_<?= $next_slot ?>" style="display: <?= ($next_slot <= $max_dialnow_slots) ? 'block' : 'none' ?>;">
                            <input type="text" class="gen-full-width" name="dialnow_<?= $next_slot ?>" placeholder="Rule <?= $next_slot ?>" value="<?= htmlspecialchars($formData["dialnow_{$next_slot}"] ?? '') ?>">
                        </div>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
            </div>

            <h3 class="gen-section-title">Global Custom Key / Value Additions</h3>
            <label>Add raw global Yealink configuration flags for y000000000000.cfg (One per line):</label>
            <textarea name="custom_inputs_global" class="gen-textarea"><?= htmlspecialchars($formData['custom_inputs_global']) ?></textarea>

            <?php if (!empty($generated_common_cfg)): ?>
                <h3 class="gen-section-title">Generated Common Output (y000000000000.cfg)</h3>
                <textarea readonly class="gen-textarea" style="height:300px;"><?= htmlspecialchars($generated_common_cfg) ?></textarea>
            <?php endif; ?>

            <br>
            <div style="display: flex; gap: 10px; margin-top: 10px;">
                <button type="submit" name="save_global" class="gen-btn" style="background: #28a745; margin-top:0;">Save Global Settings to /tftpboot/</button>
                <?php if (file_exists($tftp_dir . "y000000000000.cfg")): ?>
                    <button type="button" class="gen-btn-danger" style="margin-top:0;" onclick="confirmDeleteGlobalConfig()">Delete y000000000000.cfg</button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- TAB 2: TEMPLATE MANAGER -->
    <div id="tab_template" class="gen-tab-content <?= ($formData['active_tab'] === 'tab_template') ? 'active' : '' ?>">
        <div class="gen-load-box">
            <form method="POST">
                <label>Load Previously Saved Template:</label>
                <div style="display: flex; gap: 10px;">
                    <select id="select_template_file" name="template_to_load" class="gen-full-width">
                        <option value="" <?= empty($_POST['template_to_load']) ? 'selected' : '' ?>>-- Select a template to edit --</option>
                        <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                            <option value="<?= htmlspecialchars($tpl_file) ?>" <?= (isset($_POST['template_to_load']) && $_POST['template_to_load'] === $tpl_file) ? 'selected' : '' ?>><?= htmlspecialchars($tpl_label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="load_template" class="gen-btn" style="margin-top:0; background:#6c757d;">Load Template</button>
                    <button type="button" class="gen-btn-danger" style="margin-top:0;" onclick="confirmDeleteFile('select_template_file', 'template')">Delete</button>
                </div>
            </form>
        </div>

        <form id="template_cfg_form" method="POST" enctype="multipart/form-data">
            <div class="gen-key-row">
                <div>
                    <label>Template Name:</label>
                    <input type="text" class="gen-full-width" name="template_name" placeholder="e.g., T28_Reception" value="<?= htmlspecialchars($formData['template_name']) ?>">
                </div>
                <div>
                    <label>Phone Model Presets:</label>
                    <select name="phone_model" class="gen-full-width" onchange="handleModelSelect(this.value)">
                        <?php foreach ($yealink_models as $m_key => $m_label): ?>
                            <option value="<?= $m_key ?>" <?= ($formData['phone_model'] === $m_key) ? 'selected' : '' ?>><?= $m_label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="gen-key-row">
                <div>
                    <label>Expansion Module Model:</label>
                    <select id="select_exp_model" name="exp_model" class="gen-full-width" onchange="handleExpSelect()">
                        <?php foreach ($expansion_models as $e_key => $e_label): ?>
                            <option value="<?= $e_key ?>" <?= ($formData['exp_model'] === $e_key) ? 'selected' : '' ?>><?= $e_label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Expansion Quantity:</label>
                    <select id="select_exp_count" name="exp_count" class="gen-full-width" onchange="handleExpSelect()">
                        <option value="0" <?= ($formData['exp_count'] === '0') ? 'selected' : '' ?>>-- None --</option>
                        <option value="1" <?= ($formData['exp_count'] === '1') ? 'selected' : '' ?>>1 Unit</option>
                        <option value="2" <?= ($formData['exp_count'] === '2') ? 'selected' : '' ?>>2 Units</option>
                        <option value="3" <?= ($formData['exp_count'] === '3') ? 'selected' : '' ?>>3 Units</option>
                    </select>
                </div>
            </div>

            <div class="gen-key-row">
                <div>
                    <label>SIP Port:</label>
                    <input type="text" class="gen-full-width" name="sip_port" placeholder="<?= htmlspecialchars($default_sip_port) ?>" value="<?= htmlspecialchars($formData['sip_port']) ?>">
                </div>
                <div>
                    <label>SIP Listen Port:</label>
                    <input type="text" class="gen-full-width" name="sip_listen_port" placeholder="5062" value="<?= htmlspecialchars($formData['sip_listen_port']) ?>">
                </div>
                <div>
                    <label>Voicemail Extension Number:</label>
                    <input type="text" class="gen-full-width" name="voicemail_number" placeholder="*97" value="<?= htmlspecialchars($formData['voicemail_number']) ?>">
                </div>
            </div>

            <h3 class="gen-section-title">Line Keys BLF Settings</h3>
            <label>Number of Line Key Slots:</label>
            <select id="select_linekey_count" name="linekey_count" class="gen-full-width" onchange="updateLinekeyVisibility(this.value)">
                <?php for ($l_cnt = 1; $l_cnt <= 29; $l_cnt++): ?>
                    <option value="<?= $l_cnt ?>" <?= ($l_cnt == $max_linekeys) ? 'selected' : '' ?>><?= $l_cnt ?> Line Keys</option>
                <?php endfor; ?>
            </select>

            <div style="margin-top:10px;">
            <?php for ($i = 1; $i <= 29; $i++): ?>
                <div id="linekey_row_<?= $i ?>" class="gen-key-row" style="display: <?= ($i <= $max_linekeys) ? 'flex' : 'none' ?>;">
                    <?php if ($i === 1): ?>
                        <select name="linekey_1_type" style="background-color: #e9ecef; pointer-events: none;" readonly tabindex="-1">
                            <option value="15" selected>Line (15)</option>
                        </select>
                        <input type="text" name="linekey_1_value" placeholder="Extension Number" value="<?= htmlspecialchars($formData["linekey_1_value"] ?? '') ?>" readonly style="background-color: #e9ecef;">
                        <input type="text" name="linekey_1_label" placeholder="Extension Name" value="<?= htmlspecialchars($formData["linekey_1_label"] ?? '') ?>" readonly style="background-color: #e9ecef;">
                        <input type="text" name="linekey_1_pickup" placeholder="Pickup (**)" value="<?= htmlspecialchars($formData["linekey_1_pickup"] ?? '') ?>" readonly style="background-color: #e9ecef;">
                    <?php else: ?>
                        <select name="linekey_<?= $i ?>_type">
                            <?php 
                            $current_type = $formData["linekey_{$i}_type"] ?? '16';
                            foreach ($dss_key_types as $k_code => $k_label): 
                            ?>
                                <option value="<?= $k_code ?>" <?= ($current_type == $k_code) ? 'selected' : '' ?>><?= $k_label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="linekey_<?= $i ?>_value" placeholder="Line Key <?= $i ?> Extension" value="<?= htmlspecialchars($formData["linekey_{$i}_value"] ?? '') ?>">
                        <input type="text" name="linekey_<?= $i ?>_label" placeholder="Label" value="<?= htmlspecialchars($formData["linekey_{$i}_label"] ?? '') ?>">
                        <input type="text" name="linekey_<?= $i ?>_pickup" placeholder="Pickup (**)" value="<?= htmlspecialchars($formData["linekey_{$i}_pickup"] ?? '**') ?>">
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
            </div>

            <h3 class="gen-section-title">Memory Keys BLF Settings (Physical/Expansion)</h3>
            <label>Number of Memory Key Slots:</label>
            <select id="select_memkey_count" name="memkey_count" class="gen-full-width" onchange="updateMemkeyVisibility(this.value)">
                <option value="0" <?= (0 == $max_memkeys) ? 'selected' : '' ?>>0 Slots (Disabled)</option>
                <?php for ($k = 1; $k <= 180; $k++): ?>
                    <option value="<?= $k ?>" <?= ($k == $max_memkeys) ? 'selected' : '' ?>><?= $k ?> Slots</option>
                <?php endfor; ?>
            </select>

            <div style="margin-top:10px;">
            <?php for ($i = 1; $i <= 180; $i++): ?>
                <div id="memkey_row_<?= $i ?>" class="gen-key-row" style="display: <?= ($i <= $max_memkeys) ? 'flex' : 'none' ?>;">
                    <input type="text" name="memkey_<?= $i ?>_value" placeholder="Memory Key <?= $i ?> Extension" value="<?= htmlspecialchars($formData["memkey_{$i}_value"] ?? '') ?>">
                    <input type="text" name="memkey_<?= $i ?>_pickup" placeholder="Pickup Value" value="<?= htmlspecialchars($formData["memkey_{$i}_pickup"] ?? '**') ?>">
                </div>
            <?php endfor; ?>
            </div>

            <h3 class="gen-section-title">Customizations (Ringtone & Logo)</h3>
            <div class="gen-key-row" style="margin-top:10px;">
                <div>
                    <label>Select Existing Ringtone File:</label>
                    <div style="display: flex; gap: 5px;">
                        <select id="select_ringtone_file" name="ringtone_file" class="gen-full-width">
                            <option value="">None / No File Selected</option>
                            <?php foreach ($ringtone_filenames as $r_file): ?>
                                <option value="<?= $r_file ?>" <?= ($formData['ringtone_file'] === $r_file) ? 'selected' : '' ?>><?= $r_file ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="gen-btn-danger" style="margin-top:0;" onclick="confirmDeleteFile('select_ringtone_file', 'ringtone')">Delete</button>
                    </div>
                </div>
                <div>
                    <label>Upload New Ringtone to /PhoneSettings/ringtones/:</label>
                    <input type="file" name="ringtone_upload" class="gen-full-width" accept=".wav,.mp3">
                </div>
            </div>

            <div class="gen-key-row" style="margin-top:10px;">
                <div>
                    <label>Select Existing Wallpaper / Logo File:</label>
                    <div style="display: flex; gap: 5px;">
                        <select id="select_logo_file" name="logo_file" class="gen-full-width">
                            <option value="">Disabled (mode = 0)</option>
                            <option value="system" <?= ($formData['logo_file'] === 'system') ? 'selected' : '' ?>>System Logo (mode = 1)</option>
                            <?php foreach ($logo_filenames as $l_file): ?>
                                <option value="<?= $l_file ?>" <?= ($formData['logo_file'] === $l_file) ? 'selected' : '' ?>><?= $l_file ?> (mode = 2)</option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="gen-btn-danger" style="margin-top:0;" onclick="confirmDeleteFile('select_logo_file', 'logo')">Delete</button>
                    </div>
                </div>
                <div>
                    <label>Upload New Wallpaper to /PhoneSettings/logo/:</label>
                    <input type="file" name="logo_upload" class="gen-full-width" accept=".dob,.jpg,.png,.bmp">
                </div>
            </div>

            <h3 class="gen-section-title">Template Custom Key / Value Additions</h3>
            <label>Add raw Yealink configuration flags for this template (One per line):</label>
            <textarea name="custom_inputs" class="gen-textarea"><?= htmlspecialchars($formData['custom_inputs']) ?></textarea>

            <?php if (!empty($generated_template_cfg)): ?>
                <h3 class="gen-section-title">Generated Template Output</h3>
                <textarea readonly class="gen-textarea" style="height:250px;"><?= htmlspecialchars($generated_template_cfg) ?></textarea>
            <?php endif; ?>

            <br>
            <button type="submit" name="save_template" class="gen-btn" style="background: #28a745;">Save Template to /tftpboot/</button>
        </form>
    </div>

    <!-- TAB 3: DEVICE MANAGER -->
    <div id="tab_devices" class="gen-tab-content <?= ($formData['active_tab'] === 'tab_devices') ? 'active' : '' ?>">
        <form id="device_manager_form" method="POST">
            <input type="hidden" id="device_active_tab_field" name="active_tab" value="tab_devices">
            <input type="hidden" id="device_action_input" name="device_action" value="">
            <input type="hidden" id="single_ext_input" name="single_ext" value="">
            <input type="hidden" id="single_mac_input" name="single_mac" value="">

            <div style="display:flex; justify-space-between; align-items:center;">
                <h3>Registered Extensions & Devices</h3>
                <div style="display:flex; gap:10px; align-items:center;">
                    <button type="button" class="gen-btn" style="margin-top:0; background:#28a745;" onclick="openManualAddModal()">+ Manually Add Device</button>
                    <button type="button" class="gen-btn" style="margin-top:0; background:#17a2b8;" onclick="openScanModal()">Scan Subnet for New Phones</button>
                </div>
            </div>

            <table class="oss-table">
                <thead>
                    <tr>
                        <th style="width: 50px; text-align:center;"><input type="checkbox" onclick="toggleSelectAllPhones(this)"></th>
                        <th>MAC Address</th>
                        <th>IP Address</th>
                        <th>Brand</th>
                        <th>Model</th>
                        <th>Template</th>
                        <th>Assigned Extension</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($managed_devices)): ?>
                        <tr><td colspan="7" style="text-align:center; color:#777;">No configured device files found in /tftpboot/</td></tr>
                    <?php else: ?>
                        <?php foreach ($managed_devices as $dev): 
                            $clean_ext = preg_replace('/[^0-9]/', '', $dev['ext']);
                            $is_online = !empty($clean_ext) && isset($online_exts[$clean_ext]);
                            $status_class = $is_online ? 'online' : 'offline';
                            $status_title = $is_online ? "Extension {$dev['ext']} (Online)" : "Extension {$dev['ext']} (Offline / Unregistered)";
                        ?>
                            <tr>
                                <td style="text-align:center;">
                                    <div style="display:flex; align-items:center; justify-content:center; gap:8px;">
                                        <button type="button" 
                                                class="oss-btn-icon <?= $status_class ?>" 
                                                title="Rebuild Configuration & Option to Reboot <?= htmlspecialchars($status_title) ?>" 
                                                onclick="openSingleRebuildModal('<?= htmlspecialchars($dev['mac']) ?>', '<?= htmlspecialchars($dev['model']) ?>', '<?= htmlspecialchars($dev['template']) ?>')">&#x23FB;</button>
                                        <input type="checkbox" name="selected_phones[]" class="phone_checkbox" value="<?= htmlspecialchars($dev['mac']) ?>">
                                    </div>
                                </td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:5px;">
                                        <input type="text" 
                                               id="mac_input_<?= htmlspecialchars($dev['mac']) ?>" 
                                               name="edited_mac[<?= htmlspecialchars($dev['mac']) ?>]" 
                                               value="<?= htmlspecialchars($dev['mac']) ?>" 
                                               readonly 
                                               style="padding:4px; font-weight:bold; width:120px; font-family:monospace; text-transform:lowercase; border-radius:4px; border:1px solid #ccc; background-color:#e9ecef;">
                                        
                                        <button type="button" 
                                                title="Edit MAC Address" 
                                                onclick="enableMacEdit('<?= htmlspecialchars($dev['mac']) ?>')" 
                                                style="background:none; border:none; cursor:pointer; padding:2px 4px; display:inline-flex; align-items:center;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#555" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($dev['ip'] !== 'Unknown / Offline'): ?>
                                        <a href="http://<?= htmlspecialchars($dev['ip']) ?>" target="_blank" style="text-decoration:none; font-weight:bold; color:#007bff;"><?= htmlspecialchars($dev['ip']) ?></a>
                                    <?php else: ?>
                                        <span style="color:#888; font-style:italic;"><?= htmlspecialchars($dev['ip']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>Yealink</td>
                                <td><?= htmlspecialchars($dev['model']) ?></td>
                                <td>
                                    <select id="phone_tpl_<?= htmlspecialchars($dev['mac']) ?>" name="phone_template[<?= htmlspecialchars($dev['mac']) ?>]" style="padding:4px; border-radius:4px; border:1px solid #ccc;">
                                        <option value="" <?= empty($dev['template']) ? 'selected' : '' ?>>-- None --</option>
                                        <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                                            <option value="<?= htmlspecialchars($tpl_file) ?>" <?= ($dev['template'] === $tpl_file) ? 'selected' : '' ?>><?= htmlspecialchars($tpl_label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select name="phone_extension[<?= htmlspecialchars($dev['mac']) ?>]" style="padding:4px; border-radius:4px; border:1px solid #ccc;">
                                        <option value="">-- Unassigned --</option>
                                        <?php foreach ($all_extensions as $ext_id => $ext_data): ?>
                                            <option value="<?= $ext_id ?>" <?= ($dev['ext'] == $ext_id) ? 'selected' : '' ?>><?= $ext_id ?> - <?= htmlspecialchars($ext_data['display_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="oss-action-card">
                <h4>Selected Phone(s) Options</h4>
                <div class="oss-action-line">
                    <button type="button" class="gen-btn-danger" style="margin:0;" onclick="triggerDeviceAction('delete_selected')">Delete</button>
                    <span>Delete Selected Phones</span>
                </div>
                <div class="oss-action-line" style="flex-wrap: wrap; gap: 10px;">
                    <button type="button" class="gen-btn" style="margin:0; background:#28a745;" onclick="triggerDeviceAction('rebuild_selected')">Rebuild Selected</button>
                    
                    <select name="bulk_selected_template" style="padding: 6px; border-radius: 4px; border: 1px solid #ccc;">
                        <option value="">-- Use Assigned Individual Templates --</option>
                        <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                            <option value="<?= htmlspecialchars($tpl_file) ?>"><?= htmlspecialchars($tpl_label) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <span>
                        (<label style="display:inline; font-weight:normal;"><input type="checkbox" name="auto_provision_selected" checked> Push Auto Provision</label>)
                        (<label style="display:inline; font-weight:normal;"><input type="checkbox" name="reboot_selected"> Reboot Phones</label>)
                    </span>
                </div>

                <h4 style="margin-top:20px;">Global Phone Options</h4>
                <div class="oss-action-line">
                    <button type="button" class="gen-btn" style="margin:0; background:#17a2b8;" onclick="triggerDeviceAction('rebuild_all')">Rebuild All</button>
                    <span>
                        Rebuild Configs for All Phones 
                        (<label style="display:inline; font-weight:normal;"><input type="checkbox" name="auto_provision_all" checked> Push Auto Provision</label>)
                        (<label style="display:inline; font-weight:normal;"><input type="checkbox" name="reboot_phones"> Reboot Phones</label>)
                    </span>
                </div>

                <div class="oss-action-line" style="flex-wrap: wrap; gap: 10px; margin-top: 15px;">
                    <button type="button" class="gen-btn" style="margin:0; background:#6c757d;" onclick="triggerDeviceAction('rebuild_filtered')">Rebuild Filtered Group</button>
                    
                    <?php 
                    $registered_models = array_unique(array_filter(array_column($managed_devices, 'model')));
                    sort($registered_models);
                    ?>

                    <select name="global_filter_model" style="padding: 6px; border-radius: 4px; border: 1px solid #ccc;">
                        <option value="">-- Select Model --</option>
                        <?php foreach ($registered_models as $registered_m): ?>
                            <option value="<?= htmlspecialchars($registered_m) ?>"><?= htmlspecialchars($registered_m) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="global_filter_template" style="padding: 6px; border-radius: 4px; border: 1px solid #ccc;">
                        <option value="">-- Select Template --</option>
                        <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                            <option value="<?= htmlspecialchars($tpl_file) ?>"><?= htmlspecialchars($tpl_label) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <span>
                        (<label style="display:inline; font-weight:normal;"><input type="checkbox" name="auto_provision_filtered" checked> Push Auto Provision</label>)
                        (<label style="display:inline; font-weight:normal;"><input type="checkbox" name="reboot_filtered"> Reboot Phones</label>)
                    </span>
                </div>
            </div>
        </form>
    </div>
</div>