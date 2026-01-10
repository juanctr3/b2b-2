<?php
if (!defined('ABSPATH')) exit;

// ==========================================
// 1. INICIALIZACIÓN (MENÚS Y BADGES DE NOTIFICACIÓN)
// ==========================================
add_action('admin_menu', function() {
    global $wpdb;

    // 1. Contar documentos de proveedores pendientes de revisión
    $pending_docs = count(get_users([
        'meta_key' => 'sms_docs_status', 
        'meta_value' => 'pending', 
        'fields' => 'ID'
    ]));
    
    // 2. Contar solicitudes de nuevas categorías/servicios
    $pending_reqs = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sms_service_requests WHERE status = 'pending'");

    // 3. Contar Cotizaciones (Leads) Nuevas/Pendientes que ya verificaron su teléfono
    $pending_leads = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sms_leads WHERE status = 'pending' AND is_verified = 1");
    
    // Suma total de tareas para el globo rojo
    $total_alerts = $pending_docs + $pending_reqs + $pending_leads;
    
    $menu_label = 'Plataforma B2B';
    
    // Si hay alertas, mostramos el badge rojo con el número
    if ($total_alerts > 0) {
        $menu_label .= " <span class='awaiting-mod count-$total_alerts'><span class='pending-count'>$total_alerts</span></span>";
    }
    
    add_menu_page('Plataforma B2B', $menu_label, 'manage_options', 'sms-b2b', 'sms_render_dashboard', 'dashicons-groups');
});

add_action('admin_init', function() {
    // --- API & Webhook ---
    register_setting('sms_opts', 'sms_api_secret');
    register_setting('sms_opts', 'sms_account_id');
    register_setting('sms_opts', 'sms_webhook_secret');
    
    // --- Configuración de Notificaciones (NUEVO) ---
    register_setting('sms_opts', 'sms_admin_phone'); // Teléfono Admin para alertas inmediatas
    register_setting('sms_opts', 'sms_msg_delay');   // Retraso en segundos (Anti-bloqueo WhatsApp)
    
    // --- Servicios y Botones ---
    register_setting('sms_opts', 'sms_active_service_pages'); 
    register_setting('sms_opts', 'sms_buttons_config'); 
    
    // --- Economía (WooCommerce & Bonos) ---
    register_setting('sms_opts', 'sms_product_id'); 
    register_setting('sms_opts', 'sms_credits_qty'); 
    register_setting('sms_opts', 'sms_welcome_bonus'); 
    
    // --- Legal ---
    register_setting('sms_opts', 'sms_terms_url'); 
});

