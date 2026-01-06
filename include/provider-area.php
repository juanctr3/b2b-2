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

    // --- PROCESAR FORMULARIO (DATOS Y ARCHIVOS) ---
    if (isset($_POST['save_provider'])) {
        // 1. Guardar Datos Básicos
        $code = sanitize_text_field($_POST['p_country_code']);
        $raw_phone = sanitize_text_field($_POST['p_phone_raw']);
        $new_phone = $code . $raw_phone; 
        $old_phone = get_user_meta($uid, 'billing_phone', true);

        update_user_meta($uid, 'billing_phone', $new_phone);
        update_user_meta($uid, 'sms_advisor_name', sanitize_text_field($_POST['p_advisor']));
        update_user_meta($uid, 'sms_subscribed_pages', $_POST['p_servs'] ?? []);
        
        // 2. Notificación de cambio de teléfono
        if ($new_phone != $old_phone) {
            update_user_meta($uid, 'sms_phone_status', 'pending');
            $msg = "👋 Hola, para activar tu cuenta de proveedor, responde a este mensaje con la palabra: *ACEPTO*";
            if(function_exists('sms_send_msg')) sms_send_msg($new_phone, $msg);
            echo '<div class="woocommerce-message">✅ Número guardado. Te enviamos un WhatsApp para verificar.</div>';
        }

        // 3. Subida de Documentos (RUT / Cámara)
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        $doc_keys = ['p_doc_rut', 'p_doc_camara'];
        $docs_uploaded = false;

        foreach($doc_keys as $key) {
            if (!empty($_FILES[$key]['name'])) {
                $upload = wp_handle_upload($_FILES[$key], ['test_form' => false]);
                if (isset($upload['url'])) {
                    update_user_meta($uid, 'sms_file_' . $key, $upload['url']);
                    $docs_uploaded = true;
                }
            }
        }

        if ($docs_uploaded) {
            update_user_meta($uid, 'sms_docs_verified', 'pending'); // Resetear a pendiente si se suben nuevos
            echo '<div class="woocommerce-message">📂 Documentos subidos correctamente. En espera de revisión.</div>';
        } else {
            echo '<div class="woocommerce-message">✅ Perfil actualizado correctamente.</div>';
        }
    }

    // --- PROCESAR SOLICITUD DE NUEVO SERVICIO ---
    if (isset($_POST['req_new_service'])) {
        $serv_name = sanitize_text_field($_POST['new_service_name']);
        if($serv_name) {
            $wpdb->insert("{$wpdb->prefix}sms_service_requests", ['provider_user_id' => $uid, 'requested_service' => $serv_name]);
            echo '<div class="woocommerce-message">✅ Solicitud enviada.</div>';
        }
    }

    // --- CARGAR DATOS DEL USUARIO ---
    $active_pages_ids = get_option('sms_active_service_pages', []);
    if (!is_array($active_pages_ids)) $active_pages_ids = []; 
    
    $my_servs = get_user_meta($uid, 'sms_subscribed_pages', true) ?: [];
    $balance = (int) get_user_meta($uid, 'sms_wallet_balance', true);
    $advisor = get_user_meta($uid, 'sms_advisor_name', true);
    $phone_status = get_user_meta($uid, 'sms_phone_status', true);
    $full_phone = get_user_meta($uid, 'billing_phone', true);
    
    // Datos de Verificación
    $doc_rut = get_user_meta($uid, 'sms_file_p_doc_rut', true);
    $doc_camara = get_user_meta($uid, 'sms_file_p_doc_camara', true);
    $docs_status = get_user_meta($uid, 'sms_docs_verified', true);

    // Parsear teléfono para mostrar en inputs
    $current_code = '+57'; $current_raw = $full_phone;
    if(strpos($full_phone, '+57')===0){ $current_code='+57'; $current_raw=substr($full_phone,3); }
    elseif(strpos($full_phone, '+52')===0){ $current_code='+52'; $current_raw=substr($full_phone,3); }
    elseif(strpos($full_phone, '+51')===0){ $current_code='+51'; $current_raw=substr($full_phone,3); }
    elseif(strpos($full_phone, '+34')===0){ $current_code='+34'; $current_raw=substr($full_phone,3); }
    elseif(strpos($full_phone, '+1')===0){ $current_code='+1'; $current_raw=substr($full_phone,2); }
    elseif(strpos($full_phone, '+')===0){ $current_code='+'.substr($full_phone,1,2); $current_raw=substr($full_phone,3); }

    $view_lead_url = site_url('/oportunidad'); 

    // --- CARGAR LEADS (COTIZACIONES) ---
    $leads = [];
    if (!empty($my_servs)) {
        $ids_str = implode(',', array_map('intval', $my_servs));
        $leads = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sms_leads WHERE service_page_id IN ($ids_str) AND status = 'approved' ORDER BY created_at DESC LIMIT 50");
    }
    $my_unlocks = $wpdb->get_col("SELECT lead_id FROM {$wpdb->prefix}sms_lead_unlocks WHERE provider_user_id = $uid");

    ?>
    <style>
        .sms-layout { display: flex; flex-wrap: wrap; gap: 25px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Open Sans", "Helvetica Neue", sans-serif; }
        .sms-col-main { flex: 2; min-width: 300px; }
        .sms-col-side { flex: 1; min-width: 280px; }
        
        .sms-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px solid #f0f0f0; padding: 25px; margin-bottom: 25px; transition: transform 0.2s; }
        .sms-card:hover { border-color: #e0e0e0; }
        .sms-card h3, .sms-card h4 { margin-top: 0; color: #2c3e50; font-weight: 700; }
        
        /* Billetera */
        .sms-wallet { background: linear-gradient(135deg, #007cba 0%, #005a8c 100%); color: white; text-align: center; }
        .sms-wallet h3 { color: white; opacity: 0.9; font-weight: 400; font-size: 16px; margin-bottom: 5px; }
        .sms-balance { font-size: 38px; font-weight: 800; margin: 0; line-height: 1.2; }
        .sms-btn-recharge { background: #ffc107; color: #333; font-weight: bold; padding: 10px 20px; border-radius: 50px; text-decoration: none; display: inline-block; margin-top: 15px; transition: all 0.3s; border: none; cursor: pointer; }
        .sms-btn-recharge:hover { background: #ffca2c; transform: translateY(-2px); color: #000; }

        /* Leads */
        .sms-lead-card { border: 1px solid #eee; border-radius: 8px; padding: 15px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; background: #fff; transition: background 0.2s; }
        .sms-lead-card:hover { background: #f9fbfd; border-color: #dbeafe; }
        .sms-badge-views { font-size: 11px; background: #e2e8f0; color: #475569; padding: 3px 8px; border-radius: 10px; margin-left: 5px; }
        .sms-btn-action { padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-block; }
        .btn-unlock { background: #10b981; color: white; }
        .btn-buy { background: #f59e0b; color: white; }
        
        /* Configuración */
        .sms-label { display: block; margin-bottom: 5px; font-weight: 600; color: #555; font-size: 13px; margin-top: 10px;}
        .sms-input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; margin-bottom: 5px; background: #f9f9f9; }
        .sms-input:focus { background: #fff; border-color: #007cba; outline: none; }
        
        /* Servicios */
        .sms-services-box { border: 1px solid #ddd; border-radius: 6px; max-height: 300px; overflow-y: auto; background: #fff; margin-bottom: 15px; }
        .sms-service-item { display: block; padding: 10px 15px; border-bottom: 1px solid #f0f0f0; cursor: pointer; transition: background 0.2s; font-size: 14px; }
        .sms-service-item:hover { background: #f0f7ff; }
        .sms-service-item input { margin-right: 10px; transform: scale(1.2); }
        .sms-sticky-search { position: sticky; top: 0; background: #fff; padding: 10px; border-bottom: 1px solid #ddd; z-index: 5; }

        /* Estado Teléfono y Docs */
        .status-badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .st-verified { background: #d1fae5; color: #065f46; }
        .st-pending { background: #fee2e2; color: #991b1b; }
        .doc-link { font-size: 11px; color: #007cba; text-decoration: none; }
    </style>

    <div class="sms-layout">
        <div class="sms-col-main">
            <h3>📋 Oportunidades Disponibles</h3>
            
            <?php if($phone_status != 'verified'): ?>
                <div style="background:#fff3cd; border:1px solid #ffeeba; color:#856404; padding:15px; border-radius:8px; margin-bottom:20px;">
                    🚨 <strong>Tu WhatsApp no está verificado.</strong><br>
                    No recibirás notificaciones. Te hemos enviado un mensaje, por favor responde <strong>ACEPTO</strong>.
                </div>
            <?php endif; ?>

            <?php if (empty($my_servs)): ?>
                <div class="sms-card" style="text-align:center; padding:40px;">
                    <p style="font-size:16px; color:#666;">Selecciona tus servicios en el panel derecho para empezar a ver cotizaciones.</p>
                </div>
            <?php elseif (empty($leads)): ?>
                <div class="sms-card" style="text-align:center;">
                    <p>No hay cotizaciones activas para tus categorías en este momento.</p>
                </div>
            <?php else: ?>
                <div class="sms-card" style="padding:15px;">
                    <?php foreach ($leads as $l): 
                        $is_unlocked = in_array($l->id, $my_unlocks);
                        $ts = strtotime($l->created_at);
                        $fecha = ($ts && date('Y', $ts) > 2000) ? date('d/m/Y', $ts) : 'Hoy';
                        $views = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sms_lead_unlocks WHERE lead_id = {$l->id}");
                        
                        // LÓGICA DE PRIVACIDAD: Ocultar nombre si no está desbloqueado
                        $display_company = $is_unlocked ? esc_html($l->client_company ?: 'Particular') : '🔒 Empresa Confidencial';
                    ?>
                    <div class="sms-lead-card">
                        <div>
                            <div style="font-weight:700; font-size:16px; margin-bottom:4px;">
                                <?php echo $display_company; ?>
                                <span class="sms-badge-views">👁️ <?php echo $views; ?> interesados</span>
                            </div>
                            <div style="font-size:13px; color:#666;">
                                📅 <?php echo $fecha; ?> &nbsp;|&nbsp; 📍 <?php echo esc_html($l->city); ?>
                            </div>
                            <div style="font-size:14px; color:#444; margin-top:5px;">
                                📝 "<?php echo wp_trim_words($l->requirement, 12); ?>"
                            </div>
                        </div>
                        <div style="text-align:right; min-width:120px;">
                            <?php if ($is_unlocked): ?>
                                <a href="<?php echo $view_lead_url . '?lid=' . $l->id; ?>" class="sms-btn-action btn-unlock">👁️ VER DATOS</a>
                            <?php else: ?>
                                <a href="<?php echo $view_lead_url . '?lid=' . $l->id; ?>" class="sms-btn-action btn-buy">🔓 <?php echo $l->cost_credits; ?> CR</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <div class="sms-card" style="background:#f8f9fa; border:none;">
                <h4>¿No encuentras tu categoría?</h4>
                <form method="post" style="display:flex; gap:10px;">
                    <input type="text" name="new_service_name" class="sms-input" style="margin:0;" placeholder="Ej: Reparación de Drones" required>
                    <button type="submit" name="req_new_service" class="button">Solicitar</button>
                </form>
            </div>
        </div>

        <div class="sms-col-side">
            <div class="sms-card sms-wallet">
                <h3>💰 Tu Saldo Disponible</h3>
                <p class="sms-balance"><?php echo $balance; ?></p>
                <small>Créditos</small><br>
                <a href="/tienda" class="sms-btn-recharge">Recargar Ahora</a>
            </div>

            <div class="sms-card">
                <h4>⚙️ Configuración y Verificación</h4>
                
                <form method="post" enctype="multipart/form-data">
                    <span class="sms-label">Nombre del Asesor (Visible al cliente)</span>
                    <input type="text" name="p_advisor" value="<?php echo esc_attr($advisor); ?>" class="sms-input" placeholder="Ej: Juan Pérez" required>

                    <span class="sms-label">WhatsApp de Notificaciones 
                        <?php echo ($phone_status=='verified') ? '<span class="status-badge st-verified">VERIFICADO</span>' : '<span class="status-badge st-pending">PENDIENTE</span>'; ?>
                    </span>
                    
                    <div style="display:flex; gap:0; margin-bottom:15px;">
                        <select id="p_country_select" class="sms-input" style="width:110px; margin:0; border-radius:6px 0 0 6px; border-right:none; background:#eee;" onchange="updateCountryCode()">
                            <option value="+57" <?php selected($current_code, '+57'); ?>>🇨🇴 +57</option>
                            <option value="+52" <?php selected($current_code, '+52'); ?>>🇲🇽 +52</option>
                            <option value="+51" <?php selected($current_code, '+51'); ?>>🇵🇪 +51</option>
                            <option value="+34" <?php selected($current_code, '+34'); ?>>🇪🇸 +34</option>
                            <option value="+1" <?php selected($current_code, '+1'); ?>>🇺🇸 +1</option>
                        </select>
                        <input type="hidden" name="p_country_code" id="hidden_code" value="<?php echo $current_code; ?>">
                        <input type="number" name="p_phone_raw" value="<?php echo esc_attr($current_raw); ?>" class="sms-input" style="margin:0; border-radius:0 6px 6px 0;" placeholder="3001234567" required>
                    </div>

                    <div style="background:#f0f7ff; padding:10px; border-radius:6px; margin-bottom:15px; border:1px solid #cce5ff;">
                        <h5 style="margin:0 0 5px 0; color:#0056b3;">📂 Verificación de Empresa</h5>
                        <p style="font-size:11px; margin:0 0 10px 0; color:#555;">Sube RUT y Cámara de Comercio para generar confianza.</p>

                        <?php if($docs_status == 'yes'): ?>
                            <div style="color:green; font-weight:bold; font-size:12px; margin-bottom:5px;">✅ Documentos Aprobados</div>
                        <?php elseif($docs_status == 'pending'): ?>
                            <div style="color:orange; font-weight:bold; font-size:12px; margin-bottom:5px;">⏳ En Revisión</div>
                        <?php endif; ?>

                        <label class="sms-label" style="font-size:12px;">RUT (PDF/Imagen)</label>
                        <?php if($doc_rut) echo "<a href='$doc_rut' target='_blank' class='doc-link'>Ver actual</a><br>"; ?>
                        <input type="file" name="p_doc_rut" class="sms-input" style="font-size:12px;">

                        <label class="sms-label" style="font-size:12px;">Cámara de Comercio</label>
                        <?php if($doc_camara) echo "<a href='$doc_camara' target='_blank' class='doc-link'>Ver actual</a><br>"; ?>
                        <input type="file" name="p_doc_camara" class="sms-input" style="font-size:12px;">
                    </div>

                    <span class="sms-label">Mis Servicios Suscritos</span>
                    <div class="sms-services-box">
                        <div class="sms-sticky-search">
                            <input type="text" id="servSearch" class="sms-input" style="margin:0; padding:8px;" placeholder="🔍 Buscar servicio...">
                        </div>
                        <div id="servList">
                            <?php if(!empty($active_pages_ids)): foreach($active_pages_ids as $pid): 
                                $p = get_post($pid); if(!$p) continue;
                            ?>
                            <label class="sms-service-item serv-item">
                                <input type="checkbox" name="p_servs[]" value="<?php echo $pid; ?>" <?php echo in_array($pid, $my_servs) ? 'checked' : ''; ?>>
                                <span><?php echo $p->post_title; ?></span>
                            </label>
                            <?php endforeach; else: ?>
                                <div style="padding:10px; font-size:12px; color:red;">No hay servicios habilitados por el admin.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <button type="submit" name="save_provider" class="button button-primary" style="width:100%; margin-top:20px; font-size:16px; padding:10px;">💾 Guardar Cambios</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function updateCountryCode() {
            document.getElementById('hidden_code').value = document.getElementById('p_country_select').value;
        }
        document.getElementById('servSearch').addEventListener('keyup', function() {
            var val = this.value.toLowerCase();
            document.querySelectorAll('.serv-item').forEach(function(item) {
                var text = item.querySelector('span').innerText.toLowerCase();
                item.style.display = text.indexOf(val) > -1 ? 'block' : 'none';
            });
        });
    </script>
    <?php
});
