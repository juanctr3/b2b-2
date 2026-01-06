<?php
if (!defined('ABSPATH')) exit;

/**
 * Funci車n Maestra de Env赤o (CORREGIDA PARA TILDES Y EMOJIS)
 */
function sms_send_msg($to, $msg) {
    $url = "https://whatsapp.smsenlinea.com/api/send/whatsapp";
    
    // 1. LIMPIEZA DE CODIFICACI車N
    // Convertimos entidades HTML (como &oacute;) a caracteres reales
    $clean_msg = html_entity_decode($msg, ENT_QUOTES | ENT_XML1, 'UTF-8');
    
    // Forzamos UTF-8 para evitar los signos ??
    if (function_exists('mb_convert_encoding')) {
        $clean_msg = mb_convert_encoding($clean_msg, 'UTF-8', 'auto');
    }

    $data = [
        "secret"    => get_option('sms_api_secret'),
        "account"   => get_option('sms_account_id'),
        "recipient" => $to,
        "type"      => "text",
        "message"   => $clean_msg,
        "priority"  => 1
    ];

    wp_remote_post($url, [
        'body'      => $data,
        'timeout'   => 15,
        'blocking'  => false,
        'headers'   => [
            'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'
        ]
    ]);
}

// ==========================================
// 1. NOTIFICACI車N A PROVEEDORES (NUEVA COTIZACI車N)
// ==========================================
add_action('sms_notify_providers', 'sms_smart_notification', 10, 1);

function sms_smart_notification($lead_id) {
    global $wpdb;
    $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE id=$lead_id");
    if (!$lead) return;

    $base_url = site_url('/oportunidad'); 
    $shop_url = site_url('/tienda');

    // DEFINICI車N DE ICONOS (HEXADECIMAL PARA EVITAR ERRORES)
    $e_bell  = "\xF0\x9F\x94\x94"; // ??
    $e_pin   = "\xF0\x9F\x93\x8D"; // ??
    $e_memo  = "\xF0\x9F\x93\x9D"; // ??
    $e_card  = "\xF0\x9F\x92\xB3"; // ??
    $e_warn  = "\xE2\x9A\xA0";     // ??
    $e_point = "\xF0\x9F\x91\x89"; // ??

    $users = get_users();
    foreach($users as $u) {
        $status = get_user_meta($u->ID, 'sms_phone_status', true);
        if($status != 'verified') continue;

        $subs = get_user_meta($u->ID, 'sms_subscribed_pages', true);
        if(is_array($subs) && in_array($lead->service_page_id, $subs)) {
            $phone = get_user_meta($u->ID, 'billing_phone', true);
            $email = $u->user_email;

            if($phone) {
                $balance = (int) get_user_meta($u->ID, 'sms_wallet_balance', true);
                $cost = (int) $lead->cost_credits;
                $desc_short = mb_substr($lead->requirement, 0, 150) . (strlen($lead->requirement)>150 ? '...' : '');
                
                if ($balance >= $cost) {
                    $link = $base_url . "?lid=" . $lead_id;
                    $msg = "$e_bell *Nueva Cotizaci車n #$lead_id*\n\n$e_pin {$lead->city}\n$e_memo $desc_short\n\n$e_card Tu Saldo: *$balance cr* | Costo: *$cost cr*\n\n$e_point Responde *ACEPTO $lead_id* para comprar ya.\n$e_point O mira detalles aqu赤: $link";
                } else {
                    $msg = "$e_bell *Nueva Cotizaci車n #$lead_id*\n\n$e_warn *Saldo Insuficiente* (Tienes $balance cr, requieres $cost cr).\n$e_memo $desc_short\n\n$e_point Recarga aqu赤: $shop_url";
                }
                sms_send_msg($phone, $msg);

                // Email (Este suele funcionar bien, pero aseguramos UTF-8)
                $headers = ['Content-Type: text/html; charset=UTF-8'];
                $subject = "Nueva Oportunidad #$lead_id";
                $body = "<h3>Solicitud en {$lead->city}</h3><p>{$lead->requirement}</p><p><a href='$link'>Ver en Web</a></p>";
                wp_mail($email, $subject, $body, $headers);
            }
        }
    }
}

// ==========================================
// 2. WEBHOOK: CEREBRO DE INTERACCI車N
// ==========================================
add_action('rest_api_init', function () {
    register_rest_route('smsenlinea/v1', '/webhook', [
        'methods' => 'POST',
        'callback' => 'sms_handle_incoming_interaction',
        'permission_callback' => '__return_true',
    ]);
});

