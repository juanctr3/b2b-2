<?php
if (!defined('ABSPATH')) exit;

/**
 * 1. FUNCIÓN DE ENVÍO MENSAJES WHATSAPP (ROBUSTO)
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
 * 2. NUEVA FUNCIÓN: EMAILS HTML PROFESIONALES (CON LOGO)
 */
function sms_send_email_html($to, $subject, $body_content) {
    $site_name = get_bloginfo('name');
    $admin_email = get_option('admin_email');
    
    // Obtener Logo del Tema
    $custom_logo_id = get_theme_mod('custom_logo');
    $logo_src = $custom_logo_id ? wp_get_attachment_image_src($custom_logo_id, 'full')[0] : '';
    
    // Estilos Inline para Email
    $bg_color = '#f6f6f6';
    $main_color = '#007cba';
    
    $header_content = $logo_src 
        ? "<img src='$logo_src' alt='$site_name' style='max-width:150px; height:auto;'>" 
        : "<h1 style='color:#ffffff; margin:0; font-size:24px;'>$site_name</h1>";

    $html = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>$subject</title>
    </head>
    <body style='margin:0; padding:0; background-color:$bg_color; font-family:Helvetica, Arial, sans-serif;'>
        <table border='0' cellpadding='0' cellspacing='0' width='100%'>
            <tr>
                <td align='center' style='padding: 20px 0;'>
                    <table border='0' cellpadding='0' cellspacing='0' width='600' style='background-color:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 2px 5px rgba(0,0,0,0.05);'>
                        <tr>
                            <td align='center' style='background-color:$main_color; padding: 30px 20px;'>
                                $header_content
                            </td>
                        </tr>
                        <tr>
                            <td style='padding: 30px 30px; color:#333333; line-height:1.6;'>
                                $body_content
                            </td>
                        </tr>
                        <tr>
                            <td align='center' style='background-color:#eeeeee; padding: 20px; font-size:12px; color:#777777;'>
                                &copy; " . date('Y') . " <strong>$site_name</strong>. Todos los derechos reservados.<br>
                                <a href='" . site_url() . "' style='color:$main_color; text-decoration:none;'>Visitar Sitio Web</a>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>
    ";

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        "From: $site_name <$admin_email>"
    ];

    wp_mail($to, $subject, $html, $headers);
}

/**
 * 3. MATCH & DESBLOQUEO (Proveedor compra)
 */
function sms_notify_client_match($lead_id, $provider_user_id) {
    global $wpdb;
    $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE id=$lead_id");
    $prov = get_userdata($provider_user_id);

    if (!$lead || !$prov) return;

    // Datos Proveedor
    $p_company = get_user_meta($provider_user_id, 'sms_commercial_name', true) ?: ($prov->billing_company ?: 'Empresa Verificada');
    $p_advisor = get_user_meta($provider_user_id, 'sms_advisor_name', true) ?: $prov->display_name;
    $p_phone_raw = get_user_meta($provider_user_id, 'sms_whatsapp_notif', true) ?: $prov->billing_phone;
    $p_phone = preg_replace('/[^0-9]/', '', $p_phone_raw);
    $p_email = $prov->user_email;
    $profile_link = site_url("/perfil-proveedor?uid=" . $provider_user_id);

    // --- A. NOTIFICAR AL CLIENTE (WHATSAPP) ---
    $msg_client = "👋 Hola {$lead->client_name}.\n\n✅ *¡Proveedor Asignado!*\nLa empresa *{$p_company}* ha aceptado tu solicitud.\n\n👤 *Asesor:* $p_advisor\n📞 *WhatsApp:* +$p_phone\n📧 *Email:* $p_email\n\n🔗 *Ver Perfil:* $profile_link";
    sms_send_msg($lead->client_phone, $msg_client);

    // --- B. NOTIFICAR AL CLIENTE (EMAIL PRO) ---
    $body_client = "
        <h2 style='color:#007cba;'>¡Buenas noticias, {$lead->client_name}!</h2>
        <p>Hemos encontrado una empresa interesada en tu solicitud.</p>
        <div style='background:#f0f7fb; padding:20px; border-left:4px solid #007cba; margin:20px 0;'>
            <h3 style='margin-top:0;'>{$p_company}</h3>
            <p style='margin:5px 0;'><strong>Asesor:</strong> $p_advisor</p>
            <p style='margin:5px 0;'><strong>WhatsApp:</strong> +$p_phone</p>
            <p style='margin:5px 0;'><strong>Email:</strong> $p_email</p>
            <p style='margin-top:15px;'><a href='$profile_link' style='background:#007cba; color:#fff; padding:10px 15px; text-decoration:none; border-radius:4px;'>Ver Perfil de la Empresa</a></p>
        </div>
        <p>Pronto se pondrán en contacto contigo.</p>
    ";
    sms_send_email_html($lead->client_email, "✅ Proveedor Asignado: $p_company", $body_client);

    // --- C. NOTIFICAR AL PROVEEDOR (EMAIL PRO CON DATOS) ---
    $client_phone_clean = str_replace('+','', $lead->client_phone);
    $wa_link = "https://wa.me/$client_phone_clean";
    
    $body_prov = "
        <h2 style='color:#28a745;'>¡Has desbloqueado un cliente! 🔓</h2>
        <p>Hola <strong>$p_advisor</strong>, aquí tienes los datos completos del prospecto que acabas de adquirir:</p>
        
        <div style='background:#fcfcfc; border:1px solid #e0e0e0; padding:20px; border-radius:5px;'>
            <table width='100%'>
                <tr><td width='30%'><strong>Nombre:</strong></td><td>{$lead->client_name}</td></tr>
                <tr><td><strong>Empresa:</strong></td><td>{$lead->client_company}</td></tr>
                <tr><td><strong>Email:</strong></td><td><a href='mailto:{$lead->client_email}'>{$lead->client_email}</a></td></tr>
                <tr><td><strong>Teléfono:</strong></td><td><a href='$wa_link' style='color:#25d366; font-weight:bold; text-decoration:none;'>+{$lead->client_phone} (Clic para WhatsApp)</a></td></tr>
            </table>
            <hr style='border:0; border-top:1px solid #eee; margin:15px 0;'>
            <p><strong>Requerimiento:</strong><br>{$lead->requirement}</p>
        </div>
        
        <p style='text-align:center; margin-top:20px;'>
            <a href='$wa_link' style='background:#25d366; color:#fff; padding:12px 25px; text-decoration:none; border-radius:50px; font-weight:bold;'>📲 Chatear Ahora</a>
        </p>
    ";
    sms_send_email_html($p_email, "🔓 Datos Desbloqueados - Lead #$lead_id", $body_prov);
}

