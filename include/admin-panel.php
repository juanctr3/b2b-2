<?php
if (!defined('ABSPATH')) exit;

// ==========================================
// 1. INICIALIZACIÓN (MENÚS Y SETTINGS)
// ==========================================
add_action('admin_menu', function() {
    add_menu_page(
        'Plataforma B2B', 
        'Plataforma B2B', 
        'manage_options', 
        'sms-b2b', 
        'sms_render_dashboard', 
        'dashicons-groups'
    );
});

add_action('admin_init', function() {
    // API & Webhook
    register_setting('sms_opts', 'sms_api_secret');
    register_setting('sms_opts', 'sms_account_id');
    register_setting('sms_opts', 'sms_admin_phone');
    register_setting('sms_opts', 'sms_webhook_secret'); 
    
    // Servicios y Botones
    register_setting('sms_opts', 'sms_active_service_pages'); 
    register_setting('sms_opts', 'sms_buttons_config'); 
    
    // Economía (WooCommerce & Bonos)
    register_setting('sms_opts', 'sms_product_id'); 
    register_setting('sms_opts', 'sms_credits_qty'); 
    register_setting('sms_opts', 'sms_welcome_bonus'); 
});

// ==========================================
// 2. DASHBOARD PRINCIPAL (CONTENEDOR)
// ==========================================
function sms_render_dashboard() {
    $tab = $_GET['tab'] ?? 'leads';

    // ALERTA: Contar documentos pendientes
    $pending_docs = get_users([
        'meta_key' => 'sms_docs_verified',
        'meta_value' => 'pending',
        'fields' => 'ID'
    ]);
    $pending_count = count($pending_docs);

    ?>
    <div class="wrap">
        <h1>Gestión B2B - Centro de Control</h1>
        
        <?php if($pending_count > 0): ?>
            <div class="notice notice-warning" style="border-left-color: #f0ad4e;">
                <p><strong>⚠️ Atención Admin:</strong> Hay <strong><?php echo $pending_count; ?></strong> proveedores esperando verificación de documentos. Ve a la pestaña "Proveedores".</p>
            </div>
        <?php endif; ?>

        <h2 class="nav-tab-wrapper">
            <a href="?page=sms-b2b&tab=leads" class="nav-tab <?php echo $tab=='leads'?'nav-tab-active':''; ?>">📥 Cotizaciones</a>
            <a href="?page=sms-b2b&tab=providers" class="nav-tab <?php echo $tab=='providers'?'nav-tab-active':''; ?>">
                👥 Proveedores 
                <?php if($pending_count > 0) echo "<span class='update-plugins count-$pending_count' style='margin-left:5px;'><span class='plugin-count'>$pending_count</span></span>"; ?>
            </a>
            <a href="?page=sms-b2b&tab=services" class="nav-tab <?php echo $tab=='services'?'nav-tab-active':''; ?>">🔘 Botones y Servicios</a>
            <a href="?page=sms-b2b&tab=requests" class="nav-tab <?php echo $tab=='requests'?'nav-tab-active':''; ?>">🔔 Solicitudes</a>
            <a href="?page=sms-b2b&tab=config" class="nav-tab <?php echo $tab=='config'?'nav-tab-active':''; ?>">⚙️ Configuración</a>
        </h2>
        <div style="background:#fff; padding:20px; border:1px solid #ccc; margin-top:10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
            <?php 
            if($tab == 'leads') sms_tab_leads();
            elseif($tab == 'providers') sms_tab_providers();
            elseif($tab == 'services') sms_tab_services();
            elseif($tab == 'requests') sms_tab_requests();
            else sms_tab_config();
            ?>
        </div>
    </div>
    <?php
}

