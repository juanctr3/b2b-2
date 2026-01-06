<?php
if (!defined('ABSPATH')) exit;

/**
 * 1. FUNCIÓN DE ENVÍO MENSAJES
 * Usa codificación compatible (x-www-form-urlencoded) para evitar errores de API.
 * Convierte el mensaje a UTF-8 para prevenir signos "??".
 */
function sms_send_msg($to, $msg) {
    $url = "https://whatsapp.smsenlinea.com/api/send/whatsapp";
    
    // Asegurar UTF-8 para emojis y tildes
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
 * Se ejecuta cuando un proveedor desbloquea la cotización.
 * Envía: Empresa, Asesor, Celular, Email y Estado de Verificación.
 */
function sms_notify_client_match($lead_id, $provider_user_id) {
    global $wpdb;
    $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE id=$lead_id");
    $prov = get_userdata($provider_user_id);

    if (!$lead || !$prov) return;

    // Obtener datos del proveedor
    $p_company = get_user_meta($provider_user_id, 'sms_provider_company', true) ?: 'Empresa Confidencial';
    $p_advisor = get_user_meta($provider_user_id, 'sms_advisor_name', true) ?: $prov->display_name;
    $p_phone   = get_user_meta($provider_user_id, 'billing_phone', true);
    $p_email   = $prov->user_email;
    
    // Estado de Verificación de Documentos
    $is_doc_verified = get_user_meta($provider_user_id, 'sms_docs_verified', true) === 'yes';
    $verified_text = $is_doc_verified ? "✅ EMPRESA VERIFICADA" : "⚠️ Empresa no verificada";

    // --- MENSAJE WHATSAPP ---
    $msg = "🔔 *¡Buenas Noticias!*\n\nUna empresa aceptó tu cotización *#$lead_id*.\n\n🏢 *Empresa:* $p_company\n👤 *Asesor:* $p_advisor\n$verified_text\n\n📞 *Celular:* +$p_phone\n📧 *Email:* $p_email\n\nTe contactarán pronto.";
    
    sms_send_msg($lead->client_phone, $msg);

    // --- EMAIL ---
    $subject = "¡Proveedor encontrado para cotización #$lead_id!";
    $body = "
        <h3>¡Tenemos un interesado!</h3>
        <p>Una empresa ha revisado tu solicitud y quiere contactarte.</p>
        <hr>
        <h4>Detalles del Proveedor:</h4>
        <ul>
            <li><strong>Empresa:</strong> $p_company</li>
            <li><strong>Asesor:</strong> $p_advisor</li>
            <li><strong>Estado:</strong> $verified_text</li>
            <li><strong>Celular:</strong> $p_phone</li>
            <li><strong>Email:</strong> $p_email</li>
        </ul>
        <hr>
        <p>Puedes esperar su llamada o escribirles directamente.</p>
    ";
    
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

                // Email opcional al proveedor
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
        
        // Bloqueo temporal para evitar duplicados (60 seg)
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
                            // PROCESO DE COMPRA
                            update_user_meta($u->ID, 'sms_wallet_balance', $bal - $lead->cost_credits);
                            $wpdb->insert("{$wpdb->prefix}sms_lead_unlocks", ['lead_id' => $lead_id, 'provider_user_id' => $u->ID]);
                            
                            // 1. NOTIFICAR AL CLIENTE
                            sms_notify_client_match($lead_id, $u->ID);

                            // 2. MENSAJE AL PROVEEDOR
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

        // --- CASO 3: CLIENTE PIDE CÓDIGO (WHATSAPP) ---
        elseif (strpos($msg_body, 'WHATSAPP') !== false) {
            $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE client_phone LIKE '%$phone_sender' AND is_verified = 0 ORDER BY created_at DESC LIMIT 1");
            
            $otp_key = 'sms_otp_lock_' . $phone_sender;
            
            if ($lead && !get_transient($otp_key)) {
                sms_send_msg($phone_sender, "🔐 Tu código de verificación es: *{$lead->verification_code}*");
                set_transient($otp_key, true, 45);
            }
        }

        // --- CASO 4: CLIENTE PIDE CÓDIGO (EMAIL) ---
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