/**
 * 3. NOTIFICACIÓN A PROVEEDORES (NUEVA OPORTUNIDAD - MASIVO)
 */
add_action('sms_notify_providers', 'sms_smart_notification', 10, 2);

function sms_smart_notification($lead_id, $target_providers = []) {
    global $wpdb;
    set_time_limit(0);

    $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE id=$lead_id");
    if (!$lead) return;

    $delay_seconds = (int) get_option('sms_msg_delay', 0);
    $base_url = site_url('/oportunidad'); 
    $shop_url = site_url('/tienda');
    
    // Datos Formateados
    $client_type_str = ($lead->client_company === 'Particular' || empty($lead->client_company)) ? 'Persona Natural' : 'Empresa';
    $prio_emoji = '🟢'; $prio_txt = 'Normal';
    if(isset($lead->priority)) {
        if($lead->priority == 'Urgente') { $prio_emoji = '🔥'; $prio_txt = 'URGENTE'; }
        if($lead->priority == 'Muy Urgente') { $prio_emoji = '🚨'; $prio_txt = 'MUY URGENTE'; }
    }
    $deadline_txt = $lead->deadline ? date('d/M', strtotime($lead->deadline)) : 'Lo antes posible';
    $quotas_txt = $lead->max_quotas ? $lead->max_quotas : 3;

    // Seleccionar Usuarios
    if (!empty($target_providers) && is_array($target_providers)) {
        $users = [];
        foreach($target_providers as $uid) { $u = get_userdata($uid); if($u) $users[] = $u; }
    } else {
        $users = get_users();
    }

    foreach($users as $u) {
        $status = get_user_meta($u->ID, 'sms_phone_status', true);
        if($status != 'verified') continue; // Solo verificados

        $phone = get_user_meta($u->ID, 'sms_whatsapp_notif', true) ?: get_user_meta($u->ID, 'billing_phone', true);
        $email = $u->user_email;
        $prov_advisor = get_user_meta($u->ID, 'sms_advisor_name', true) ?: $u->display_name;
        $prov_company = get_user_meta($u->ID, 'sms_commercial_name', true) ?: 'tu empresa';

        if($phone) {
            $balance = (int) get_user_meta($u->ID, 'sms_wallet_balance', true);
            $cost = (int) $lead->cost_credits;
            $desc_short = mb_substr($lead->requirement, 0, 100) . '...';
            
            // 1. WhatsApp Personalizado
            $header = "👋 Hola *$prov_advisor*,\nHay una oportunidad excelente para tu empresa *$prov_company*.\n\n$prio_emoji *Solicitud #$lead_id* [$prio_txt]";
            $details = "👉 Cliente: *$client_type_str*\n📅 Cierre: *$deadline_txt*\n📉 Cupos: Busca *$quotas_txt ofertas*";
            $location = "📍 {$lead->city}";

            if ($balance >= $cost) {
                $link = $base_url . "?lid=" . $lead_id;
                $msg = "$header\n$details\n$location\n📝 $desc_short\n\n💰 Saldo: *$balance cr* | Costo: *$cost cr*\n\n👉 Responde *ACEPTO $lead_id* para comprar.\n👉 Detalles: $link";
            } else {
                $msg = "$header\n$details\n$location\n⚠️ *Saldo Insuficiente* ($balance cr).\n📝 $desc_short\n\n👉 Recarga aquí: $shop_url";
            }
            sms_send_msg($phone, $msg);
            
            // 2. Email Profesional (HTML)
            $mail_body = "
                <h3>Hola $prov_advisor,</h3>
                <p>Tenemos una nueva oportunidad de negocio que encaja con <strong>$prov_company</strong>.</p>
                
                <div style='background:#fdfdfe; border:1px solid #e1e1e1; padding:15px; border-radius:5px;'>
                    <p style='margin:5px 0;'><strong>Prioridad:</strong> <span style='color:red;'>$prio_txt</span></p>
                    <p style='margin:5px 0;'><strong>Ubicación:</strong> {$lead->city}</p>
                    <p style='margin:5px 0;'><strong>Cliente:</strong> $client_type_str</p>
                    <p style='margin:5px 0;'><strong>Requerimiento:</strong><br><em>{$lead->requirement}</em></p>
                </div>
                
                <p align='center' style='margin-top:20px;'>
                    <a href='$base_url?lid=$lead_id' style='background:#007cba; color:#ffffff; padding:12px 24px; text-decoration:none; border-radius:4px; font-weight:bold;'>Ver Oportunidad en la Web</a>
                </p>
            ";
            sms_send_email_html($email, "Nueva Oportunidad #$lead_id ($prio_txt)", $mail_body);

            // 3. Retraso
            if ($delay_seconds > 0) sleep($delay_seconds);
        }
    }
}

