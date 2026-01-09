<?php
if (!defined('ABSPATH')) exit;

// Registrar Shortcode
add_shortcode('sms_ver_oportunidad', 'sms_render_unlock_page');

function sms_render_unlock_page() {
    // 1. Verificar Login
    if (!is_user_logged_in()) {
        return '<div class="woocommerce-message">🔒 Debes <a href="/mi-cuenta">iniciar sesión</a> para ver esta oportunidad.</div>';
    }

    global $wpdb;
    $lead_id = isset($_GET['lid']) ? intval($_GET['lid']) : 0;
    $user_id = get_current_user_id();

    if ($lead_id === 0) return '<p>Enlace inválido.</p>';

    // 2. Obtener Cotización
    $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE id = $lead_id");
    
    if (!$lead || $lead->status != 'approved') {
        return '<div class="woocommerce-error">Esta cotización no está disponible o ha expirado.</div>';
    }

    // 3. Verificar si este usuario ya la desbloqueó
    $is_unlocked = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}sms_lead_unlocks WHERE lead_id = $lead_id AND provider_user_id = $user_id");

    // 4. Calcular Cupos y Urgencia
    $competitors_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sms_lead_unlocks WHERE lead_id = $lead_id");
    $max_allowed = (int) $lead->max_quotas;
    
    // Si max_quotas es 0 o null (por leads antiguos), asumir 3 por defecto
    if ($max_allowed <= 0) $max_allowed = 3;

    $is_full = ($competitors_count >= $max_allowed);

    $msg = '';

    // ==========================================
    // PROCESO DE COMPRA (Botón Web)
    // ==========================================
    if (isset($_POST['sms_confirm_unlock']) && !$is_unlocked) {
        
        // Re-validar cupos (por si alguien compró hace un milisegundo)
        $current_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sms_lead_unlocks WHERE lead_id = $lead_id");
        if ($current_count >= $max_allowed) {
            $msg = '<div class="woocommerce-error">⛔ <strong>¡Lo sentimos!</strong> Justo se acaba de ocupar el último cupo para esta cotización.</div>';
            $is_full = true; // Actualizar estado visual
        } else {
            $balance = (int) get_user_meta($user_id, 'sms_wallet_balance', true);
            
            if ($balance >= $lead->cost_credits) {
                // A. Descontar saldo
                update_user_meta($user_id, 'sms_wallet_balance', $balance - $lead->cost_credits);
                
                // B. Registrar desbloqueo
                $wpdb->insert("{$wpdb->prefix}sms_lead_unlocks", [
                    'lead_id' => $lead_id, 
                    'provider_user_id' => $user_id
                ]);

                // C. Notificar al Cliente (Match)
                if(function_exists('sms_notify_client_match')) {
                    sms_notify_client_match($lead_id, $user_id);
                }
                
                // Recargar para mostrar datos desbloqueados
                echo "<script>window.location.reload();</script>"; 
                return; 

            } else {
                $msg = '<div class="woocommerce-error">❌ Saldo insuficiente. Tienes '.$balance.' créditos. <a href="/tienda" class="button">Recargar</a></div>';
            }
        }
    }

    // ==========================================
    // RENDERIZADO VISUAL
    // ==========================================
    ob_start();
    echo $msg;
    ?>
    <div class="sms-opportunity-card" style="border:1px solid #ddd; padding:20px; border-radius:8px; max-width:600px; margin:0 auto; background:#fff; box-shadow: 0 4px 10px rgba(0,0,0,0.05);">
        
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;">
            <h3 style="margin:0;">Oportunidad #<?php echo $lead_id; ?></h3>
            
            <div style="text-align:right;">
                <span style="background:<?php echo $is_full ? '#f8d7da' : '#e2e3e5'; ?>; color:<?php echo $is_full ? '#721c24' : '#383d41'; ?>; padding:5px 10px; border-radius:15px; font-size:12px; font-weight:bold;">
                    <?php if($is_full): ?>⛔ Cerrada<?php else: ?>⚡ Abierta<?php endif; ?>
                </span>
                <div style="font-size:11px; color:#666; margin-top:3px;">
                    Cupos: <strong><?php echo $competitors_count; ?> / <?php echo $max_allowed; ?></strong>
                </div>
            </div>
        </div>
        
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:20px;">
            <div style="background:#f9f9f9; padding:10px; border-radius:5px;">
                📍 <strong>Ubicación:</strong><br><?php echo esc_html($lead->city . ', ' . $lead->country); ?>
            </div>
            
            <div style="background:#f9f9f9; padding:10px; border-radius:5px;">
                🏢 <strong>Empresa:</strong><br>
                <?php if ($is_unlocked): ?>
                    <span style="color:#007cba; font-weight:bold;"><?php echo esc_html($lead->client_company ?: 'Particular'); ?></span>
                <?php else: ?>
                    <span style="color:#777;">🔒 Empresa Confidencial</span>
                <?php endif; ?>
            </div>
            
            <div style="background:#f9f9f9; padding:10px; grid-column: span 2; border-radius:5px;">
                📝 <strong>Requerimiento:</strong><br>
                <div style="max-height:150px; overflow-y:auto; margin-top:5px;">
                    <?php echo nl2br(esc_html($lead->requirement)); ?>
                </div>
            </div>
        </div>

        <?php if ($is_unlocked): ?>
            
            <div style="background:#d4edda; border:1px solid #c3e6cb; padding:15px; border-radius:5px; color:#155724;">
                <h4 style="margin-top:0;">🔓 Datos de Contacto:</h4>
                <ul style="list-style:none; padding:0; font-size:16px;">
                    <li style="margin-bottom:8px;">👤 <strong>Nombre:</strong> <?php echo esc_html($lead->client_name); ?></li>
                    <li style="margin-bottom:8px;">📞 <strong>WhatsApp:</strong> <a href="https://wa.me/<?php echo str_replace('+','',$lead->client_phone); ?>" target="_blank" style="text-decoration:none; color:#155724; font-weight:bold;">+<?php echo esc_html($lead->client_phone); ?> (Clic aquí)</a></li>
                    <li style="margin-bottom:8px;">✉️ <strong>Email:</strong> <a href="mailto:<?php echo esc_attr($lead->client_email); ?>"><?php echo esc_html($lead->client_email); ?></a></li>
                </ul>
                <div style="margin-top:15px; text-align:center;">
                    <a href="https://wa.me/<?php echo str_replace('+','',$lead->client_phone); ?>" class="button button-primary" target="_blank">📲 Chatear por WhatsApp Ahora</a>
                </div>
            </div>
        
        <?php else: 
            $my_bal = (int) get_user_meta($user_id, 'sms_wallet_balance', true);
        ?>
            <div style="text-align:center; background:#fff3cd; padding:20px; border-radius:5px; border:1px solid #ffeeba;">
                
                <?php if ($is_full): ?>
                    <div style="color:#721c24; font-weight:bold;">
                        🚫 Esta cotización alcanzó el límite máximo de <?php echo $max_allowed; ?> proveedores.
                        <br><span style="font-weight:normal; font-size:13px;">Ya no es posible participar.</span>
                    </div>

                <?php else: ?>
                    <p style="margin-bottom:10px;">⚠️ Desbloquea para ver nombre y contacto del cliente.</p>
                    
                    <div style="display:flex; justify-content:space-around; align-items:center; margin-bottom:15px; background:#fff; padding:10px; border-radius:5px;">
                        <div>Costo:<br><strong style="font-size:18px; color:#d63638;"><?php echo $lead->cost_credits; ?> cr</strong></div>
                        <div style="border-left:1px solid #ddd; height:30px;"></div>
                        <div>Tu Saldo:<br><strong style="font-size:18px; color:#007cba;"><?php echo $my_bal; ?> cr</strong></div>
                    </div>

                    <?php if ($my_bal >= $lead->cost_credits): ?>
                        <form method="post">
                            <button type="submit" name="sms_confirm_unlock" class="button button-primary" style="font-size:18px; padding:10px 25px; width:100%;">🔓 DESBLOQUEAR DATOS</button>
                        </form>
                    <?php else: ?>
                        <a href="/tienda" class="button button-primary" style="width:100%;">💳 Recargar Saldo</a>
                        <p style="font-size:11px; margin-top:5px;">Te faltan <?php echo ($lead->cost_credits - $my_bal); ?> créditos.</p>
                    <?php endif; ?>

                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
