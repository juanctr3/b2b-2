<?php
if (!defined('ABSPATH')) exit;

add_action('wp_footer', 'sms_render_frontend');

function sms_render_frontend() {
    $current_page_id = get_queried_object_id();
    if (!$current_page_id) return;

    $buttons_config = get_option('sms_buttons_config', []);
    $terms_url = get_option('sms_terms_url', '#'); // URL de términos desde admin
    $active_btn = null;
    
    if (!empty($buttons_config) && is_array($buttons_config)) {
        foreach ($buttons_config as $btn) {
            if (isset($btn['pages']) && is_array($btn['pages']) && in_array($current_page_id, $btn['pages'])) {
                $active_btn = $btn; 
                break;
            }
        }
    }
    if (!$active_btn) return;

    // Contar proveedores disponibles
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
        .sms-fab-container { position: fixed; bottom: 30px; right: 30px; z-index: 9999; display:flex; flex-direction:column; align-items:flex-end; }
        .sms-fab { background: #25d366; color: #fff; padding: 12px 25px; border-radius: 50px; cursor: pointer; box-shadow: 0 4px 15px rgba(0,0,0,0.2); display: flex; align-items: center; gap: 10px; font-family: sans-serif; font-weight: bold; transition: transform 0.3s; }
        .sms-fab:hover { transform: scale(1.05); }
        .sms-counter-badge { background: #333; color: #fff; font-size: 11px; padding: 5px 10px; border-radius: 10px; margin-bottom: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.2); opacity: 0.9; }
        
        .sms-modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 10000; justify-content: center; align-items: center; }
        .sms-modal-box { background: #fff; width: 90%; max-width: 450px; padding: 25px; border-radius: 12px; position: relative; max-height: 90vh; overflow-y: auto; }
        .sms-input { width: 100%; padding: 10px; margin-bottom: 10px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; }
        .sms-btn-submit { width: 100%; padding: 12px; background: #007cba; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; margin-top: 5px; }
        
        /* Estilos Tooltip WhatsApp */
        .sms-tooltip-container { position: relative; display: inline-block; margin-left: 5px; cursor: help; }
        .sms-info-icon { background: #eee; color: #666; border-radius: 50%; width: 18px; height: 18px; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold; border: 1px solid #ccc; }
        .sms-tooltip-text { visibility: hidden; width: 220px; background-color: #333; color: #fff; text-align: center; border-radius: 6px; padding: 8px; position: absolute; z-index: 1; bottom: 125%; left: 50%; margin-left: -110px; opacity: 0; transition: opacity 0.3s; font-size: 11px; line-height: 1.4; font-weight: normal; pointer-events: none; }
        .sms-tooltip-container:hover .sms-tooltip-text { visibility: visible; opacity: 1; }
        
        .sms-animate-pulse { animation: pulse 2s infinite; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(37, 211, 102, 0.7); } 70% { box-shadow: 0 0 0 10px rgba(37, 211, 102, 0); } 100% { box-shadow: 0 0 0 0 rgba(37, 211, 102, 0); } }
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
            <span onclick="event.stopPropagation(); closeSmsModal()" style="position: absolute; top: 15px; right: 20px; font-size: 24px; cursor: pointer;">&times;</span>
            
            <div id="smsStep1">
                <h3>Solicitar Cotizaci&oacute;n</h3>
                <p style="color:#666; margin-bottom:15px;">Enviar a <?php echo $provider_count; ?> empresas verificadas.</p>
                
                <form id="smsLeadForm">
                    <input type="hidden" name="action" value="sms_submit_lead_step1">
                    <input type="hidden" name="page_id" value="<?php echo $current_page_id; ?>">
                    
                    <div style="margin-bottom:15px; display:flex; gap:15px; background:#f9f9f9; padding:10px; border-radius:6px;">
                        <label style="cursor:pointer; display:flex; align-items:center;">
                            <input type="radio" name="client_type" value="company" checked onclick="toggleCompany(true)" style="margin-right:5px;"> 
                            Soy Empresa
                        </label>
                        <label style="cursor:pointer; display:flex; align-items:center;">
                            <input type="radio" name="client_type" value="person" onclick="toggleCompany(false)" style="margin-right:5px;"> 
                            Persona Natural
                        </label>
                    </div>

                    <div id="msgPerson" style="display:none; background:#fff3cd; color:#856404; padding:10px; font-size:13px; margin-bottom:15px; border-radius:4px; border:1px solid #ffeeba;">
                        ⚠️ <strong>Nota:</strong> Algunos proveedores solo cotizan a empresas constituidas (con RUT).
                    </div>
                    
                    <select name="country" class="sms-input" id="smsCountrySel" onchange="updateSmsCode()">
                        <option value="Colombia" data-code="+57">&#127464;&#127476; Colombia (+57)</option>
                        <option value="Mexico" data-code="+52">&#127474;&#127485; M&eacute;xico (+52)</option>
                        <option value="Peru" data-code="+51">&#127477;&#127466; Per&uacute; (+51)</option>
                        <option value="Espana" data-code="+34">&#127466;&#127480; Espa&ntilde;a (+34)</option>
                        <option value="USA" data-code="+1">&#127482;&#127480; Estados Unidos (+1)</option>
                        <option value="Chile" data-code="+56">&#127464;&#127473; Chile (+56)</option>
                        <option value="Argentina" data-code="+54">&#127462;&#127479; Argentina (+54)</option>
                    </select>

                    <input type="text" name="city" placeholder="Ciudad" class="sms-input" required>
                    
                    <div id="fieldCompany">
                        <input type="text" name="company" placeholder="Nombre de tu Empresa" class="sms-input">
                    </div>

                    <input type="text" name="name" placeholder="Tu Nombre" class="sms-input" required>
                    
                    <label style="font-size:12px; font-weight:bold; margin-bottom:5px; display:block;">
                        Número de WhatsApp 
                        <div class="sms-tooltip-container">
                            <span class="sms-info-icon">?</span>
                            <span class="sms-tooltip-text">🔒 Tu seguridad es primero.<br>Este número solo se usará para enviarte un código de verificación y únicamente se compartirá con las empresas que coticen tu servicio.</span>
                        </div>
                    </label>
                    <div style="display:flex; gap:5px; margin-bottom:10px;">
                        <input type="text" id="smsPhoneCode" value="+57" readonly style="width:60px; background:#f0f0f0; text-align:center;" class="sms-input">
                        <input type="number" name="phone_raw" placeholder="3001234567" class="sms-input" required>
                    </div>
                    
                    <input type="email" name="email" placeholder="Email" class="sms-input" required>
                    <textarea name="req" rows="3" placeholder="Describe tu requerimiento..." class="sms-input" required></textarea>
                    
                    <div style="margin-bottom:15px; font-size:13px; display:flex; align-items:start;">
                        <input type="checkbox" name="terms_accept" id="smsTerms" required style="margin-top:2px; margin-right:8px;">
                        <label for="smsTerms">
                            He leído y acepto los <a href="<?php echo esc_url($terms_url); ?>" target="_blank" style="color:#007cba; text-decoration:underline;">Términos y Condiciones</a> y la política de tratamiento de datos.
                        </label>
                    </div>

                    <button type="submit" class="sms-btn-submit" id="btnStep1">Continuar</button>
                </form>
            </div>

            <div id="smsStepWaitInteraction" style="display:none; text-align:center;">
                <h3 style="color:#007cba;">&#128172; Acci&oacute;n Requerida</h3>
                <p>Te hemos enviado un mensaje a tu WhatsApp.</p>
                <div style="background:#e8f0fe; padding:15px; border-radius:8px; margin:15px 0; border:1px solid #b8daff;">
                    <p style="margin:0; font-weight:bold;">Por favor responde: <br><span style="font-size:20px; color:#004085;">"WHATSAPP"</span></p>
                    <p style="margin:5px 0 0 0; font-size:12px;">Para enviarte el c&oacute;digo de verificaci&oacute;n.</p>
                </div>
                <div class="sms-animate-pulse" style="width:10px; height:10px; background:#25d366; border-radius:50%; margin:0 auto;"></div>
                <p style="font-size:12px; color:#666;">Esperando tu respuesta...</p>
                
                <button onclick="goToStep2()" class="sms-btn-submit" style="background:#fff; color:#007cba; border:1px solid #007cba; margin-top:20px;">Ya respond&iacute;, ingresar c&oacute;digo</button>
            </div>

            <div id="smsStep2" style="display:none; text-align:center;">
                <h3>&#128272; Ingresa el C&oacute;digo</h3>
                <p>Enviado despu&eacute;s de tu respuesta.</p>
                <input type="text" id="otpInput" class="sms-input" placeholder="0000" style="text-align:center; font-size:24px; letter-spacing:5px; width:150px; margin: 0 auto; display:block;">
                <input type="hidden" id="tempLeadId">
                <button onclick="verifyOtp()" class="sms-btn-submit">Confirmar</button>
                <p id="otpError" style="color:red;"></p>
            </div>

            <div id="smsStep3" style="display:none; text-align:center;">
                <h2 style="color:green;">&#9989;</h2>
                <h3>&iexcl;Recibido!</h3>
                <p>Datos verificados. Las empresas te contactar&aacute;n pronto.</p>
                <button onclick="closeSmsModal()" class="sms-btn-submit" style="background:#666;">Cerrar</button>
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

        function goToStep2(){ 
            document.getElementById('smsStepWaitInteraction').style.display='none'; 
            document.getElementById('smsStep2').style.display='block'; 
        }
        
        function updateSmsCode() {
            var sel = document.getElementById('smsCountrySel');
            document.getElementById('smsPhoneCode').value = sel.options[sel.selectedIndex].getAttribute('data-code');
        }

        document.getElementById('smsLeadForm').addEventListener('submit', function(e){
            e.preventDefault();
            
            // Validar Terms
            var terms = document.getElementById('smsTerms');
            if(!terms.checked) {
                alert('Debes aceptar los términos y condiciones.');
                return;
            }

            var btn = document.getElementById('btnStep1');
            btn.innerHTML = 'Procesando...'; btn.disabled = true;

            var fd = new FormData(this);
            fd.append('phone', document.getElementById('smsPhoneCode').value + fd.get('phone_raw'));

            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {method:'POST', body:fd})
            .then(r=>r.json())
            .then(d=>{
                if(d.success){
                    document.getElementById('smsStep1').style.display='none';
                    document.getElementById('smsStepWaitInteraction').style.display='block';
                    document.getElementById('tempLeadId').value = d.data.lead_id;
                } else {
                    alert('Error: ' + d.data);
                    btn.innerHTML = 'Continuar'; btn.disabled = false;
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
                    document.getElementById('smsStep2').style.display='none';
                    document.getElementById('smsStep3').style.display='block';
                } else {
                    document.getElementById('otpError').innerText = 'Código incorrecto.';
                }
            });
        }
    </script>
    <?php
}

// ==========================================
// AJAX HANDLERS (BACKEND)
// ==========================================

add_action('wp_ajax_sms_submit_lead_step1', 'sms_handle_step1');
add_action('wp_ajax_nopriv_sms_submit_lead_step1', 'sms_handle_step1');

function sms_handle_step1() {
    global $wpdb;
    $otp = rand(1000, 9999);
    
    $raw_phone = isset($_POST['phone']) ? $_POST['phone'] : '';
    $clean_phone = '+' . preg_replace('/[^0-9]/', '', $raw_phone); 

    $email = sanitize_email($_POST['email']);
    $company_val = sanitize_text_field($_POST['company']);
    if (empty($company_val)) {
        $company_val = 'Particular'; 
    }

    $data = [
        'country' => sanitize_text_field($_POST['country']),
        'city' => sanitize_text_field($_POST['city']),
        'client_company' => $company_val,
        'client_name' => sanitize_text_field($_POST['name']),
        'client_phone' => $clean_phone,
        'client_email' => $email,
        'service_page_id' => intval($_POST['page_id']),
        'requirement' => sanitize_textarea_field($_POST['req']),
        'verification_code' => $otp,
        'status' => 'pending',
        'created_at' => current_time('mysql')
    ];

    $wpdb->insert("{$wpdb->prefix}sms_leads", $data);
    $lid = $wpdb->insert_id;
    
    // ENVIAR MENSAJE
    if(function_exists('sms_send_msg')) {
        $msg = "👋 Hola, recibimos tu solicitud.\n\nPara enviarte el código de verificación, responde a este mensaje:\n\n👉 Escribe *WHATSAPP* si quieres el código por aquí.\n👉 Escribe *EMAIL* si prefieres por correo.";
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

// SHORTCODE Perfil Público
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