// ==========================================
// 2. DASHBOARD PRINCIPAL (CONTENEDOR DE PESTAÑAS)
// ==========================================
function sms_render_dashboard() {
    $tab = $_GET['tab'] ?? 'leads';
    global $wpdb;

    // Recalcular contadores para las pestañas individuales
    $pending_docs = count(get_users(['meta_key' => 'sms_docs_status', 'meta_value' => 'pending', 'fields' => 'ID']));
    $pending_leads = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sms_leads WHERE status = 'pending' AND is_verified = 1");

    ?>
    <div class="wrap">
        <h1>Gestión B2B - Centro de Control</h1>
        
        <?php if($pending_docs > 0 || $pending_leads > 0): ?>
            <div class="notice notice-warning" style="border-left-color: #f0ad4e;">
                <p><strong>⚠️ Tareas Pendientes:</strong> 
                <?php if($pending_leads > 0) echo "Hay <strong>$pending_leads</strong> cotizaciones nuevas esperando aprobación. "; ?>
                <?php if($pending_docs > 0) echo "Hay <strong>$pending_docs</strong> proveedores esperando verificación de documentos."; ?>
                </p>
            </div>
        <?php endif; ?>

        <h2 class="nav-tab-wrapper">
            <a href="?page=sms-b2b&tab=leads" class="nav-tab <?php echo $tab=='leads'?'nav-tab-active':''; ?>">
                📥 Cotizaciones 
                <?php if($pending_leads > 0) echo "<span class='update-plugins count-$pending_leads'><span class='plugin-count'>$pending_leads</span></span>"; ?>
            </a>
            <a href="?page=sms-b2b&tab=providers" class="nav-tab <?php echo $tab=='providers'?'nav-tab-active':''; ?>">
                👥 Proveedores 
                <?php if($pending_docs > 0) echo "<span class='update-plugins count-$pending_docs'><span class='plugin-count'>$pending_docs</span></span>"; ?>
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
    
    // --- LÓGICA DE PROCESAMIENTO DE FORMULARIOS ---
    if (isset($_POST['action_lead'])) {
        $lid = intval($_POST['lead_id']);
        
        // Datos editables
        $new_req = sanitize_textarea_field($_POST['edited_req']);
        $new_quota = intval($_POST['edited_quota']);
        $new_prio = sanitize_text_field($_POST['edited_priority']);
        $new_date = sanitize_text_field($_POST['edited_deadline']);

        // A. Guardar Edición (Sin cambiar estado)
        if ($_POST['action_lead'] == 'save_edit') {
            $wpdb->update("{$wpdb->prefix}sms_leads", 
                [
                    'requirement' => $new_req, 
                    'max_quotas' => $new_quota, 
                    'priority' => $new_prio, 
                    'deadline' => $new_date
                ], 
                ['id' => $lid]
            );
            echo '<div class="notice notice-success is-dismissible"><p>✅ Datos de la cotización actualizados.</p></div>';
        }
        
        // B. Aprobar y Notificar (CON SELECCIÓN DE PROVEEDORES)
        if ($_POST['action_lead'] == 'approve') {
            $cost = intval($_POST['lead_cost']);
            
            // Obtenemos el array de IDs de proveedores seleccionados
            $selected_providers = isset($_POST['target_providers']) ? array_map('intval', $_POST['target_providers']) : [];

            // Actualizamos estado a 'approved'
            $wpdb->update("{$wpdb->prefix}sms_leads", 
                [
                    'status' => 'approved', 
                    'cost_credits' => $cost, 
                    'requirement' => $new_req, 
                    'max_quotas' => $new_quota, 
                    'priority' => $new_prio, 
                    'deadline' => $new_date
                ], 
                ['id' => $lid]
            );
            
            // Ejecutamos el Hook de notificación enviando la lista de elegidos
            do_action('sms_notify_providers', $lid, $selected_providers);
            
            $count_sent = count($selected_providers);
            echo '<div class="notice notice-success is-dismissible"><p>🚀 Cotización Aprobada y Publicada. Se enviarán notificaciones a <strong>'.$count_sent.'</strong> proveedores seleccionados.</p></div>';
        }

        // C. Despublicar (Pausar)
        if ($_POST['action_lead'] == 'unapprove') {
            $wpdb->update("{$wpdb->prefix}sms_leads", ['status' => 'pending'], ['id' => $lid]);
            echo '<div class="notice notice-warning is-dismissible"><p>🚫 Cotización ocultada (Estado: Pendiente).</p></div>';
        }

        // D. Eliminar
        if ($_POST['action_lead'] == 'delete') {
            $wpdb->delete("{$wpdb->prefix}sms_leads", ['id' => $lid]);
            $wpdb->delete("{$wpdb->prefix}sms_lead_unlocks", ['lead_id' => $lid]); 
            echo '<div class="notice notice-error is-dismissible"><p>🗑️ Cotización eliminada correctamente.</p></div>';
        }
    }

    // --- FILTROS DE VISUALIZACIÓN ---
    $filter_status = $_GET['f_status'] ?? 'pending'; // Por defecto mostrar pendientes
    $where = "WHERE 1=1";
    
    if($filter_status == 'pending') $where .= " AND status = 'pending'";
    if($filter_status == 'approved') $where .= " AND status = 'approved'";

    // Consulta de Leads
    $leads = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sms_leads $where ORDER BY created_at DESC LIMIT 100");
    ?>
    
    <div class="tablenav top">
        <form method="get">
            <input type="hidden" name="page" value="sms-b2b">
            <input type="hidden" name="tab" value="leads">
            <select name="f_status">
                <option value="all" <?php selected($filter_status, 'all'); ?>>Todos</option>
                <option value="pending" <?php selected($filter_status, 'pending'); ?>>Pendientes (Por Aprobar)</option>
                <option value="approved" <?php selected($filter_status, 'approved'); ?>>Publicados</option>
            </select>
            <input type="submit" class="button" value="Filtrar Vista">
        </form>
    </div>

    <table class="widefat striped">
        <thead>
            <tr>
                <th>Estado</th>
                <th>Cliente (Datos Privados)</th>
                <th>Urgencia / Fecha</th>
                <th>Detalle del Servicio</th> 
                <th style="width:35%;">Gestión & Aprobación</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($leads as $l): 
                $page = get_post($l->service_page_id);
                $service_name = $page ? $page->post_title : '(Servicio General)';
                
                // Contar desbloqueos
                $unlocks = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sms_lead_unlocks WHERE lead_id={$l->id}");
                $remaining = max(0, $l->max_quotas - $unlocks);
                
                // --- BÚSQUEDA DE PROVEEDORES COMPATIBLES ---
                // Solo buscamos proveedores si la cotización está pendiente, para mostrar el selector
                $eligible_providers = [];
                if ($l->status == 'pending') {
                    // Traemos usuarios que sean proveedores (tengan servicios aprobados)
                    $all_users = get_users(['meta_key' => 'sms_approved_services', 'meta_compare' => 'EXISTS']);
                    
                    foreach($all_users as $prov) {
                        $prov_services = get_user_meta($prov->ID, 'sms_approved_services', true);
                        $phone_st = get_user_meta($prov->ID, 'sms_phone_status', true);
                        
                        // Verificamos si ofrece este servicio y si tiene WhatsApp verificado
                        if (is_array($prov_services) && in_array($l->service_page_id, $prov_services) && $phone_st == 'verified') {
                            $p_name = get_user_meta($prov->ID, 'sms_commercial_name', true) ?: $prov->display_name;
                            $eligible_providers[] = ['id' => $prov->ID, 'name' => $p_name];
                        }
                    }
                }
            ?>
            <tr>
                <td>
                    <?php echo ($l->is_verified) ? '<span style="color:green; font-weight:bold;">✅ Verificado</span>' : '<span style="color:red;">❌ No Verif.</span>'; ?><br>
                    <strong><?php echo strtoupper($l->status); ?></strong><br>
                    <small><?php echo date_i18n('d/M H:i', strtotime($l->created_at)); ?></small>
                </td>
                <td style="background:#f0f6fc;">
                    <strong><?php echo esc_html($l->client_company ?: 'Persona Natural'); ?></strong><br>
                    👤 <?php echo esc_html($l->client_name); ?><br>
                    📞 <?php echo esc_html($l->client_phone); ?><br>
                    ✉️ <?php echo esc_html($l->client_email); ?>
                </td>
                <td>
                    <strong><?php echo esc_html($l->priority); ?></strong><br>
                    📅 Cierre: <?php echo $l->deadline ? date('d/M', strtotime($l->deadline)) : 'Lo antes posible'; ?>
                </td>
                <td>
                    <strong><?php echo esc_html($service_name); ?></strong>
                    <div style="font-size:11px; margin-top:5px;">
                        Cupos: <strong><?php echo $unlocks; ?> / <?php echo $l->max_quotas; ?></strong><br>
                        (Disponibles: <?php echo $remaining; ?>)
                    </div>
                </td>
                <td>
                    <form method="post" style="padding:10px; background:#fff; border:1px solid #ddd; border-radius:5px;">
                        <input type="hidden" name="lead_id" value="<?php echo $l->id; ?>">
                        
                        <textarea name="edited_req" style="width:100%; height:45px; font-size:12px; margin-bottom:5px;" placeholder="Requerimiento..."><?php echo esc_textarea($l->requirement); ?></textarea>
                        
                        <div style="display:flex; gap:5px; margin-bottom:5px;">
                            <select name="edited_priority" style="font-size:11px;">
                                <option value="Normal" <?php selected($l->priority, 'Normal'); ?>>Normal</option>
                                <option value="Urgente" <?php selected($l->priority, 'Urgente'); ?>>Urgente</option>
                                <option value="Muy Urgente" <?php selected($l->priority, 'Muy Urgente'); ?>>Muy Urgente</option>
                            </select>
                            <input type="date" name="edited_deadline" value="<?php echo $l->deadline; ?>" style="font-size:11px;">
                        </div>

                        <div style="display:flex; gap:5px; align-items:center; margin-bottom:10px;">
                            <label style="font-size:11px;">Cupos:</label>
                            <input type="number" name="edited_quota" value="<?php echo $l->max_quotas; ?>" style="width:50px;" min="1">
                            <button type="submit" name="action_lead" value="save_edit" class="button button-small" title="Guardar cambios sin aprobar">💾 Guardar</button>
                        </div>

                        <?php if($l->status == 'pending'): ?>
                            <div style="border-top:1px solid #eee; padding-top:8px; margin-top:5px;">
                                <div style="font-size:11px; font-weight:bold; margin-bottom:5px; color:#007cba;">📢 Seleccionar Proveedores a Notificar:</div>
                                
                                <div style="max-height:100px; overflow-y:auto; border:1px solid #ccc; padding:5px; margin-bottom:10px; background:#fafafa;">
                                    <?php if(empty($eligible_providers)): ?>
                                        <div style="color:red; font-size:10px;">No se encontraron proveedores activos para esta categoría.</div>
                                    <?php else: ?>
                                        <label style="display:block; font-size:11px; border-bottom:1px solid #eee; padding-bottom:3px; margin-bottom:3px;">
                                            <input type="checkbox" onchange="toggleAll(this)" checked> <strong>Marcar/Desmarcar Todos</strong>
                                        </label>
                                        <?php foreach($eligible_providers as $ep): ?>
                                            <label style="display:block; font-size:11px;">
                                                <input type="checkbox" name="target_providers[]" value="<?php echo $ep['id']; ?>" checked class="chk-prov"> 
                                                <?php echo esc_html($ep['name']); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <div>
                                        <label style="font-size:10px; display:block;">Costo (cr):</label>
                                        <input type="number" name="lead_cost" value="10" style="width:50px;" min="1">
                                    </div>
                                    <button type="submit" name="action_lead" value="approve" class="button button-primary">🚀 Aprobar & Enviar</button>
                                </div>
                            </div>
                        <?php else: ?>
                            <div style="background:#e7f7d3; color:#259d28; padding:5px; text-align:center; font-size:11px; font-weight:bold; border-radius:4px;">
                                ✅ Cotización Publicada
                            </div>
                        <?php endif; ?>
                    </form>
                </td>
                <td style="text-align:center;">
                    <?php if($l->status == 'approved'): ?>
                        <form method="post" style="margin-bottom:5px;">
                            <input type="hidden" name="lead_id" value="<?php echo $l->id; ?>">
                            <button type="submit" name="action_lead" value="unapprove" class="button button-small" style="color:orange;">⏸️ Pausar</button>
                        </form>
                    <?php endif; ?>
                    
                    <form method="post" onsubmit="return confirm('¿Estás seguro de eliminar esta cotización? Se borrarán los datos.');">
                        <input type="hidden" name="lead_id" value="<?php echo $l->id; ?>">
                        <button type="submit" name="action_lead" value="delete" class="button button-link-delete" style="color:#a00;"><span class="dashicons dashicons-trash"></span></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <script>
        function toggleAll(source) {
            var checkboxes = source.closest('form').querySelectorAll('.chk-prov');
            for(var i=0; i<checkboxes.length; i++) {
                checkboxes[i].checked = source.checked;
            }
        }
    </script>
    <?php
}

