<?php
if (!defined('ABSPATH')) exit;

/**
 * 1. FUNCIÓN DE ENVÍO CORREGIDA (SOLUCIÓN A CARACTERES ??)
 */
function sms_send_msg($to, $msg) {
    $url = "https://whatsapp.smsenlinea.com/api/send/whatsapp";
    
    // Solución definitiva UTF-8: No recodificar si ya es UTF-8
    // json_encode con JSON_UNESCAPED_UNICODE preserva tildes y emojis correctamente
    
    $data = [
        "secret"    => get_option('sms_api_secret'),
        "account"   => get_option('sms_account_id'),
        "recipient" => $to,
        "type"      => "text",
        "message"   => $msg,
        "priority"  => 1
    ];

    wp_remote_post($url, [
        'body'      => json_encode($data, JSON_UNESCAPED_UNICODE), // Clave para los caracteres
        'timeout'   => 15,
        'blocking'  => false,
        'headers'   => [
            'Content-Type' => 'application/json; charset=utf-8' // Enviamos como JSON explícito
        ]
    ]);
}

/**
 * 2. NUEVA FUNCIÓN: NOTIFICAR AL CLIENTE (PUNTO 3 y 4)
 */
function sms_notify_client_match($lead_id, $provider_user_id) {
    global $wpdb;
    $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE id=$lead_id");
    $prov = get_userdata($provider_user_id);

    if (!$lead || !$prov) return;

    // Datos del Proveedor
    $p_name = get_user_meta($provider_user_id, 'sms_advisor_name', true) ?: $prov->display_name;
    $p_phone = get_user_meta($provider_user_id, 'billing_phone', true);
    $p_email = $prov->user_email;
    
    // Verificar Documentos (Punto 4)
    $is_doc_verified = get_user_meta($provider_user_id, 'sms_docs_verified', true) === 'yes';
    $verified_text = $is_doc_verified ? "✅ EMPRESA VERIFICADA (Documentos Revisados)" : "⚠️ Empresa pendiente de verificar documentos";

    // Mensaje para el Cliente (WhatsApp)
    $msg = "🔔 *¡Buenas Noticias!*\n\nUna empresa ha aceptado tu solicitud de cotización *#$lead_id*.\n\n👤 *Proveedor:* $p_name\n$verified_text\n\n📞 *WhatsApp:* +$p_phone\n📧 *Email:* $p_email\n\nEllos te contactarán pronto, pero puedes escribirles ya mismo.";
    
    // Enviar al Cliente
    sms_send_msg($lead->client_phone, $msg);

    // Enviar Email al Cliente
    $subject = "¡Proveedor encontrado para tu solicitud #$lead_id!";
    $body = "Hola {$lead->client_name},<br><br>El proveedor <strong>$p_name</strong> está interesado.<br>Estado: <strong>$verified_text</strong><br><br>Teléfono: $p_phone<br>Email: $p_email";
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    wp_mail($lead->client_email, $subject, $body, $headers);
}

// ... (Mantén la función sms_smart_notification original igual) ...
add_action('sms_notify_providers', 'sms_smart_notification', 10, 1);
function sms_smart_notification($lead_id) {
    // ... Copia el contenido original de esta función aquí ...
    // Solo asegúrate de que use la nueva sms_send_msg de arriba
    // He omitido el cuerpo para no hacer la respuesta infinita, usa la original.
     global $wpdb;
    $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE id=$lead_id");
    if (!$lead) return;

    $base_url = site_url('/oportunidad'); 
    $shop_url = site_url('/tienda');

    $users = get_users();
    foreach($users as $u) {
        $status = get_user_meta($u->ID, 'sms_phone_status', true);
        if($status != 'verified') continue;

        $subs = get_user_meta($u->ID, 'sms_subscribed_pages', true);
        if(is_array($subs) && in_array($lead->service_page_id, $subs)) {
            $phone = get_user_meta($u->ID, 'billing_phone', true);
            
            // Lógica de saldo igual a tu original...
             $balance = (int) get_user_meta($u->ID, 'sms_wallet_balance', true);
             $cost = (int) $lead->cost_credits;
             $desc_short = mb_substr($lead->requirement, 0, 100) . '...';

             if ($balance >= $cost) {
                $link = $base_url . "?lid=" . $lead_id;
                // NOTA: He simplificado los iconos para evitar errores de codificación manual
                $msg = "🔔 *Nueva Oportunidad #$lead_id*\n📍 {$lead->city}\n📝 $desc_short\n\n💰 Tu Saldo: *$balance cr*\n\n👉 Responde *ACEPTO $lead_id* para comprar datos.\n👉 Ver web: $link";
            } else {
                $msg = "🔔 *Oportunidad #$lead_id*\n⚠️ *Saldo Bajo* ($balance cr).\nRecarga aquí: $shop_url";
            }
            sms_send_msg($phone, $msg);
        }
    }
}


