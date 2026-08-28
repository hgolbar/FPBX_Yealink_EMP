<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

// 1. Directory Setup & Permissions
$tftp_dir = "/tftpboot/";
$logo_dir = "/var/www/html/PhoneSettings/logo/";
$ringtone_dir = "/var/www/html/PhoneSettings/ringtones/";

foreach ([$logo_dir, $ringtone_dir] as $dir) {
    if (!file_exists($dir)) {
        @mkdir($dir, 0775, true);
        @chown($dir, 'asterisk');
        @chgrp($dir, 'asterisk');
    }
}

if (!file_exists($tftp_dir)) {
    @mkdir($tftp_dir, 0775, true);
    @chown($tftp_dir, 'asterisk');
    @chgrp($tftp_dir, 'asterisk');
}

// 2. Ensure Apache Symlink Exists for HTTP Provisioning
$web_symlink = "/var/www/html/tftpboot";
if (!file_exists($web_symlink)) {
    @symlink($tftp_dir, $web_symlink);
    @chown($web_symlink, 'asterisk');
}

// 3. Enable Directory Indexing via .htaccess
$htaccess_file = "/var/www/html/PhoneSettings/.htaccess";
if (!file_exists($htaccess_file)) {
    @file_put_contents($htaccess_file, "Options +Indexes\n");
    @chown($htaccess_file, 'asterisk');
    @chgrp($htaccess_file, 'asterisk');
    @chmod($htaccess_file, 0644);
}

// 4. Add Yealink Reboot / Check-Sync Stanzas to Asterisk Custom Configs
$notify_stanzas = <<<EOT

; --- Added by Yealink Endpoint Manager Module ---
[check-sync]
Event=>check-sync;reboot=false

[reboot-yealink]
Event=>check-sync;reboot=true

[reboot]
Event=>check-sync;reboot=true
EOT;

$files_to_update = [
    '/etc/asterisk/sip_notify_custom.conf',
    '/etc/asterisk/pjsip_notify_custom.conf'
];

$needs_asterisk_reload = false;

foreach ($files_to_update as $file) {
    if (file_exists(dirname($file))) {
        if (!file_exists($file)) {
            @file_put_contents($file, "");
            @chmod($file, 0664);
            @chown($file, 'asterisk');
            @chgrp($file, 'asterisk');
        }

        $current_content = file_get_contents($file);

        if (strpos($current_content, '[check-sync]') === false || strpos($current_content, 'reboot=false') === false) {
            if (@file_put_contents($file, $notify_stanzas . "\n", FILE_APPEND) !== false) {
                @chown($file, 'asterisk');
                $needs_asterisk_reload = true;
            }
        }
    }
}

// Reload Asterisk NOTIFY modules if stanzas were added
if ($needs_asterisk_reload) {
    @exec("asterisk -rx 'module reload res_pjsip_notify.so' >/dev/null 2>&1");
    @exec("asterisk -rx 'module reload res_sip_notify.so' >/dev/null 2>&1");
}