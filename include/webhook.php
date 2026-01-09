<?php
if (!defined('ABSPATH')) exit;

/**
 * 1. FUNCIÓN DE ENVÍO MENSAJES (ROBUSTO)
 */
function sms_send_msg($to, $msg) {
    $url = "https://whatsapp.smsenlinea.com/api/send/whatsapp";
    $to = preg_replace('/[^0-9]/', '', $to); 

    if (function_exists('mb_convert_encoding')) {
        $msg = mb_convert_encoding($msg, 'UTF-8', 'auto');
    }

    $data = [
        "secret"    => get_option('sms_api_secret'),
        "account"   => get_option('sms_account_id'),
        "recipient" => $to,
        "type"      => "text",
        "message"   => $msg,
        "priority"  => 1
    ];

    $response = wp_remote_post($url, [
        'body'      => $data, 
        'timeout'   => 20,
        'blocking'  => true, 
        'headers'   => ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8']
    ]);
    
    if (is_wp_error($response)) {
        error_log('SMS Error: ' . $response->get_error_message());
    }
}

/**
 * 2. NOTIFICAR AL CLIENTE (MATCH)
 */
function sms_notify_client_match($lead_id, $provider_user_id) {
    global $wpdb;
    $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE id=$lead_id");
    $prov = get_userdata($provider_user_id);

    if (!$lead || !$prov) return;

    $p_company = get_user_meta($provider_user_id, 'sms_commercial_name', true) ?: ($prov->billing_company ?: 'Empresa Verificada');
    $p_advisor = get_user_meta($provider_user_id, 'sms_advisor_name', true) ?: $prov->display_name;
    $p_phone = preg_replace('/[^0-9]/', '', get_user_meta($provider_user_id, 'sms_whatsapp_notif', true) ?: $prov->billing_phone);
    $p_email = $prov->user_email;
    $profile_link = site_url("/perfil-proveedor?uid=" . $provider_user_id);

    $msg = "👋 Hola {$lead->client_name}.\n\n✅ *¡Proveedor Asignado!*\nLa empresa *{$p_company}* ha aceptado tu solicitud.\n\n👤 *Asesor:* $p_advisor\n📞 *WhatsApp:* +$p_phone\n📧 *Email:* $p_email\n\n🔗 *Ver Perfil:* $profile_link";
    sms_send_msg($lead->client_phone, $msg);

    $subject = "✅ Proveedor Asignado: $p_company";
    $body = "<h3>¡Buenas noticias!</h3><p>La empresa <strong>$p_company</strong> te contactará.</p><ul><li>Asesor: $p_advisor</li><li>WhatsApp: +$p_phone</li><li>Email: $p_email</li></ul><p><a href='$profile_link' style='background:#007cba; color:#fff; padding:10px; border-radius:5px; text-decoration:none;'>Ver Perfil de la Empresa</a></p>";
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    wp_mail($lead->client_email, $subject, $body, $headers);
}

/**
 * 3. NOTIFICACIÓN A PROVEEDORES
 */
add_action('sms_notify_providers', 'sms_smart_notification', 10, 1);