// ==========================================
// 3. PESTAÑA: COTIZACIONES (LEADS)
// ==========================================
function sms_tab_leads() {
    global $wpdb;
    
    // PROCESAR ACCIONES
    if (isset($_POST['action_lead'])) {
        $lid = intval($_POST['lead_id']);
        
        // A. Guardar solo texto (limpieza)
        if ($_POST['action_lead'] == 'save_edit') {
            $new_req = sanitize_textarea_field($_POST['edited_req']);
            $wpdb->update("{$wpdb->prefix}sms_leads", ['requirement' => $new_req], ['id' => $lid]);
            echo '<div class="notice notice-success is-dismissible"><p>✅ Descripción actualizada.</p></div>';
        }
        
        // B. Aprobar y Distribuir
        if ($_POST['action_lead'] == 'approve') {
            $cost = intval($_POST['lead_cost']);
            $new_req = sanitize_textarea_field($_POST['edited_req']);
            
            $wpdb->update("{$wpdb->prefix}sms_leads", 
                ['status' => 'approved', 'cost_credits' => $cost, 'requirement' => $new_req], 
                ['id' => $lid]
            );
            
            // Hook para enviar WhatsApp (webhook.php)
            do_action('sms_notify_providers', $lid);
            echo '<div class="notice notice-success is-dismissible"><p>🚀 Cotización Aprobada y Notificada.</p></div>';
        }

        // C. Despublicar (Volver a pendiente)
        if ($_POST['action_lead'] == 'unapprove') {
            $wpdb->update("{$wpdb->prefix}sms_leads", ['status' => 'pending'], ['id' => $lid]);
            echo '<div class="notice notice-warning is-dismissible"><p>🚫 Cotización ocultada de los proveedores.</p></div>';
        }

        // D. Eliminar definitivamente
        if ($_POST['action_lead'] == 'delete') {
            $wpdb->delete("{$wpdb->prefix}sms_leads", ['id' => $lid]);
            $wpdb->delete("{$wpdb->prefix}sms_lead_unlocks", ['lead_id' => $lid]); // Borrar historial
            echo '<div class="notice notice-error is-dismissible"><p>🗑️ Cotización eliminada.</p></div>';
        }
    }

    // FILTROS
    $filter_status = $_GET['f_status'] ?? 'all';
    $where = "WHERE 1=1";
    if($filter_status == 'pending') $where .= " AND status = 'pending'";
    if($filter_status == 'approved') $where .= " AND status = 'approved'";

    $leads = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sms_leads $where ORDER BY created_at DESC LIMIT 100");
    ?>
    
    <div class="tablenav top">
        <form method="get">
            <input type="hidden" name="page" value="sms-b2b">
            <input type="hidden" name="tab" value="leads">
            <select name="f_status">
                <option value="all" <?php selected($filter_status, 'all'); ?>>Todos</option>
                <option value="pending" <?php selected($filter_status, 'pending'); ?>>Pendientes</option>
                <option value="approved" <?php selected($filter_status, 'approved'); ?>>Publicados</option>
            </select>
            <input type="submit" class="button" value="Filtrar">
        </form>
    </div>

    <table class="widefat striped">
        <table class="widefat striped">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Estado</th>
                <th>Datos Contacto (Admin)</th>
                <th>Servicio / Cobertura</th> <th style="width:35%;">Edición</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($leads as $l): 
                $page = get_post($l->service_page_id);
                $service_name = $page ? $page->post_title : '(General)';
                $ts = strtotime($l->created_at);
                $fecha_display = ($ts && date('Y', $ts) > 2000) ? date_i18n(get_option('date_format'), $ts) : '<span style="color:#999">(Sin fecha)</span>';
                
                // --- LÓGICA PUNTO 2: CONTAR PROVEEDORES HABILITADOS ---
                // Obtenemos todos los usuarios proveedores
                $all_providers = get_users(['meta_key' => 'sms_approved_services', 'meta_compare' => 'EXISTS']);
                $enabled_providers = [];
                
                foreach($all_providers as $prov) {
                    $prov_services = get_user_meta($prov->ID, 'sms_approved_services', true);
                    if (is_array($prov_services) && in_array($l->service_page_id, $prov_services)) {
                        // Intentamos obtener el nombre comercial, si no, la razón social, si no, el nombre de usuario
                        $com_name = get_user_meta($prov->ID, 'sms_commercial_name', true);
                        $raz_soc = get_user_meta($prov->ID, 'billing_company', true);
                        $enabled_providers[] = $com_name ?: ($raz_soc ?: $prov->display_name);
                    }
                }
                $count_provs = count($enabled_providers);
                // -----------------------------------------------------
            ?>
            <tr>
                <td><?php echo $fecha_display; ?></td>
                <td>
                    <?php echo ($l->is_verified) ? '<span style="color:green;">✅ Verif.</span>' : '<span style="color:red;">❌ No Verif.</span>'; ?><br>
                    <strong><?php echo strtoupper($l->status); ?></strong>
                </td>
                <td style="background:#f0f6fc;">
                    <strong><?php echo esc_html($l->client_company); ?></strong><br>
                    👤 <?php echo esc_html($l->client_name); ?><br>
                    📞 <?php echo esc_html($l->client_phone); ?><br>
                    ✉️ <?php echo esc_html($l->client_email); ?>
                </td>
                
                <td>
                    <strong><?php echo esc_html($service_name); ?></strong>
                    <div style="margin-top:8px;">
                        <?php if($count_provs > 0): ?>
                            <details style="cursor:pointer;">
                                <summary style="color:#007cba; font-weight:bold; font-size:12px;">
                                    🏭 <?php echo $count_provs; ?> Proveedores listos
                                </summary>
                                <ul style="margin:5px 0 0 15px; font-size:11px; list-style:disc; color:#555;">
                                    <?php foreach($enabled_providers as $ep): ?>
                                        <li><?php echo esc_html($ep); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php else: ?>
                            <span style="color:red; font-size:11px;">⚠️ Ningún proveedor habilitado</span>
                        <?php endif; ?>
                    </div>
                </td>
                
                <td>
                    <form method="post" style="padding:5px;">
                        <input type="hidden" name="lead_id" value="<?php echo $l->id; ?>">
                        <textarea name="edited_req" style="width:100%; height:60px; font-size:12px; margin-bottom:5px;"><?php echo esc_textarea($l->requirement); ?></textarea>
                        <div style="display:flex; gap:5px; align-items:center;">
                            <button type="submit" name="action_lead" value="save_edit" class="button button-small">💾 Guardar Texto</button>
                            <?php if($l->status == 'pending'): ?>
                                <input type="number" name="lead_cost" value="10" style="width:45px; height:25px;" min="1">
                                <button type="submit" name="action_lead" value="approve" class="button button-primary button-small">Aprobar</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </td>
                <td style="text-align:center;">
                    <?php if($l->status == 'approved'): ?>
                        <div style="margin-bottom:5px;"><strong><?php echo $l->cost_credits; ?></strong> cr</div>
                        <form method="post"><input type="hidden" name="lead_id" value="<?php echo $l->id; ?>"><button type="submit" name="action_lead" value="unapprove" class="button button-small" style="color:orange;">🚫 Ocultar</button></form>
                    <?php endif; ?>
                    <form method="post" onsubmit="return confirm('¿Eliminar definitivamente?');" style="margin-top:10px;">
                        <input type="hidden" name="lead_id" value="<?php echo $l->id; ?>">
                        <button type="submit" name="action_lead" value="delete" class="button button-link-delete" style="color:red; text-decoration:none;"><span class="dashicons dashicons-trash"></span></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

// ==========================================
// 4. PESTAÑA: PROVEEDORES (GESTIÓN DOCUMENTOS Y CRÉDITOS)
// ==========================================
function sms_tab_providers() {
    global $wpdb;

    // A. Procesar Carga Manual de Créditos
    if (isset($_POST['manual_credit_change'])) {
        $uid = intval($_POST['target_user_id']);
        $amount = intval($_POST['credit_amount']);
        
        if ($uid > 0 && $amount != 0) {
            $current = (int) get_user_meta($uid, 'sms_wallet_balance', true);
            $new = $current + $amount;
            update_user_meta($uid, 'sms_wallet_balance', $new);
            echo '<div class="notice notice-success is-dismissible"><p>✅ Saldo actualizado. Nuevo balance: <strong>'.$new.'</strong></p></div>';
        }
    }

    // B. Procesar APROBACIÓN/RECHAZO DE DOCUMENTOS (Punto 4)
    if (isset($_POST['doc_action'])) {
        $uid = intval($_POST['target_user_id']);
        $action = $_POST['doc_action'];
        $prov_phone = get_user_meta($uid, 'billing_phone', true);

        if ($action == 'approve') {
            update_user_meta($uid, 'sms_docs_verified', 'yes');
            
            // Notificar al Proveedor por WhatsApp
            if(function_exists('sms_send_msg')) {
                sms_send_msg($prov_phone, "✅ *Documentos Aprobados*\nTu empresa ahora está verificada en la plataforma. Esto generará más confianza a los clientes.");
            }
            echo "<div class='notice notice-success is-dismissible'><p>✅ Documentos aprobados. Proveedor notificado por WhatsApp.</p></div>";
        } 
        elseif ($action == 'reject') {
            update_user_meta($uid, 'sms_docs_verified', 'rejected');
            
            // Notificar al Proveedor por WhatsApp
            if(function_exists('sms_send_msg')) {
                sms_send_msg($prov_phone, "❌ *Documentos Rechazados*\nPor favor ingresa a tu panel, verifica que los archivos sean legibles y vuelve a subirlos (Solo PDF).");
            }
            echo "<div class='notice notice-error is-dismissible'><p>❌ Documentos rechazados. Proveedor notificado por WhatsApp.</p></div>";
        }
    }

    $users = get_users(); 
    ?>
    <h3>Gestión de Proveedores</h3>
    <table class="widefat striped">
        <thead>
            <tr>
                <th>Proveedor / Empresa</th>
                <th>Estado WhatsApp</th>
                <th>Documentos (Empresa)</th>
                <th>Servicios Inscritos</th>
                <th style="background:#e8f0fe; width:200px;">Gestión de Saldo</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($users as $u): 
                $balance = (int) get_user_meta($u->ID, 'sms_wallet_balance', true);
                $phone = get_user_meta($u->ID, 'billing_phone', true);
                $advisor = get_user_meta($u->ID, 'sms_advisor_name', true);
                $company_name = get_user_meta($u->ID, 'sms_provider_company', true);
                $status = get_user_meta($u->ID, 'sms_phone_status', true);
                
                $subs = get_user_meta($u->ID, 'sms_subscribed_pages', true);
                $serv_count = is_array($subs) ? count($subs) : 0;
                
                // Datos de Documentos
                $rut = get_user_meta($u->ID, 'sms_file_p_doc_rut', true);
                $camara = get_user_meta($u->ID, 'sms_file_p_doc_camara', true);
                $doc_stat = get_user_meta($u->ID, 'sms_docs_verified', true);
            ?>
            <tr>
                <td>
                    <strong><?php echo esc_html($company_name ?: $u->display_name); ?></strong><br>
                    <small>👤 <?php echo $advisor ? $advisor : 'Sin asesor'; ?></small><br>
                    <small>📞 <?php echo $phone ? $phone : '-'; ?></small><br>
                    <small>📧 <?php echo $u->user_email; ?></small>
                </td>
                <td>
                    <?php echo ($status=='verified') 
                        ? '<span class="badge" style="background:#d4edda; color:#155724; padding:3px 6px; border-radius:4px;">✅ Verificado</span>' 
                        : '<span class="badge" style="background:#f8d7da; color:#721c24; padding:3px 6px; border-radius:4px;">⏳ Pendiente</span>'; 
                    ?>
                </td>
                <td style="<?php if($doc_stat=='pending') echo 'background:#fff3cd;'; ?>">
                    <?php if($rut): ?>
                        <a href="<?php echo $rut; ?>" target="_blank" class="button button-small" style="margin-bottom:2px;">📄 Ver RUT</a><br>
                    <?php endif; ?>
                    
                    <?php if($camara): ?>
                        <a href="<?php echo $camara; ?>" target="_blank" class="button button-small">📄 Ver Cámara</a><br>
                    <?php endif; ?>

                    <div style="margin-top:5px; font-size:12px;">
                        Estado: 
                        <?php 
                        if ($doc_stat=='yes') echo '<strong style="color:green">✅ VERIFICADO</strong>';
                        elseif ($doc_stat=='rejected') echo '<strong style="color:red">❌ RECHAZADO</strong>';
                        elseif ($doc_stat=='pending') echo '<strong style="color:orange">⏳ PENDIENTE</strong>';
                        else echo '<span style="color:#999">Sin revisión</span>';
                        ?>
                    </div>

                    <?php if($rut || $camara): ?>
                        <form method="post" style="margin-top:5px; display:flex; gap:5px;">
                            <input type="hidden" name="target_user_id" value="<?php echo $u->ID; ?>">
                            
                            <?php if($doc_stat != 'yes'): ?>
                                <button type="submit" name="doc_action" value="approve" class="button button-primary button-small">✅ Aprobar</button>
                            <?php endif; ?>
                            
                            <?php if($doc_stat != 'rejected'): ?>
                                <button type="submit" name="doc_action" value="reject" class="button button-secondary button-small" style="color:red; border-color:red;">Rechazar</button>
                            <?php endif; ?>
                        </form>
                    <?php else: ?>
                        <small style="color:#999;">Faltan archivos.</small>
                    <?php endif; ?>
                </td>
                <td>
                    <strong><?php echo $serv_count; ?></strong> categorías.<br>
                </td>
                <td style="background:#f9f9f9; border-left:1px solid #ddd;">
                    <div style="font-size:16px; font-weight:bold; margin-bottom:5px;"><?php echo $balance; ?> Créditos</div>
                    <form method="post" style="display:flex; align-items:center; gap:5px;">
                        <input type="hidden" name="target_user_id" value="<?php echo $u->ID; ?>">
                        <input type="number" name="credit_amount" placeholder="+/-" style="width:70px;" required>
                        <button type="submit" name="manual_credit_change" class="button button-small">Aplicar</button>
                    </form>
                    <small style="color:#777;">Ej: <code>50</code> o <code>-10</code></small>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

// ==========================================
// 5. PESTAÑA: BOTONES Y SERVICIOS
// ==========================================
function sms_tab_services() {
    if(isset($_POST['save_config'])) {
        update_option('sms_active_service_pages', $_POST['active_pages'] ?? []);
        
        $new_buttons = [];
        if(isset($_POST['btn_labels'])) {
            $labels = $_POST['btn_labels']; 
            $page_groups = $_POST['btn_pages_ids']; 
            
            for($i=0; $i < count($labels); $i++){
                if(!empty(trim($labels[$i]))){
                    $pgs = isset($page_groups[$i]) ? $page_groups[$i] : [];
                    if(!is_array($pgs)) $pgs = [];
                    
                    $new_buttons[] = [
                        'label' => sanitize_text_field($labels[$i]), 
                        'pages' => array_map('intval', $pgs)
                    ];
                }
            }
        }
        update_option('sms_buttons_config', $new_buttons);
        echo '<div class="notice notice-success is-dismissible"><p>Configuración guardada.</p></div>';
    }

    $all_pages = get_pages(['post_status' => 'publish']);
    
    $active = get_option('sms_active_service_pages', []);
    if (!is_array($active)) $active = [];

    $btns = get_option('sms_buttons_config', []);
    if (!is_array($btns) || empty($btns)) $btns = [['label'=>'Cotizar', 'pages'=>[]]];
    ?>
    <form method="post">
        <div class="card" style="margin-bottom:20px; padding:15px;">
            <h3>1. Habilitar Servicios (Para Proveedores)</h3>
            <div style="height:200px; overflow-y:scroll; border:1px solid #ddd; padding:10px;">
                <?php if($all_pages): foreach($all_pages as $p): ?>
                <label style="display:block; margin-bottom:4px;">
                    <input type="checkbox" name="active_pages[]" value="<?php echo $p->ID; ?>" <?php checked(in_array($p->ID, $active)); ?>> 
                    <?php echo esc_html($p->post_title); ?>
                </label>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="card" style="padding:15px;">
            <h3>2. Botones Flotantes (Frontend)</h3>
            <div id="buttons_wrapper">
                <?php foreach($btns as $idx => $btn): 
                    $btn_pages = (isset($btn['pages']) && is_array($btn['pages'])) ? $btn['pages'] : [];
                ?>
                <div class="btn-row" style="background:#f9f9f9; border:1px solid #ddd; padding:10px; margin-bottom:10px; border-radius:5px;">
                    <p><strong>Etiqueta:</strong> <input type="text" name="btn_labels[]" value="<?php echo esc_attr($btn['label']); ?>" class="regular-text"></p>
                    <p><strong>Mostrar en:</strong><br>
                    <select name="btn_pages_ids[<?php echo $idx; ?>][]" multiple style="width:100%; height:120px;">
                        <?php if($all_pages): foreach($all_pages as $p): 
                            $sel = in_array($p->ID, $btn_pages) ? 'selected' : ''; 
                        ?>
                        <option value="<?php echo $p->ID; ?>" <?php echo $sel; ?>><?php echo esc_html($p->post_title); ?></option>
                        <?php endforeach; endif; ?>
                    </select>
                    </p>
                    <button type="button" class="button remove-row" onclick="this.parentElement.remove()" style="color:#a00;">Eliminar Botón</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button" id="add_btn_row">➕ Añadir Nuevo Botón</button>
            <hr>
            <button type="submit" name="save_config" class="button button-primary button-large">Guardar Cambios</button>
        </div>
    </form>

    <script>
        document.getElementById('add_btn_row').addEventListener('click', function(){
            var w = document.getElementById('buttons_wrapper');
            if(w.children.length > 0) {
                var c = w.children[0].cloneNode(true);
                var i = w.children.length;
                c.querySelector('input').value = '';
                var sel = c.querySelector('select');
                sel.name = 'btn_pages_ids['+i+'][]';
                var o = sel.options;
                for(var k=0; k<o.length; k++) o[k].selected = false;
                w.appendChild(c);
            } else {
                alert('Guarda la configuración actual para resetear antes de añadir más.');
            }
        });
    </script>
    <?php
}

// ==========================================
// 6. PESTAÑA: SOLICITUDES
// ==========================================
function sms_tab_requests() {
    global $wpdb;
    $reqs = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sms_service_requests ORDER BY created_at DESC");
    ?>
    <h3>Solicitudes de Nuevas Categorías</h3>
    <table class="widefat striped">
        <thead><tr><th>Fecha</th><th>Proveedor</th><th>Servicio Solicitado</th></tr></thead>
        <tbody>
            <?php if($reqs): foreach($reqs as $r): 
                $u = get_userdata($r->provider_user_id);
            ?>
            <tr>
                <td><?php echo $r->created_at; ?></td>
                <td><?php echo $u ? $u->display_name : 'ID '.$r->provider_user_id; ?></td>
                <td><strong><?php echo esc_html($r->requested_service); ?></strong></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php
}

// ==========================================
// 7. PESTAÑA: CONFIGURACIÓN
// ==========================================
function sms_tab_config() {
    $sec = get_option('sms_webhook_secret');
    $site_url = site_url();
    ?>
    <form method="post" action="options.php">
        <?php settings_fields('sms_opts'); do_settings_sections('sms_opts'); ?>
        <table class="form-table">
            <tr><th colspan="2"><h3>📡 Conexión API (smsenlinea)</h3></th></tr>
            <tr><th>API Secret</th><td><input type="text" name="sms_api_secret" value="<?php echo get_option('sms_api_secret'); ?>" class="regular-text"></td></tr>
            <tr><th>Account ID</th><td><input type="text" name="sms_account_id" value="<?php echo get_option('sms_account_id'); ?>" class="regular-text"></td></tr>
            <tr><th>Teléfono Admin</th><td><input type="text" name="sms_admin_phone" value="<?php echo get_option('sms_admin_phone'); ?>" class="regular-text"></td></tr>
            
            <tr style="background:#e8f0fe;">
                <th>Webhook Secret</th>
                <td>
                    <input type="text" name="sms_webhook_secret" value="<?php echo esc_attr($sec); ?>" class="regular-text">
                    <p class="description">
                        URL para smsenlinea: <code><?php echo $site_url; ?>/wp-json/smsenlinea/v1/webhook?secret=<?php echo $sec; ?></code>
                    </p>
                </td>
            </tr>

            <tr><td colspan="2"><hr><h3>🎁 Incentivos (Growth)</h3></td></tr>
            <tr style="background:#d4edda;">
                <th>Bono Bienvenida</th>
                <td>
                    <input type="number" name="sms_welcome_bonus" value="<?php echo get_option('sms_welcome_bonus', 0); ?>" class="small-text">
                    <p class="description">Créditos GRATIS al verificar WhatsApp (Responder ACEPTO). Pon 0 para desactivar.</p>
                </td>
            </tr>

            <tr><td colspan="2"><hr><h3>💳 Recargas WooCommerce</h3></td></tr>
            <tr><th>ID Producto Recarga</th><td><input type="number" name="sms_product_id" value="<?php echo get_option('sms_product_id'); ?>" class="small-text"></td></tr>
            <tr><th>Créditos por Unidad</th><td><input type="number" name="sms_credits_qty" value="<?php echo get_option('sms_credits_qty', 100); ?>" class="small-text"></td></tr>
        </table>
        <?php submit_button(); ?>
    </form>
    <?php
}

// ==========================================
// 8. HOOKS AUXILIARES (PERFIL Y WOOCOMMERCE)
// ==========================================

// A. Mostrar saldo en perfil de usuario (Read-Only)
add_action('show_user_profile', 'sms_manual_credits_profile_view');
add_action('edit_user_profile', 'sms_manual_credits_profile_view');

function sms_manual_credits_profile_view($user) {
    if (!current_user_can('manage_options')) return;
    $balance = (int) get_user_meta($user->ID, 'sms_wallet_balance', true);
    ?>
    <h3>💳 Gestión B2B</h3>
    <table class="form-table">
        <tr>
            <th>Saldo Actual</th>
            <td><input type="text" value="<?php echo $balance; ?>" disabled class="regular-text"> créditos</td>
        </tr>
    </table>
    <?php
}

// B. Procesar Recargas WooCommerce
add_action('woocommerce_order_status_processing', 'sms_check_order_for_credits');
add_action('woocommerce_order_status_completed', 'sms_check_order_for_credits');

function sms_check_order_for_credits($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $user_id = $order->get_user_id();
    if (!$user_id) return; 

    // Candado de seguridad para evitar duplicados
    if (get_post_meta($order_id, '_sms_credits_granted', true) == 'yes') return; 

    $target_pid = (int) get_option('sms_product_id');
    $credits_per_unit = (int) get_option('sms_credits_qty', 100);
    
    if (!$target_pid) return;

    $credits_to_add = 0;
    $found = false;

    foreach ($order->get_items() as $item) {
        if ($item->get_product_id() == $target_pid || $item->get_variation_id() == $target_pid) {
            $qty = $item->get_quantity();
            $credits_to_add += ($credits_per_unit * $qty);
            $found = true;
        }
    }

    if ($found && $credits_to_add > 0) {
        $current_balance = (int) get_user_meta($user_id, 'sms_wallet_balance', true);
        $new_balance = $current_balance + $credits_to_add;
        
        update_user_meta($user_id, 'sms_wallet_balance', $new_balance);
        update_post_meta($order_id, '_sms_credits_granted', 'yes');
        
        $order->add_order_note("✅ Sistema B2B: Se añadieron $credits_to_add créditos automáticamente. Nuevo saldo: $new_balance");
    }
}

