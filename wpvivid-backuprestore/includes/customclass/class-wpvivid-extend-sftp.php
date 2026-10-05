<?php

if (!defined('WPVIVID_PLUGIN_DIR')){
    die;
}
include_once WPVIVID_PLUGIN_DIR.'/vendor/autoload.php';

if (class_exists('\\WPvividphpseclib3\\Net\\SFTP') && !class_exists('WPvivid_Net_SFTP')) {
    class WPvivid_Net_SFTP extends \WPvividphpseclib3\Net\SFTP {}
    return;
}