function sms_handle_incoming_interaction($req) {
    global $wpdb;
    $params = $req->get_params();

    // DEFINICI車N DE TODOS LOS ICONOS NECESARIOS (HEX)
    $e_check = "\xE2\x9C\x85";     // ?
    $e_gift  = "\xF0\x9F\x8E\x81"; // ??
    $e_party = "\xF0\x9F\x8E\x89"; // ??
    $e_user  = "\xF0\x9F\x91\xA4"; // ??
    $e_phone = "\xF0\x9F\x93\x9E"; // ??
    $e_mail  = "\xE2\x9C\x89";     // ??
    $e_lock  = "\xF0\x9F\x94\x90"; // ??
    $e_x     = "\xE2\x9D\x8C";     // ?
    $e_build = "\xF0\x9F\x8F\xA2"; // ?? (Faltaba en la versi車n anterior)
    $e_memo  = "\xF0\x9F\x93\x9D"; // ?? (Faltaba en la versi車n anterior)

    if(isset($params['type']) && $params['type'] == 'whatsapp') {
        $msg_body = trim(strtoupper($params['data']['message'])); 
        $phone_sender = str_replace('+', '', $params['data']['phone']); 
        
        // --- EVITAR DUPLICADOS (BLOQUEO DE 60 SEGUNDOS) ---
        $transient_key = 'sms_lock_' . md5($phone_sender . $msg_body);
        if (get_transient($transient_key)) {
            return new WP_REST_Response('Duplicate Ignored', 200);
        }
        set_transient($transient_key, true, 60);

        // --- CASO 1: ACTIVAR CUENTA ("ACEPTO") ---
        if ($msg_body === 'ACEPTO') {
            $users = get_users(['meta_query' => [['key' => 'billing_phone', 'value' => $phone_sender, 'compare' => 'LIKE']], 'number' => 1]);
            
            if (!empty($users)) {
                $u = $users[0];
                update_user_meta($u->ID, 'sms_phone_status', 'verified');
                
                $bonus = (int) get_option('sms_welcome_bonus', 0);
                $given = get_user_meta($u->ID, '_sms_bonus_given', true);

                if ($bonus > 0 && !$given) {
                    $curr = (int) get_user_meta($u->ID, 'sms_wallet_balance', true);
                    update_user_meta($u->ID, 'sms_wallet_balance', $curr + $bonus);
                    update_user_meta($u->ID, '_sms_bonus_given', 'yes');
                    sms_send_msg($phone_sender, "$e_check ?Cuenta Verificada!\n$e_gift *Regalo:* Te cargamos *$bonus cr谷ditos* gratis.");
                } else {
                    sms_send_msg($phone_sender, "$e_check ?Cuenta Verificada! Ahora recibir芍s alertas.");
                }
            }
        }

        // --- CASO 2: COMPRA R芍PIDA ("ACEPTO 123") ---
        elseif (preg_match('/^ACEPTO\s+(\d+)/i', $msg_body, $matches)) {
            $lead_id = intval($matches[1]);
            $users = get_users(['meta_query' => [['key' => 'billing_phone', 'value' => $phone_sender, 'compare' => 'LIKE']], 'number' => 1]);
            
            if(!empty($users)) {
                $u = $users[0];
                $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE id=$lead_id");
                
                if($lead && $lead->status == 'approved') {
                    $already = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}sms_lead_unlocks WHERE lead_id=$lead_id AND provider_user_id={$u->ID}");
                    
                    if($already) {
                        // Mensaje de Ya Comprado
                        $info = "$e_check *Datos del Cliente:*\n\n$e_user {$lead->client_name}\n$e_phone +{$lead->client_phone}\n$e_mail {$lead->client_email}";
                        sms_send_msg($phone_sender, $info);
                    } else {
                        $bal = (int) get_user_meta($u->ID, 'sms_wallet_balance', true);
                        if($bal >= $lead->cost_credits) {
                            // PROCESO DE COMPRA
                            update_user_meta($u->ID, 'sms_wallet_balance', $bal - $lead->cost_credits);
                            $wpdb->insert("{$wpdb->prefix}sms_lead_unlocks", ['lead_id' => $lead_id, 'provider_user_id' => $u->ID]);
                            
                            // MENSAJE DE 谷XITO (Aqu赤 faltaban los iconos antes)
                            $company_name = $lead->client_company ?: 'Particular';
                            
                            $info = "$e_party *Compra Exitosa*\nNuevo saldo: ".($bal - $lead->cost_credits)."\n\nDatos:\n$e_build $company_name\n$e_user {$lead->client_name}\n$e_phone +{$lead->client_phone}\n$e_mail {$lead->client_email}\n$e_memo {$lead->requirement}";
                            
                            sms_send_msg($phone_sender, $info);
                        } else {
                            sms_send_msg($phone_sender, "$e_x Saldo insuficiente ($bal cr). Costo: {$lead->cost_credits}.");
                        }
                    }
                }
            }
        }

        // --- CASO 3: CLIENTE PIDE C車DIGO (WHATSAPP) ---
        elseif (strpos($msg_body, 'WHATSAPP') !== false) {
            $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE client_phone LIKE '%$phone_sender' AND is_verified = 0 ORDER BY created_at DESC LIMIT 1");
            
            $otp_key = 'sms_otp_lock_' . $phone_sender;
            
            if ($lead && !get_transient($otp_key)) {
                // Mensaje con tildes corregidas
                sms_send_msg($phone_sender, "$e_lock Tu c車digo de verificaci車n es: *{$lead->verification_code}*");
                set_transient($otp_key, true, 45);
            }
        }

        // --- CASO 4: CLIENTE PIDE C車DIGO (EMAIL) ---
        elseif (strpos($msg_body, 'EMAIL') !== false) {
            $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE client_phone LIKE '%$phone_sender' AND is_verified = 0 ORDER BY created_at DESC LIMIT 1");
            
            $mail_key = 'sms_mail_lock_' . $phone_sender;
            
            if ($lead && is_email($lead->client_email) && !get_transient($mail_key)) {
                $headers = ['Content-Type: text/html; charset=UTF-8'];
                wp_mail($lead->client_email, "C車digo Verificaci車n", "C車digo: <strong>{$lead->verification_code}</strong>", $headers);
                
                sms_send_msg($phone_sender, "$e_mail C車digo enviado a tu email.");
                set_transient($mail_key, true, 45); 
            }
        }
    }
    return new WP_REST_Response('OK', 200);
}