function sms_smart_notification($lead_id) {
    global $wpdb;
    $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE id=$lead_id");
    if (!$lead) return;

    $base_url = site_url('/oportunidad'); 
    $shop_url = site_url('/tienda');
    $users = get_users();
    
    $client_type_str = ($lead->client_company === 'Particular' || empty($lead->client_company)) ? '👤 Persona Natural' : '🏢 Empresa';
    
    $prio_emoji = '🟢'; $prio_txt = 'Normal';
    if(isset($lead->priority)) {
        if($lead->priority == 'Urgente') { $prio_emoji = '🔥'; $prio_txt = 'URGENTE'; }
        if($lead->priority == 'Muy Urgente') { $prio_emoji = '🚨'; $prio_txt = 'MUY URGENTE'; }
    }

    $deadline_txt = $lead->deadline ? date('d/M', strtotime($lead->deadline)) : 'Lo antes posible';
    $quotas_txt = $lead->max_quotas ? $lead->max_quotas : 3;

    foreach($users as $u) {
        $status = get_user_meta($u->ID, 'sms_phone_status', true);
        if($status != 'verified') continue;

        $subs = get_user_meta($u->ID, 'sms_approved_services', true);
        
        if(is_array($subs) && in_array($lead->service_page_id, $subs)) {
            $phone = get_user_meta($u->ID, 'sms_whatsapp_notif', true) ?: get_user_meta($u->ID, 'billing_phone', true);
            $email = $u->user_email;

            if($phone) {
                $balance = (int) get_user_meta($u->ID, 'sms_wallet_balance', true);
                $cost = (int) $lead->cost_credits;
                $desc_short = mb_substr($lead->requirement, 0, 100) . '...';
                
                $header = "🔔 *Nueva Oportunidad #$lead_id* [$prio_emoji $prio_txt]";
                $details = "👉 Tipo: *$client_type_str*\n📅 Cierre: *$deadline_txt*\n📉 Cupos: Busca *$quotas_txt ofertas*";
                $location = "📍 {$lead->city}";

                if ($balance >= $cost) {
                    $link = $base_url . "?lid=" . $lead_id;
                    $msg = "$header\n$details\n$location\n📝 $desc_short\n\n💰 Saldo: *$balance cr* | Costo: *$cost cr*\n\n👉 Responde *ACEPTO $lead_id* para comprar.\n👉 Detalles: $link";
                } else {
                    $msg = "$header\n$details\n$location\n⚠️ *Saldo Insuficiente* ($balance cr).\n📝 $desc_short\n\n👉 Recarga aquí: $shop_url";
                }
                
                sms_send_msg($phone, $msg);
            }
        }
    }
}

