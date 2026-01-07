<?php
if (!defined('ABSPATH')) exit;

add_filter('woocommerce_account_menu_items', function($items) {
    $items['zona-proveedor'] = '🏭 Zona Proveedor';
    return $items;
});

add_action('init', function() { add_rewrite_endpoint('zona-proveedor', EP_ROOT | EP_PAGES); });

add_action('woocommerce_account_zona-proveedor_endpoint', function() {
    $uid = get_current_user_id();
    global $wpdb;

    // A. GUARDAR PERFIL COMPLETO (Punto 4)
    if (isset($_POST['save_provider_profile'])) {
        // Datos Básicos
        update_user_meta($uid, 'billing_company', sanitize_text_field($_POST['p_razon_social'])); // Razón Social
        update_user_meta($uid, 'sms_commercial_name', sanitize_text_field($_POST['p_commercial_name'])); // Nombre Comercial
        update_user_meta($uid, 'sms_nit', sanitize_text_field($_POST['p_nit']));
        
        // Contacto
        update_user_meta($uid, 'billing_address_1', sanitize_text_field($_POST['p_address']));
        update_user_meta($uid, 'billing_phone', sanitize_text_field($_POST['p_phone']));
        update_user_meta($uid, 'sms_whatsapp_notif', sanitize_text_field($_POST['p_whatsapp']));
        update_user_meta($uid, 'billing_email', sanitize_email($_POST['p_email']));
        
        // Detalles
        update_user_meta($uid, 'sms_advisor_name', sanitize_text_field($_POST['p_advisor']));
        update_user_meta($uid, 'sms_company_desc', sanitize_textarea_field($_POST['p_desc']));

        // Guardar servicios solicitados
        $requested_pages = $_POST['p_servs'] ?? [];
        update_user_meta($uid, 'sms_requested_services', $requested_pages);
        
        // Lógica de Verificación de WhatsApp (NUEVA)
        $old_wa = get_user_meta($uid, 'sms_whatsapp_notif', true);
        $current_status = get_user_meta($uid, 'sms_phone_status', true);
        $new_wa = sanitize_text_field($_POST['p_whatsapp']);
        $new_wa_clean = str_replace([' ','+'], '', $new_wa);
        
        // Guardar el nuevo número
        update_user_meta($uid, 'sms_whatsapp_notif', $new_wa_clean);

        $msg_extra = "";
        // Si el número cambió o nunca se ha verificado, pedir confirmación
        if ($new_wa_clean && ($new_wa_clean !== $old_wa || $current_status !== 'verified')) {
            update_user_meta($uid, 'sms_phone_status', 'pending');
            
            if (function_exists('sms_send_msg')) {
                $site_name = get_bloginfo('name');
                $txt = "🔐 *Activación de Notificaciones*\n\nHola, para recibir alertas de clientes de *$site_name*, por favor responde a este mensaje escribiendo:\n\n*CONFIRMADO*";
                sms_send_msg($new_wa_clean, $txt);
                $msg_extra = "<br>📨 <strong>¡Atención!</strong> Te enviamos un WhatsApp. Responde <b>CONFIRMADO</b> en tu celular para activar las notificaciones.";
            }
        }

        echo '<div class="woocommerce-message">✅ Perfil actualizado. ' . $msg_extra . '</div>';
    }

    // B. SOLICITUD SERVICIO
    if (isset($_POST['req_new_service'])) {
        $serv_name = sanitize_text_field($_POST['new_service_name']);
        if($serv_name) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sms_service_requests WHERE provider_user_id = %d AND requested_service = %s", $uid, $serv_name));
            if(!$exists) {
                $wpdb->insert("{$wpdb->prefix}sms_service_requests", ['provider_user_id' => $uid, 'requested_service' => $serv_name]);
                echo '<div class="woocommerce-message">✅ Solicitud enviada.</div>';
            }
        }
    }

    // C. CARGA DE DOCUMENTOS
    // PUNTO 1: Solo permitimos subir si NO está verificado.
    $docs_status = get_user_meta($uid, 'sms_docs_status', true);
    
    if (isset($_FILES['p_docs']) && $docs_status != 'verified') {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        $files = $_FILES['p_docs'];
        $uploaded_count = 0;
        
        foreach ($files['name'] as $key => $value) {
            if ($files['name'][$key]) {
                $file = [
                    'name'     => $files['name'][$key],
                    'type'     => $files['type'][$key],
                    'tmp_name' => $files['tmp_name'][$key],
                    'error'    => $files['error'][$key],
                    'size'     => $files['size'][$key]
                ];
                $upload = wp_handle_upload($file, ['test_form' => false]);
                if (!isset($upload['error']) && isset($upload['url'])) {
                    $current_docs = get_user_meta($uid, 'sms_company_docs', true) ?: [];
                    $current_docs[] = $upload['url'];
                    update_user_meta($uid, 'sms_company_docs', $current_docs);
                    $uploaded_count++;
                }
            }
        }

        if ($uploaded_count > 0) {
            update_user_meta($uid, 'sms_docs_status', 'pending');
            // Notificar Admin (código resumido)
            $admin_email = get_option('admin_email');
            wp_mail($admin_email, "Docs Subidos", "Proveedor ID $uid subió documentos.");
            echo "<script>window.location.href = '" . wc_get_account_endpoint_url('zona-proveedor') . "?docs_uploaded=1';</script>";
            exit;
        }
    }

    if(isset($_GET['docs_uploaded'])) echo '<div class="woocommerce-message">✅ Documentos enviados.</div>';

    // DATOS DE LECTURA
    $active_pages_ids = get_option('sms_active_service_pages', []);
    $approved_servs = get_user_meta($uid, 'sms_approved_services', true) ?: [];
    $requested_servs = get_user_meta($uid, 'sms_requested_services', true) ?: [];
    $balance = (int) get_user_meta($uid, 'sms_wallet_balance', true);
    $docs_urls = get_user_meta($uid, 'sms_company_docs', true) ?: [];

    // OBTENER DATOS DE PERFIL PARA EL FORMULARIO
    $p_razon = get_user_meta($uid, 'billing_company', true);
    $p_comercial = get_user_meta($uid, 'sms_commercial_name', true);
    $p_nit = get_user_meta($uid, 'sms_nit', true);
    $p_address = get_user_meta($uid, 'billing_address_1', true);
    $p_phone = get_user_meta($uid, 'billing_phone', true);
    $p_whatsapp = get_user_meta($uid, 'sms_whatsapp_notif', true);
    $p_email = get_user_meta($uid, 'billing_email', true) ?: wp_get_current_user()->user_email;
    $p_advisor = get_user_meta($uid, 'sms_advisor_name', true);
    $p_desc = get_user_meta($uid, 'sms_company_desc', true);

    // OBTENER LEADS
    $leads = [];
    if (!empty($approved_servs)) {
        $ids_str = implode(',', array_map('intval', $approved_servs));
        $leads = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sms_leads WHERE service_page_id IN ($ids_str) AND status = 'approved' ORDER BY created_at DESC LIMIT 50");
    }
    
    // ESTILOS Y UI
    ?>
    <style>
        .sms-layout { display: flex; flex-wrap: wrap; gap: 25px; } 
        .sms-col-main { flex: 2; min-width: 300px; } 
        .sms-col-side { flex: 1; min-width: 280px; } 
        .sms-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); padding: 25px; margin-bottom: 25px; border:1px solid #eee; }
        .sms-input-group { margin-bottom: 15px; }
        .sms-input-group label { display: block; font-weight: bold; font-size: 12px; margin-bottom: 5px; }
        .sms-input-group input, .sms-input-group textarea { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        .row-2-col { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    </style>

    <div class="sms-layout">
        <div class="sms-col-main">
            <h3>📋 Tablero de Oportunidades</h3>
            <?php if(empty($approved_servs)): ?>
                <div class="sms-card" style="border-left: 5px solid orange;">
                    <p>⚠️ <strong>Perfil Incompleto:</strong> Configura tus servicios y completa tus datos de empresa para ver cotizaciones.</p>
                </div>
            <?php elseif(empty($leads)): ?>
                <div class="sms-card"><p>No hay cotizaciones activas en tus categorías.</p></div>
            <?php else: ?>
                <div class="sms-card">
                <?php foreach($leads as $l): ?>
                    <div style="border-bottom:1px solid #eee; padding:15px 0;">
                        <div style="display:flex; justify-content:space-between;">
                            <strong><?php echo $l->client_company?:'Particular'; ?></strong>
                            <span style="color:green; font-weight:bold;"><?php echo $l->cost_credits; ?> cr</span>
                        </div>
                        <span style="font-size:12px; color:#666;">📍 <?php echo $l->city; ?></span>
                        <p style="margin:5px 0;"><?php echo wp_trim_words($l->requirement, 15); ?></p>
                        <a href="<?php echo site_url('/oportunidad?lid='.$l->id); ?>" class="button button-primary button-small">Ver Detalles y Contactar</a>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="sms-card">
                <h3>🏢 Perfil de Empresa (Público para Clientes)</h3>
                <form method="post">
                    <div class="row-2-col">
                        <div class="sms-input-group">
                            <label>Razón Social (Cámara de Comercio)</label>
                            <input type="text" name="p_razon_social" value="<?php echo esc_attr($p_razon); ?>" required>
                        </div>
                        <div class="sms-input-group">
                            <label>NIT / Identificación Fiscal</label>
                            <input type="text" name="p_nit" value="<?php echo esc_attr($p_nit); ?>" required>
                        </div>
                    </div>

                    <div class="sms-input-group">
                        <label>Nombre Comercial (Marca visible al cliente)</label>
                        <input type="text" name="p_commercial_name" value="<?php echo esc_attr($p_comercial); ?>" placeholder="Ej: Soluciones Rápidas SAS" required>
                    </div>

                    <div class="row-2-col">
                         <div class="sms-input-group">
                            <label>Dirección Física</label>
                            <input type="text" name="p_address" value="<?php echo esc_attr($p_address); ?>">
                        </div>
                        <div class="sms-input-group">
                            <label>Email Contacto</label>
                            <input type="email" name="p_email" value="<?php echo esc_attr($p_email); ?>">
                        </div>
                    </div>

                    <div class="row-2-col">
                         <div class="sms-input-group">
                            <label>Teléfono Fijo / PBX</label>
                            <input type="text" name="p_phone" value="<?php echo esc_attr($p_phone); ?>">
                        </div>
                        <div class="sms-input-group">
                            <label>WhatsApp (Notificaciones y Clientes)</label>
                            <input type="text" name="p_whatsapp" value="<?php echo esc_attr($p_whatsapp); ?>" placeholder="+57300..." required>
                        </div>
                    </div>

                    <div class="sms-input-group">
                        <label>Nombre Asesor Encargado</label>
                        <input type="text" name="p_advisor" value="<?php echo esc_attr($p_advisor); ?>">
                    </div>

                    <div class="sms-input-group">
                        <label>Descripción de la Empresa (Servicios, experiencia...)</label>
                        <textarea name="p_desc" rows="3"><?php echo esc_textarea($p_desc); ?></textarea>
                    </div>

                    <hr>
                    <h4>⚙️ Configuración de Servicios</h4>
                    <div style="height:150px; overflow-y:scroll; border:1px solid #eee; padding:10px; margin-bottom:15px; background:#f9f9f9;">
                        <?php foreach($active_pages_ids as $pid): 
                            $p = get_post($pid); if(!$p) continue;
                            $is_requested = in_array($pid, $requested_servs);
                            $is_approved = in_array($pid, $approved_servs);
                        ?>
                        <label style="display:block; font-size:12px; margin-bottom:5px;">
                            <input type="checkbox" name="p_servs[]" value="<?php echo $pid; ?>" <?php checked($is_requested); ?>>
                            <?php echo $p->post_title; ?>
                            <?php if($is_approved): ?> <span style="color:green; font-weight:bold;">(✅ Aprobado)</span>
                            <?php elseif($is_requested): ?> <span style="color:orange;">(⏳ Pendiente)</span>
                            <?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" name="save_provider_profile" class="button button-primary" style="width:100%;">💾 Guardar Perfil Completo</button>
                </form>
            </div>
        </div>

        <div class="sms-col-side">
            <div class="sms-col-side">
            <div class="sms-card" style="text-align:center; border: 2px solid #007cba;">
                <h4 style="margin-top:0;">🌐 Tu Presencia Digital</h4>
                <p style="font-size:12px;">Así ven los clientes tu empresa:</p>
                <a href="<?php echo site_url('/perfil-proveedor?uid='.$uid); ?>" target="_blank" class="button button-primary" style="width:100%;">👁️ Ver mi Perfil Público</a>
            </div>

            <div class="sms-card" style="background:#e8f0fe; text-align:center;">
                
                
            <div class="sms-card" style="background:#e8f0fe; text-align:center;">
                <h3>💰 Saldo Disponible</h3>
                <h2 style="color:#007cba; margin:10px 0;"><?php echo $balance; ?> Créditos</h2>
                <a href="/tienda" class="button">Recargar Saldo</a>
            </div>

            <div class="sms-card">
                <h4>📂 Documentación Legal</h4>
                <p style="font-size:12px;">Requisito: RUT y Cámara de Comercio vigentes.</p>
                
                <?php if($docs_status == 'verified'): ?>
                    <div style="color:green; background:#d4edda; padding:10px; border-radius:5px; border:1px solid #c3e6cb;">
                        <strong>✅ Documentación Aprobada</strong>
                        <p style="font-size:11px; margin:5px 0;">Sus documentos han sido validados. Para actualizar, contacte a soporte.</p>
                    </div>
                <?php else: ?>
                    
                    <?php if($docs_status == 'pending'): ?>
                        <div style="color:#856404; background:#fff3cd; padding:10px; border-radius:5px; margin-bottom:10px;">⏳ En Revisión por Admin</div>
                    <?php endif; ?>

                    <form method="post" enctype="multipart/form-data">
                        <input type="file" name="p_docs[]" multiple accept=".pdf,.jpg,.png" style="font-size:12px; margin-bottom:10px;">
                        <button type="submit" class="button button-small">📤 Subir Archivos</button>
                    </form>

                <?php endif; ?>

                <div class="doc-list" style="margin-top:15px;">
                    <?php if(!empty($docs_urls)): foreach($docs_urls as $idx => $url): ?>
                        <div style="background:#f1f1f1; padding:5px; margin-bottom:3px; font-size:11px; display:flex; justify-content:space-between;">
                            <span>Doc #<?php echo $idx+1; ?></span>
                            <a href="<?php echo $url; ?>" target="_blank">Ver</a>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
});


