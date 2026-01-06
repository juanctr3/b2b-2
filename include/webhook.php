<?php
if (!defined('ABSPATH')) exit;

/**
 * 1. FUNCIÓN DE ENVÍO MAESTRA
 * Restaurada a formato Form-Urlencoded (Compatible con la API)
 * Con corrección de codificación UTF-8 para evitar "???"
 */
function sms_send_msg($to, $msg) {
    $url = "https://whatsapp.smsenlinea.com/api/send/whatsapp";
    
    // CORRECCIÓN DE CARACTERES:
    // Aseguramos que el mensaje sea UTF-8 puro antes de enviarlo.
    // Esto arregla los tildes y emojis sin romper la compatibilidad de la API.
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

    // Enviamos como array estándar (WordPress lo convierte a x-www-form-urlencoded)
    // Esto es lo que espera tu proveedor de SMS.
    wp_remote_post($url, [
        'body'      => $data,
        'timeout'   => 15,
        'blocking'  => false,
        'headers'   => [
            'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'
        ]
    ]);
}

/**
 * 2. NOTIFICAR AL CLIENTE (MATCH)
 */
function sms_notify_client_match($lead_id, $provider_user_id) {
    global $wpdb;
    $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE id=$lead_id");
    $prov = get_userdata($provider_user_id);

    if (!$lead || !$prov) return;

    $p_name = get_user_meta($provider_user_id, 'sms_advisor_name', true) ?: $prov->display_name;
    $p_phone = get_user_meta($provider_user_id, 'billing_phone', true);
    $p_email = $prov->user_email;
    
    $is_doc_verified = get_user_meta($provider_user_id, 'sms_docs_verified', true) === 'yes';
    $verified_text = $is_doc_verified ? "✅ EMPRESA VERIFICADA (Documentos Revisados)" : "⚠️ Empresa pendiente de verificar documentos";

    $msg = "🔔 *¡Buenas Noticias!*\n\nUna empresa ha aceptado tu solicitud de cotización *#$lead_id*.\n\n👤 *Proveedor:* $p_name\n$verified_text\n\n📞 *WhatsApp:* +$p_phone\n📧 *Email:* $p_email\n\nEllos te contactarán pronto.";
    
    sms_send_msg($lead->client_phone, $msg);

    // Email
    $subject = "¡Proveedor encontrado para tu solicitud #$lead_id!";
    $body = "Hola {$lead->client_name},<br><br>El proveedor <strong>$p_name</strong> está interesado.<br>Estado: <strong>$verified_text</strong><br><br>Teléfono: $p_phone<br>Email: $p_email";
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    wp_mail($lead->client_email, $subject, $body, $headers);
}

// ==========================================
// 3. NOTIFICACIÓN A PROVEEDORES (NUEVA COTIZACIÓN)
// ==========================================
add_action('sms_notify_providers', 'sms_smart_notification', 10, 1);

function sms_smart_notification($lead_id) {
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
            $email = $u->user_email;

            if($phone) {
                $balance = (int) get_user_meta($u->ID, 'sms_wallet_balance', true);
                $cost = (int) $lead->cost_credits;
                $desc_short = mb_substr($lead->requirement, 0, 100) . '...';
                
                if ($balance >= $cost) {
                    $link = $base_url . "?lid=" . $lead_id;
                    $msg = "🔔 *Nueva Cotización #$lead_id*\n📍 {$lead->city}\n📝 $desc_short\n\n💰 Saldo: *$balance cr* | Costo: *$cost cr*\n\n👉 Responde *ACEPTO $lead_id* para comprar ya.\n👉 O mira detalles aquí: $link";
                } else {
                    $msg = "🔔 *Nueva Cotización #$lead_id*\n⚠️ *Saldo Insuficiente* (Tienes $balance cr).\n📝 $desc_short\n\n👉 Recarga aquí: $shop_url";
                }
                sms_send_msg($phone, $msg);

                // Email
                $headers = ['Content-Type: text/html; charset=UTF-8'];
                $subject = "Nueva Oportunidad #$lead_id";
                $body = "<h3>Solicitud en {$lead->city}</h3><p>{$lead->requirement}</p><p><a href='$link'>Ver en Web</a></p>";
                wp_mail($email, $subject, $body, $headers);
            }
        }
    }
}

