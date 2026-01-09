<?php
if (!defined('ABSPATH')) exit;

add_action('wp_footer', 'sms_render_frontend');

function sms_render_frontend() {
    $current_page_id = get_queried_object_id();
    if (!$current_page_id) return;

    $buttons_config = get_option('sms_buttons_config', []);
    $terms_url = get_option('sms_terms_url', '#');
    $active_btn = null;
    
    // Buscar configuración para esta página
    if (!empty($buttons_config) && is_array($buttons_config)) {
        foreach ($buttons_config as $btn) {
            if (isset($btn['pages']) && is_array($btn['pages']) && in_array($current_page_id, $btn['pages'])) {
                $active_btn = $btn; 
                break;
            }
        }
    }
    if (!$active_btn) return;

    $max_quotas_allowed = isset($active_btn['max_quotas']) ? intval($active_btn['max_quotas']) : 3;
    if ($max_quotas_allowed <= 0) $max_quotas_allowed = 3;

    // Contar proveedores
    $users = get_users();
    $provider_count = 0;
    foreach ($users as $u) {
        $subs = get_user_meta($u->ID, 'sms_approved_services', true);
        $docs_ok = get_user_meta($u->ID, 'sms_docs_status', true) === 'verified';
        if ($docs_ok && is_array($subs) && in_array($current_page_id, $subs)) {
            $provider_count++;
        }
    }
    ?>
    <style>
        /* Estilos Base */
        .sms-fab-container { position: fixed; bottom: 30px; right: 30px; z-index: 9999; display:flex; flex-direction:column; align-items:flex-end; }
        .sms-fab { background: #25d366; color: #fff; padding: 12px 25px; border-radius: 50px; cursor: pointer; box-shadow: 0 4px 15px rgba(0,0,0,0.2); display: flex; align-items: center; gap: 10px; font-family: sans-serif; font-weight: bold; transition: transform 0.3s; }
        .sms-fab:hover { transform: scale(1.05); }
        .sms-counter-badge { background: #333; color: #fff; font-size: 11px; padding: 5px 10px; border-radius: 10px; margin-bottom: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.2); opacity: 0.9; }
        
        .sms-modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 10000; justify-content: center; align-items: center; }
        .sms-modal-box { background: #fff; width: 90%; max-width: 450px; padding: 25px; border-radius: 12px; position: relative; max-height: 90vh; overflow-y: auto; display:flex; flex-direction:column; }
        
        .sms-input { width: 100%; padding: 12px; margin-bottom: 15px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size:14px; }
        .sms-label { font-size:12px; font-weight:bold; margin-bottom:5px; display:block; color:#333; }
        
        .sms-btn-next { width: 100%; padding: 12px; background: #007cba; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; margin-top: 10px; font-weight:bold; }
        .sms-btn-prev { background:none; border:none; color:#666; cursor:pointer; font-size:13px; margin-top:10px; text-decoration:underline; }
        
        /* Tooltip */
        .sms-tooltip-container { position: relative; display: inline-block; margin-left: 5px; cursor: help; }
        .sms-info-icon { background: #eee; color: #666; border-radius: 50%; width: 18px; height: 18px; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold; border: 1px solid #ccc; }
        .sms-tooltip-text { visibility: hidden; width: 220px; background-color: #333; color: #fff; text-align: center; border-radius: 6px; padding: 8px; position: absolute; z-index: 1; bottom: 125%; left: 50%; margin-left: -110px; opacity: 0; transition: opacity 0.3s; font-size: 11px; line-height: 1.4; font-weight: normal; pointer-events: none; }
        .sms-tooltip-container:hover .sms-tooltip-text { visibility: visible; opacity: 1; }

        /* Steps */
        .sms-step-content { display: none; animation: fadeIn 0.4s; }
        .sms-step-active { display: block; }
        @keyframes fadeIn { from { opacity:0; transform:translateY(5px); } to { opacity:1; transform:translateY(0); } }

        .sms-progress { display:flex; gap:5px; margin-bottom:20px; }
        .sms-bar { height:4px; flex:1; background:#eee; border-radius:2px; }
        .sms-bar.active { background:#007cba; }
    </style>

    <div class="sms-fab-container" onclick="openSmsModal()">
        <?php if($provider_count > 0): ?>
            <div class="sms-counter-badge">&#127970; <?php echo $provider_count; ?> Empresas Disponibles</div>
        <?php endif; ?>
        <div class="sms-fab">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="white"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
            <span><?php echo esc_html($active_btn['label']); ?></span>
        </div>
    </div>

    <div id="smsModal" class="sms-modal-overlay">
        <div class="sms-modal-box">
            <span onclick="event.stopPropagation(); closeSmsModal()" style="position: absolute; top: 15px; right: 20px; font-size: 24px; cursor: pointer; z-index:10;">&times;</span>
            
            <div id="smsWizardContainer">
                
                <h3 style="margin-top:0; margin-bottom:5px;">Solicitar Cotizaci&oacute;n</h3>
                <p style="color:#666; font-size:13px; margin-bottom:15px;">Atendemos de inmediato tu solicitud.</p>
                
                <div class="sms-progress">
                    <div class="sms-bar active" id="bar1"></div>
                    <div class="sms-bar" id="bar2"></div>
                    <div class="sms-bar" id="bar3"></div>
                </div>

                <form id="smsLeadForm">
                    <input type="hidden" name="action" value="sms_submit_lead_step1">
                    <input type="hidden" name="page_id" value="<?php echo $current_page_id; ?>">

                    <div class="sms-step-content sms-step-active" id="step1">
                        <div style="margin-bottom:15px; display:flex; gap:15px; background:#f9f9f9; padding:10px; border-radius:6px;">
                            <label style="cursor:pointer; display:flex; align-items:center; font-size:13px;">
                                <input type="radio" name="client_type" value="company" checked onclick="toggleCompany(true)" style="margin-right:5px;"> 
                                Soy Empresa
                            </label>
                            <label style="cursor:pointer; display:flex; align-items:center; font-size:13px;">
                                <input type="radio" name="client_type" value="person" onclick="toggleCompany(false)" style="margin-right:5px;"> 
                                Persona Natural
                            </label>
                        </div>

                        <label class="sms-label">¿Qué necesitas cotizar? (Detalla tu requerimiento)</label>
                        <textarea name="req" id="inputReq" rows="5" class="sms-input" placeholder="Ej: Requerimos el servicio de XXXX, tenemos los siguientes detalles..... cotizar costos, traslados, ejecución, etc, Necesitamos las cotizaciones lo antes posible..." required></textarea>

                        <label class="sms-label">Nivel de Prioridad</label>
                        <select name="priority" class="sms-input">
                            <option value="Normal">Normal (Sin afán)</option>
                            <option value="Urgente">Urgente (Lo antes posible)</option>
                            <option value="Muy Urgente">🚨 Muy Urgente (Inmediato)</option>
                        </select>

                        <button type="button" class="sms-btn-next" onclick="nextStep(2)">Siguiente &rarr;</button>
                    </div>

                    <div class="sms-step-content" id="step2">
                        <label class="sms-label">¿Para cuándo necesitas recibir las cotizaciones?</label>
                        <input type="date" name="deadline" id="inputDeadline" class="sms-input" min="<?php echo date('Y-m-d'); ?>" required>

                        <label class="sms-label">¿Cuántas cotizaciones deseas recibir máximo?</label>
                        <select name="quotas_requested" class="sms-input">
                            <?php for($i=1; $i<=$max_quotas_allowed; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php selected($i, $max_quotas_allowed); ?>><?php echo $i; ?> Cotizaciones</option>
                            <?php endfor; ?>
                        </select>
                        
                        <div style="background:#e8f0fe; padding:10px; border-radius:5px; font-size:12px; color:#004085; margin-bottom:15px;">
                            💡 Entre más detallada sea tu solicitud, mejores precios recibirás.
                        </div>

                        <button type="button" class="sms-btn-next" onclick="nextStep(3)">Siguiente &rarr;</button>
                        <button type="button" class="sms-btn-prev" onclick="prevStep(1)">&larr; Atrás</button>
                    </div>

                    <div class="sms-step-content" id="step3">
                        
                        <div id="fieldCompany">
                            <label class="sms-label">Nombre de tu Empresa</label>
                            <input type="text" name="company" class="sms-input" placeholder="Razón Social">
                        </div>

                        <div id="msgPerson" style="display:none; background:#fff3cd; color:#856404; padding:8px; font-size:12px; margin-bottom:10px; border-radius:4px; border:1px solid #ffeeba;">
                            ⚠️ Algunos proveedores solo atienden a empresas.
                        </div>

                        <div style="display:flex; gap:10px;">
                             <div style="flex:1;">
                                 <label class="sms-label">País</label>
                                 <select name="country" class="sms-input" id="smsCountrySel" onchange="updateSmsCode()" style="padding:10px 5px;">
                                    <option value="Colombia" data-code="+57">🇨🇴 Col</option>
                                    <option value="Mexico" data-code="+52">🇲🇽 Mex</option>
                                    <option value="Peru" data-code="+51">🇵🇪 Per</option>
                                    <option value="Espana" data-code="+34">🇪🇸 Esp</option>
                                    <option value="USA" data-code="+1">🇺🇸 USA</option>
                                    <option value="Chile" data-code="+56">🇨🇱 Chi</option>
                                </select>
                             </div>
                             <div style="flex:2;">
                                 <label class="sms-label">Ciudad</label>
                                 <input type="text" name="city" id="inputCity" class="sms-input" required>
                             </div>
                        </div>

                        <label class="sms-label">Tu Nombre Completo</label>
                        <input type="text" name="name" id="inputName" class="sms-input" required>

                        <label class="sms-label" style="display:flex; align-items:center;">
                            Número de WhatsApp 
                            <div class="sms-tooltip-container">
                                <span class="sms-info-icon">?</span>
                                <span class="sms-tooltip-text">🔒 Solo se usará para enviarte el código de verificación y conectar con los proveedores.</span>
                            </div>
                        </label>
                        <div style="display:flex; gap:5px; margin-bottom:15px;">
                            <input type="text" id="smsPhoneCode" value="+57" readonly style="width:60px; background:#f0f0f0; text-align:center;" class="sms-input">
                            <input type="number" name="phone_raw" id="inputPhone" placeholder="3001234567" class="sms-input" required>
                        </div>
                        
                        <label class="sms-label">Correo Electrónico</label>
                        <input type="email" name="email" id="inputEmail" class="sms-input" required>
                        
                        <div style="margin-bottom:15px; font-size:12px; display:flex; align-items:start;">
                            <input type="checkbox" name="terms_accept" id="smsTerms" required style="margin-top:2px; margin-right:8px;">
                            <label for="smsTerms">
                                Acepto los <a href="<?php echo esc_url($terms_url); ?>" target="_blank" style="color:#007cba;">Términos y Condiciones</a>.
                            </label>
                        </div>

                        <button type="submit" class="sms-btn-next" id="btnFinalSubmit">Finalizar y Cotizar</button>
                        <button type="button" class="sms-btn-prev" onclick="prevStep(2)">&larr; Atrás</button>
                    </div>
                </form>
            </div>

            <div id="smsStepWaitInteraction" style="display:none; text-align:center;">
                <h3 style="color:#007cba;">&#128172; Revisa tu WhatsApp</h3>
                
                <p>Te hemos enviado un mensaje al:<br><strong id="smsTargetPhone" style="font-size:16px; color:#333;">...</strong></p>
                
                <div style="background:#e8f0fe; padding:15px; border-radius:8px; margin:15px 0; border:1px solid #b8daff;">
                    <p style="margin:0; font-weight:bold; font-size:14px; color:#555;">Responde al mensaje con la opción:</p>
                    <div style="display:flex; justify-content:center; gap:10px; margin-top:10px;">
                        <span style="background:#fff; padding:5px 10px; border-radius:5px; font-weight:bold; color:#004085; border:1px solid #b8daff;">WHATSAPP</span>
                        <span style="align-self:center; font-size:12px;">ó</span>
                        <span style="background:#fff; padding:5px 10px; border-radius:5px; font-weight:bold; color:#004085; border:1px solid #b8daff;">EMAIL</span>
                    </div>
                    <p style="margin:10px 0 0 0; font-size:11px; color:#666;">Para recibir tu código de verificación.</p>
                </div>
                
                <div style="margin:20px auto;" class="sms-animate-pulse">⏳</div>
                <p style="font-size:12px; color:#666;">Esperando tu respuesta...</p>
                
                <button onclick="goToStepCode()" class="sms-btn-submit" style="background:#fff; color:#007cba; border:1px solid #007cba; margin-top:10px;">Ya respond&iacute;, tengo el c&oacute;digo</button>
            </div>

            <div id="smsStepCode" style="display:none; text-align:center;">
                <h3>&#128272; Ingresa el C&oacute;digo</h3>
                <p>Ingresa el número de 4 dígitos que te enviamos.</p>
                <input type="text" id="otpInput" class="sms-input" placeholder="0000" style="text-align:center; font-size:24px; letter-spacing:5px; width:150px; margin: 0 auto; display:block;">
                <input type="hidden" id="tempLeadId">
                <button onclick="verifyOtp()" class="sms-btn-next">Verificar</button>
                <p id="otpError" style="color:red; font-size:13px; margin-top:5px;"></p>
            </div>

            <div id="smsStepSuccess" style="display:none; text-align:center;">
                <h1 style="font-size:40px; margin:0;">&#9989;</h1>
                <h3>&iexcl;Solicitud Exitosa!</h3>
                <p>Tus datos han sido verificados. Las empresas te contactar&aacute;n pronto con sus propuestas.</p>
                <button onclick="closeSmsModal()" class="sms-btn-next" style="background:#666;">Cerrar</button>
            </div>
        </div>
    </div>

    <script>
        function openSmsModal(){ document.getElementById('smsModal').style.display='flex'; }
        function closeSmsModal(){ document.getElementById('smsModal').style.display='none'; }
        
        function toggleCompany(isCompany) {
            var field = document.getElementById('fieldCompany');
            var msg = document.getElementById('msgPerson');
            if(isCompany) {
                field.style.display = 'block';
                msg.style.display = 'none';
            } else {
                field.style.display = 'none';
                msg.style.display = 'block';
                field.querySelector('input').value = ''; 
            }
        }

        function updateSmsCode() {
            var sel = document.getElementById('smsCountrySel');
            document.getElementById('smsPhoneCode').value = sel.options[sel.selectedIndex].getAttribute('data-code');
        }

        // --- LÓGICA DEL WIZARD (PASOS) ---
        function nextStep(stepNum) {
            // Validaciones Simples antes de avanzar
            if(stepNum === 2) {
                var req = document.getElementById('inputReq').value;
                if(req.length < 5) { alert('Por favor describe tu requerimiento.'); return; }
            }
            if(stepNum === 3) {
                var date = document.getElementById('inputDeadline').value;
                if(!date) { alert('Selecciona una fecha límite.'); return; }
            }

            // Ocultar todos, mostrar actual
            document.querySelectorAll('.sms-step-content').forEach(el => el.classList.remove('sms-step-active'));
            document.getElementById('step' + stepNum).classList.add('sms-step-active');

            // Barras
            document.querySelectorAll('.sms-bar').forEach(el => el.classList.remove('active'));
            for(let i=1; i<=stepNum; i++) {
                document.getElementById('bar' + i).classList.add('active');
            }
        }

        function prevStep(stepNum) {
            document.querySelectorAll('.sms-step-content').forEach(el => el.classList.remove('sms-step-active'));
            document.getElementById('step' + stepNum).classList.add('sms-step-active');
            
            // Barras Update
            document.querySelectorAll('.sms-bar').forEach(el => el.classList.remove('active'));
            for(let i=1; i<=stepNum; i++) {
                document.getElementById('bar' + i).classList.add('active');
            }
        }
        
        // --- ENVÍO Y VERIFICACIÓN ---
        function goToStepCode(){ 
            document.getElementById('smsStepWaitInteraction').style.display='none'; 
            document.getElementById('smsStepCode').style.display='block'; 
        }

        document.getElementById('smsLeadForm').addEventListener('submit', function(e){
            e.preventDefault();
            
            // Validar Terms
            if(!document.getElementById('smsTerms').checked) {
                alert('Debes aceptar los términos y condiciones.'); return;
            }

            var btn = document.getElementById('btnFinalSubmit');
            btn.innerHTML = 'Enviando...'; btn.disabled = true;

            var fd = new FormData(this);
            var fullPhone = document.getElementById('smsPhoneCode').value + document.getElementById('inputPhone').value;
            fd.append('phone', fullPhone);

            // CAMBIO: Mostrar número en la siguiente pantalla
            document.getElementById('smsTargetPhone').innerText = fullPhone;

            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {method:'POST', body:fd})
            .then(r=>r.json())
            .then(d=>{
                if(d.success){
                    document.getElementById('smsWizardContainer').style.display='none';
                    document.getElementById('smsStepWaitInteraction').style.display='block';
                    document.getElementById('tempLeadId').value = d.data.lead_id;
                } else {
                    alert('Error: ' + d.data);
                    btn.innerHTML = 'Finalizar'; btn.disabled = false;
                }
            });
        });

        function verifyOtp(){
            var fd = new FormData();
            fd.append('action', 'sms_verify_otp');
            fd.append('lead_id', document.getElementById('tempLeadId').value);
            fd.append('code', document.getElementById('otpInput').value);

            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {method:'POST', body:fd})
            .then(r=>r.json())
            .then(d=>{
                if(d.success){
                    document.getElementById('smsStepCode').style.display='none';
                    document.getElementById('smsStepSuccess').style.display='block';
                } else {
                    document.getElementById('otpError').innerText = 'Código incorrecto. Intenta de nuevo.';
                }
            });
        }
    </script>
    <?php
}

// ==========================================
// AJAX HANDLERS
// ==========================================

add_action('wp_ajax_sms_submit_lead_step1', 'sms_handle_step1');
add_action('wp_ajax_nopriv_sms_submit_lead_step1', 'sms_handle_step1');

function sms_handle_step1() {
    global $wpdb;
    $otp = rand(1000, 9999);
    
    $raw_phone = isset($_POST['phone']) ? $_POST['phone'] : '';
    // Limpieza estricta: Solo números y el + inicial si existe
    $clean_phone = '+' . preg_replace('/[^0-9]/', '', $raw_phone); 

    $email = sanitize_email($_POST['email']);
    $company_val = sanitize_text_field($_POST['company']);
    if (empty($company_val)) { $company_val = 'Particular'; }

    $data = [
        'country' => sanitize_text_field($_POST['country']),
        'city' => sanitize_text_field($_POST['city']),
        'client_company' => $company_val,
        'client_name' => sanitize_text_field($_POST['name']),
        'client_phone' => $clean_phone,
        'client_email' => $email,
        'service_page_id' => intval($_POST['page_id']),
        'requirement' => sanitize_textarea_field($_POST['req']),
        'priority' => sanitize_text_field($_POST['priority']),
        'deadline' => sanitize_text_field($_POST['deadline']),
        'max_quotas' => intval($_POST['quotas_requested']),
        'verification_code' => $otp,
        'status' => 'pending',
        'created_at' => current_time('mysql')
    ];

    $wpdb->insert("{$wpdb->prefix}sms_leads", $data);
    $lid = $wpdb->insert_id;
    
    // ENVIAR MENSAJE INICIAL (CAMBIO: Opción EMAIL Restaurada)
    if(function_exists('sms_send_msg')) {
        $msg = "👋 Hola, recibimos tu solicitud.\n\nPara enviarte el código de verificación, responde:\n\n👉 *WHATSAPP* para recibirlo por aquí.\n👉 *EMAIL* para enviarlo a tu correo.";
        sms_send_msg($clean_phone, $msg);
    }

    wp_send_json_success(['lead_id' => $lid]);
}

add_action('wp_ajax_sms_verify_otp', 'sms_handle_step2');
add_action('wp_ajax_nopriv_sms_verify_otp', 'sms_handle_step2');

function sms_handle_step2() {
    global $wpdb;
    $lid = intval($_POST['lead_id']);
    $code = sanitize_text_field($_POST['code']);
    $row = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE id = $lid");
    
    if ($row && $row->verification_code == $code) {
        $wpdb->update("{$wpdb->prefix}sms_leads", ['is_verified' => 1], ['id' => $lid]);
        
        $admin_phone = get_option('sms_admin_phone');
        if($admin_phone && function_exists('sms_send_msg')) {
            sms_send_msg($admin_phone, "🔔 Nueva cotización verificada #$lid. Revisa el panel.");
        }
        wp_send_json_success();
    } else {
        wp_send_json_error();
    }
}

// SHORTCODE Perfil Público (Se mantiene igual)
add_shortcode('sms_perfil_publico', 'sms_render_public_profile');
function sms_render_public_profile() {
     if (!isset($_GET['uid'])) return '<p>Perfil no especificado.</p>';
    $uid = intval($_GET['uid']);
    $user = get_userdata($uid);
    if (!$user) return '<p>Proveedor no encontrado.</p>';

    $com_name = get_user_meta($uid, 'sms_commercial_name', true) ?: $user->display_name;
    $desc = get_user_meta($uid, 'sms_company_desc', true) ?: 'Sin descripción disponible.';
    $address = get_user_meta($uid, 'billing_address_1', true);
    $phone = get_user_meta($uid, 'sms_whatsapp_notif', true); 
    $email = get_user_meta($uid, 'billing_email', true) ?: $user->user_email;
    $advisor = get_user_meta($uid, 'sms_advisor_name', true);
    $doc_status = get_user_meta($uid, 'sms_docs_status', true);
    
    if($doc_status != 'verified') {
        return '<div style="background:#fff3cd; color:#856404; padding:15px; border-radius:5px;">⚠️ Este perfil aún está en proceso de verificación.</div>';
    }

    ob_start();
    ?>
    <div style="max-width:800px; margin:0 auto; font-family:sans-serif; color:#333;">
        <div style="background:#007cba; color:#fff; padding:40px 20px; border-radius:10px 10px 0 0; text-align:center;">
            <div style="font-size:50px; margin-bottom:10px;">🏭</div>
            <h1 style="margin:0; font-size:28px;"><?php echo esc_html($com_name); ?></h1>
            <p style="opacity:0.9;">Proveedor Verificado en la Plataforma</p>
        </div>
        <div style="background:#fff; border:1px solid #ddd; border-top:none; border-radius:0 0 10px 10px; padding:30px; box-shadow:0 4px 10px rgba(0,0,0,0.05);">
            <div style="margin-bottom:30px;">
                <h3 style="border-bottom:2px solid #f0f0f0; padding-bottom:10px;">Sobre Nosotros</h3>
                <p style="line-height:1.6; color:#555;"><?php echo nl2br(esc_html($desc)); ?></p>
            </div>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                <div style="background:#f9f9f9; padding:20px; border-radius:8px;">
                    <h4 style="margin-top:0;">📞 Contacto Comercial</h4>
                    <p><strong>Asesor:</strong> <?php echo esc_html($advisor ?: 'Gerencia'); ?></p>
                    <p><strong>WhatsApp:</strong> <a href="https://wa.me/<?php echo str_replace('+','',$phone); ?>" target="_blank" style="text-decoration:none; color:#25d366; font-weight:bold;">Chat Directo 📲</a></p>
                    <p><strong>Email:</strong> <?php echo esc_html($email); ?></p>
                </div>
                <div style="background:#f9f9f9; padding:20px; border-radius:8px;">
                    <h4 style="margin-top:0;">📍 Ubicación</h4>
                    <p><?php echo esc_html($address ?: 'Oficina Virtual / Sin dirección pública'); ?></p>
                    <div style="margin-top:15px; color:green; font-weight:bold; font-size:12px;">
                        ✅ Cámara de Comercio Verificada<br>✅ RUT Verificado
                    </div>
                </div>
            </div>
            <div style="text-align:center; margin-top:30px;">
                <a href="https://wa.me/<?php echo str_replace('+','',$phone); ?>" class="button" style="background:#25d366; color:#fff; padding:12px 25px; border-radius:50px; text-decoration:none; font-size:18px;">Solicitar Cotización Directa</a>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}



