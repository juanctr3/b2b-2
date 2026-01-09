<?php
if (!defined('ABSPATH')) exit;

// ============================================================
// 1. RENDERIZADO DEL BOTÓN Y FORMULARIO (FRONTEND)
// ============================================================
add_action('wp_footer', 'sms_render_frontend');

function sms_render_frontend() {
    $current_page_id = get_queried_object_id();
    if (!$current_page_id) return;

    $buttons_config = get_option('sms_buttons_config', []);
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

    $terms_url = get_option('sms_terms_url', '#');
    $max_quotas_btn = isset($active_btn['max_quotas']) ? intval($active_btn['max_quotas']) : 3;

    ?>
    <style>
        .sms-fab-container { position: fixed; bottom: 30px; right: 30px; z-index: 9999; display:flex; flex-direction:column; align-items:flex-end; }
        .sms-fab { background: #25d366; color: #fff; padding: 12px 25px; border-radius: 50px; cursor: pointer; box-shadow: 0 4px 15px rgba(0,0,0,0.2); display: flex; align-items: center; gap: 10px; font-family: sans-serif; font-weight: bold; transition: transform 0.3s; }
        .sms-fab:hover { transform: scale(1.05); }
        .sms-counter-badge { background: #333; color: #fff; font-size: 11px; padding: 5px 10px; border-radius: 10px; margin-bottom: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.2); opacity: 0.9; }
        
        .sms-modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 10000; justify-content: center; align-items: center; }
        .sms-modal-box { background: #fff; width: 90%; max-width: 450px; padding: 25px; border-radius: 12px; position: relative; max-height: 90vh; overflow-y: auto; font-family: sans-serif; }
        .sms-input { width: 100%; padding: 10px; margin-bottom: 10px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; }
        .sms-btn-submit { width: 100%; padding: 12px; background: #007cba; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; margin-top: 5px; }
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
                <h3>Solicitar Cotización</h3>
                <p style="color:#666; margin-bottom:15px; font-size:13px;">Conectamos tu solicitud con <?php echo $provider_count > 0 ? $provider_count : 'múltiples'; ?> empresas verificadas.</p>
                
                <form id="smsLeadForm">
                    <input type="hidden" name="action" value="sms_submit_lead_step1">
                    <input type="hidden" name="page_id" value="<?php echo $current_page_id; ?>">
                    <input type="hidden" id="maxAllowed" value="<?php echo $max_quotas_btn; ?>">
                    
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

                    <div id="msgPerson" style="display:none; background:#fff3cd; color:#856404; padding:10px; font-size:12px; margin-bottom:15px; border-radius:4px; border:1px solid #ffeeba;">
                        ⚠️ <strong>Nota:</strong> Algunos proveedores solo atienden a empresas legalmente constituidas.
                    </div>
                    
                    <div style="display:flex; gap:5px;">
                        <select name="country" class="sms-input" id="smsCountrySel" onchange="updateSmsCode()" style="flex:1;">
                            <option value="Colombia" data-code="+57">🇨🇴 Colombia (+57)</option>
                            <option value="Mexico" data-code="+52">🇲🇽 México (+52)</option>
                            <option value="Peru" data-code="+51">🇵🇪 Perú (+51)</option>
                            <option value="Espana" data-code="+34">🇪🇸 España (+34)</option>
                            <option value="Chile" data-code="+56">🇨🇱 Chile (+56)</option>
                            <option value="Argentina" data-code="+54">🇦🇷 Argentina (+54)</option>
                            <option value="USA" data-code="+1">🇺🇸 USA (+1)</option>
                        </select>
                        <input type="text" name="city" placeholder="Ciudad" class="sms-input" style="flex:1;" required>
                    </div>
                    
                    <div id="fieldCompany">
                        <input type="text" name="company" placeholder="Nombre de tu Empresa" class="sms-input">
                    </div>

                    <input type="text" name="name" placeholder="Nombre de Contacto" class="sms-input" required>
                    
                    <div style="position:relative; margin-bottom:10px;">
                        <div style="display:flex; gap:5px;">
                            <input type="text" id="smsPhoneCode" value="+57" readonly style="width:50px; background:#f0f0f0; text-align:center;" class="sms-input">
                            <input type="number" name="phone_raw" placeholder="Tu WhatsApp (Sin espacios)" class="sms-input" required onfocus="showTip()" onblur="hideTip()">
                        </div>
                        <div id="waTooltip" style="display:none; position:absolute; top:-35px; left:0; right:0; background:#333; color:#fff; padding:5px 10px; border-radius:5px; font-size:11px; z-index:100; text-align:center;">
                            🔒 Tu número solo se compartirá con los proveedores interesados en cotizar.
                        </div>
                    </div>
                    
                    <input type="email" name="email" placeholder="Email Corporativo" class="sms-input" required>
                    
                    <label style="font-size:12px; font-weight:bold; display:block; margin-bottom:5px;">¿Cuántas cotizaciones necesitas?</label>
                    <select name="requested_quotas" class="sms-input" id="quotaSel">
                        </select>

                    <textarea name="req" rows="3" placeholder="Describe detalladamente qué necesitas..." class="sms-input" required></textarea>
                    
                    <div style="margin-bottom:15px; font-size:12px; color:#555;">
                        <label style="cursor:pointer; display:flex; align-items:flex-start; gap:5px;">
                            <input type="checkbox" required style="margin-top:2px;"> 
                            <span>He leído y acepto los <a href="<?php echo esc_url($terms_url); ?>" target="_blank" style="color:#007cba; text-decoration:underline;">Términos y Condiciones</a> y la política de tratamiento de datos.</span>
                        </label>
                    </div>
                    
                    <button type="submit" class="sms-btn-submit" id="btnStep1">Solicitar Cotizaciones</button>
                </form>
            </div>

            <div id="smsStepWaitInteraction" style="display:none; text-align:center;">
                <h3 style="color:#007cba;">💬 Verificación Requerida</h3>
                <p>Para confirmar que eres una persona real, te hemos enviado un mensaje a <strong>WhatsApp</strong>.</p>
                
                <div style="background:#e8f0fe; padding:15px; border-radius:8px; margin:20px 0; border:1px solid #b8daff;">
                    <p style="margin:0; font-weight:bold; font-size:14px;">Por favor responde al mensaje con la palabra:</p>
                    <h2 style="margin:10px 0; color:#004085; letter-spacing:1px;">WHATSAPP</h2>
                    <p style="margin:0; font-size:12px;">El sistema te responderá automáticamente con tu código.</p>
                </div>
                
                <div class="sms-animate-pulse" style="width:12px; height:12px; background:#25d366; border-radius:50%; margin:0 auto 10px;"></div>
                <p style="font-size:12px; color:#666;">Esperando tu respuesta...</p>
                
                <button onclick="goToStep2()" class="sms-btn-submit" style="background:#fff; color:#007cba; border:1px solid #007cba; margin-top:15px;">Ya tengo el código</button>
            </div>

            <div id="smsStep2" style="display:none; text-align:center;">
                <h3>🔐 Ingresa el Código</h3>
                <p>Escribe el código de 4 dígitos que recibiste:</p>
                <input type="text" id="otpInput" class="sms-input" placeholder="0000" style="text-align:center; font-size:24px; letter-spacing:5px; width:150px; margin: 10px auto; display:block;" maxlength="4">
                <input type="hidden" id="tempLeadId">
                <button onclick="verifyOtp()" class="sms-btn-submit">Verificar y Enviar</button>
                <p id="otpError" style="color:red; margin-top:10px; font-size:13px;"></p>
            </div>

            <div id="smsStep3" style="display:none; text-align:center; padding:20px 0;">
                <div style="font-size:60px; margin-bottom:10px;">✅</div>
                <h3 style="color:#28a745; margin:0;">¡Solicitud Exitosa!</h3>
                <p style="color:#555;">Tus datos han sido validados.</p>
                <p style="font-size:13px; color:#666;">Los proveedores interesados se pondrán en contacto contigo pronto.</p>
                <button onclick="closeSmsModal()" class="sms-btn-submit" style="background:#666; margin-top:20px;">Cerrar</button>
            </div>
        </div>
    </div>

    <script>
        // Utilidades de UI
        function openSmsModal(){ document.getElementById('smsModal').style.display='flex'; }
        function closeSmsModal(){ document.getElementById('smsModal').style.display='none'; }
        function showTip() { document.getElementById('waTooltip').style.display='block'; }
        function hideTip() { document.getElementById('waTooltip').style.display='none'; }

        // Toggle Empresa/Persona
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

        // Navegación
        function goToStep2(){ 
            document.getElementById('smsStepWaitInteraction').style.display='none'; 
            document.getElementById('smsStep2').style.display='block'; 
        }
        
        // Actualizar indicativo
        function updateSmsCode() {
            var sel = document.getElementById('smsCountrySel');
            document.getElementById('smsPhoneCode').value = sel.options[sel.selectedIndex].getAttribute('data-code');
        }

        // Poblar Select de Cupos (IIFE)
        (function(){
            var max = parseInt(document.getElementById('maxAllowed').value) || 3;
            var sel = document.getElementById('quotaSel');
            if(sel) {
                sel.innerHTML = ''; // Limpiar
                for(var i=1; i<=max; i++) {
                    var opt = document.createElement('option');
                    opt.value = i;
                    opt.text = i + (i===max ? ' (Máximo)' : '');
                    if(i===max) opt.selected = true;
                    sel.appendChild(opt);
                }
            }
        })();

        // Enviar Formulario (Paso 1)
        document.getElementById('smsLeadForm').addEventListener('submit', function(e){
            e.preventDefault();
            var btn = document.getElementById('btnStep1');
            var originalText = btn.innerText;
            btn.innerText = 'Procesando...'; btn.disabled = true;

            var fd = new FormData(this);
            // Combinar indicativo + teléfono
            fd.append('phone', document.getElementById('smsPhoneCode').value + fd.get('phone_raw'));

            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {method:'POST', body:fd})
            .then(r=>r.json())
            .then(d=>{
                if(d.success){
                    document.getElementById('smsStep1').style.display='none';
                    document.getElementById('smsStepWaitInteraction').style.display='block';
                    document.getElementById('tempLeadId').value = d.data.lead_id;
                } else {
                    alert('Error: ' + (d.data || 'Intenta nuevamente'));
                    btn.innerText = originalText; btn.disabled = false;
                }
            })
            .catch(err => {
                alert('Error de conexión.');
                btn.innerText = originalText; btn.disabled = false;
            });
        });

        // Verificar Código (Paso 2)
        function verifyOtp(){
            var btn = document.querySelector('#smsStep2 button');
            btn.disabled = true; btn.innerText = 'Verificando...';

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
                    document.getElementById('otpError').innerText = 'Código incorrecto. Intenta de nuevo.';
                    btn.disabled = false; btn.innerText = 'Verificar y Enviar';
                }
            })
            .catch(err => {
                document.getElementById('otpError').innerText = 'Error de conexión.';
                btn.disabled = false; btn.innerText = 'Verificar y Enviar';
            });
        }
    </script>
    <?php
}