// ==========================================
// 4. WEBHOOK: CEREBRO DE INTERACCIÓN
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

    if(isset($params['type']) && $params['type'] == 'whatsapp') {
        $msg_body = trim(strtoupper($params['data']['message'])); 
        $phone_sender = str_replace('+', '', $params['data']['phone']); 
        
        // Anti-Duplicados
        $transient_key = 'sms_lock_' . md5($phone_sender . $msg_body);
        if (get_transient($transient_key)) return new WP_REST_Response('Duplicate Ignored', 200);
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
                    sms_send_msg($phone_sender, "✅ ¡Cuenta Verificada!\n🎁 *Regalo:* Te cargamos *$bonus créditos* gratis.");
                } else {
                    sms_send_msg($phone_sender, "✅ ¡Cuenta Verificada! Ahora recibirás alertas.");
                }
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
                    
                    if($already) {
                        $info = "✅ *Ya compraste este dato:*\n\n👤 {$lead->client_name}\n📞 +{$lead->client_phone}\n✉️ {$lead->client_email}";
                        sms_send_msg($phone_sender, $info);
                    } else {
                        $bal = (int) get_user_meta($u->ID, 'sms_wallet_balance', true);
                        if($bal >= $lead->cost_credits) {
                            update_user_meta($u->ID, 'sms_wallet_balance', $bal - $lead->cost_credits);
                            $wpdb->insert("{$wpdb->prefix}sms_lead_unlocks", ['lead_id' => $lead_id, 'provider_user_id' => $u->ID]);
                            
                            // Notificar Cliente
                            sms_notify_client_match($lead_id, $u->ID);

                            // Notificar Proveedor
                            $company_name = $lead->client_company ?: 'Particular';
                            $info = "🎉 *Compra Exitosa*\nNuevo saldo: ".($bal - $lead->cost_credits)."\n\nDatos:\n🏢 $company_name\n👤 {$lead->client_name}\n📞 +{$lead->client_phone}\n✉️ {$lead->client_email}";
                            sms_send_msg($phone_sender, $info);
                        } else {
                            sms_send_msg($phone_sender, "❌ Saldo insuficiente ($bal cr). Costo: {$lead->cost_credits}.");
                        }
                    }
                }
            }
        }

        // --- CASO 3: SOLICITAR CÓDIGO POR WHATSAPP ---
        elseif (strpos($msg_body, 'WHATSAPP') !== false) {
            $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE client_phone LIKE '%$phone_sender' AND is_verified = 0 ORDER BY created_at DESC LIMIT 1");
            
            $otp_key = 'sms_otp_lock_' . $phone_sender;
            
            if ($lead && !get_transient($otp_key)) {
                sms_send_msg($phone_sender, "🔐 Tu código de verificación es: *{$lead->verification_code}*");
                set_transient($otp_key, true, 45);
            }
        }

        // --- CASO 4: SOLICITAR CÓDIGO POR EMAIL ---
        elseif (strpos($msg_body, 'EMAIL') !== false) {
            $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE client_phone LIKE '%$phone_sender' AND is_verified = 0 ORDER BY created_at DESC LIMIT 1");
            
            $mail_key = 'sms_mail_lock_' . $phone_sender;
            
            if ($lead && is_email($lead->client_email) && !get_transient($mail_key)) {
                $headers = ['Content-Type: text/html; charset=UTF-8'];
                wp_mail($lead->client_email, "Código Verificación", "Código: <strong>{$lead->verification_code}</strong>", $headers);
                
                sms_send_msg($phone_sender, "📧 Código enviado a tu email.");
                set_transient($mail_key, true, 45); 
            }
        }
    }
    return new WP_REST_Response('OK', 200);
}