/**
 * 4. WEBHOOK: CEREBRO DE INTERACCIÓN (BÚSQUEDA ROBUSTA)
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

        // Idempotencia: 3 segundos
        $transient_key = 'sms_lock_' . md5($phone_sender . $msg_body);
        if (get_transient($transient_key)) return new WP_REST_Response('Ignored', 200);
        set_transient($transient_key, true, 3);

        // Búsqueda Flexible
        $search_term = (strlen($phone_sender) > 10) ? substr($phone_sender, -10) : $phone_sender;

        // A. CONFIRMACIÓN (PROVEEDOR)
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
        
        // B. COMPRA (PROVEEDOR ACEPTO)
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
                            $wpdb->insert("{$wpdb->prefix}sms_lead_unlocks", [
                                'lead_id' => $lead_id, 
                                'provider_user_id' => $u->ID,
                                'credits_spent' => $lead->cost_credits
                            ]);
                            // Notifica al cliente y envía email con datos al proveedor
                            if(function_exists('sms_notify_client_match')) sms_notify_client_match($lead_id, $u->ID);
                        }
                        
                        $c_type = ($lead->client_company === 'Particular' || empty($lead->client_company)) ? 'Persona' : 'Empresa (' . $lead->client_company . ')';
                        $prio_txt = isset($lead->priority) ? $lead->priority : 'Normal';
                        $client_phone = str_replace('+','', $lead->client_phone);
                        
                        // WhatsApp con datos inmediatos
                        $info = "$e_check *Datos Lead #$lead_id*\n\n👉 $c_type\n⚠️ Prioridad: $prio_txt\n👤 {$lead->client_name}\n📞 +$client_phone\n📧 {$lead->client_email}\n📝 {$lead->requirement}\n\n📨 *Nota:* También te enviamos estos datos a tu correo.";
                        sms_send_msg($phone_sender, $info);
                    } else {
                        sms_send_msg($phone_sender, "$e_x Saldo insuficiente ($bal cr).");
                    }
                }
            }
        }
        
        // C. VERIFICACIÓN (CLIENTE WHATSAPP)
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
        
        // D. VERIFICACIÓN (CLIENTE EMAIL)
        elseif (strpos($msg_body, 'EMAIL') !== false) {
            $sql = "SELECT * FROM {$wpdb->prefix}sms_leads 
                    WHERE REPLACE(REPLACE(client_phone, ' ', ''), '+', '') LIKE '%$search_term' 
                    AND is_verified = 0 
                    ORDER BY created_at DESC LIMIT 1";
            $lead = $wpdb->get_row($sql);
            if ($lead && is_email($lead->client_email)) {
                $mail_key = 'sms_mail_sent_' . $phone_sender;
                if (!get_transient($mail_key)) {
                    $body = "<h3>Tu código de verificación es:</h3><h1 style='color:#007cba;'>{$lead->verification_code}</h1><p>Úsalo para validar tu solicitud de cotización.</p>";
                    sms_send_email_html($lead->client_email, "Código de Verificación", $body);
                    
                    sms_send_msg($phone_sender, "$e_mail Hemos enviado el código a tu correo: {$lead->client_email}");
                    set_transient($mail_key, true, 5); 
                }
            }
        }
    }
    return new WP_REST_Response('OK', 200);
}