// ============================================================
// 2. LÓGICA AJAX (BACKEND)
// ============================================================

add_action('wp_ajax_sms_submit_lead_step1', 'sms_handle_step1');
add_action('wp_ajax_nopriv_sms_submit_lead_step1', 'sms_handle_step1');

function sms_handle_step1() {
    global $wpdb;
    
    // Generar código simple de 4 dígitos
    $otp = rand(1000, 9999);
    
    // --- CORRECCIÓN CRÍTICA DE LIMPIEZA ---
    // Limpiamos el teléfono aquí. Esto asegura que en la base de datos quede "+57300..."
    // Y así el webhook podrá encontrarlo fácilmente con LIKE '%300...'.
    $raw_phone = isset($_POST['phone']) ? $_POST['phone'] : '';
    $clean_phone = '+' . preg_replace('/[^0-9]/', '', $raw_phone); 
    
    $email = sanitize_email($_POST['email']);
    
    // Manejo Empresa/Persona
    $company_val = sanitize_text_field($_POST['company']);
    if (isset($_POST['client_type']) && $_POST['client_type'] === 'person' || empty($company_val)) {
        $company_val = 'Particular';
    }

    // Capturar cupos solicitados
    $requested_quotas = intval($_POST['requested_quotas']);
    if($requested_quotas <= 0) $requested_quotas = 3; 

    $data = [
        'country' => sanitize_text_field($_POST['country']),
        'city' => sanitize_text_field($_POST['city']), // <-- AQUÍ SE GUARDA LA CIUDAD
        'client_company' => $company_val,              // <-- AQUÍ SE GUARDA SI ES EMPRESA O PARTICULAR
        'client_name' => sanitize_text_field($_POST['name']),
        'client_phone' => $clean_phone, // <-- TELÉFONO LIMPIO
        'client_email' => $email,
        'service_page_id' => intval($_POST['page_id']),
        'requirement' => sanitize_textarea_field($_POST['req']),
        'verification_code' => $otp,
        'status' => 'pending',
        'max_quotas' => $requested_quotas, 
        'created_at' => current_time('mysql')
    ];

    $wpdb->insert("{$wpdb->prefix}sms_leads", $data);
    $lid = $wpdb->insert_id;
    
    // ENVIAR MENSAJE DE VERIFICACIÓN
    if(function_exists('sms_send_msg')) {
        $msg = "👋 Hola, recibimos tu solicitud.\n\nPara verificar tus datos y enviarte a los proveedores, responde a este mensaje:\n\n👉 Escribe *WHATSAPP* para recibir el código aquí.\n👉 Escribe *EMAIL* para recibirlo por correo.";
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
        
        // Notificar Admin
        $admin_phone = get_option('sms_admin_phone');
        if($admin_phone && function_exists('sms_send_msg')) {
            sms_send_msg($admin_phone, "🔔 Nueva cotización verificada #$lid ({$row->city}). Pendiente de aprobación.");
        }
        
        wp_send_json_success();
    } else {
        wp_send_json_error();
    }
}

