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
        // 1. Obtener datos previos
        $old_wa = get_user_meta($uid, 'sms_whatsapp_notif', true);
        
        // 2. Construir nuevo número
        $country_code = sanitize_text_field($_POST['p_country_code']);
        $phone_raw = sanitize_text_field($_POST['p_whatsapp_raw']);
        $new_wa_clean = $country_code . preg_replace('/[^0-9]/', '', $phone_raw);

        // 3. Guardar Datos
        update_user_meta($uid, 'billing_company', sanitize_text_field($_POST['p_razon_social']));
        update_user_meta($uid, 'sms_commercial_name', sanitize_text_field($_POST['p_commercial_name']));
        update_user_meta($uid, 'sms_nit', sanitize_text_field($_POST['p_nit']));
        update_user_meta($uid, 'billing_address_1', sanitize_text_field($_POST['p_address']));
        update_user_meta($uid, 'billing_phone', sanitize_text_field($_POST['p_phone']));
        update_user_meta($uid, 'billing_email', sanitize_email($_POST['p_email']));
        update_user_meta($uid, 'billing_phone', $new_wa_clean); 
        update_user_meta($uid, 'sms_whatsapp_notif', $new_wa_clean);
        update_user_meta($uid, 'sms_advisor_name', sanitize_text_field($_POST['p_advisor']));
        update_user_meta($uid, 'sms_company_desc', sanitize_textarea_field($_POST['p_desc']));

        // Servicios Solicitados
        $requested_pages = $_POST['p_servs'] ?? [];
        update_user_meta($uid, 'sms_requested_services', $requested_pages);
        
        if(!get_user_meta($uid, 'sms_approved_services', true)) {
            update_user_meta($uid, 'sms_approved_services', []);
        }

        // 4. Lógica Verificación WhatsApp
        $msg_extra = "";
        if ($new_wa_clean && $new_wa_clean !== $old_wa) {
            update_user_meta($uid, 'sms_phone_status', 'pending');
            if (function_exists('sms_send_msg')) {
                $site_name = get_bloginfo('name');
                $txt = "🔐 *Verificación de Seguridad*\n\nHola, detectamos un cambio de número en *$site_name*.\nResponde: *CONFIRMADO*";
                sms_send_msg($new_wa_clean, $txt);
                $msg_extra = "<br>📨 <strong>¡Número Actualizado!</strong> Te enviamos un WhatsApp. Responde <b>CONFIRMADO</b> para activarlo.";
            }
        } elseif (get_user_meta($uid, 'sms_phone_status', true) !== 'verified') {
            $msg_extra = "<br>⚠️ Tu WhatsApp aún no está verificado.";
        }

        echo '<div class="woocommerce-message">✅ Perfil actualizado.' . $msg_extra . '</div>';
    }

    // ==========================================
    // B. SOLICITUD DE NUEVO SERVICIO (CON NOTIFICACIÓN ADMIN)
    // ==========================================
    if (isset($_POST['req_new_service'])) {
        $serv_name = sanitize_text_field($_POST['new_service_name']);
        if($serv_name) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sms_service_requests WHERE provider_user_id = %d AND requested_service = %s", $uid, $serv_name));
            if(!$exists) {
                $wpdb->insert("{$wpdb->prefix}sms_service_requests", ['provider_user_id' => $uid, 'requested_service' => $serv_name]);
                
                // NOTIFICAR AL ADMIN
                $admin_phone = get_option('sms_admin_phone');
                $prov_name = wp_get_current_user()->display_name;
                
                if(function_exists('sms_send_msg') && $admin_phone) {
                    $msg_admin = "🔔 *Nueva Solicitud de Categoría*\n\nEl proveedor *$prov_name* solicita:\n👉 $serv_name\n\nVe al panel para crearla y aprobarla.";
                    sms_send_msg($admin_phone, $msg_admin);
                }

                echo '<div class="woocommerce-message">✅ Solicitud enviada. Te avisaremos cuando la categoría sea creada.</div>';
            } else {
                echo '<div class="woocommerce-info">⚠️ Ya habías solicitado esta categoría previamente.</div>';
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
                $file = ['name' => $files['name'][$key], 'type' => $files['type'][$key], 'tmp_name' => $files['tmp_name'][$key], 'error' => $files['error'][$key], 'size' => $files['size'][$key]];
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

    // DATOS DE LECTURA
    $active_pages_ids = get_option('sms_active_service_pages', []);
    $approved_servs = get_user_meta($uid, 'sms_approved_services', true) ?: [];
    $requested_servs = get_user_meta($uid, 'sms_requested_services', true) ?: [];
    $balance = (int) get_user_meta($uid, 'sms_wallet_balance', true);
    $docs_urls = get_user_meta($uid, 'sms_company_docs', true) ?: [];

    // Form data
    $p_razon = get_user_meta($uid, 'billing_company', true);
    $p_comercial = get_user_meta($uid, 'sms_commercial_name', true);
    $p_nit = get_user_meta($uid, 'sms_nit', true);
    $p_address = get_user_meta($uid, 'billing_address_1', true);
    $p_phone = get_user_meta($uid, 'billing_phone', true);
    $p_whatsapp = get_user_meta($uid, 'sms_whatsapp_notif', true);
    $p_email = get_user_meta($uid, 'billing_email', true) ?: wp_get_current_user()->user_email;
    $p_advisor = get_user_meta($uid, 'sms_advisor_name', true);
    $p_desc = get_user_meta($uid, 'sms_company_desc', true);
    $wa_status = get_user_meta($uid, 'sms_phone_status', true);

    // OBTENER LEADS
    $leads = [];
    if (!empty($approved_servs)) {
        $ids_str = implode(',', array_map('intval', $approved_servs));
        $leads = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sms_leads WHERE service_page_id IN ($ids_str) AND status = 'approved' ORDER BY created_at DESC LIMIT 50");
    }
    
    // UI
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
                <?php foreach($leads as $l): 
                    // Lógica para mostrar Empresa vs Persona
                    $is_company = ($l->client_company !== 'Particular' && $l->client_company !== '(Persona Natural)');
                    $type_label = $is_company ? '🏢 Empresa' : '👤 Persona Natural';
                    $type_bg = $is_company ? '#e3f2fd' : '#f1f8e9';
                    $type_color = $is_company ? '#0d47a1' : '#33691e';
                ?>
                    <div style="border-bottom:1px solid #eee; padding:15px 0;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                            <div>
                                <span style="background:<?php echo $type_bg; ?>; color:<?php echo $type_color; ?>; font-size:11px; padding:3px 8px; border-radius:10px; font-weight:bold; display:inline-block; margin-bottom:5px;">
                                    <?php echo $type_label; ?>
                                </span>
                                <div style="font-weight:bold; font-size:14px; color:#333;">
                                    📍 <?php echo esc_html($l->city); ?>, <?php echo esc_html($l->country); ?>
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <span style="color:green; font-weight:bold; font-size:14px;"><?php echo $l->cost_credits; ?> cr</span>
                            </div>
                        </div>
                        
                        <p style="margin:8px 0; color:#555; line-height:1.4;"><?php echo wp_trim_words($l->requirement, 25); ?></p>
                        
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:10px;">
                            <span style="font-size:11px; color:#999;"><?php echo date_i18n('d M, h:i A', strtotime($l->created_at)); ?></span>
                            <a href="<?php echo site_url('/oportunidad?lid='.$l->id); ?>" class="button button-primary button-small">Ver Detalles y Contactar</a>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="sms-card">
                <h3>🏢 Perfil de Empresa</h3>
                <form method="post">
                    <div class="row-2-col">
                        <div class="sms-input-group"><label>Razón Social</label><input type="text" name="p_razon_social" value="<?php echo esc_attr($p_razon); ?>" required></div>
                        <div class="sms-input-group"><label>NIT</label><input type="text" name="p_nit" value="<?php echo esc_attr($p_nit); ?>" required></div>
                    </div>
                    <div class="sms-input-group"><label>Nombre Comercial</label><input type="text" name="p_commercial_name" value="<?php echo esc_attr($p_comercial); ?>" required></div>
                    <div class="row-2-col">
                         <div class="sms-input-group"><label>Dirección</label><input type="text" name="p_address" value="<?php echo esc_attr($p_address); ?>"></div>
                         <div class="sms-input-group"><label>Email</label><input type="email" name="p_email" value="<?php echo esc_attr($p_email); ?>"></div>
                    </div>
                    <div class="sms-input-group">
                        <label>WhatsApp (Notificaciones) <?php echo ($wa_status=='verified') ? '<span style="color:green">✅</span>' : '<span style="color:red">⚠️</span>'; ?></label>
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
                            <input type="number" name="p_whatsapp_raw" value="<?php echo preg_replace('/^(57|52|51|54|56|34|1)/', '', $p_whatsapp); ?>" required>
                        </div>
                    </div>
                    <div class="sms-input-group"><label>Asesor</label><input type="text" name="p_advisor" value="<?php echo esc_attr($p_advisor); ?>"></div>
                    <div class="sms-input-group"><label>Descripción</label><textarea name="p_desc" rows="3"><?php echo esc_textarea($p_desc); ?></textarea></div>

                    <hr>
                    <h4>⚙️ Selección de Servicios</h4>
                    
                    <input type="text" id="searchServ" onkeyup="filterServices()" placeholder="🔍 Escribe para buscar categoría..." style="margin-bottom:10px; border-color:#007cba;">

                    <div id="servList" style="height:200px; overflow-y:scroll; border:1px solid #eee; padding:10px; margin-bottom:15px; background:#f9f9f9; border-radius:4px;">
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

                    <div style="background:#e8f0fe; padding:15px; border-radius:8px; margin-bottom:20px; border:1px solid #b8daff;">
                        <strong style="display:block; margin-bottom:5px; color:#004085;">¿No encuentras tu categoría?</strong>
                        <div style="display:flex; gap:10px;">
                            <input type="text" name="new_service_name" placeholder="Ej: Mantenimiento de Ascensores" style="flex:1;">
                            <button type="submit" name="req_new_service" class="button button-small" style="background:#004085; color:#fff; border:none;">Solicitar Creación</button>
                        </div>
                    </div>

                    <button type="submit" name="save_provider_profile" class="button button-primary" style="width:100%;">💾 Guardar Perfil Completo</button>
                </form>
            </div>
        </div>

        <div class="sms-col-side">
            <div class="sms-card" style="text-align:center; border: 2px solid #007cba;">
                <h4 style="margin-top:0;">🌐 Tu Presencia Digital</h4>
                <a href="<?php echo site_url('/perfil-proveedor?uid='.$uid); ?>" target="_blank" class="button button-primary" style="width:100%;">👁️ Ver mi Perfil Público</a>
            </div>

            <div class="sms-card" style="background:#e8f0fe; text-align:center;">
                <h3>💰 Saldo Disponible</h3>
                <h2 style="color:#007cba; margin:10px 0;"><?php echo $balance; ?> Créditos</h2>
                <a href="/tienda" class="button">Recargar Saldo</a>
            </div>

            <div class="sms-card">
                <h4>📂 Documentación Legal</h4>
                <?php if($docs_status == 'verified'): ?>
                    <div style="color:green; background:#d4edda; padding:10px; border-radius:5px;">✅ Aprobado</div>
                <?php else: ?>
                    <?php if($docs_status == 'pending'): ?>
                        <div style="color:#856404; background:#fff3cd; padding:10px; border-radius:5px; margin-bottom:10px;">⏳ En Revisión</div>
                    <?php endif; ?>
                    <form method="post" enctype="multipart/form-data">
                        <input type="file" name="p_docs[]" multiple accept=".pdf" style="font-size:12px; margin-bottom:10px;">
                        <button type="submit" class="button button-small">📤 Subir Archivos</button>
                    </form>
                <?php endif; ?>
                
                <div class="doc-list" style="margin-top:10px;">
                    <?php if(!empty($docs_urls)): foreach($docs_urls as $idx => $url): ?>
                        <div class="doc-item" style="background:#f1f1f1; padding:5px; margin-bottom:2px; font-size:11px; display:flex; justify-content:space-between;">
                            <span>Doc #<?php echo $idx+1; ?></span><a href="<?php echo $url; ?>" target="_blank">Ver</a>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function filterServices() {
            var input = document.getElementById("searchServ");
            var filter = input.value.toUpperCase();
            var div = document.getElementById("servList");
            var labels = div.getElementsByTagName("label");
            for (var i = 0; i < labels.length; i++) {
                var txtValue = labels[i].textContent || labels[i].innerText;
                if (txtValue.toUpperCase().indexOf(filter) > -1) {
                    labels[i].style.display = "";
                } else {
                    labels[i].style.display = "none";
                }
            }
        }
    </script>
    <?php
});
