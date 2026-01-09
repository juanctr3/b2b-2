<?php
if (!defined('ABSPATH')) exit;

function sms_install_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // 1. Leads (Cotizaciones)
    $sql_leads = "CREATE TABLE {$wpdb->prefix}sms_leads (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        country varchar(50),
        city varchar(100),
        client_name varchar(100),
        client_company varchar(100),
        client_phone varchar(20),
        client_email varchar(100),
        service_page_id mediumint(9), 
        requirement text,
        priority varchar(50) DEFAULT 'Normal', 
        deadline date DEFAULT NULL,
        verification_code varchar(10),
        is_verified tinyint(1) DEFAULT 0,
        status varchar(20) DEFAULT 'pending',
        cost_credits int(5) DEFAULT 0,
        max_quotas int(5) DEFAULT 3, 
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    // 2. Unlocks (Historial) - SE AGREGA 'credits_spent'
    $sql_unlocks = "CREATE TABLE {$wpdb->prefix}sms_lead_unlocks (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        lead_id mediumint(9) NOT NULL,
        provider_user_id mediumint(9) NOT NULL,
        credits_spent int(9) DEFAULT 0, 
        unlocked_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    // 3. Solicitudes
    $sql_requests = "CREATE TABLE {$wpdb->prefix}sms_service_requests (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        provider_user_id mediumint(9) NOT NULL,
        requested_service varchar(255) NOT NULL,
        status varchar(20) DEFAULT 'pending',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    dbDelta($sql_leads);
    dbDelta($sql_unlocks);
    dbDelta($sql_requests);
}