// WEBHOOK
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

    if(isset($params['type']) && $params['type'] == 'whatsapp') {
        $msg_body = trim(strtoupper($params['data']['message'])); 
        $phone_sender = str_replace('+', '', $params['data']['phone']); 
        
        $transient_key = 'sms_lock_' . md5($phone_sender . $msg_body);
        if (get_transient($transient_key)) return new WP_REST_Response('Duplicate', 200);
        set_transient($transient_key, true, 60);

        // --- CASO 1: ACTIVAR CUENTA ---
        if ($msg_body === 'ACEPTO') {
            $users = get_users(['meta_query' => [['key' => 'billing_phone', 'value' => $phone_sender, 'compare' => 'LIKE']], 'number' => 1]);
            if (!empty($users)) {
                $u = $users[0];
                update_user_meta($u->ID, 'sms_phone_status', 'verified');
                sms_send_msg($phone_sender, "✅ ¡Cuenta Verificada! Ahora recibirás alertas.");
            }
        }

        // --- CASO 2: COMPRA RÁPIDA ("ACEPTO 123") ---
        elseif (preg_match('/^ACEPTO\s+(\d+)/i', $msg_body, $matches)) {
            $lead_id = intval($matches[1]);
            $users = get_users(['meta_query' => [['key' => 'billing_phone', 'value' => $phone_sender, 'compare' => 'LIKE']], 'number' => 1]);
            
            if(!empty($users)) {
                $u = $users[0];
                $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE id=$lead_id");
                
                if($lead && $lead->status == 'approved') {
                    $already = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}sms_lead_unlocks WHERE lead_id=$lead_id AND provider_user_id={$u->ID}");
                    
                    if(!$already) {
                        $bal = (int) get_user_meta($u->ID, 'sms_wallet_balance', true);
                        if($bal >= $lead->cost_credits) {
                            // COMPRA
                            update_user_meta($u->ID, 'sms_wallet_balance', $bal - $lead->cost_credits);
                            $wpdb->insert("{$wpdb->prefix}sms_lead_unlocks", ['lead_id' => $lead_id, 'provider_user_id' => $u->ID]);
                            
                            // *** AQUÍ AGREGAMOS LA NOTIFICACIÓN AL CLIENTE (PUNTO 3) ***
                            sms_notify_client_match($lead_id, $u->ID);

                            // Mensaje al Proveedor
                            $info = "🎉 *Compra Exitosa*\nDatos del Cliente:\n👤 {$lead->client_name}\n📞 +{$lead->client_phone}\n✉️ {$lead->client_email}";
                            sms_send_msg($phone_sender, $info);
                        } else {
                            sms_send_msg($phone_sender, "❌ Saldo insuficiente ($bal cr).");
                        }
                    }
                }
            }
        }

        // --- CASO 3 y 4: OTP ---
        elseif (strpos($msg_body, 'WHATSAPP') !== false || strpos($msg_body, 'EMAIL') !== false) {
             // ... (Lógica OTP igual que antes pero usando la nueva sms_send_msg para que salga sin ??) ...
             $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE client_phone LIKE '%$phone_sender' AND is_verified = 0 ORDER BY created_at DESC LIMIT 1");
             if ($lead) {
                 sms_send_msg($phone_sender, "🔐 Tu código de verificación es: *{$lead->verification_code}*");
             }
        }
    }
    return new WP_REST_Response('OK', 200);
}