// ==========================================
// 4. PESTAÑA: PROVEEDORES
// ==========================================
function sms_tab_providers() {
    global $wpdb;

    // Procesar Acciones Rápidas
    if (isset($_POST['prov_action'])) {
        $uid = intval($_POST['user_id']);
        $prov_phone = get_user_meta($uid, 'sms_whatsapp_notif', true);

        if ($_POST['prov_action'] == 'approve_services') {
            $requested = get_user_meta($uid, 'sms_requested_services', true) ?: [];
            update_user_meta($uid, 'sms_approved_services', $requested);
            
            // Notificar por WhatsApp
            if(function_exists('sms_send_msg') && $prov_phone) {
                sms_send_msg($prov_phone, "✅ *Servicios Aprobados*\nTus categorías han sido habilitadas. Empezarás a recibir oportunidades en breve.");
            }
            echo '<div class="notice notice-success"><p>✅ Servicios del proveedor aprobados.</p></div>';
        }

        if ($_POST['prov_action'] == 'approve_docs') {
            update_user_meta($uid, 'sms_docs_status', 'verified');
            if(function_exists('sms_send_msg') && $prov_phone) {
                sms_send_msg($prov_phone, "✅ *Documentos Aprobados*\nTu empresa está verificada en la plataforma.");
            }
            echo '<div class="notice notice-success"><p>✅ Documentos aprobados.</p></div>';
        }

        if ($_POST['prov_action'] == 'reject_docs') {
            update_user_meta($uid, 'sms_docs_status', 'rejected');
            delete_user_meta($uid, 'sms_company_docs'); 
            if(function_exists('sms_send_msg') && $prov_phone) {
                sms_send_msg($prov_phone, "❌ *Documentos Rechazados*\nPor favor ingresa a tu panel y sube documentos legibles.");
            }
            echo '<div class="notice notice-error"><p>❌ Documentos rechazados.</p></div>';
        }
        
        if ($_POST['prov_action'] == 'manual_credit') {
             $amt = intval($_POST['credit_amount']);
             $curr = (int) get_user_meta($uid, 'sms_wallet_balance', true);
             update_user_meta($uid, 'sms_wallet_balance', $curr + $amt);
             echo '<div class="notice notice-success"><p>Saldo actualizado manualmente.</p></div>';
        }
    }

    $users = get_users(['orderby' => 'ID', 'order' => 'DESC']); 
    ?>
    <h3>Gestión de Proveedores</h3>
    <table class="widefat striped">
        <thead>
            <tr>
                <th>Empresa / Contacto</th>
                <th>Estado WhatsApp</th>
                <th>Documentos</th>
                <th>Servicios</th>
                <th>Saldo</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($users as $u): 
                $docs_st = get_user_meta($u->ID, 'sms_docs_status', true);
                $docs = get_user_meta($u->ID, 'sms_company_docs', true);
                $req = get_user_meta($u->ID, 'sms_requested_services', true) ?: [];
                $app = get_user_meta($u->ID, 'sms_approved_services', true) ?: [];
                
                // Detectar si hay servicios pendientes de aprobación
                $pending_services = array_diff($req, $app);
                $has_pending = (!empty($pending_services) || $docs_st == 'pending');
                $bg_style = $has_pending ? 'background:#fff9e6;' : '';
            ?>
            <tr style="<?php echo $bg_style; ?>">
                <td>
                    <strong><?php echo get_user_meta($u->ID, 'sms_commercial_name', true) ?: $u->display_name; ?></strong><br>
                    <?php echo $u->user_email; ?>
                </td>
                <td>
                    <?php $wa_st = get_user_meta($u->ID, 'sms_phone_status', true); ?>
                    <?php if($wa_st=='verified'): ?>
                        <span style="color:green;">✅ Activo</span><br>
                        <small><?php echo get_user_meta($u->ID, 'sms_whatsapp_notif', true); ?></small>
                    <?php else: ?>
                        <span style="color:red;">❌ Pendiente</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php 
                        if($docs_st=='verified') echo '<strong style="color:green">OK</strong>';
                        elseif($docs_st=='pending') echo '<strong style="color:orange">⏳ Revisar</strong>';
                        elseif($docs_st=='rejected') echo '<strong style="color:red">Rechazado</strong>';
                        else echo '<span style="color:#ccc">-</span>';
                    ?>
                    <?php if(!empty($docs) && is_array($docs)): ?>
                        <div style="margin:5px 0;">
                        <?php foreach($docs as $k=>$d): ?>
                            <a href="<?php echo $d; ?>" target="_blank" class="button button-small">📄 Ver</a>
                        <?php endforeach; ?>
                        </div>
                        <?php if($docs_st == 'pending'): ?>
                        <form method="post" style="display:flex; gap:5px; margin-top:5px;">
                            <input type="hidden" name="user_id" value="<?php echo $u->ID; ?>">
                            <button type="submit" name="prov_action" value="approve_docs" class="button button-primary button-small">✔</button>
                            <button type="submit" name="prov_action" value="reject_docs" class="button button-small" style="color:red;">✖</button>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <div>Activos: <strong><?php echo count($app); ?></strong></div>
                    <?php if(!empty($pending_services)): ?>
                        <div style="color:#d63638; font-weight:bold; margin-top:5px; font-size:10px;">⚠️ Nuevos servicios solicitados</div>
                        <form method="post" style="margin-top:3px;">
                            <input type="hidden" name="user_id" value="<?php echo $u->ID; ?>">
                            <button type="submit" name="prov_action" value="approve_services" class="button button-small button-primary">Aprobar Cambios</button>
                        </form>
                    <?php else: ?>
                        <span style="color:green; font-size:10px;">Al día</span>
                    <?php endif; ?>
                </td>
                <td>
                    <strong><?php echo (int)get_user_meta($u->ID, 'sms_wallet_balance', true); ?></strong>
                    <form method="post" style="margin-top:5px; display:flex; align-items:center;">
                        <input type="hidden" name="user_id" value="<?php echo $u->ID; ?>">
                        <input type="number" name="credit_amount" style="width:50px; height:25px;" placeholder="+/-">
                        <button type="submit" name="prov_action" value="manual_credit" class="button button-small">Ok</button>
                    </form>
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
    // Guardar Configuración
    if(isset($_POST['save_config'])) {
        update_option('sms_active_service_pages', $_POST['active_pages'] ?? []);
        
        $new_buttons = [];
        if(isset($_POST['btn_labels'])) {
            $labels = $_POST['btn_labels']; 
            $quotas = $_POST['btn_quotas']; 
            $page_groups = $_POST['btn_pages_ids']; 
            
            for($i=0; $i < count($labels); $i++){
                if(!empty(trim($labels[$i]))){
                    $pgs = isset($page_groups[$i]) ? $page_groups[$i] : [];
                    $new_buttons[] = [
                        'label' => sanitize_text_field($labels[$i]), 
                        'max_quotas' => intval($quotas[$i]),
                        'pages' => array_map('intval', $pgs)
                    ];
                }
            }
        }
        update_option('sms_buttons_config', $new_buttons);
        echo '<div class="notice notice-success is-dismissible"><p>Configuración de botones y servicios guardada.</p></div>';
    }

    $all_pages = get_pages(['post_status' => 'publish']);
    $active = get_option('sms_active_service_pages', []);
    if (!is_array($active)) $active = [];

    $btns = get_option('sms_buttons_config', []);
    if (empty($btns)) $btns = [['label'=>'Cotizar', 'max_quotas'=>3, 'pages'=>[]]];
    ?>
    <form method="post">
        <div class="card" style="margin-bottom:20px; padding:15px;">
            <h3>1. Habilitar Servicios (Para Proveedores)</h3>
            <p class="description">Selecciona las páginas que representan servicios. Los proveedores podrán suscribirse a estas páginas.</p>
            <div style="height:150px; overflow-y:scroll; border:1px solid #ddd; padding:10px;">
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
            <p class="description">Crea botones que aparecerán en las páginas seleccionadas para captar clientes.</p>
            <div id="buttons_wrapper">
                <?php foreach($btns as $idx => $btn): 
                    $btn_pages = (isset($btn['pages']) && is_array($btn['pages'])) ? $btn['pages'] : [];
                ?>
                <div class="btn-row" style="background:#f9f9f9; border:1px solid #ddd; padding:15px; margin-bottom:10px; border-radius:5px;">
                    <div style="display:flex; gap:15px; margin-bottom:10px;">
                        <div>
                            <label>Texto del Botón:</label><br>
                            <input type="text" name="btn_labels[]" value="<?php echo esc_attr($btn['label']); ?>" class="regular-text">
                        </div>
                        <div>
                            <label>Max Cotizaciones:</label><br>
                            <input type="number" name="btn_quotas[]" value="<?php echo esc_attr($btn['max_quotas'] ?? 3); ?>" style="width:70px;" min="1">
                        </div>
                    </div>
                    
                    <label>Mostrar en Páginas:</label><br>
                    <select name="btn_pages_ids[<?php echo $idx; ?>][]" multiple style="width:100%; height:100px;">
                        <?php if($all_pages): foreach($all_pages as $p): 
                            $sel = in_array($p->ID, $btn_pages) ? 'selected' : ''; 
                        ?>
                        <option value="<?php echo $p->ID; ?>" <?php echo $sel; ?>><?php echo esc_html($p->post_title); ?></option>
                        <?php endforeach; endif; ?>
                    </select>
                    
                    <div style="text-align:right; margin-top:5px;">
                        <button type="button" class="button" onclick="this.parentElement.parentElement.remove()" style="color:#a00; border-color:#a00;">Eliminar este botón</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button button-secondary" id="add_btn_row">➕ Añadir Nuevo Botón</button>
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
                c.querySelector('input[type=text]').value = '';
                c.querySelector('input[type=number]').value = '3';
                
                var sel = c.querySelector('select');
                sel.name = 'btn_pages_ids['+i+'][]';
                var o = sel.options;
                for(var k=0; k<o.length; k++) o[k].selected = false;
                
                w.appendChild(c);
            } else {
                alert('Por favor guarda la configuración actual antes de añadir más botones si no hay ninguno.');
            }
        });
    </script>
    <?php
}

