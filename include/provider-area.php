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
    // A. PROCESAR FORMULARIOS
    // ==========================================
    
    // 1. Guardar Perfil (Datos + Logo)
    if (isset($_POST['save_provider_profile'])) {
        $old_wa = get_user_meta($uid, 'sms_whatsapp_notif', true);
        
        $country_code = sanitize_text_field($_POST['p_country_code']);
        $phone_raw = sanitize_text_field($_POST['p_whatsapp_raw']);
        $new_wa_clean = $country_code . preg_replace('/[^0-9]/', '', $phone_raw);

        // Guardar textos
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

        // Guardar Servicios
        $requested_pages = $_POST['p_servs'] ?? [];
        update_user_meta($uid, 'sms_requested_services', $requested_pages);
        if(!get_user_meta($uid, 'sms_approved_services', true)) {
            update_user_meta($uid, 'sms_approved_services', []);
        }

        // --- LÓGICA DE LOGO (NUEVO) ---
        if (!empty($_FILES['p_logo']['name'])) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            
            $file = $_FILES['p_logo'];
            $check = wp_check_filetype($file['name']);
            
            // Solo permitir imágenes
            if (in_array($check['type'], ['image/jpeg', 'image/png', 'image/jpg'])) {
                $upload = wp_handle_upload($file, ['test_form' => false]);
                
                if (!isset($upload['error']) && isset($upload['file'])) {
                    // Redimensionar a 300x300 (Cuadrado perfecto)
                    $editor = wp_get_image_editor($upload['file']);
                    if (!is_wp_error($editor)) {
                        $editor->resize(300, 300, true);
                        $editor->save($upload['file']);
                    }
                    // Guardar URL
                    update_user_meta($uid, 'sms_company_logo', $upload['url']);
                }
            }
        }

        // Lógica Verificación WhatsApp
        $msg_extra = "";
        if ($new_wa_clean && $new_wa_clean !== $old_wa) {
            update_user_meta($uid, 'sms_phone_status', 'pending');
            if (function_exists('sms_send_msg')) {
                $site_name = get_bloginfo('name');
                $txt = "🔐 *Verificación de Seguridad*\n\nHola, detectamos un cambio de número en *$site_name*.\nResponde: *CONFIRMADO*";
                sms_send_msg($new_wa_clean, $txt);
                $msg_extra = "<br>📨 <strong>¡Número Actualizado!</strong> Te enviamos un WhatsApp. Responde <b>CONFIRMADO</b> para activarlo.";
            }
        }
        echo '<div class="woocommerce-message">✅ Perfil y Logo actualizados.' . $msg_extra . '</div>';
    }

    // 2. Solicitud Nuevo Servicio
    if (isset($_POST['req_new_service'])) {
        $serv_name = sanitize_text_field($_POST['new_service_name']);
        if($serv_name) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sms_service_requests WHERE provider_user_id = %d AND requested_service = %s", $uid, $serv_name));
            if(!$exists) {
                $wpdb->insert("{$wpdb->prefix}sms_service_requests", ['provider_user_id' => $uid, 'requested_service' => $serv_name]);
                
                $admin_phone = get_option('sms_admin_phone');
                $prov_name = wp_get_current_user()->display_name;
                if(function_exists('sms_send_msg') && $admin_phone) {
                    $msg_admin = "🔔 *Nueva Solicitud de Categoría*\n\nEl proveedor *$prov_name* solicita:\n👉 $serv_name\n\nVe al panel para crearla.";
                    sms_send_msg($admin_phone, $msg_admin);
                }
                echo '<div class="woocommerce-message">✅ Solicitud enviada.</div>';
            }
        }
    }

    // 3. Subida de Documentos
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

    // ==========================================
    // B. PREPARACIÓN DE DATOS
    // ==========================================
    $active_pages_ids = get_option('sms_active_service_pages', []);
    $approved_servs = get_user_meta($uid, 'sms_approved_services', true) ?: [];
    $requested_servs = get_user_meta($uid, 'sms_requested_services', true) ?: [];
    $balance = (int) get_user_meta($uid, 'sms_wallet_balance', true);
    $docs_urls = get_user_meta($uid, 'sms_company_docs', true) ?: [];

    // Perfil
    $p_razon = get_user_meta($uid, 'billing_company', true);
    $p_comercial = get_user_meta($uid, 'sms_commercial_name', true);
    $p_nit = get_user_meta($uid, 'sms_nit', true);
    $p_address = get_user_meta($uid, 'billing_address_1', true);
    $p_phone = get_user_meta($uid, 'billing_phone', true);
    $p_whatsapp = get_user_meta($uid, 'sms_whatsapp_notif', true);
    $p_email = get_user_meta($uid, 'billing_email', true) ?: wp_get_current_user()->user_email;
    $p_advisor = get_user_meta($uid, 'sms_advisor_name', true);
    $p_desc = get_user_meta($uid, 'sms_company_desc', true);
    $p_logo = get_user_meta($uid, 'sms_company_logo', true); // Logo Actual
    $wa_status = get_user_meta($uid, 'sms_phone_status', true);

    // OBTENER LEADS (Filtrado por servicios aprobados)
    $raw_leads = [];
    if (!empty($approved_servs)) {
        $ids_str = implode(',', array_map('intval', $approved_servs));
        $raw_leads = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sms_leads WHERE service_page_id IN ($ids_str) AND status = 'approved'");
    }

    // Obtener Desbloqueados
    $unlocked_rows = $wpdb->get_results("SELECT lead_id FROM {$wpdb->prefix}sms_lead_unlocks WHERE provider_user_id = $uid");
    $unlocked_ids = [];
    foreach($unlocked_rows as $ur) $unlocked_ids[] = $ur->lead_id;

    // Filtros y Orden
    $filter_status = isset($_GET['f_status']) ? $_GET['f_status'] : 'all'; 
    $sort_order = isset($_GET['f_sort']) ? $_GET['f_sort'] : 'desc'; 

    $final_leads = [];
    foreach($raw_leads as $lead) {
        $is_unlocked = in_array($lead->id, $unlocked_ids);
        if ($filter_status == 'new' && $is_unlocked) continue;
        if ($filter_status == 'unlocked' && !$is_unlocked) continue;
        $lead->is_unlocked_by_me = $is_unlocked;
        $final_leads[] = $lead;
    }

    usort($final_leads, function($a, $b) use ($sort_order) {
        $t1 = strtotime($a->created_at);
        $t2 = strtotime($b->created_at);
        return ($sort_order == 'asc') ? $t1 - $t2 : $t2 - $t1;
    });

    // Historial
    $history = $wpdb->get_results("
        SELECT u.*, l.city, l.service_page_id, l.client_name 
        FROM {$wpdb->prefix}sms_lead_unlocks u
        LEFT JOIN {$wpdb->prefix}sms_leads l ON u.lead_id = l.id
        WHERE u.provider_user_id = $uid
        ORDER BY u.unlocked_at DESC
        LIMIT 20
    ");

    ?>
    <style>
        .sms-layout { display: flex; flex-wrap: wrap; gap: 25px; } 
        .sms-col-main { flex: 2; min-width: 300px; } 
        .sms-col-side { flex: 1; min-width: 280px; } 
        .sms-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); padding: 25px; margin-bottom: 25px; border:1px solid #eee; }
        
        .lead-item { border-bottom:1px solid #eee; padding:15px; margin-bottom:15px; border-radius:8px; border-left:4px solid #ddd; transition: transform 0.2s; }
        .lead-item:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .lead-new { border-left-color: #007cba; background: #fff; }
        .lead-unlocked { border-left-color: #25d366; background: #f0fff4; border: 1px solid #c3e6cb; border-left-width: 4px; }
        
        .sms-input-group { margin-bottom: 15px; }
        .sms-input-group label { display: block; font-weight: bold; font-size: 12px; margin-bottom: 5px; }
        .sms-input-group input, .sms-input-group textarea, .sms-input-group select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .row-2-col { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        
        .sms-toolbar { display:flex; justify-content:space-between; align-items:center; background:#f9f9f9; padding:10px; border-radius:8px; margin-bottom:15px; border:1px solid #eee; }
        .sms-filter-select { padding:5px; border-radius:4px; border:1px solid #ddd; font-size:13px; }
        
        .sms-history-table { width:100%; border-collapse: collapse; font-size:12px; }
        .sms-history-table th { text-align:left; background:#f5f5f5; padding:8px; color:#555; }
        .sms-history-table td { border-bottom:1px solid #eee; padding:8px; }

        /* Estilo para Logo Preview */
        .logo-preview { width: 80px; height: 80px; object-fit: cover; border-radius: 8px; border: 1px solid #ddd; margin-bottom: 10px; display: block; }
    </style>

    <div class="sms-layout">
        <div class="sms-col-main">
            
            <div class="sms-card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h3 style="margin:0;">📋 Oportunidades</h3>
                    <div style="font-size:12px; color:#666;">
                        Mostrando: <strong><?php echo count($final_leads); ?></strong>
                    </div>
                </div>

                <?php if(empty($approved_servs)): ?>
                    <div style="padding:15px; background:#fff3cd; color:#856404; border-radius:5px;">
                        ⚠️ <strong>Perfil Incompleto:</strong> Configura tus servicios abajo para ver oportunidades.
                    </div>
                <?php else: ?>
                    
                    <form method="get" class="sms-toolbar">
                        <div style="display:flex; gap:10px; align-items:center;">
                            <label style="font-size:12px; font-weight:bold;">Filtrar:</label>
                            <select name="f_status" class="sms-filter-select" onchange="this.form.submit()">
                                <option value="all" <?php selected($filter_status, 'all'); ?>>Todas</option>
                                <option value="new" <?php selected($filter_status, 'new'); ?>>🔵 Nuevas</option>
                                <option value="unlocked" <?php selected($filter_status, 'unlocked'); ?>>🔓 Desbloqueadas</option>
                            </select>
                        </div>
                        <div style="display:flex; gap:10px; align-items:center;">
                            <label style="font-size:12px; font-weight:bold;">Orden:</label>
                            <select name="f_sort" class="sms-filter-select" onchange="this.form.submit()">
                                <option value="desc" <?php selected($sort_order, 'desc'); ?>>📅 Recientes</option>
                                <option value="asc" <?php selected($sort_order, 'asc'); ?>>📅 Antiguas</option>
                            </select>
                        </div>
                    </form>

                    <?php if(empty($final_leads)): ?>
                        <p style="text-align:center; color:#777; padding:20px;">No hay resultados con estos filtros.</p>
                    <?php else: ?>
                        <?php foreach($final_leads as $l): 
                            $is_company = ($l->client_company !== 'Particular' && $l->client_company !== '(Persona Natural)');
                            $type_label = $is_company ? '🏢 Empresa' : '👤 Persona Natural';
                            
                            $css_class = $l->is_unlocked_by_me ? 'lead-unlocked' : 'lead-new';
                            $status_badge = $l->is_unlocked_by_me 
                                ? '<span style="background:#25d366; color:#fff; padding:3px 8px; border-radius:10px; font-size:10px; font-weight:bold;">🔓 YA LA TIENES</span>' 
                                : '<span style="background:#007cba; color:#fff; padding:3px 8px; border-radius:10px; font-size:10px; font-weight:bold;">⚡ NUEVA</span>';
                        ?>
                            <div class="lead-item <?php echo $css_class; ?>">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                    <div>
                                        <?php echo $status_badge; ?>
                                        <span style="background:#eee; color:#555; font-size:10px; padding:3px 8px; border-radius:10px; margin-left:5px;">
                                            <?php echo $type_label; ?>
                                        </span>
                                        <div style="font-weight:bold; font-size:15px; color:#333; margin-top:5px;">
                                            📍 <?php echo esc_html($l->city); ?>, <?php echo esc_html($l->country); ?>
                                        </div>
                                    </div>
                                    <div style="text-align:right;">
                                        <?php if(!$l->is_unlocked_by_me): ?>
                                            <span style="color:#d63638; font-weight:bold; font-size:14px;"><?php echo $l->cost_credits; ?> cr</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <p style="margin:10px 0; color:#555; line-height:1.4; font-size:13px;">
                                    <?php echo wp_trim_words($l->requirement, 25); ?>
                                </p>
                                
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:10px; border-top:1px solid rgba(0,0,0,0.05); padding-top:8px;">
                                    <span style="font-size:11px; color:#999;">
                                        🕒 Publicado: <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($l->created_at)); ?>
                                    </span>
                                    
                                    <a href="<?php echo site_url('/oportunidad?lid='.$l->id); ?>" class="button button-small <?php echo $l->is_unlocked_by_me ? '' : 'button-primary'; ?>">
                                        <?php echo $l->is_unlocked_by_me ? 'Ver Datos 👁️' : 'Ver Detalles'; ?>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="sms-card">
                <h3>🏢 Perfil de Empresa</h3>
                <form method="post" enctype="multipart/form-data"> <div style="background:#f9f9f9; padding:15px; border-radius:8px; margin-bottom:15px; border:1px solid #eee;">
                        <label style="display:block; font-weight:bold; margin-bottom:5px;">Logo de la Empresa</label>
                        <div style="display:flex; align-items:center; gap:15px;">
                            <?php if($p_logo): ?>
                                <img src="<?php echo esc_url($p_logo); ?>" class="logo-preview">
                            <?php else: ?>
                                <div class="logo-preview" style="background:#eee; display:flex; align-items:center; justify-content:center; color:#999;">Sin Logo</div>
                            <?php endif; ?>
                            <div>
                                <input type="file" name="p_logo" accept="image/*">
                                <p style="font-size:11px; color:#666; margin:5px 0 0 0;">Recomendado: Formato Cuadrado (300x300px). JPG o PNG.</p>
                            </div>
                        </div>
                    </div>

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
                    <input type="text" id="searchServ" onkeyup="filterServices()" placeholder="🔍 Buscar categoría..." style="margin-bottom:10px; width:100%; padding:8px;">
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
                        <strong style="display:block; margin-bottom:5px; color:#004085; font-size:12px;">¿Falta una categoría?</strong>
                        <div style="display:flex; gap:10px;">
                            <input type="text" name="new_service_name" placeholder="Nombre del servicio..." style="flex:1;">
                            <button type="submit" name="req_new_service" class="button button-small" style="background:#004085; color:#fff; border:none;">Solicitar</button>
                        </div>
                    </div>

                    <button type="submit" name="save_provider_profile" class="button button-primary" style="width:100%;">💾 Guardar Perfil</button>
                </form>
            </div>

            <div class="sms-card">
                <h3>📉 Historial de Inversiones</h3>
                <?php if(empty($history)): ?>
                    <p style="color:#666; font-size:13px;">No has realizado movimientos aún.</p>
                <?php else: ?>
                    <table class="sms-history-table">
                        <thead><tr><th>Fecha</th><th>Concepto</th><th>Inversión</th></tr></thead>
                        <tbody>
                            <?php foreach($history as $h): 
                                $serv_page = get_post($h->service_page_id);
                                $serv_title = $serv_page ? $serv_page->post_title : 'Servicio General';
                                $spent = isset($h->credits_spent) ? $h->credits_spent : '-';
                            ?>
                            <tr>
                                <td>
                                    <?php echo date_i18n('d/M/Y', strtotime($h->unlocked_at)); ?><br>
                                    <span style="color:#999; font-size:10px;"><?php echo date_i18n('h:i A', strtotime($h->unlocked_at)); ?></span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($serv_title); ?></strong><br>
                                    <small><?php echo esc_html($h->city); ?> (#<?php echo $h->lead_id; ?>)</small>
                                </td>
                                <td style="color:#d63638; font-weight:bold;">-<?php echo $spent; ?> cr</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
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