/**
 * 4. WEBHOOK: CEREBRO DE INTERACCIÓN
 */
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

    $e_check = "\xE2\x9C\x85"; $e_lock = "\xF0\x9F\x94\x90"; $e_x = "\xE2\x9D\x8C"; $e_mail = "\xE2\x9C\x89";

    if(isset($params['type']) && $params['type'] == 'whatsapp') {
        $msg_body = trim(strtoupper($params['data']['message'])); 
        $phone_sender = preg_replace('/[^0-9]/', '', $params['data']['phone']); 
        
        if(strlen($phone_sender) < 7) return new WP_REST_Response('Invalid Phone', 400);

        // Idempotencia (3 segundos para pruebas)
        $transient_key = 'sms_lock_' . md5($phone_sender . $msg_body);
        if (get_transient($transient_key)) return new WP_REST_Response('Ignored', 200);
        set_transient($transient_key, true, 3);

        // Búsqueda flexible (últimos 10 dígitos)
        $search_term = (strlen($phone_sender) > 10) ? substr($phone_sender, -10) : $phone_sender;

        // A. CONFIRMACIÓN
        if ($msg_body === 'CONFIRMADO') {
            $users = get_users(['meta_query' => [['key' => 'sms_whatsapp_notif', 'value' => $search_term, 'compare' => 'LIKE']], 'number' => 1]);
            
            if (!empty($users)) {
                $u = $users[0];
                update_user_meta($u->ID, 'sms_phone_status', 'verified');
                
                $bonus = (int) get_option('sms_welcome_bonus', 0);
                $given = get_user_meta($u->ID, '_sms_bonus_given', true);
                $bonus_msg = "";
                if ($bonus > 0 && !$given) {
                    $curr = (int) get_user_meta($u->ID, 'sms_wallet_balance', true);
                    update_user_meta($u->ID, 'sms_wallet_balance', $curr + $bonus);
                    update_user_meta($u->ID, '_sms_bonus_given', 'yes');
                    $bonus_msg = "\n🎁 *¡Recibiste $bonus créditos de regalo!*";
                }
                sms_send_msg($phone_sender, "$e_check *¡Notificaciones Activadas!*\nTu cuenta de proveedor está lista.$bonus_msg");
            }
        }
        
        // B. COMPRA (ACEPTO)
        elseif (preg_match('/^ACEPTO\s+(\d+)/i', $msg_body, $matches)) {
            $lead_id = intval($matches[1]);
            $users = get_users(['meta_query' => [['key' => 'sms_whatsapp_notif', 'value' => $search_term, 'compare' => 'LIKE']], 'number' => 1]);
            
            if(!empty($users)) {
                $u = $users[0];
                
                if(get_user_meta($u->ID, 'sms_phone_status', true) != 'verified') {
                    sms_send_msg($phone_sender, "⚠️ Tu número no está verificado. Responde *CONFIRMADO* para activarlo.");
                    return new WP_REST_Response('OK', 200);
                }

                $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE id=$lead_id");
                $approved_services = get_user_meta($u->ID, 'sms_approved_services', true) ?: [];
                
                if($lead && in_array($lead->service_page_id, $approved_services)) {
                    $already = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}sms_lead_unlocks WHERE lead_id=$lead_id AND provider_user_id={$u->ID}");
                    $unlocks_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sms_lead_unlocks WHERE lead_id=$lead_id");
                    $max_quotas = (int) $lead->max_quotas ?: 3; 

                    if (!$already && $unlocks_count >= $max_quotas) {
                        sms_send_msg($phone_sender, "⛔ *Cotización Cerrada*\nSe alcanzó el límite de proveedores.");
                        return new WP_REST_Response('Full', 200);
                    }

                    $bal = (int) get_user_meta($u->ID, 'sms_wallet_balance', true);
                    
                    if($already || $bal >= $lead->cost_credits) {
                        if(!$already) {
                            update_user_meta($u->ID, 'sms_wallet_balance', $bal - $lead->cost_credits);
                            // GUARDAR EL COSTO AQUÍ
                            $wpdb->insert("{$wpdb->prefix}sms_lead_unlocks", [
                                'lead_id' => $lead_id, 
                                'provider_user_id' => $u->ID,
                                'credits_spent' => $lead->cost_credits // <--- NUEVO
                            ]);
                            if(function_exists('sms_notify_client_match')) sms_notify_client_match($lead_id, $u->ID);
                        }
                        
                        $c_type = ($lead->client_company === 'Particular' || empty($lead->client_company)) ? '👤 Persona' : '🏢 Empresa (' . $lead->client_company . ')';
                        $prio_txt = isset($lead->priority) ? $lead->priority : 'Normal';
                        $client_phone = str_replace('+','', $lead->client_phone);
                        $info = "$e_check *Datos Lead #$lead_id*\n\n👉 $c_type\n⚠️ Prioridad: $prio_txt\n👤 {$lead->client_name}\n📞 +$client_phone\n📧 {$lead->client_email}\n📝 {$lead->requirement}";
                        sms_send_msg($phone_sender, $info);
                    } else {
                        sms_send_msg($phone_sender, "$e_x Saldo insuficiente ($bal cr).");
                    }
                }
            }
        }
        
        // C. VERIFICACIÓN (PIDE WHATSAPP)
        elseif (strpos($msg_body, 'WHATSAPP') !== false) {
            $sql = "SELECT * FROM {$wpdb->prefix}sms_leads 
                    WHERE REPLACE(REPLACE(client_phone, ' ', ''), '+', '') LIKE '%$search_term' 
                    AND is_verified = 0 
                    ORDER BY created_at DESC LIMIT 1";
            $lead = $wpdb->get_row($sql);
            if ($lead) {
                $otp_key = 'sms_otp_sent_' . $phone_sender;
                if (!get_transient($otp_key)) {
                    sms_send_msg($phone_sender, "$e_lock Tu código de verificación es: *{$lead->verification_code}*");
                    set_transient($otp_key, true, 5);
                }
            }
        }
        
        // D. VERIFICACIÓN (PIDE EMAIL)
        elseif (strpos($msg_body, 'EMAIL') !== false) {
            $sql = "SELECT * FROM {$wpdb->prefix}sms_leads 
                    WHERE REPLACE(REPLACE(client_phone, ' ', ''), '+', '') LIKE '%$search_term' 
                    AND is_verified = 0 
                    ORDER BY created_at DESC LIMIT 1";
            $lead = $wpdb->get_row($sql);
            if ($lead && is_email($lead->client_email)) {
                $mail_key = 'sms_mail_sent_' . $phone_sender;
                if (!get_transient($mail_key)) {
                    $subject = "Código de Verificación";
                    $body = "<h3>Tu código de verificación es:</h3><h1 style='color:#007cba;'>{$lead->verification_code}</h1><p>Úsalo para validar tu solicitud de cotización.</p>";
                    $headers = ['Content-Type: text/html; charset=UTF-8'];
                    wp_mail($lead->client_email, $subject, $body, $headers);
                    sms_send_msg($phone_sender, "$e_mail Hemos enviado el código a tu correo: {$lead->client_email}");
                    set_transient($mail_key, true, 5); 
                }
            }
        }
    }
    return new WP_REST_Response('OK', 200);
}