// ==========================================
// 6. PESTAÑA: SOLICITUDES DE NUEVOS SERVICIOS
// ==========================================
function sms_tab_requests() {
    global $wpdb;

    if(isset($_POST['req_action']) && $_POST['req_action'] == 'complete') {
        $req_id = intval($_POST['request_id']);
        $prov_id = intval($_POST['prov_id']);
        $serv_name = sanitize_text_field($_POST['serv_name']);
        
        $wpdb->update("{$wpdb->prefix}sms_service_requests", ['status' => 'completed'], ['id' => $req_id]);
        
        $prov_phone = get_user_meta($prov_id, 'sms_whatsapp_notif', true);
        if(function_exists('sms_send_msg') && $prov_phone) {
            $msg = "✅ *Solicitud Atendida*\n\nLa categoría: *$serv_name* ha sido creada en la plataforma.\n\nPor favor ingresa a tu perfil y selecciónala en la lista de servicios para empezar a recibir alertas.";
            sms_send_msg($prov_phone, $msg);
        }
        echo '<div class="notice notice-success is-dismissible"><p>✅ Solicitud marcada como completada y proveedor notificado.</p></div>';
    }

    $reqs = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sms_service_requests ORDER BY created_at DESC");
    ?>
    <h3>Solicitudes de Nuevas Categorías</h3>
    <table class="widefat striped">
        <thead><tr><th>Fecha</th><th>Proveedor</th><th>Servicio Solicitado</th><th>Estado / Acción</th></tr></thead>
        <tbody>
            <?php if($reqs): foreach($reqs as $r): 
                $u = get_userdata($r->provider_user_id);
            ?>
            <tr>
                <td><?php echo $r->created_at; ?></td>
                <td><?php echo $u ? $u->display_name : 'ID '.$r->provider_user_id; ?></td>
                <td><strong><?php echo esc_html($r->requested_service); ?></strong></td>
                <td>
                    <?php if($r->status == 'pending'): ?>
                        <form method="post">
                            <input type="hidden" name="request_id" value="<?php echo $r->id; ?>">
                            <input type="hidden" name="prov_id" value="<?php echo $r->provider_user_id; ?>">
                            <input type="hidden" name="serv_name" value="<?php echo esc_attr($r->requested_service); ?>">
                            <button type="submit" name="req_action" value="complete" class="button button-primary button-small">✅ Marcar Completado</button>
                        </form>
                    <?php else: ?>
                        <span style="color:green; font-weight:bold;">✔ Atendida</span>
                    <?php endif; ?>
                </td>
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
            
            <tr style="background:#e8f0fe;">
                <th>Webhook Secret</th>
                <td>
                    <input type="text" name="sms_webhook_secret" value="<?php echo esc_attr($sec); ?>" class="regular-text">
                    <p class="description">
                        URL para smsenlinea: <code><?php echo $site_url; ?>/wp-json/smsenlinea/v1/webhook?secret=<?php echo $sec; ?></code>
                    </p>
                </td>
            </tr>

            <tr><td colspan="2"><hr><h3>🔔 Notificaciones y Límites</h3></td></tr>
            <tr>
                <th>Teléfono Admin (Alertas)</th>
                <td>
                    <input type="text" name="sms_admin_phone" value="<?php echo get_option('sms_admin_phone'); ?>" class="regular-text">
                    <p class="description">Recibe notificaciones inmediatas por WhatsApp cuando llega una nueva cotización.</p>
                </td>
            </tr>
            <tr>
                <th>Retraso entre mensajes (Seg)</th>
                <td>
                    <input type="number" name="sms_msg_delay" value="<?php echo get_option('sms_msg_delay', 0); ?>" class="small-text">
                    <p class="description">Tiempo de espera (en segundos) entre el envío de cada mensaje a proveedores. Ayuda a evitar bloqueos de WhatsApp por envío masivo.</p>
                </td>
            </tr>

            <tr><td colspan="2"><hr><h3>🎁 Incentivos</h3></td></tr>
            <tr><th>Bono Bienvenida</th><td><input type="number" name="sms_welcome_bonus" value="<?php echo get_option('sms_welcome_bonus', 0); ?>" class="small-text"> créditos</td></tr>

            <tr><td colspan="2"><hr><h3>💳 Recargas WooCommerce</h3></td></tr>
            <tr><th>ID Producto Recarga</th><td><input type="number" name="sms_product_id" value="<?php echo get_option('sms_product_id'); ?>" class="small-text"></td></tr>
            <tr><th>Créditos por Unidad</th><td><input type="number" name="sms_credits_qty" value="<?php echo get_option('sms_credits_qty', 100); ?>" class="small-text"></td></tr>

            <tr><td colspan="2"><hr><h3>📜 Legal</h3></td></tr>
            <tr><th>URL Términos</th><td><input type="text" name="sms_terms_url" value="<?php echo get_option('sms_terms_url'); ?>" class="regular-text" placeholder="https://..."></td></tr>
        </table>
        <?php submit_button(); ?>
    </form>
    <?php
}

