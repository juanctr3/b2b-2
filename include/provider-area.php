<?php
if (!defined('ABSPATH')) exit;

// ==========================================
// 1. INTEGRACIÓN CON MI CUENTA (WOOCOMMERCE)
// ==========================================
add_filter('woocommerce_account_menu_items', function($items) {
    $items['zona-proveedor'] = '🏭 Zona Proveedor';
    return $items;
});

add_action('init', function() { add_rewrite_endpoint('zona-proveedor', EP_ROOT | EP_PAGES); });

add_action('woocommerce_account_zona-proveedor_endpoint', function() {
    $uid = get_current_user_id();
    global $wpdb;

    // ==========================================
    // A. GUARDAR PERFIL Y VERIFICACIÓN WHATSAPP
    // ==========================================
    if (isset($_POST['save_provider_profile'])) {
        // 1. Obtener datos previos para detectar cambios
        $old_wa = get_user_meta($uid, 'sms_whatsapp_notif', true);
        
        // 2. Construir el nuevo número (País + Celular)
        $country_code = sanitize_text_field($_POST['p_country_code']);
        $phone_raw = sanitize_text_field($_POST['p_whatsapp_raw']);
        $new_wa_clean = $country_code . preg_replace('/[^0-9]/', '', $phone_raw); // Ej: 573001234567

        // 3. Guardar Datos Generales de la Empresa
        update_user_meta($uid, 'billing_company', sanitize_text_field($_POST['p_razon_social']));
        update_user_meta($uid, 'sms_commercial_name', sanitize_text_field($_POST['p_commercial_name']));
        update_user_meta($uid, 'sms_nit', sanitize_text_field($_POST['p_nit']));
        
        // Datos de Contacto
        update_user_meta($uid, 'billing_address_1', sanitize_text_field($_POST['p_address']));
        update_user_meta($uid, 'billing_email', sanitize_email($_POST['p_email']));
        
        // Guardamos el WhatsApp también como 'billing_phone' para compatibilidad con el Admin
        update_user_meta($uid, 'billing_phone', $new_wa_clean); 
        update_user_meta($uid, 'sms_whatsapp_notif', $new_wa_clean);

        // Detalles Adicionales
        update_user_meta($uid, 'sms_advisor_name', sanitize_text_field($_POST['p_advisor']));
        update_user_meta($uid, 'sms_company_desc', sanitize_textarea_field($_POST['p_desc']));

        // Servicios Solicitados
        $requested_pages = $_POST['p_servs'] ?? [];
        update_user_meta($uid, 'sms_requested_services', $requested_pages);
        
        // Asegurar que exista el array de aprobados
        if(!get_user_meta($uid, 'sms_approved_services', true)) {
            update_user_meta($uid, 'sms_approved_services', []);
        }

        // 4. LÓGICA DE VERIFICACIÓN DE WHATSAPP
        $msg_extra = "";
        
        // Si el número cambió respecto al anterior, forzamos re-verificación
        if ($new_wa_clean && $new_wa_clean !== $old_wa) {
            update_user_meta($uid, 'sms_phone_status', 'pending');
            
            if (function_exists('sms_send_msg')) {
                $site_name = get_bloginfo('name');
                $txt = "🔐 *Verificación de Seguridad*\n\nHola, hemos detectado un cambio de número en tu cuenta de *$site_name*.\n\nPara activar las notificaciones, responde:\n*CONFIRMADO*";
                sms_send_msg($new_wa_clean, $txt);
                $msg_extra = "<br>📨 <strong>¡Número Actualizado!</strong> Te enviamos un WhatsApp. Responde <b>CONFIRMADO</b> para activarlo.";
            }
        } 
        // Si no cambió pero sigue pendiente
        elseif (get_user_meta($uid, 'sms_phone_status', true) !== 'verified') {
            $msg_extra = "<br>⚠️ Tu WhatsApp aún no está verificado. Busca nuestro mensaje y responde <b>CONFIRMADO</b>.";
        }

        echo '<div class="woocommerce-message">✅ Perfil de empresa actualizado.' . $msg_extra . '</div>';
    }

    // ==========================================
    // B. SOLICITUD DE NUEVO SERVICIO (EXTRA)
    // ==========================================
    if (isset($_POST['req_new_service'])) {
        $serv_name = sanitize_text_field($_POST['new_service_name']);
        if($serv_name) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sms_service_requests WHERE provider_user_id = %d AND requested_service = %s", $uid, $serv_name));
            if(!$exists) {
                $wpdb->insert("{$wpdb->prefix}sms_service_requests", ['provider_user_id' => $uid, 'requested_service' => $serv_name]);
                echo '<div class="woocommerce-message">✅ Solicitud de categoría enviada al admin.</div>';
            }
        }
    }

    // ==========================================
    // C. CARGA DE DOCUMENTOS
    // ==========================================
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
            // Notificar Admin
            $admin_phone = get_option('sms_admin_phone');
            $prov_name = wp_get_current_user()->display_name;
            if(function_exists('sms_send_msg') && $admin_phone) {
                sms_send_msg($admin_phone, "📂 *Admin:* El proveedor $prov_name ha subido documentos.");
            }
            
            echo "<script>window.location.href = '" . wc_get_account_endpoint_url('zona-proveedor') . "?docs_uploaded=1';</script>";
            exit;
        }
    }

    if(isset($_GET['docs_uploaded'])) echo '<div class="woocommerce-message">✅ Documentos enviados a revisión.</div>';

    // ==========================================
    // D. PREPARACIÓN DE DATOS PARA LA VISTA
    // ==========================================
    $active_pages_ids = get_option('sms_active_service_pages', []);
    $approved_servs = get_user_meta($uid, 'sms_approved_services', true) ?: [];
    $requested_servs = get_user_meta($uid, 'sms_requested_services', true) ?: [];
    $balance = (int) get_user_meta($uid, 'sms_wallet_balance', true);
    $docs_urls = get_user_meta($uid, 'sms_company_docs', true) ?: [];

    // Datos del formulario
    $p_razon = get_user_meta($uid, 'billing_company', true);
    $p_comercial = get_user_meta($uid, 'sms_commercial_name', true);
    $p_nit = get_user_meta($uid, 'sms_nit', true);
    $p_address = get_user_meta($uid, 'billing_address_1', true);
    $p_email = get_user_meta($uid, 'billing_email', true) ?: wp_get_current_user()->user_email;
    $p_advisor = get_user_meta($uid, 'sms_advisor_name', true);
    $p_desc = get_user_meta($uid, 'sms_company_desc', true);
    
    // WhatsApp y Estado
    $full_whatsapp = get_user_meta($uid, 'sms_whatsapp_notif', true);
    $wa_status = get_user_meta($uid, 'sms_phone_status', true);

    // OBTENER LEADS (Solo de servicios aprobados)
    $leads = [];
    if (!empty($approved_servs)) {
        $ids_str = implode(',', array_map('intval', $approved_servs));
        $leads = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sms_leads WHERE service_page_id IN ($ids_str) AND status = 'approved' ORDER BY created_at DESC LIMIT 50");
    }
    
    // ==========================================
    // E. RENDERIZADO (HTML)
    // ==========================================
    ?>
    <style>
        .sms-layout { display: flex; flex-wrap: wrap; gap: 25px; } 
        .sms-col-main { flex: 2; min-width: 300px; } 
        .sms-col-side { flex: 1; min-width: 280px; } 
        .sms-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); padding: 25px; margin-bottom: 25px; border:1px solid #eee; }
        .sms-input-group { margin-bottom: 15px; }
        .sms-input-group label { display: block; font-weight: bold; font-size: 12px; margin-bottom: 5px; }
        .sms-input-group input, .sms-input-group textarea, .sms-input-group select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .row-2-col { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .doc-list { margin-top:10px; }
        .doc-item { background:#f9f9f9; padding:5px; margin-bottom:3px; font-size:11px; display:flex; justify-content:space-between; border-radius:4px; }
    </style>

    <div class="sms-layout">
        <div class="sms-col-main">
            
            <h3>📋 Oportunidades Disponibles</h3>
            <?php if(empty($approved_servs)): ?>
                <div class="sms-card" style="border-left: 5px solid orange;">
                    <p>⚠️ <strong>Perfil Incompleto:</strong> Configura tus servicios abajo y espera la aprobación del administrador.</p>
                </div>
            <?php elseif(empty($leads)): ?>
                <div class="sms-card"><p>No hay cotizaciones activas en tus categorías por ahora.</p></div>
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
                <h3>🏢 Perfil de Empresa (Público)</h3>
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
                            <label>Email Corporativo</label>
                            <input type="email" name="p_email" value="<?php echo esc_attr($p_email); ?>">
                        </div>
                    </div>

                    <div class="sms-input-group">
                        <label>WhatsApp para Notificaciones y Clientes 
                            <?php echo ($wa_status=='verified') ? '<span style="color:green">✅ (Verificado)</span>' : '<span style="color:red; font-size:11px;">⚠️ (Sin verificar)</span>'; ?>
                        </label>
                        <div style="display:flex; gap:10px;">
                            <select name="p_country_code" style="width:130px; flex-shrink:0;">
                                <option value="57">🇨🇴 +57</option>
                                <option value="52">🇲🇽 +52</option>
                                <option value="51">🇵🇪 +51</option>
                                <option value="54">🇦🇷 +54</option>
                                <option value="56">🇨🇱 +56</option>
                                <option value="34">🇪🇸 +34</option>
                                <option value="1">🇺🇸 +1</option>
                            </select>
                            <input type="number" name="p_whatsapp_raw" value="<?php 
                                // Limpiamos visualmente el indicativo para que el usuario solo vea su número local
                                echo preg_replace('/^(57|52|51|54|56|34|1)/', '', $full_whatsapp); 
                            ?>" placeholder="Ej: 3001234567" required>
                        </div>
                        <p class="description" style="font-size:11px; color:#666;">Selecciona tu país e ingresa tu número celular sin espacios.</p>
                    </div>

                    <div class="sms-input-group">
                        <label>Nombre del Asesor Encargado</label>
                        <input type="text" name="p_advisor" value="<?php echo esc_attr($p_advisor); ?>">
                    </div>

                    <div class="sms-input-group">
                        <label>Descripción de la Empresa (Experiencia, Servicios...)</label>
                        <textarea name="p_desc" rows="3"><?php echo esc_textarea($p_desc); ?></textarea>
                    </div>

                    <hr>
                    <h4>⚙️ Selección de Servicios</h4>
                    <p style="font-size:12px; color:#666; margin-bottom:5px;">Marca los servicios que deseas ofrecer. Deben ser aprobados por el administrador.</p>
                    <div style="height:150px; overflow-y:scroll; border:1px solid #eee; padding:10px; margin-bottom:15px; background:#f9f9f9; border-radius:4px;">
                        <?php foreach($active_pages_ids as $pid): 
                            $p = get_post($pid); if(!$p) continue;
                            $is_requested = in_array($pid, $requested_servs);
                            $is_approved = in_array($pid, $approved_servs);
                        ?>
                        <label style="display:block; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="p_servs[]" value="<?php echo $pid; ?>" <?php checked($is_requested); ?>>
                            <?php echo $p->post_title; ?>
                            <?php if($is_approved): ?> <span style="color:green; font-weight:bold; font-size:10px;">● ACTIVO</span>
                            <?php elseif($is_requested): ?> <span style="color:orange; font-size:10px;">● PENDIENTE</span>
                            <?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" name="save_provider_profile" class="button button-primary" style="width:100%; padding:10px;">💾 Guardar Perfil Completo</button>
                </form>
            </div>
        </div>

        <div class="sms-col-side">
            
            <div class="sms-card" style="text-align:center; border: 2px solid #007cba;">
                <h4 style="margin-top:0;">🌐 Tu Presencia Digital</h4>
                <p style="font-size:12px;">Así te ven los clientes:</p>
                <a href="<?php echo site_url('/perfil-proveedor?uid='.$uid); ?>" target="_blank" class="button button-primary" style="width:100%;">👁️ Ver mi Perfil Público</a>
            </div>

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
                        <strong>✅ Aprobado</strong>
                        <p style="font-size:11px; margin:5px 0;">Tu empresa está verificada.</p>
                    </div>
                <?php else: ?>
                    
                    <?php if($docs_status == 'pending'): ?>
                        <div style="color:#856404; background:#fff3cd; padding:10px; border-radius:5px; margin-bottom:10px;">⏳ En Revisión</div>
                    <?php endif; ?>

                    <form method="post" enctype="multipart/form-data">
                        <input type="file" name="p_docs[]" multiple accept=".pdf,.jpg,.png" style="font-size:12px; margin-bottom:10px;">
                        <button type="submit" class="button button-small">📤 Subir Archivos</button>
                    </form>

                <?php endif; ?>

                <div class="doc-list">
                    <?php if(!empty($docs_urls)): foreach($docs_urls as $idx => $url): ?>
                        <div class="doc-item">
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
