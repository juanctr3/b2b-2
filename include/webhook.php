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

    // Emojis
    $e_check = "\xE2\x9C\x85"; $e_gift = "\xF0\x9F\x8E\x81"; $e_party = "\xF0\x9F\x8E\x89"; 
    $e_user = "\xF0\x9F\x91\xA4"; $e_phone = "\xF0\x9F\x93\x9E"; $e_mail = "\xE2\x9C\x89"; 
    $e_lock = "\xF0\x9F\x94\x90"; $e_x = "\xE2\x9D\x8C"; $e_build = "\xF0\x9F\x8F\xA2"; 
    $e_memo = "\xF0\x9F\x93\x9D"; $e_world = "\xF0\x9F\x8C\x8D"; $e_pin = "\xF0\x9F\x93\x8D";
    $e_tie  = "\xF0\x9F\x91\x94"; $e_link = "\xF0\x9F\x94\x97";

    if(isset($params['type']) && $params['type'] == 'whatsapp') {
        $msg_body = trim(strtoupper($params['data']['message'])); 
        $phone_sender = str_replace('+', '', $params['data']['phone']); 
        
        $transient_key = 'sms_lock_' . md5($phone_sender . $msg_body);
        if (get_transient($transient_key)) return new WP_REST_Response('Ignored', 200);
        set_transient($transient_key, true, 60);

        // A. Verificación de Cuenta del Proveedor
        if ($msg_body === 'ACEPTO') {
            $users = get_users(['meta_query' => [['key' => 'billing_phone', 'value' => $phone_sender, 'compare' => 'LIKE']], 'number' => 1]);
            if (!empty($users)) {
                $u = $users[0];
                update_user_meta($u->ID, 'sms_phone_status', 'verified');
                sms_send_msg($phone_sender, "$e_check ¡Cuenta Verificada! Ahora recibirás alertas.");
            }
        }
        // B. COMPRA VIA WHATSAPP (Lógica Principal Mejorada)
        elseif (preg_match('/^ACEPTO\s+(\d+)/i', $msg_body, $matches)) {
            $lead_id = intval($matches[1]);
            $users = get_users(['meta_query' => [['key' => 'billing_phone', 'value' => $phone_sender, 'compare' => 'LIKE']], 'number' => 1]);
            
            if(!empty($users)) {
                $u = $users[0];
                $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE id=$lead_id");
                $approved_services = get_user_meta($u->ID, 'sms_approved_services', true) ?: [];
                
                if($lead && in_array($lead->service_page_id, $approved_services)) {
                    
                    // Verificar saldo o si ya compró
                    $already = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}sms_lead_unlocks WHERE lead_id=$lead_id AND provider_user_id={$u->ID}");
                    $bal = (int) get_user_meta($u->ID, 'sms_wallet_balance', true);
                    
                    if($already || $bal >= $lead->cost_credits) {
                        
                        if(!$already) {
                            update_user_meta($u->ID, 'sms_wallet_balance', $bal - $lead->cost_credits);
                            $wpdb->insert("{$wpdb->prefix}sms_lead_unlocks", ['lead_id' => $lead_id, 'provider_user_id' => $u->ID]);
                        }

                        // --- 1. MENSAJE PARA EL PROVEEDOR (Tus requisitos punto 2) ---
                        // Limpiar celular cliente (quitar +57 o 57 al inicio)
                        $client_cel_clean = preg_replace('/^(\+?57)/', '', $lead->client_phone);
                        $client_comp = $lead->client_company ?: 'Particular';
                        
                        $msg_prov = "$e_party *¡Compra Exitosa! Detalle del Lead #$lead_id*\n\n" .
                                    "$e_build *Empresa:* $client_comp\n" .
                                    "$e_user *Contacto:* {$lead->client_name}\n" .
                                    "$e_mail *Email:* {$lead->client_email}\n" .
                                    "$e_phone *Celular:* $client_cel_clean\n" .
                                    "$e_pin *Ciudad:* {$lead->city}\n" .
                                    "$e_world *País:* {$lead->country}\n\n" .
                                    "$e_memo *Requerimiento:*\n{$lead->requirement}";
                        
                        sms_send_msg($phone_sender, $msg_prov);

                        // --- 2. MENSAJE PARA EL CLIENTE (Tus requisitos punto 1) ---
                        // Obtener datos del proveedor
                        $prov_com_name = get_user_meta($u->ID, 'sms_commercial_name', true) ?: ($u->billing_company ?: $u->display_name);
                        $prov_advisor = get_user_meta($u->ID, 'sms_advisor_name', true) ?: 'Asesor Comercial';
                        $prov_wa = get_user_meta($u->ID, 'sms_whatsapp_notif', true) ?: $phone_sender;
                        $prov_email = get_user_meta($u->ID, 'billing_email', true) ?: $u->user_email;
                        
                        // Enlace al perfil (asumiendo que la página es /perfil-proveedor)
                        $profile_link = site_url("/perfil-proveedor?uid=" . $u->ID);

                        $msg_client = "👋 Hola {$lead->client_name}.\n\n" .
                                      "$e_check *¡Buenas Noticias! Un proveedor ha aceptado tu solicitud.*\n\n" .
                                      "$e_build *Empresa:* $prov_com_name\n" .
                                      "$e_tie *Asesor:* $prov_advisor\n" .
                                      "$e_phone *WhatsApp:* $prov_wa\n" .
                                      "$e_mail *Email:* $prov_email\n\n" .
                                      "$e_link *Ver Perfil y Trayectoria:*\n$profile_link";
                        
                        sms_send_msg($lead->client_phone, $msg_client);

                        // Enviar también por Email al Cliente
                        $subject = "✅ Proveedor Asignado: $prov_com_name";
                        $body = "<h3>Hola {$lead->client_name},</h3>" .
                                "<p>La empresa <strong>$prov_com_name</strong> ha revisado tu solicitud y está interesada.</p>" .
                                "<ul>" .
                                "<li><strong>Asesor:</strong> $prov_advisor</li>" .
                                "<li><strong>WhatsApp:</strong> $prov_wa</li>" .
                                "<li><strong>Email:</strong> $prov_email</li>" .
                                "</ul>" .
                                "<p><a href='$profile_link' style='background:#007cba; color:#fff; padding:10px; text-decoration:none; border-radius:5px;'>Ver Perfil de la Empresa</a></p>";
                        
                        $headers = ['Content-Type: text/html; charset=UTF-8'];
                        wp_mail($lead->client_email, $subject, $body, $headers);

                    } else {
                        sms_send_msg($phone_sender, "$e_x Saldo insuficiente ($bal cr). Recarga en: " . site_url('/tienda'));
                    }
                } else {
                    sms_send_msg($phone_sender, "$e_x No autorizado o cotización expirada.");
                }
            }
        }
        // C. y D. (Verificación Cliente) se mantienen igual...
        elseif (strpos($msg_body, 'WHATSAPP') !== false) {
            $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE client_phone LIKE '%$phone_sender' AND is_verified = 0 ORDER BY created_at DESC LIMIT 1");
            $otp_key = 'sms_otp_lock_' . $phone_sender;
            if ($lead && !get_transient($otp_key)) {
                sms_send_msg($phone_sender, "$e_lock Tu código de verificación: *{$lead->verification_code}*");
                set_transient($otp_key, true, 45);
            }
        }
        elseif (strpos($msg_body, 'EMAIL') !== false) {
             $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE client_phone LIKE '%$phone_sender' AND is_verified = 0 ORDER BY created_at DESC LIMIT 1");
             if ($lead && is_email($lead->client_email)) {
                 wp_mail($lead->client_email, "Código", "Tu código es: {$lead->verification_code}");
                 sms_send_msg($phone_sender, "$e_mail Código enviado al email.");
             }
        }
    }
    return new WP_REST_Response('OK', 200);
}

