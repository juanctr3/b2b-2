<?php
/**
 * Plugin Name: SMS B2B Platform (Final)
 * Description: Sistema de Licitaciones, Marketplace de Leads y Pagos por Créditos.
 * Version: 7.1
 * Author: Tu Asistente IA
 */

if (!defined('ABSPATH')) exit;

define('SMS_PLUGIN_DIR', plugin_dir_path(__FILE__));

// CORRECCIÓN: Usamos 'include/' en singular para coincidir con tu estructura de carpetas
require_once SMS_PLUGIN_DIR . 'include/db-init.php';
require_once SMS_PLUGIN_DIR . 'include/admin-panel.php';
require_once SMS_PLUGIN_DIR . 'include/frontend-logic.php';
require_once SMS_PLUGIN_DIR . 'include/provider-area.php';
require_once SMS_PLUGIN_DIR . 'include/webhook.php';
require_once SMS_PLUGIN_DIR . 'include/unlock-logic.php';

register_activation_hook(__FILE__, 'sms_install_tables');