// ============================================================
// 3. SHORTCODE: PERFIL PÚBLICO DEL PROVEEDOR
// ============================================================
add_shortcode('sms_perfil_publico', 'sms_render_public_profile');

function sms_render_public_profile() {
    if (!isset($_GET['uid'])) return '<p>Perfil no especificado.</p>';
    
    $uid = intval($_GET['uid']);
    $user = get_userdata($uid);
    if (!$user) return '<p>Proveedor no encontrado.</p>';

    // Obtener datos
    $com_name = get_user_meta($uid, 'sms_commercial_name', true) ?: $user->display_name;
    $desc = get_user_meta($uid, 'sms_company_desc', true) ?: 'Sin descripción disponible.';
    $address = get_user_meta($uid, 'billing_address_1', true);
    $phone = get_user_meta($uid, 'sms_whatsapp_notif', true); 
    $email = get_user_meta($uid, 'billing_email', true) ?: $user->user_email;
    $advisor = get_user_meta($uid, 'sms_advisor_name', true);
    $doc_status = get_user_meta($uid, 'sms_docs_status', true);
    
    // Alerta si no está verificado
    if($doc_status != 'verified') {
        return '<div style="background:#fff3cd; color:#856404; padding:15px; border-radius:5px; text-align:center;">⚠️ Este perfil está en proceso de verificación.</div>';
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
                    <h4 style="margin-top:0;">📍 Información Legal</h4>
                    <p><strong>Dirección:</strong> <?php echo esc_html($address ?: 'Oficina Virtual'); ?></p>
                    <div style="margin-top:15px; color:green; font-weight:bold; font-size:12px;">
                        ✅ Cámara de Comercio Verificada<br>
                        ✅ RUT Verificado
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