// ==========================================
// 8. HOOKS AUXILIARES (PERFIL DE USUARIO Y PAGOS)
// ==========================================

// Mostrar saldo en perfil de usuario (Admin)
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

// Asignar créditos al completar compra en WooCommerce
add_action('woocommerce_order_status_processing', 'sms_check_order_for_credits');
add_action('woocommerce_order_status_completed', 'sms_check_order_for_credits');

function sms_check_order_for_credits($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;
    $user_id = $order->get_user_id();
    if (!$user_id) return; 

    // Evitar duplicados
    if (get_post_meta($order_id, '_sms_credits_granted', true) == 'yes') return; 

    $target_pid = (int) get_option('sms_product_id');
    $credits_per_unit = (int) get_option('sms_credits_qty', 100);
    
    if (!$target_pid) return;

    $credits_to_add = 0;
    $found = false;

    foreach ($order->get_items() as $item) {
        // Verifica producto simple o variación
        if ($item->get_product_id() == $target_pid || $item->get_variation_id() == $target_pid) {
            $qty = $item->get_quantity();
            $credits_to_add += ($credits_per_unit * $qty);
            $found = true;
        }
    }

    if ($found && $credits_to_add > 0) {
        $curr = (int) get_user_meta($user_id, 'sms_wallet_balance', true);
        $new = $curr + $credits_to_add;
        update_user_meta($user_id, 'sms_wallet_balance', $new);
        update_post_meta($order_id, '_sms_credits_granted', 'yes');
        $order->add_order_note("✅ Sistema B2B: Se añadieron +$credits_to_add créditos. Nuevo saldo: $new");
    }
}
