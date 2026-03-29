<?php
namespace RoutesPro;

class Shortcodes {
    public static function register() {
        $map = [
            'fieldflow_my_daily_route' => 'my_daily_route',
            'fieldflow_dashboard' => 'dashboard',
            'fieldflow_route_change_form' => 'route_change_form',
            'fieldflow_front_hub' => 'front_hub',
            'fieldflow_front_routes' => 'front_routes',
            'fieldflow_front_commercial' => 'front_commercial',
            'fieldflow_app' => 'app',
            'fieldflow_route_today' => 'route_today',
            'fieldflow_discovery' => 'discovery',
            'fieldflow_report_visit' => 'report_visit',
            'fieldflow_checkin' => 'checkin',
            'fieldflow_client_portal' => 'client_portal',
            'fieldflow_client_team_mail' => 'client_team_mail',
            'fieldflow_team_inbox' => 'team_inbox',
            // aliases legados para não partir instalações atuais
            'routespro_my_daily_route' => 'my_daily_route',
            'routespro_dashboard' => 'dashboard',
            'routespro_route_change_form' => 'route_change_form',
            'routespro_front_hub' => 'front_hub',
            'routespro_front_routes' => 'front_routes',
            'routespro_front_commercial' => 'front_commercial',
            'routespro_app' => 'app',
            'routespro_route_today' => 'route_today',
            'routespro_discovery' => 'discovery',
            'routespro_report_visit' => 'report_visit',
            'routespro_checkin' => 'checkin',
            'routespro_client_portal' => 'client_portal',
            'routespro_client_team_mail' => 'client_team_mail',
            'routespro_team_inbox' => 'team_inbox',
        ];
        foreach ($map as $tag => $method) {
            add_shortcode($tag, [self::class, $method]);
        }
    }

    /**
     * SHORTCODE: [fieldflow_my_daily_route date="YYYY-MM-DD"]
     * - Mostra TODAS as rotas do utilizador no dia escolhido
     * - Tabs (desktop) ou dropdown (mobile) para trocar de rota
     * - Gestão de paragens: adicionar (Google Places), concluir, falha, foto, assinatura, tempos
     * - Export CSV + abrir no Google Maps
     */
    public static function my_daily_route($atts = []) {
        if (!is_user_logged_in()) return '<p>Por favor, inicia sessão.</p>';

        $date  = sanitize_text_field($atts['date'] ?? date('Y-m-d'));
        $nonce = wp_create_nonce('wp_rest');
        $export_nonce = wp_create_nonce('routespro_export_submissions');

        // Aparência
        $ap  = \RoutesPro\Admin\Appearance::get();
        $c1  = !empty($ap['primary_transparent']) ? 'transparent' : ($ap['primary_color'] ?? '#2b6cb0');
        $c2  = !empty($ap['accent_transparent'])  ? 'transparent' : ($ap['accent_color'] ?? '#38b2ac');
        $cbg = !empty($ap['bg_transparent'])      ? 'transparent' : ($ap['bg_color'] ?? '#f7fafc');
        $ff  = $ap['font_family'] ?? 'inherit';
        $fz  = intval($ap['font_size_px'] ?? 16);

        // Provider / key
        $opts  = \RoutesPro\Admin\Settings::get();
        $gmKey = $opts['google_maps_key'] ?? '';
        $prov  = $opts['maps_provider']   ?? 'google';

        $user  = wp_get_current_user();
        $user_label = trim(($user->display_name ?: $user->user_login) . ' • ' . ($user->user_email ?: ''));

        ob_start(); ?>
<style>
  .rp-wrap{border:1px solid #e6e6e6;border-radius:12px;background:<?php echo esc_attr($cbg); ?>;padding:12px;font-family:<?php echo esc_attr($ff); ?>;font-size:<?php echo esc_attr($fz); ?>px}
  .rp-btn{background:<?php echo esc_attr($c1); ?>;color:#fff;border:0;border-radius:8px;padding:8px 12px;cursor:pointer}
  .rp-btn.secondary{background:<?php echo esc_attr($c2); ?>}
  .rp-btn.ghost{background:#fff;color:#333;border:1px solid #ddd}
  .rp-input,.rp-select,.rp-textarea{border:1px solid #ddd;border-radius:8px;padding:8px;width:100%}
  .rp-textarea{min-height:70px}
  .rp-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .rp-title{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
  .rp-muted{opacity:.8}
  .rp-actions{display:flex;gap:6px;margin-top:6px;flex-wrap:wrap}
  .rp-chip{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid #e2e8f0;font-size:12px;background:#fff}
  .rp-chip.done{background:#e6ffed;border-color:#b7f5c5}
  .rp-chip.pending{background:#fffbea;border-color:#fde68a}
  .rp-chip.failed{background:#ffeaea;border-color:#ffc4c4}

  .rp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}
  .rp-route-main{display:grid;grid-template-columns:minmax(300px,420px) minmax(0,1fr);gap:20px;align-items:start}
  .rp-route-main > div{min-width:0}
  .rp-panel{margin-top:8px;border-top:1px dashed #e5e7eb;padding-top:8px}
  .rp-kv{display:grid;grid-template-columns:140px 1fr auto;gap:6px;align-items:center}
  .rp-kv label{font-size:12px;color:#555}

  .rp-list{list-style:decimal;padding-left:20px}
  .rp-list li{margin:10px 0;padding:10px;border:1px solid #eee;border-radius:10px;background:#fff}
  .rp-list li.rp-done{opacity:.9;background:#f4f8f4;border-color:#dfeee0}
  .rp-list li.rp-failed{background:#fff6f6;border-color:#ffd6d6}

  .rp-sig-wrap{border:1px dashed #bbb;border-radius:8px;padding:6px;background:#fafafa}
  .rp-sig-canvas{width:100%;height:140px;background:#fff;border:1px solid #ddd;border-radius:6px;touch-action:none}

  /* Tabs (desktop) */
  .rp-tabs{display:flex;gap:6px;overflow:auto;padding:6px;border-bottom:1px solid #e5e7eb;margin-top:8px}
  .rp-tabs.is-single{display:none}
  .rp-tab{white-space:nowrap;background:#fff;border:1px solid #e5e7eb;border-bottom-width:2px;border-radius:8px 8px 0 0;padding:6px 10px;cursor:pointer}
  .rp-tab[aria-selected="true"]{background:#fff;border-bottom-color:<?php echo esc_attr($c1); ?>;box-shadow:0 1px 0 <?php echo esc_attr($c1); ?> inset;font-weight:600}

  /* Mobile route picker (mostra no mobile, esconde tabs) */
  .rp-route-picker{display:none;margin-top:8px}
  .rp-route-picker.is-single{display:none !important}
  @media (max-width: 980px){.rp-route-main{grid-template-columns:1fr}}
  @media (max-width: 640px){
    .rp-tabs{display:none}
    .rp-route-picker{display:block}
  }
</style>

<div id="routespro-my-daily-route"
     class="rp-wrap"
     data-date="<?php echo esc_attr($date); ?>"
     data-nonce="<?php echo esc_attr($nonce); ?>"
     data-prov="<?php echo esc_attr($prov); ?>"
     data-gmkey="<?php echo esc_attr($gmKey); ?>">
  <div class="rp-title">
    <div>
      <h3 style="margin:0">As minhas rotas</h3>
      <div class="rp-muted"><?php echo esc_html($user_label); ?></div>
    </div>
    <div class="rp-row" role="group" aria-label="Data">
      <button class="rp-btn ghost" id="rp-prev-day" title="Ontem">◀</button>
      <input id="rp-date" class="rp-input" type="date" value="<?php echo esc_attr($date); ?>" style="max-width:160px">
      <button class="rp-btn ghost" id="rp-next-day" title="Amanhã">▶</button>
      <button id="btn-export" class="rp-btn secondary">Exportar CSV</button>
    </div>
  </div>

  <!-- Tabs desktop -->
  <div id="rp-tabs" class="rp-tabs" role="tablist" aria-label="Rotas do dia"></div>
  <!-- Dropdown mobile -->
  <div class="rp-route-picker">
    <label class="rp-muted" for="rp-route-select" style="display:block;margin-bottom:4px">Selecionar rota</label>
    <select id="rp-route-select" class="rp-select"></select>
  </div>

  <div id="rp-content" style="margin-top:12px"></div>
</div>

<script>
(function(){
  const root     = document.getElementById('routespro-my-daily-route');
  const content  = document.getElementById('rp-content');
  const tabsBox  = document.getElementById('rp-tabs');
  const selRoute = document.getElementById('rp-route-select');
  const inpDate  = document.getElementById('rp-date');

  const nonce   = root.dataset.nonce;
  const apiBase = '<?php echo esc_url( rest_url('routespro/v1/') ); ?>';

  const mapsProv = (root.dataset.prov || 'google').toLowerCase();
  const gmKey    = root.dataset.gmkey || '';
  let googleReady = false;

  // state
  let routesCache = [];   // [{id,date,status, ...}]
  let activeRouteId = null;

  function esc(s){ return (s==null?'':String(s)).replace(/[&<>]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }
  async function j(url, opts){
    const res = await fetch(url, Object.assign({credentials:'same-origin', headers:{'X-WP-Nonce':nonce}}, opts||{}));
    const text = await res.text();
    let jj = {};
    try { jj = text ? JSON.parse(text) : {}; } catch(_){ jj = { message: text }; }
    if(!res.ok) throw new Error(jj.message||res.statusText||'Request failed');
    return jj;
  }
  const localTimeHHMM = () => { const d=new Date(); return String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0'); };
  const joinDateTimeToISO = (dateYYYYMMDD, timeHHMM) => { if (!timeHHMM) return null; const dt = new Date(`${dateYYYYMMDD}T${timeHHMM}`); return isNaN(dt.getTime()) ? null : dt.toISOString(); };

  function fmtDate(d){ return new Date(d).toISOString().slice(0,10); }

  // geolocalização
  function getGeo(){
    return new Promise((resolve)=> {
      if (!('geolocation' in navigator)) return resolve(null);
      navigator.geolocation.getCurrentPosition(
        p=> resolve({lat: p.coords.latitude, lng: p.coords.longitude}),
        _=> resolve(null),
        { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 }
      );
    });
  }

  // Google Places
  function loadGoogle(cb){
    if (googleReady || mapsProv !== 'google' || !gmKey) { cb && cb(); return; }
    const s = document.createElement('script');
    s.src = 'https://maps.googleapis.com/maps/api/js?key='+encodeURIComponent(gmKey)+'&libraries=places';
    s.async = true; s.defer = true;
    s.onload = ()=>{ googleReady = true; cb && cb(); };
    s.onerror = ()=>{ cb && cb(); };
    document.head.appendChild(s);
  }
  function makeAutocomplete(input){
    if (!googleReady || !window.google || !google.maps?.places) return null;
    return new google.maps.places.Autocomplete(input, { fields:['formatted_address','geometry','name'] });
  }
  function geocodeAddress(addr){
    return new Promise((resolve)=>{
      if (!googleReady || !addr) return resolve(null);
      const geocoder = new google.maps.Geocoder();
      geocoder.geocode({ address: addr }, (res, status)=>{
        if (status === 'OK' && res && res[0] && res[0].geometry) {
          const g = res[0].geometry.location;
          resolve({ lat:g.lat(), lng:g.lng(), formatted: res[0].formatted_address });
        } else resolve(null);
      });
    });
  }
  function isNum(n){ return Number.isFinite(n) && !Number.isNaN(n); }

  // Media upload (foto/prova) -> WP Media Library
  async function uploadMedia(file){
    const endpoint = '<?php echo esc_url( rest_url('wp/v2/media') ); ?>';
    const res = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'X-WP-Nonce': nonce,
        'Content-Disposition': 'attachment; filename="'+encodeURIComponent(file.name||('foto-'+Date.now()+'.jpg'))+'"'
      },
      body: file
    });
    const dataText = await res.text();
    let data = {};
    try{ data = dataText ? JSON.parse(dataText) : {}; }catch(_){ data = {}; }
    if(!res.ok) throw new Error(data.message || 'Falha no upload');
    return data.source_url || data.guid?.rendered || '';
  }

  // Helpers para criar localização e paragem
  async function ensureLoc(address, lat, lng, client_id, project_id){
    if (!isNum(lat) || !isNum(lng)) throw new Error('Sem coordenadas válidas.');
    const r = await fetch(apiBase+'locations', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},
      body: JSON.stringify({
        client_id: parseInt(client_id||0,10) || 0,
        project_id: project_id ? parseInt(project_id,10) : null,
        name: address, address, lat, lng
      })
    });
    const text = await r.text();
    let jx = {};
    try{ jx = text ? JSON.parse(text) : {}; }catch(_){ jx = { message:text }; }
    if(!r.ok) throw new Error(jx.message || 'Falha a criar localização');
    if(!jx.id) throw new Error('Resposta sem id da localização');
    return jx.id;
  }
  async function createStop(routeId, locationId, seq){
    if (!routeId || !locationId) throw new Error('route_id/location_id obrigatórios');
    const r = await fetch(apiBase+'stops', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},
      body: JSON.stringify({ route_id: parseInt(routeId,10), location_id: parseInt(locationId,10), seq: parseInt(seq||0,10) })
    });
    if(!r.ok){ const t = await r.text(); throw new Error(t || 'Falha a criar paragem'); }
    return true;
  }
  async function patchStop(id, payload){
    return j(apiBase+'stops/'+id, {
      method:'PATCH',
      headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},
      body: JSON.stringify(payload || {})
    });
  }

  async function refetchAndRender(routeId){
    const det = await j(apiBase+'routes/'+routeId+'?v='+Date.now());
    renderRoute(det);
  }

  function renderEmpty(dateStr){
    tabsBox.innerHTML = '';
    selRoute.innerHTML = '';
    content.innerHTML =
      '<h3>Não tens rota atribuída para '+esc(dateStr)+'</h3>' +
      '<p class="rp-muted">Se a rota foi criada no BO, confirma a atribuição a este utilizador.</p>';
  }

  // Google Maps Directions URL
  function buildGMapsUrlFromStops(stops){
    if (!Array.isArray(stops) || stops.length < 2) return null;
    const toPoint = (s) => {
      const lat = parseFloat(s.lat);
      const lng = parseFloat(s.lng);
      if (Number.isFinite(lat) && Number.isFinite(lng)) return lat + ',' + lng;
      return (s.address && s.address.trim()) ? s.address.trim() : (s.location_name || '');
    };
    const origin = toPoint(stops[0]);
    const destination = toPoint(stops[stops.length - 1]);
    const mids = stops.slice(1, -1).map(toPoint).slice(0, 23);
    let url = 'https://www.google.com/maps/dir/?api=1&travelmode=driving'
      + '&origin=' + encodeURIComponent(origin)
      + '&destination=' + encodeURIComponent(destination);
    if (mids.length) url += '&waypoints=' + encodeURIComponent(mids.join('|'));
    return url;
  }

  // assinatura (canvas)
  function initSigPad(canvas){
    const ctx = canvas.getContext('2d');
    let drawing = false, last = null;
    const getPos = (e) => {
      const rect = canvas.getBoundingClientRect();
      const touch = e.touches ? e.touches[0] : null;
      const x = (touch ? touch.clientX : e.clientX) - rect.left;
      const y = (touch ? touch.clientY : e.clientY) - rect.top;
      return { x: x * (canvas.width/rect.width), y: y * (canvas.height/rect.height) };
    };
    const start = (e)=>{ drawing = true; last = getPos(e); e.preventDefault(); };
    const move  = (e)=>{ if(!drawing) return; const p=getPos(e); ctx.beginPath(); ctx.moveTo(last.x,last.y); ctx.lineTo(p.x,p.y); ctx.lineWidth=2; ctx.lineCap='round'; ctx.stroke(); last = p; e.preventDefault(); };
    const end   = ()=>{ drawing = false; };
    canvas.addEventListener('mousedown', start); canvas.addEventListener('mousemove', move); window.addEventListener('mouseup', end);
    canvas.addEventListener('touchstart', start, {passive:false}); canvas.addEventListener('touchmove', move, {passive:false}); canvas.addEventListener('touchend', end);
    const dpr = window.devicePixelRatio || 1;
    canvas.width  = Math.floor(canvas.clientWidth * dpr);
    canvas.height = Math.floor(canvas.clientHeight * dpr);
    ctx.scale(dpr, dpr);
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    return ()=>{ ctx.clearRect(0,0,canvas.width,canvas.height); };
  }

  function renderTabs(routes){
    const hasMultipleRoutes = Array.isArray(routes) && routes.length > 1;
    tabsBox.classList.toggle('is-single', !hasMultipleRoutes);
    selRoute.closest('.rp-route-picker')?.classList.toggle('is-single', !hasMultipleRoutes);

    // desktop tabs
    tabsBox.innerHTML = routes.map((r,idx)=>{
      const label = '#'+r.id + (r.project_id ? ' · P'+r.project_id : '') + (r.status ? ' · '+r.status : '');
      const sel = (r.id===activeRouteId) ? 'true' : 'false';
      return `<button role="tab" class="rp-tab" data-route="${r.id}" aria-selected="${sel}" aria-controls="rp-panel-${r.id}">${esc(label)}</button>`;
    }).join('');

    tabsBox.querySelectorAll('.rp-tab').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const rid = parseInt(btn.dataset.route,10);
        if (rid && rid!==activeRouteId){ activeRouteId = rid; updateActiveTab(); refetchAndRender(activeRouteId); syncMobilePicker(); }
      });
    });

    // mobile select
    selRoute.innerHTML = routes.map(r=>{
      const label = '#'+r.id + (r.project_id ? ' · P'+r.project_id : '') + (r.status ? ' · '+r.status : '');
      const sel = (r.id===activeRouteId) ? 'selected' : '';
      return `<option value="${r.id}" ${sel}>${esc(label)}</option>`;
    }).join('');
    selRoute.disabled = !hasMultipleRoutes;

    selRoute.onchange = ()=>{
      const rid = parseInt(selRoute.value,10);
      if (rid && rid!==activeRouteId){ activeRouteId = rid; updateActiveTab(); refetchAndRender(activeRouteId); }
    };
  }
  function updateActiveTab(){
    tabsBox.querySelectorAll('.rp-tab').forEach(b=> b.setAttribute('aria-selected', String(parseInt(b.dataset.route,10)===activeRouteId)) );
  }
  function syncMobilePicker(){
    selRoute.value = activeRouteId ? String(activeRouteId) : '';
  }

  function renderRoute(det){
    const route = det;
    const stops = det.stops || [];

    let html = '';
    html += '<div class="rp-route-main">';
    html += '  <div>';
    html += '    <h3 style="margin:0">Rota #'+esc(route.id)+' — '+esc(route.date)+'</h3>';
    html += '    <p class="rp-muted" style="margin:.25rem 0">Status: <strong>'+esc(route.status||'')+'</strong> • Paragens: <strong>'+stops.length+'</strong></p>';
    html += '    <button id="rp-edit" class="rp-btn">Editar rota</button> ';
    html += '    <button id="rp-add-stop" class="rp-btn secondary">Adicionar paragem</button> ';
    html += '    <button id="rp-open-gmaps" class="rp-btn secondary" title="Abrir no Google Maps">Ver no Google Maps</button>';
    html += '    <div id="rp-add-inline" style="display:none" class="rp-row"></div>';
    html += '  </div>';
    html += '  <div>';
    html += '    <h4 style="margin:.25rem 0">Itinerário</h4><ol class="rp-list">';

    for (let i=0;i<stops.length;i++){
      const s = stops[i];
      const status = String(s.status||'').toLowerCase();
      const isDone = (status === 'done' || status === 'completed');
      const isFailed = (status === 'failed');
      const liClass = isDone ? 'rp-done' : (isFailed ? 'rp-failed' : '');

      html += '<li data-stop="'+s.id+'" class="'+liClass+'">';
      html +=   '<strong>'+(s.seq)+'. '+esc(s.location_name||'')+'</strong>';
      if (s.address) html += '<br><small class="rp-muted">'+esc(s.address)+'</small>';

      const chipClass = isDone ? 'rp-chip done' : (isFailed ? 'rp-chip failed' : 'rp-chip pending');
      const chipText  = isDone ? 'executado' : (isFailed ? 'falhou' : 'pendente');

      html += '<div class="rp-actions">';
      html +=   '<span class="'+chipClass+'">'+chipText+'</span>';
      if (!isDone) {
        html +=   '<button data-action="mark-done" class="rp-btn">Concluir</button>';
        html +=   '<button data-action="mark-failed" class="rp-btn secondary">Falhou</button>';
      }
      html +=     '<button data-action="delete-stop" class="rp-btn ghost">Apagar</button>';
      html += '</div>';

      // Painel de reporte
      html += '<div class="rp-panel">';
      html +=   '<div class="rp-grid">';

      // Notas
      html +=     '<div><label class="rp-muted" for="note-'+s.id+'">Notas/observações</label><textarea id="note-'+s.id+'" class="rp-textarea" placeholder="Escreve observações...">'+esc(s.note||'')+'</textarea></div>';

      // Motivo de falha + Foto (upload)
      html +=     '<div>';
      html +=       '<div class="rp-kv"><label for="fail-'+s.id+'">Motivo de falha</label>';
      html +=       '<select id="fail-'+s.id+'" class="rp-select">';
      const options = ['','ausente','morada_errada','acesso_negado','condicoes','outro'];
      for (const val of options){
        const label = val ? val.replace('_',' ') : '(nenhum)';
        html += `<option value="${val}" ${val=== (s.fail_reason||'') ? 'selected':''}>${label}</option>`;
      }
      html +=       '</select><span></span></div>';

      html +=       '<div class="rp-kv" style="margin-top:6px"><label for="photo-'+s.id+'">URL foto/prova</label><input id="photo-'+s.id+'" class="rp-input" type="url" placeholder="https://..." value="'+esc(s.photo_url||'')+'">';
      html +=       '<button class="rp-btn ghost" data-action="upload-photo" data-stop="'+s.id+'">Tirar foto/Upload</button></div>';
      html +=     '</div>';

      // Chegada/Partida — hora
      const arrTime = s.arrived_at ? new Date(s.arrived_at).toTimeString().slice(0,5) : '';
      const depTime = s.departed_at ? new Date(s.departed_at).toTimeString().slice(0,5) : '';
      const dur = parseInt(s.duration_s||0,10) || 0;

      html +=     '<div>';
      html +=       '<div class="rp-kv"><label>Chegada</label><button data-action="set-arr" data-stop="'+s.id+'" class="rp-btn ghost">Agora</button><input id="arr-'+s.id+'" class="rp-input" type="time" value="'+esc(arrTime)+'"></div>';
      html +=       '<div class="rp-kv" style="margin-top:6px"><label>Partida</label><button data-action="set-dep" data-stop="'+s.id+'" class="rp-btn ghost">Agora</button><input id="dep-'+s.id+'" class="rp-input" type="time" value="'+esc(depTime)+'"></div>';
      html +=       '<div class="rp-kv" style="margin-top:6px"><label>Duração</label><span id="dur-'+s.id+'" class="rp-muted">'+(dur?Math.round(dur/60)+' min':'—')+'</span><span></span></div>';
      html +=     '</div>';

      // Assinatura
      html +=     '<div class="rp-sig-wrap">';
      html +=       '<label class="rp-muted">Assinatura do cliente</label>';
      html +=       '<canvas id="sig-'+s.id+'" class="rp-sig-canvas"></canvas>';
      html +=       '<div class="rp-actions"><button data-action="sig-clear" data-stop="'+s.id+'" class="rp-btn ghost">Limpar</button></div>';
      html +=     '</div>';

      html +=   '</div>'; // grid
      html +=   '<div class="rp-actions" style="justify-content:flex-end">';
      html +=     '<button data-action="save-report" class="rp-btn secondary">Guardar reporte</button>';
      html +=   '</div>';
      html += '</div>'; // panel

      html += '</li>';
    }
    html += '    </ol>';
    html += '  </div>';
    html += '</div>';

    // editor simples (data/status)
    html += '<div id="rp-edit-form" style="display:none;margin-top:12px" class="rp-wrap">';
    html += '  <h4 style="margin:.25rem 0">Editar Rota</h4>';
    html += '  <div class="rp-grid">';
    html += '    <label>Data <input class="rp-input" id="f-date" type="date" value="'+esc(route.date)+'"></label>';
    html += '    <label>Status <select class="rp-input" id="f-status">'+['draft','planned','in_progress','completed','canceled'].map(s=>'<option '+(s===route.status?'selected':'')+'>'+s+'</option>').join('')+'</select></label>';
    html += '  </div>';
    html += '  <p style="margin-top:8px"><button id="f-save" class="rp-btn">Guardar</button> <button id="f-cancel" class="rp-btn secondary">Cancelar</button></p>';
    html += '</div>';

    content.innerHTML = html;

    // Iniciar canvases assinatura
    const clearers = {};
    for (const s of stops){
      const cnv = document.getElementById('sig-'+s.id);
      if (cnv) clearers[s.id] = initSigPad(cnv);
    }

    // abrir Directions
    const openBtn = document.getElementById('rp-open-gmaps');
    if (openBtn) openBtn.addEventListener('click', ()=> {
      const url = buildGMapsUrlFromStops(stops);
      if (!url) { alert('Precisas de pelo menos duas paragens para navegar.'); return; }
      window.open(url, '_blank', 'noopener');
    });

    // toggle editor
    document.getElementById('rp-edit').onclick = ()=>{ document.getElementById('rp-edit-form').style.display='block'; };
    const cancelBtn = document.getElementById('f-cancel');
    if (cancelBtn) cancelBtn.onclick = ()=>{ document.getElementById('rp-edit-form').style.display='none'; };

    // Inline Add Stop
    const addBtn = document.getElementById('rp-add-stop');
    const addWrap= document.getElementById('rp-add-inline');
    addBtn.addEventListener('click', () => {
      addWrap.style.display = '';
      addWrap.innerHTML =
        '<input id="rp-new-stop" class="rp-input" placeholder="Morada ou ponto">'+
        '<button id="rp-save-stop" class="rp-btn">Adicionar</button>'+
        '<button id="rp-cancel-stop" class="rp-btn secondary">Cancelar</button>';

      loadGoogle(()=> {
        const input = document.getElementById('rp-new-stop');
        const ac = makeAutocomplete(input);
        if (ac) {
          ac.addListener('place_changed', () => {
            const plc = ac.getPlace();
            input.dataset.lat = '';
            input.dataset.lng = '';
            input.dataset.addr = '';
            if (plc && plc.geometry) {
              input.dataset.lat  = plc.geometry.location.lat();
              input.dataset.lng  = plc.geometry.location.lng();
              input.dataset.addr = plc.formatted_address || plc.name || input.value;
            }
          });
        }
      });
    });

    // handlers
    content.addEventListener('click', async (e)=>{
      const b = e.target.closest('button'); if(!b) return;
      const li  = b.closest('li');
      const sid = li ? li.getAttribute('data-stop') : null;

      // Guardar alterações básicas (rota)
      if (b.id === 'f-save'){
        const d = document.getElementById('f-date').value;
        const status = document.getElementById('f-status').value;
        await j(apiBase+'routes/'+(route.id||det.id), {
          method:'PATCH',
          headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},
          body: JSON.stringify({ date: d, status: status })
        });
        alert('Rota atualizada.');
        await refetchAndRender(route.id||det.id);
        return;
      }

      if (b.id === 'f-cancel'){ return; }

      // Hora = agora
      if (b.dataset.action === 'set-arr' && sid){
        const input = document.getElementById('arr-'+sid);
        input.value = localTimeHHMM();
        return;
      }
      if (b.dataset.action === 'set-dep' && sid){
        const input = document.getElementById('dep-'+sid);
        input.value = localTimeHHMM();
        return;
      }

      // Assinatura: limpar
      if (b.dataset.action === 'sig-clear' && sid){
        if (clearers[sid]) clearers[sid]();
        return;
      }

      // Upload foto (câmara/ficheiro)
      if (b.dataset.action === 'upload-photo' && sid){
        try{
          const picker = document.createElement('input');
          picker.type = 'file';
          picker.accept = 'image/*';
          picker.capture = 'environment';
          picker.onchange = async () => {
            const file = picker.files && picker.files[0];
            if (!file) return;
            const url = await uploadMedia(file);
            const urlEl = document.getElementById('photo-'+sid);
            if (urlEl) urlEl.value = url;
          };
          picker.click();
        }catch(err){ alert('Falha no upload: '+(err?.message||err)); }
        return;
      }

      // Marcar como executado
      if (b.dataset.action === 'mark-done' && sid){
        const payload = collectReportPayload(sid, 'done', route.date);
        const geo = await getGeo(); if (geo){ payload.real_lat = geo.lat; payload.real_lng = geo.lng; }
        if (!payload.departed_at) {
          const hhmm = localTimeHHMM();
          payload.departed_at = joinDateTimeToISO(route.date, hhmm);
        }
        await patchStop(sid, payload);
        await refetchAndRender(route.id||det.id);
        return;
      }

      // Marcar como falhou
      if (b.dataset.action === 'mark-failed' && sid){
        const failEl = document.getElementById('fail-'+sid);
        const reason = (failEl?.value || '').trim();
        if (!reason){ alert('Seleciona um motivo de falha.'); return; }
        const payload = collectReportPayload(sid, 'failed', route.date);
        await patchStop(sid, payload);
        await refetchAndRender(route.id||det.id);
        return;
      }

      // Remover stop
      if (b.dataset.action === 'delete-stop' && sid){
        if (!confirm('Apagar esta paragem?')) return;
        await j(apiBase+'stops/'+sid, { method:'DELETE' });
        await refetchAndRender(route.id||det.id);
        return;
      }

      // Cancelar inline add
      if (b.id === 'rp-cancel-stop'){
        document.getElementById('rp-add-inline').style.display='none';
        document.getElementById('rp-add-inline').innerHTML='';
        return;
      }

      // Adicionar stop
      if (b.id === 'rp-save-stop'){
        const btn = b;
        try{
          btn.disabled = true;
          const input = document.getElementById('rp-new-stop');
          let addr = (input.dataset.addr || '').trim();
          let lat  = parseFloat(input.dataset.lat || 'NaN');
          let lng  = parseFloat(input.dataset.lng || 'NaN');

          if (!addr) addr = (input.value || '').trim();
          if ((!isNum(lat) || !isNum(lng)) && addr && googleReady) {
            const g = await geocodeAddress(addr);
            if (g) { lat = g.lat; lng = g.lng; addr = g.formatted || addr; }
          }
          if (!addr || !isNum(lat) || !isNum(lng)) { alert('Escolhe um ponto válido do dropdown (Google) ou usa uma morada completa.'); return; }

          const routeId   = route.id || det.id;
          const client_id = route.client_id ?? det.client_id ?? null;
          const project_id= route.project_id ?? det.project_id ?? null;

          const locId = await ensureLoc(addr, lat, lng, client_id, project_id);

          const lastSeq = Math.max(0, ...Array.from(content.querySelectorAll('.rp-list li')).map(li => parseInt(li.querySelector('strong')?.textContent?.split('.')[0]) || 0));
          const nextSeq = Number.isFinite(lastSeq) ? lastSeq + 1 : 1;

          await createStop(routeId, locId, nextSeq);

          document.getElementById('rp-add-inline').style.display='none';
          document.getElementById('rp-add-inline').innerHTML='';
          await refetchAndRender(routeId);
        } catch(err){
          console.error('[routespro] add stop fail', err);
          let msg = err?.message || String(err);
          try { const jj = JSON.parse(msg); msg = jj.message || msg; } catch(_){}
          alert('Falha ao adicionar paragem: ' + msg);
        } finally {
          btn.disabled = false;
        }
        return;
      }

      // Guardar reporte manualmente
      if (b.dataset.action === 'save-report' && sid){
        const payload = collectReportPayload(sid, null, route.date);
        await patchStop(sid, payload);
        alert('Reporte guardado.');
        await refetchAndRender(route.id||det.id);
        return;
      }
    });

    function collectReportPayload(sid, setStatus, dateStr){
      const note  = (document.getElementById('note-'+sid)?.value || '').trim();
      const fail  = (document.getElementById('fail-'+sid)?.value || '').trim();
      const photo = (document.getElementById('photo-'+sid)?.value || '').trim();

      const arrEl = document.getElementById('arr-'+sid);
      const depEl = document.getElementById('dep-'+sid);
      const arrISO = arrEl && arrEl.value ? joinDateTimeToISO(dateStr, arrEl.value) : null;
      const depISO = depEl && depEl.value ? joinDateTimeToISO(dateStr, depEl.value) : null;

      const cnv = document.getElementById('sig-'+sid);
      let signature_data = null;
      if (cnv){ try { signature_data = cnv.toDataURL('image/png'); } catch(_){ } }

      let duration_s = null;
      if (arrISO && depISO){
        duration_s = Math.max(0, Math.floor((new Date(depISO).getTime() - new Date(arrISO).getTime())/1000));
      }

      const payload = {};
      if (setStatus) payload.status = setStatus;
      payload.note = note;
      if (fail) payload.fail_reason = fail;
      if (photo) payload.photo_url = photo;
      if (signature_data) payload.signature_data = signature_data;
      if (arrISO) payload.arrived_at = arrISO;
      if (depISO) payload.departed_at = depISO;
      if (duration_s !== null) payload.duration_s = duration_s;

      return payload;
    }
  }

  async function loadRoutesForDay(dateStr){
    const data = await j(apiBase+'routes?date='+encodeURIComponent(dateStr)+'&user_id=me');
    routesCache = Array.isArray(data.routes) ? data.routes : [];
    if (!routesCache.length){ renderEmpty(dateStr); return false; }
    // escolher ativa: mantém anterior se existir, senão a 1ª
    if (!activeRouteId || !routesCache.some(r=>r.id===activeRouteId)){
      activeRouteId = routesCache[0].id;
    }
    renderTabs(routesCache);
    await refetchAndRender(activeRouteId);
    return true;
  }

  // Export CSV (resumo simples da rota ativa)
  document.getElementById('btn-export').addEventListener('click', async function(){
    if (!activeRouteId) return;
    const det = await j(apiBase+'routes/'+activeRouteId+'?v='+Date.now());
    let csv = 'route_id,date,status,stops\n';
    csv += [det.id, det.date||'', det.status||'', (det.stops||[]).length].join(',') + '\n';
    const blob = new Blob([csv], {type:'text/csv'});
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'routespro-'+(det.date||'')+'.csv'; a.click();
  });

  // Navegação de datas
  document.getElementById('rp-prev-day').addEventListener('click', async ()=>{
    const d = new Date(inpDate.value || new Date());
    d.setDate(d.getDate()-1); inpDate.value = fmtDate(d);
    await loadRoutesForDay(inpDate.value);
  });
  document.getElementById('rp-next-day').addEventListener('click', async ()=>{
    const d = new Date(inpDate.value || new Date());
    d.setDate(d.getDate()+1); inpDate.value = fmtDate(d);
    await loadRoutesForDay(inpDate.value);
  });
  inpDate.addEventListener('change', ()=> loadRoutesForDay(inpDate.value));

  loadGoogle(()=>{ loadRoutesForDay(inpDate.value); });
})();
</script>
<?php
        return ob_get_clean();
    }

    /**
     * DASHBOARD
     * - Filtros: Projeto, Função (implementação/merchandising/comercial), Funcionário
     * - Resumo + tabela por dia
     * - Exporta toda a tabela
     */


    private static function front_guard() {
        if (!is_user_logged_in()) return '<p>Por favor, inicia sessão.</p>';
        if (!\RoutesPro\Support\Permissions::can_access_front()) {
            return '<p>Sem permissões.</p>';
        }
        return '';
    }

    private static function front_theme(): array {
        $ap  = \RoutesPro\Admin\Appearance::get();
        return [
            'primary' => !empty($ap['primary_transparent']) ? 'transparent' : ($ap['primary_color'] ?? '#7c3aed'),
            'accent'  => !empty($ap['accent_transparent'])  ? 'transparent' : ($ap['accent_color'] ?? '#0ea5e9'),
            'bg'      => !empty($ap['bg_transparent'])      ? 'transparent' : ($ap['bg_color'] ?? '#f8fafc'),
            'font'    => $ap['font_family'] ?? 'inherit',
            'size'    => intval($ap['font_size_px'] ?? 16),
        ];
    }

    public static function front_hub($atts = []) {
        return self::app($atts);
    }

    public static function front_commercial($atts = []) {
        $guard = self::front_guard();
        if ($guard) return $guard;
        $theme = self::front_theme();
        $opts = \RoutesPro\Admin\Settings::get();
        $gmKey = trim((string)($opts['google_maps_key'] ?? ''));
        $nonce = wp_create_nonce('wp_rest');
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $clients = \RoutesPro\Support\Permissions::filter_clients($wpdb->get_results("SELECT id,name FROM {$px}clients ORDER BY name ASC", ARRAY_A) ?: []);
        $projects = \RoutesPro\Support\Permissions::filter_projects($wpdb->get_results("SELECT id,name,client_id FROM {$px}projects ORDER BY name ASC", ARRAY_A) ?: []);
        $initial_client_id = absint($atts['client_id'] ?? ($_GET['client_id'] ?? 0));
        $initial_project_id = absint($atts['project_id'] ?? ($_GET['project_id'] ?? 0));
        $scope = \RoutesPro\Support\Permissions::get_scope();
        ob_start(); ?>
<style>
.rp-front-commercial{--rp-primary:<?php echo esc_attr($theme['primary']); ?>;--rp-accent:<?php echo esc_attr($theme['accent']); ?>;--rp-bg:<?php echo esc_attr($theme['bg']); ?>;font-family:<?php echo esc_attr($theme['font']); ?>;font-size:<?php echo esc_attr($theme['size']); ?>px}
.rp-front-commercial .rp-card{background:#fff;border:1px solid #e2e8f0;border-radius:22px;box-shadow:0 16px 40px rgba(15,23,42,.08);padding:18px}
.rp-front-commercial .rp-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(320px,.8fr);gap:16px}
.rp-front-commercial .rp-toolbar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.rp-front-commercial .rp-toolbar input,.rp-front-commercial .rp-toolbar select{min-width:160px;border:1px solid #cbd5e1;border-radius:12px;padding:10px 12px}
.rp-front-commercial .pac-container{z-index:99999 !important;border-radius:14px;box-shadow:0 18px 40px rgba(15,23,42,.18)}
.rp-front-commercial .rp-btn{border:0;border-radius:12px;padding:10px 14px;background:var(--rp-primary);color:#fff;font-weight:700;cursor:pointer}
.rp-front-commercial .rp-btn.alt{background:var(--rp-accent)}
.rp-front-commercial .rp-btn.ghost{background:#fff;color:#0f172a;border:1px solid #cbd5e1}
.rp-front-commercial .rp-map{height:360px;min-height:360px;border-radius:18px;overflow:hidden;border:1px solid #dbeafe;background:#eff6ff;position:relative;z-index:1}
.rp-front-commercial .rp-list{max-height:420px;overflow:auto;margin-top:12px}
.rp-front-commercial .rp-item{border:1px solid #e2e8f0;border-radius:16px;padding:12px;background:#fff;margin-bottom:10px}
.rp-front-commercial .rp-item h4{margin:0 0 6px}
.rp-front-commercial .rp-meta{color:#64748b;font-size:13px}
.rp-front-commercial .rp-inline{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.rp-front-commercial .rp-inline .full{grid-column:1/-1}
.rp-front-commercial .rp-inline input,.rp-front-commercial .rp-inline select{border:1px solid #cbd5e1;border-radius:12px;padding:10px 12px;width:100%}
.rp-front-commercial .rp-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:12px}
.rp-front-commercial .rp-summary div{background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:14px}
.rp-front-commercial .rp-summary strong{display:block;font-size:24px}
@media (max-width:1080px){.rp-front-commercial .rp-grid,.rp-front-commercial .rp-inline,.rp-front-commercial .rp-summary{grid-template-columns:1fr}}
</style>
<div class="rp-front-commercial" data-nonce="<?php echo esc_attr($nonce); ?>" data-gmkey="<?php echo esc_attr($gmKey); ?>" data-clients='<?php echo esc_attr(wp_json_encode($clients)); ?>' data-projects='<?php echo esc_attr(wp_json_encode($projects)); ?>' data-client-id="<?php echo esc_attr($initial_client_id); ?>" data-project-id="<?php echo esc_attr($initial_project_id); ?>" data-scope='<?php echo esc_attr(wp_json_encode($scope)); ?>'>
  <div class="rp-card" style="margin-bottom:16px">
    <h3 style="margin:0 0 6px">Base Comercial Front</h3>
    <div class="rp-meta">Mesma lógica da Base Comercial do backoffice, mas pensada para operação no front, com pesquisa interna, novos PDVs via Google e gravação imediata.</div>
    <div class="rp-toolbar" style="margin-top:14px">
      <select id="rpc-client"><option value="">Cliente</option></select>
      <select id="rpc-project"><option value="">Campanha</option></select>
      <select id="rpc-owner"><option value="">Owner operativo</option></select>
      <select id="rpc-role"><option value="">Função operacional</option></select>
      <select id="rpc-district"><option value="">Distrito</option></select>
      <select id="rpc-county"><option value="">Concelho</option></select>
      <select id="rpc-city"><option value="">Cidade</option></select>
      <select id="rpc-category"><option value="">Categoria</option></select>
      <select id="rpc-subcategory"><option value="">Subcategoria</option></select>
      <input type="search" id="rpc-q" placeholder="Nome, morada ou contacto">
      <button type="button" class="rp-btn" id="rpc-run">Filtrar</button>
      <button type="button" class="rp-btn alt" id="rpc-google">Google</button>
    </div>
    <div class="rp-summary">
      <div><span>Internos</span><strong id="rpc-count-internal">0</strong></div>
      <div><span>Google</span><strong id="rpc-count-google">0</strong></div>
      <div><span>No mapa</span><strong id="rpc-count-map">0</strong></div>
    </div>
  </div>
  <div class="rp-grid">
    <div class="rp-card">
      <div id="rpc-map" class="rp-map"></div>
      <div class="rp-list" id="rpc-results"></div>
    </div>
    <div class="rp-card">
      <h4 style="margin-top:0">Novo / editar PDV</h4>
      <div class="rp-inline">
        <input class="full" id="rpc-name" placeholder="Nome do estabelecimento">
        <input class="full" id="rpc-address" placeholder="Morada">
        <input id="rpc-phone" placeholder="Telefone">
        <input id="rpc-email" placeholder="Email">
        <input id="rpc-contact" placeholder="Contacto">
        <input id="rpc-website" placeholder="Website">
        <input id="rpc-lat" placeholder="Lat">
        <input id="rpc-lng" placeholder="Lng">
        <input type="hidden" id="rpc-place-id">
        <button type="button" class="rp-btn full" id="rpc-save">Guardar PDV</button>
      </div>
      <p class="rp-meta" id="rpc-status" style="margin-top:10px">Seleciona um PDV da lista ou do mapa para preencher automaticamente. Também podes criar manualmente.</p>
    </div>
  </div>
</div>
<script>
(async function(){
  const root = document.currentScript.previousElementSibling; if(!root || !root.classList.contains('rp-front-commercial')) return;
  const nonce = root.dataset.nonce;
  const gmKey = root.dataset.gmkey || '';
  const api = '<?php echo esc_url(rest_url('routespro/v1/')); ?>';
  const clients = JSON.parse(root.dataset.clients || '[]');
  const projects = JSON.parse(root.dataset.projects || '[]');
  const initialClientId = String(root.dataset.clientId || '');
  const initialProjectId = String(root.dataset.projectId || '');
  const currentUserId = <?php echo (int) get_current_user_id(); ?>;
  const adminAjax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
  const isPortalContext = !!root.closest('.rp-client-portal');
  const isAppContext = !!root.closest('.rp-app');
  const scope = JSON.parse(root.dataset.scope || '{}');
  let map, markers=[], googleMap, placesSvc, geocoder, googleReady=false, addressAutocomplete=null;
  let categories = [];
  let selected = null;
  let scopeFilters = {districts:[],countiesByDistrict:{},citiesByDistrict:{},categories:[]};
  let autoRunTimer = null;
  let scopeReqId = 0;
  const els = {
    client: root.querySelector('#rpc-client'), project: root.querySelector('#rpc-project'), owner: root.querySelector('#rpc-owner'), role: root.querySelector('#rpc-role'),
    district: root.querySelector('#rpc-district'), county: root.querySelector('#rpc-county'), city: root.querySelector('#rpc-city'),
    category: root.querySelector('#rpc-category'), subcategory: root.querySelector('#rpc-subcategory'), q: root.querySelector('#rpc-q'),
    results: root.querySelector('#rpc-results'), status: root.querySelector('#rpc-status'),
    countInternal: root.querySelector('#rpc-count-internal'), countGoogle: root.querySelector('#rpc-count-google'), countMap: root.querySelector('#rpc-count-map'),
    name: root.querySelector('#rpc-name'), address: root.querySelector('#rpc-address'), phone: root.querySelector('#rpc-phone'), email: root.querySelector('#rpc-email'), contact: root.querySelector('#rpc-contact'), website: root.querySelector('#rpc-website'), lat: root.querySelector('#rpc-lat'), lng: root.querySelector('#rpc-lng'), placeId: root.querySelector('#rpc-place-id')
  };
  function esc(s){ return (s==null?'':String(s)).replace(/[&<>]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }
  function setStatus(msg){ els.status.textContent = msg || ''; }
  async function j(url, opts){ const r=await fetch(url,Object.assign({credentials:'same-origin',headers:{'X-WP-Nonce':nonce}},opts||{})); const t=await r.text(); let d={}; try{d=t?JSON.parse(t):{}}catch(_){d={message:t}} if(!r.ok) throw new Error(d.message||r.statusText); return d; }
  function fillClients(){ if(!els.client) return; const current = String(els.client.value || initialClientId || ''); els.client.innerHTML='<option value="">Cliente</option>'+clients.map(x=>`<option value="${x.id}">${esc(x.name)}</option>`).join(''); if(current) els.client.value=current; }
  function syncProjects(){ if(!els.project) return; const cid=parseInt(els.client?.value||'0',10); const filtered=cid ? projects.filter(x=>parseInt(x.client_id||0,10)===cid) : projects; const preferred = String(els.project.value || initialProjectId || ''); els.project.innerHTML='<option value="">Campanha</option>'+filtered.map(x=>`<option value="${x.id}">${esc(x.name)}</option>`).join(''); if(preferred && Array.from(els.project.options).some(o=>String(o.value)===preferred)) els.project.value=preferred; }
  function allowedClientIds(){ return Array.isArray(scope?.client_ids) ? scope.client_ids.map(v=>String(parseInt(v,10))).filter(Boolean) : []; }
  function allowedProjectIds(){ return Array.isArray(scope?.project_ids) ? scope.project_ids.map(v=>String(parseInt(v,10))).filter(Boolean) : []; }
  function effectiveOwnerId(){ return (isAppContext && !scope?.is_manager) ? String(currentUserId || '') : String(els.owner?.value || ''); }
  function ensureAppOwner(){
    if(!isAppContext || scope?.is_manager || !els.owner) return;
    const forced = String(currentUserId || '');
    if(!forced) return;
    if(!Array.from(els.owner.options).some(o=>String(o.value)===forced)){
      const opt=document.createElement('option'); opt.value=forced; opt.textContent='Meu utilizador'; els.owner.appendChild(opt);
    }
    els.owner.value = forced;
    els.owner.disabled = true;
  }
  function lockAppScope(){
    if(!isAppContext || scope?.is_manager) return;
    const clientIds = allowedClientIds();
    const projectIds = allowedProjectIds();
    if(els.client){
      if(!els.client.value && initialClientId && clientIds.includes(String(initialClientId))) els.client.value=String(initialClientId);
      if(!els.client.value && clientIds.length===1) els.client.value=clientIds[0];
      els.client.disabled = clientIds.length <= 1;
    }
    syncProjects();
    if(els.project){
      if(!els.project.value && initialProjectId && projectIds.includes(String(initialProjectId)) && Array.from(els.project.options).some(o=>String(o.value)===String(initialProjectId))) els.project.value=String(initialProjectId);
      if(!els.project.value && projectIds.length===1 && Array.from(els.project.options).some(o=>String(o.value)===projectIds[0])) els.project.value=projectIds[0];
      els.project.disabled = projectIds.length <= 1 && projectIds.length > 0;
    }
  }
  function appScopeReady(){
    if(!isAppContext || scope?.is_manager) return true;
    return !!(els.client?.value && els.project?.value && effectiveOwnerId());
  }
  async function syncOwnersAndRoles(){
    const currentOwner = String(els.owner?.value || '');
    const currentRole = String(els.role?.value || '');
    const urlUsers = new URL(adminAjax, window.location.origin);
    urlUsers.searchParams.set('action', 'routespro_users');
    if(els.client?.value) urlUsers.searchParams.set('client_id', els.client.value);
    if(els.project?.value) urlUsers.searchParams.set('project_id', els.project.value);
    const urlRoles = new URL(adminAjax, window.location.origin);
    urlRoles.searchParams.set('action', 'routespro_roles');
    if(els.client?.value) urlRoles.searchParams.set('client_id', els.client.value);
    if(els.project?.value) urlRoles.searchParams.set('project_id', els.project.value);
    try {
      const [usersRes, rolesRes] = await Promise.all([
        fetch(urlUsers.toString(), {credentials:'same-origin'}).then(r=>r.json()).catch(()=>[]),
        fetch(urlRoles.toString(), {credentials:'same-origin'}).then(r=>r.json()).catch(()=>[])
      ]);
      const users = Array.isArray(usersRes) ? usersRes : [];
      const roles = Array.isArray(rolesRes) ? rolesRes : [];
      if(els.owner){
        els.owner.innerHTML = '<option value="">Owner operativo</option>' + users.map(u=>`<option value="${parseInt(u.ID||0,10)}">${esc(u.label || u.displayName || u.username || ('User '+(u.ID||'')))}</option>`).join('');
        if(currentOwner && Array.from(els.owner.options).some(o=>String(o.value)===currentOwner)) {
          els.owner.value = currentOwner;
        } else if(isAppContext && users.some(u=>String(parseInt(u.ID||0,10))===String(currentUserId))) {
          els.owner.value = String(currentUserId);
        }
        if(isAppContext && !scope?.is_manager){
          ensureAppOwner();
        } else if(els.owner){
          els.owner.disabled = false;
        }
      }
      if(els.role){
        els.role.innerHTML = '<option value="">Função operacional</option>' + roles.map(r=>`<option value="${esc(r.id||'')}">${esc(r.name||r.id||'')}</option>`).join('');
        if(currentRole && Array.from(els.role.options).some(o=>String(o.value)===currentRole)) els.role.value = currentRole;
      }
    } catch(_){ }
  }
  function syncFromParentScope(){
    const portal = root.closest('.rp-client-portal');
    const app = root.closest('.rp-app');
    const parentClient = portal?.querySelector('#rpcp-client') || app?.querySelector('[data-scope-client]');
    const parentProject = portal?.querySelector('#rpcp-project') || app?.querySelector('[data-scope-project]');
    if(parentClient && els.client && parentClient.value && els.client.value !== parentClient.value){ els.client.value = parentClient.value; }
    if(parentProject && els.project){ syncProjects(); if(parentProject.value && Array.from(els.project.options).some(o=>o.value===parentProject.value)){ els.project.value = parentProject.value; } }
  }
  function parsePlaceComponents(place){ const out={district:'',county:'',city:''}; const comps=Array.isArray(place?.address_components)?place.address_components:[]; comps.forEach(c=>{ const types=Array.isArray(c.types)?c.types:[]; if(types.includes('administrative_area_level_1')) out.district=c.long_name||out.district; if(types.includes('administrative_area_level_2')) out.county=(c.long_name||'').replace(/^Área Metropolitana do /i,'')||out.county; if(types.includes('locality')) out.city=c.long_name||out.city; if(!out.city && types.includes('postal_town')) out.city=c.long_name||out.city; if(!out.city && types.includes('administrative_area_level_3')) out.city=c.long_name||out.city; }); return out; }
  function applyPlaceToForm(place, origin){ if(!place) return; const loc=place.geometry?.location; const meta=parsePlaceComponents(place); const payload={name:(place.name&&place.name!==place.formatted_address)?place.name:els.name.value,address:place.formatted_address||els.address.value,lat:loc?.lat?.(),lng:loc?.lng?.(),place_id:place.place_id||'',website:place.website||'',phone:place.formatted_phone_number||'',contact_person:place.name||els.contact.value||''}; applySelected(payload, origin||'autocomplete'); if(meta.district && !els.district.value){ els.district.value=meta.district; fillDependent(); } else if(meta.district && els.district.value!==meta.district && (scopeFilters?.countiesByDistrict?.[meta.district] || scopeFilters?.citiesByDistrict?.[meta.district])){ els.district.value=meta.district; fillDependent(); }
    if(meta.county && Array.from(els.county.options).some(o=>o.value===meta.county)) els.county.value=meta.county;
    if(meta.city && Array.from(els.city.options).some(o=>o.value===meta.city)) els.city.value=meta.city;
    setStatus('Morada validada com autocomplete. Coordenadas preenchidas automaticamente.'); }
  async function geocodeFreeAddress(){ const raw=(els.address.value||'').trim(); if(!raw || !googleReady || !geocoder) return; return new Promise((resolve)=>{ geocoder.geocode({address: raw + ', Portugal'}, (res,status)=>{ if(status==='OK' && Array.isArray(res) && res[0]){ applyPlaceToForm(res[0],'geocode'); return resolve(res[0]); } resolve(null); }); }); }
  function bindAddressAutocomplete(){
    if(!googleReady || !(window.google && google.maps && google.maps.places && google.maps.places.Autocomplete) || !els.address) return false;
    if(!addressAutocomplete){
      addressAutocomplete = new google.maps.places.Autocomplete(els.address,{componentRestrictions:{country:'pt'}, fields:['formatted_address','geometry','name','place_id','website','formatted_phone_number','address_components']});
      addressAutocomplete.addListener('place_changed',()=>{ const p=addressAutocomplete.getPlace(); if(!p) return; applyPlaceToForm(p,'autocomplete'); });
    }
    if(!els.address.dataset.rpAutocompleteBound){
      els.address.dataset.rpAutocompleteBound='1';
      els.address.addEventListener('focus',()=>{ bindAddressAutocomplete(); });
      els.address.addEventListener('click',()=>{ bindAddressAutocomplete(); });
      els.address.addEventListener('blur',()=>{ setTimeout(()=>{ if((!els.lat.value || !els.lng.value) && els.address.value.trim()){ geocodeFreeAddress().catch(()=>{}); } },180); });
      els.address.addEventListener('keydown',(ev)=>{ if(ev.key==='Enter'){ ev.preventDefault(); geocodeFreeAddress().catch(()=>{}); } });
    }
    return true;
  }
  function setupGoogleHelpers(){
    if(!(window.google && google.maps)) return false;
    googleReady=true;
    if(!geocoder){ geocoder=new google.maps.Geocoder(); }
    if(!(google.maps.places && google.maps.places.PlacesService)){ return false; }
    if(!googleMap){ const dummy=document.createElement('div'); googleMap=new google.maps.Map(dummy,{center:{lat:38.7223,lng:-9.1393},zoom:11}); }
    if(!placesSvc){ placesSvc=new google.maps.places.PlacesService(googleMap); }
    bindAddressAutocomplete();
    if(google.maps.places && google.maps.places.Autocomplete){
      if(els.q && !els.q.dataset.rpAutocomplete){
        const acQ = new google.maps.places.Autocomplete(els.q,{ componentRestrictions:{country:'pt'}, fields:['formatted_address','geometry','name','place_id','address_components']});
        els.q.dataset.rpAutocomplete='1';
        acQ.addListener('place_changed',()=>{ const p=acQ.getPlace(); if(!p) return; const item={name:p.name||'',address:p.formatted_address||'',lat:p.geometry?.location?.lat?.(),lng:p.geometry?.location?.lng?.(),place_id:p.place_id||'',contact_person:p.name||'',source:'google'}; renderList([], [item]); renderMarkers([item]); setStatus('Sugestão Google carregada no mapa.'); });
      }
      if(els.q && !els.q.dataset.rpAutocompleteFocus){
        els.q.dataset.rpAutocompleteFocus='1';
        els.q.addEventListener('focus',()=>{ loadGoogle().catch(()=>{}); });
        els.q.addEventListener('click',()=>{ loadGoogle().catch(()=>{}); });
      }
    }
    return true;
  }
  async function loadGoogle(){
    const hasBaseGoogle = !!(window.google && google.maps);
    const hasPlaces = !!(window.google && google.maps && google.maps.places && google.maps.places.Autocomplete && google.maps.places.PlacesService);
    if(hasPlaces){ setupGoogleHelpers(); return true; }
    if(hasBaseGoogle && google.maps.importLibrary){
      try {
        await google.maps.importLibrary('maps');
        await google.maps.importLibrary('places');
      } catch(_) {}
      const nowHasPlaces = !!(window.google && google.maps && google.maps.places && google.maps.places.Autocomplete && google.maps.places.PlacesService);
      if(nowHasPlaces){ return setupGoogleHelpers(); }
    }
    if(!gmKey){ return false; }
    const promiseKey = hasBaseGoogle ? '__routesProGooglePlacesPromise' : '__routesProGooglePromise';
    if(!window[promiseKey]){
      window[promiseKey] = new Promise((res,rej)=>{
        const callbackName = '__routesProGoogleReady_' + Math.random().toString(36).slice(2);
        window[callbackName] = ()=>{ try{ delete window[callbackName]; }catch(_){ window[callbackName]=undefined; } res(); };
        const existing=document.querySelector('script[data-routespro-google="1"]');
        if(existing){
          const wait=()=>{
            if(window.google && google.maps && google.maps.places){ return res(); }
            setTimeout(wait,180);
          };
          return wait();
        }
        const s=document.createElement('script');
        s.src='https://maps.googleapis.com/maps/api/js?key='+encodeURIComponent(gmKey)+'&libraries=places&loading=async&callback='+callbackName;
        s.async=true; s.defer=true; s.dataset.routesproGoogle='1';
        s.onerror=(err)=>{ try{ delete window[callbackName]; }catch(_){ window[callbackName]=undefined; } rej(err); };
        document.head.appendChild(s);
      });
    }
    try { await window[promiseKey]; } catch(_) { return false; }
    return setupGoogleHelpers();
  }
  function ensureGoogleMap(){ const mapEl=root.querySelector('#rpc-map'); if(!mapEl || !(window.google && google.maps)) return; const visible = mapEl.offsetWidth > 40 && mapEl.offsetHeight > 40; if(!visible){ setTimeout(ensureGoogleMap, 180); return; } if(!map){ map=new google.maps.Map(mapEl,{center:{lat:38.7223,lng:-9.1393},zoom:7,mapTypeId:google.maps.MapTypeId.ROADMAP,gestureHandling:'greedy',streetViewControl:false,fullscreenControl:true,mapTypeControl:false}); }
    setTimeout(()=>{ try{ google.maps.event.trigger(map,'resize'); }catch(_){ } },80); }
  function applyOptions(selectEl, placeholder, items, currentValue){
    if(!selectEl) return;
    const wanted = String(currentValue ?? selectEl.value ?? '');
    selectEl.innerHTML = `<option value="">${placeholder}</option>` + items.map(item=>`<option value="${esc(item.value)}">${esc(item.label)}</option>`).join('');
    if(wanted && Array.from(selectEl.options).some(o=>String(o.value)===wanted)) selectEl.value = wanted;
  }
  function buildFilterScopeParams(){
    const params = new URLSearchParams();
    if(els.client?.value) params.set('client_id', els.client.value);
    if(els.project?.value) params.set('project_id', els.project.value);
    const activeOwnerId = effectiveOwnerId(); if(activeOwnerId){ params.set('owner_user_id', activeOwnerId); }
    if(!isAppContext && els.role?.value){ params.set('role', String(els.role.value)); }
    if(els.district?.value) params.set('district', els.district.value);
    if(els.county?.value) params.set('county', els.county.value);
    if(els.city?.value) params.set('city', els.city.value);
    if(els.category?.value) params.set('category_id', els.category.value);
    if(els.subcategory?.value) params.set('subcategory_id', els.subcategory.value);
    return params;
  }
  async function loadGeo(force){
    const reqId = ++scopeReqId;
    const params = buildFilterScopeParams();
    const data = await j(api+'commercial-filters?'+params.toString());
    if(reqId !== scopeReqId) return;
    scopeFilters = {
      districts: Array.isArray(data.districts) ? data.districts : [],
      countiesByDistrict: data.countiesByDistrict || {},
      citiesByDistrict: data.citiesByDistrict || {},
      categories: Array.isArray(data.categories) ? data.categories : []
    };
    categories = scopeFilters.categories.slice();
    applyOptions(els.district, 'Distrito', scopeFilters.districts.map(x=>({value:x,label:x})), els.district.value);
    fillDependent();
    fillCategories();
  }
  function fillDependent(){
    const d=els.district.value||'';
    const currentCounty = els.county.value || '';
    const currentCity = els.city.value || '';
    const counties=(scopeFilters?.countiesByDistrict?.[d]||[]);
    const cities=(scopeFilters?.citiesByDistrict?.[d]||[]);
    applyOptions(els.county,'Concelho',counties.map(x=>({value:x,label:x})),currentCounty);
    applyOptions(els.city,'Cidade',cities.map(x=>({value:x,label:x})),currentCity);
  }
  function fillCategories(){
    const seen={};
    const roots=categories.filter(x=>!parseInt(x.parent_id||0,10)).filter(x=>{ const k=(x.slug||x.name||'').toString().trim().toLowerCase(); if(!k || seen[k]) return false; seen[k]=1; return true; });
    applyOptions(els.category,'Categoria',roots.map(x=>({value:x.id,label:x.name})),els.category.value);
    fillSubcategories();
  }
  function fillSubcategories(){
    const pid=parseInt(els.category.value||'0',10);
    const seen={};
    const subs=categories.filter(x=>parseInt(x.parent_id||0,10)===pid).filter(x=>{ const k=(x.name||'').trim().toLowerCase(); if(seen[k]) return false; seen[k]=1; return true; });
    applyOptions(els.subcategory,'Subcategoria',subs.map(x=>({value:x.id,label:x.name})),els.subcategory.value);
  }
  async function runInternalAndRender(message){
    if(!appScopeReady()){
      els.results.innerHTML = '<div class="rp-item">Seleciona cliente e campanha válidos para carregar a Base.</div>';
      els.countInternal.textContent='0';
      els.countGoogle.textContent='0';
      els.countMap.textContent='0';
      setStatus('Base bloqueada ao teu cliente, campanha e owner operativo.');
      return [];
    }
    setStatus(message || 'A carregar PDVs internos...');
    const internal = await runInternal();
    renderList(internal, []);
    renderMarkers(internal);
    setStatus('Pesquisa interna concluída.');
    return internal;
  }
  function scheduleInternalRun(delay){
    clearTimeout(autoRunTimer);
    autoRunTimer = setTimeout(()=>{ runInternalAndRender('A atualizar filtros da Base...').catch(err=>setStatus(err.message||'Falha na atualização da Base.')); }, typeof delay === 'number' ? delay : 220);
  }
  async function refreshFilterOptionsAndResults(message, delay){
    try{
      await loadGeo(true);
      if(!appScopeReady()){
        els.results.innerHTML = '<div class="rp-item">Seleciona cliente e campanha válidos para carregar a Base.</div>';
        els.countInternal.textContent='0';
        els.countGoogle.textContent='0';
        els.countMap.textContent='0';
        setStatus('Base bloqueada ao teu cliente, campanha e owner operativo.');
        return;
      }
      if(typeof delay === 'number') scheduleInternalRun(delay);
      else await runInternalAndRender(message || 'A atualizar filtros da Base...');
    }catch(err){
      setStatus(err?.message || 'Falha na atualização da Base.');
    }
  }
  function markerColor(src){ return src==='google' ? '#dc2626' : '#2563eb'; }
  function renderMarkers(items){ if(!map || !(window.google && google.maps)) return; markers.forEach(m=>m.setMap(null)); markers=[]; const bounds=[]; items.forEach(item=>{ const lat=parseFloat(item.lat), lng=parseFloat(item.lng); if(!Number.isFinite(lat)||!Number.isFinite(lng)) return; const marker=new google.maps.Marker({map, position:{lat,lng}, title:item.name||item.address||'PDV'}); marker.addListener('click',()=>applySelected(item,'map')); markers.push(marker); bounds.push({lat,lng}); }); els.countMap.textContent=String(bounds.length); if(!bounds.length){ map.setCenter({lat:38.7223,lng:-9.1393}); map.setZoom(7); return; } if(bounds.length===1){ map.setCenter(bounds[0]); map.setZoom(14); return; } const gb=new google.maps.LatLngBounds(); bounds.forEach(pt=>gb.extend(pt)); map.fitBounds(gb,30); }
  function applySelected(item, origin){ selected=item||null; els.name.value=item?.name||''; els.address.value=item?.address||''; els.phone.value=item?.phone||''; els.email.value=item?.email||''; els.contact.value=item?.contact_person||item?.name||''; els.website.value=item?.website||''; els.lat.value=(item?.lat ?? '')!==''?String(item.lat):''; els.lng.value=(item?.lng ?? '')!==''?String(item.lng):''; els.placeId.value=item?.place_id||''; setStatus((origin==='map'?'PDV selecionado no mapa.':'PDV carregado.') + ' Podes guardar ou ajustar antes de submeter.'); }
  function renderList(internal, googleItems){ const all=[]; if(internal.length){ all.push('<div class="rp-item" style="background:#eff6ff;font-weight:700">PDVs existentes</div>'); internal.forEach(item=> all.push(`<div class="rp-item"><h4>${esc(item.name||'PDV')}</h4><div class="rp-meta">${esc(item.address||'')}</div><div class="rp-meta">${esc(item.phone||'')} ${item.city?(' · '+esc(item.city)):''}</div><div style="margin-top:10px"><button class="rp-btn ghost" data-kind="internal" data-id="${item.id||''}">Usar</button></div></div>`)); }
    if(googleItems.length){ all.push('<div class="rp-item" style="background:#fef2f2;font-weight:700">Novos PDVs Google</div>'); googleItems.forEach((item,idx)=> all.push(`<div class="rp-item"><h4>${esc(item.name||'PDV')}</h4><div class="rp-meta">${esc(item.address||'')}</div><div style="margin-top:10px"><button class="rp-btn ghost" data-kind="google" data-idx="${idx}">Usar</button></div></div>`)); }
    els.results.innerHTML=all.join('') || '<div class="rp-item">Sem resultados.</div>';
    els.results.querySelectorAll('button[data-kind="internal"]').forEach(btn=>btn.addEventListener('click',()=>{ const id=parseInt(btn.dataset.id,10); const item=internal.find(x=>parseInt(x.id,10)===id); if(item) applySelected(item,'list'); }));
    els.results.querySelectorAll('button[data-kind="google"]').forEach(btn=>btn.addEventListener('click',()=>{ const item=googleItems[parseInt(btn.dataset.idx,10)]; if(item) applySelected(item,'list'); }));
  }
  function buildParams(){ const p=new URLSearchParams(); if(els.client?.value) p.set('client_id',els.client.value); if(els.project?.value) p.set('project_id',els.project.value); const ownerId = effectiveOwnerId(); if(ownerId) p.set('owner_user_id', ownerId); if(!isAppContext && els.role?.value) p.set('role', String(els.role.value)); if(els.district.value) p.set('district',els.district.value); if(els.county.value) p.set('county',els.county.value); if(els.city.value) p.set('city',els.city.value); if(els.category.value) p.set('category_id',els.category.value); if(els.subcategory.value) p.set('subcategory_id',els.subcategory.value); if(els.q.value) p.set('q',els.q.value); p.set('per_page','200'); return p; }
  async function fetchCommercialPages(params, fetchAll){ const merged=[]; let page=1, total=0; const wantsAll=!!fetchAll; while(true){ const req=new URLSearchParams(params.toString()); req.set('page', String(page)); const data=await j(api+'commercial-search?'+req.toString()); const items=Array.isArray(data.items)?data.items:[]; total=parseInt(data.total||items.length||0,10)||0; merged.push(...items); if(!wantsAll) break; if(!items.length || merged.length>=total || page>=50) break; page++; } return {items:merged,total}; }
  async function runInternal(){ const params=buildParams(); const fetchAll=isPortalContext || !!(els.project?.value); const data=await fetchCommercialPages(params, fetchAll); const items=Array.isArray(data.items)?data.items:[]; els.countInternal.textContent=String(data.total||items.length); return items; }
  function geocodeArea(){ return new Promise((resolve)=>{ if(!googleReady || !geocoder) return resolve(null); const pieces=[els.city.value,els.county.value,els.district.value,'Portugal'].filter(Boolean); if(!pieces.length) return resolve(null); geocoder.geocode({address:pieces.join(', ')}, (res,status)=>{ if(status==='OK' && res && res[0]){ const g=res[0].geometry.location; resolve({lat:g.lat(),lng:g.lng()}); } else resolve(null); }); }); }
  function findPlaceDetails(placeId){ return new Promise((resolve)=>{ if(!placesSvc || !placeId) return resolve({}); placesSvc.getDetails({placeId, fields:['name','formatted_address','formatted_phone_number','website','geometry','place_id']}, (res,status)=>{ if(status===google.maps.places.PlacesServiceStatus.OK && res){ resolve({phone:res.formatted_phone_number||'',website:res.website||'',lat:res.geometry?.location?.lat?.(),lng:res.geometry?.location?.lng?.()}); } else resolve({}); }); }); }
  async function runGoogle(){ if(!googleReady || !placesSvc){ els.countGoogle.textContent='0'; return []; } const center=await geocodeArea(); const keyword=[els.subcategory.options[els.subcategory.selectedIndex]?.text||'', els.category.options[els.category.selectedIndex]?.text||'', els.q.value||''].filter(Boolean).join(' ').trim() || 'estabelecimento comercial'; return new Promise((resolve)=>{ const req={query:[keyword, els.city.value, els.county.value, els.district.value, 'Portugal'].filter(Boolean).join(', '), language:'pt-PT'}; if(center){ req.location=new google.maps.LatLng(center.lat,center.lng); req.radius=els.city.value?12000:(els.county.value?25000:50000); } placesSvc.textSearch(req, async (results,status)=>{ if(status!==google.maps.places.PlacesServiceStatus.OK || !Array.isArray(results)){ els.countGoogle.textContent='0'; return resolve([]); } const items=[]; for(const r of results.slice(0,20)){ const extra=await findPlaceDetails(r.place_id); items.push({name:r.name||'',address:r.formatted_address||r.vicinity||'',lat:extra.lat ?? r.geometry?.location?.lat?.(),lng:extra.lng ?? r.geometry?.location?.lng?.(),place_id:r.place_id||'',phone:extra.phone||'',website:extra.website||'',source:'google'}); } els.countGoogle.textContent=String(items.length); resolve(items); }); }); }
  root.querySelector('#rpc-run').addEventListener('click', async ()=>{ try{ await runInternalAndRender('A carregar PDVs internos...'); }catch(err){ setStatus(err.message||'Falha na pesquisa interna.'); }});
  root.querySelector('#rpc-google').addEventListener('click', async ()=>{ try{ setStatus('A combinar BD interna e Google...'); const internal=await runInternal(); const googleItems=await runGoogle(); const merged=[...internal, ...googleItems]; renderList(internal,googleItems); renderMarkers(merged); setStatus('Pesquisa concluída.'); }catch(err){ setStatus(err.message||'Falha na pesquisa Google.'); }});
  root.querySelector('#rpc-save').addEventListener('click', async ()=>{ try{ if((!els.lat.value || !els.lng.value) && els.address.value.trim()){ setStatus('A validar morada antes de guardar...'); await geocodeFreeAddress(); } const payload={name:els.name.value,address:els.address.value,phone:els.phone.value,email:els.email.value,contact_person:els.contact.value,website:els.website.value,lat:els.lat.value,lng:els.lng.value,place_id:els.placeId.value,client_id:parseInt(els.client?.value||'0',10)||null,project_id:parseInt(els.project?.value||'0',10)||null,category_id:parseInt(els.category.value||'0',10)||null,subcategory_id:parseInt(els.subcategory.value||'0',10)||null,district:els.district.value,county:els.county.value,city:els.city.value,source:selected?.source||((els.placeId.value||els.lat.value||els.lng.value)?'google':'manual'),location_type:'pdv',replace_existing:1}; const res=await j(api+'locations',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify(payload)}); await loadGeo(true); scheduleInternalRun(80); setStatus(res.existing?'PDV existente atualizado com o último save.':'PDV guardado com sucesso.'); }catch(err){ setStatus(err.message||'Falha ao guardar PDV.'); }});
  els.client?.addEventListener('change', async ()=>{ syncProjects(); await syncOwnersAndRoles().catch(()=>{}); await refreshFilterOptionsAndResults('A atualizar filtros da Base...', 60); });
  els.project?.addEventListener('change', async ()=>{ await syncOwnersAndRoles().catch(()=>{}); await refreshFilterOptionsAndResults('A atualizar filtros da Base...', 60); });
  els.owner?.addEventListener('change', async ()=>{ await refreshFilterOptionsAndResults('A atualizar filtros da Base...', 60); });
  els.role?.addEventListener('change', async ()=>{ await refreshFilterOptionsAndResults('A atualizar filtros da Base...', 60); });
  els.district.addEventListener('change', async ()=>{ fillDependent(); await refreshFilterOptionsAndResults('A atualizar filtros da Base...', 80); });
  els.county.addEventListener('change', async ()=>{ await refreshFilterOptionsAndResults('A atualizar filtros da Base...', 80); });
  els.city.addEventListener('change', async ()=>{ await refreshFilterOptionsAndResults('A atualizar filtros da Base...', 80); });
  els.category.addEventListener('change', async ()=>{ fillSubcategories(); await refreshFilterOptionsAndResults('A atualizar filtros da Base...', 80); });
  els.subcategory.addEventListener('change', async ()=>{ await refreshFilterOptionsAndResults('A atualizar filtros da Base...', 80); });
  els.q.addEventListener('input', ()=>{ scheduleInternalRun(260); });
  if(isAppContext && els.role){ els.role.value=''; els.role.disabled=true; els.role.setAttribute('aria-hidden','true'); }
  fillClients(); syncProjects(); syncFromParentScope(); lockAppScope(); await syncOwnersAndRoles().catch(()=>{}); ensureAppOwner(); await loadGeo(); await loadGoogle().catch(()=>{}); bindAddressAutocomplete(); if(els.q){ els.q.dispatchEvent(new Event('focus')); } ensureGoogleMap(); await runInternalAndRender('A carregar Base Comercial...'); root.addEventListener('routespro:scope-change', async (ev)=>{ const d=ev.detail||{}; if(els.client && d.client_id!==undefined){ els.client.value=String(d.client_id||''); syncProjects(); } if(els.project && d.project_id!==undefined && Array.from(els.project.options).some(o=>o.value===String(d.project_id||''))){ els.project.value=String(d.project_id||''); } lockAppScope(); await syncOwnersAndRoles().catch(()=>{}); await loadGeo(true); scheduleInternalRun(40); }); root.addEventListener('routespro:panel-open', ()=>{ syncFromParentScope(); lockAppScope(); syncOwnersAndRoles().then(()=>{ ensureAppOwner(); }).catch(()=>{}); ensureGoogleMap(); bindAddressAutocomplete(); loadGoogle().catch(()=>{}); setTimeout(()=>{ ensureGoogleMap(); bindAddressAutocomplete(); loadGoogle().catch(()=>{}); scheduleInternalRun(40); },220); }); window.addEventListener('resize', ()=>{ ensureGoogleMap(); if(map && window.google && google.maps && google.maps.event){ google.maps.event.trigger(map,'resize'); } });
  if(window.MutationObserver){
    const mo=new MutationObserver(()=>{
      if(root.offsetParent!==null){
        ensureGoogleMap();
        bindAddressAutocomplete();
        if(map && window.google && google.maps && google.maps.event){ google.maps.event.trigger(map,'resize'); }
      }
    });
    mo.observe(document.body,{attributes:true,childList:true,subtree:true});
  }
})();
</script>
<?php
        return ob_get_clean();
    }

    public static function front_routes($atts = []) {
        $guard = self::front_guard();
        if ($guard) return $guard;
        $theme = self::front_theme();
        $opts = \RoutesPro\Admin\Settings::get();
        $gmKey = trim((string)($opts['google_maps_key'] ?? ''));
        $nonce = wp_create_nonce('wp_rest');
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $clients = \RoutesPro\Support\Permissions::filter_clients($wpdb->get_results("SELECT id,name FROM {$px}clients ORDER BY name ASC", ARRAY_A) ?: []);
        $projects = \RoutesPro\Support\Permissions::filter_projects($wpdb->get_results("SELECT id,name,client_id FROM {$px}projects ORDER BY name ASC", ARRAY_A) ?: []);
        $scope = \RoutesPro\Support\Permissions::get_scope();
        ob_start(); ?>
<style>
.rp-front-routes{--rp-primary:<?php echo esc_attr($theme['primary']); ?>;--rp-accent:<?php echo esc_attr($theme['accent']); ?>;font-family:<?php echo esc_attr($theme['font']); ?>;font-size:<?php echo esc_attr($theme['size']); ?>px;color:#0f172a}
.rp-front-routes .rp-card{background:#fff;border:1px solid #e2e8f0;border-radius:24px;box-shadow:0 18px 48px rgba(15,23,42,.08);padding:18px}
.rp-front-routes .rp-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(340px,.8fr);gap:16px}
.rp-front-routes .rp-form,.rp-front-routes .rp-toolbar{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.rp-front-routes .rp-toolbar{grid-template-columns:repeat(4,minmax(0,1fr));margin-top:10px}
.rp-front-routes .rp-full{grid-column:1/-1}
.rp-front-routes input,.rp-front-routes select{border:1px solid #cbd5e1;border-radius:14px;padding:11px 12px;width:100%;min-width:0;background:#fff}
.rp-front-routes .rp-btn{border:0;border-radius:14px;padding:12px 14px;background:var(--rp-primary);color:#fff;font-weight:700;cursor:pointer}
.rp-front-routes .rp-btn.alt{background:var(--rp-accent)}
.rp-front-routes .rp-btn.ghost{background:#fff;color:#0f172a;border:1px solid #cbd5e1}
.rp-front-routes .rp-map{height:430px;min-height:430px;border-radius:20px;overflow:hidden;border:1px solid #dbeafe;background:#e2e8f0;position:relative;z-index:1}
.rp-front-routes .pac-container{z-index:99999 !important;border-radius:14px;box-shadow:0 18px 40px rgba(15,23,42,.18)}
.rp-front-routes .rp-list,.rp-front-routes .rp-queue{max-height:460px;overflow:auto;margin-top:12px;padding-right:4px}
.rp-front-routes .rp-item,.rp-front-routes .rp-qitem{border:1px solid #e2e8f0;border-radius:18px;padding:14px;background:#fff;margin-bottom:10px}
.rp-front-routes .rp-item h4,.rp-front-routes .rp-qitem h4{margin:0 0 6px;font-size:18px;line-height:1.15;color:#0f172a}
.rp-front-routes .rp-meta{color:#64748b;font-size:13px;line-height:1.45}
.rp-front-routes .rp-qitem{display:grid;grid-template-columns:42px 1fr auto;gap:12px;align-items:center}
.rp-front-routes .rp-no{width:38px;height:38px;border-radius:999px;background:#111827;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700}
.rp-front-routes .rp-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:12px 0}
.rp-front-routes .rp-summary div{background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:14px}
.rp-front-routes .rp-summary strong{display:block;font-size:24px;color:#0f172a}
.rp-front-routes .rp-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-weight:700;font-size:12px;margin-bottom:8px}
.rp-front-routes .rp-section-title{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.rp-front-routes .rp-note{margin-top:12px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:16px;padding:12px;color:#475569}
.rp-front-routes .rp-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
@media (max-width:1080px){.rp-front-routes .rp-grid,.rp-front-routes .rp-form,.rp-front-routes .rp-toolbar,.rp-front-routes .rp-summary{grid-template-columns:1fr}.rp-front-routes .rp-map{height:360px;min-height:360px}}
</style>
<div class="rp-front-routes" data-nonce="<?php echo esc_attr($nonce); ?>" data-gmkey="<?php echo esc_attr($gmKey); ?>" data-can-manage="<?php echo \RoutesPro\Support\Permissions::can_access_front() ? '1' : '0'; ?>" data-projects='<?php echo esc_attr(wp_json_encode($projects)); ?>' data-client-id="<?php echo esc_attr($initial_client_id); ?>" data-project-id="<?php echo esc_attr($initial_project_id); ?>" data-scope='<?php echo esc_attr(wp_json_encode($scope)); ?>' data-geopt='<?php echo esc_attr(wp_json_encode([
            'districts' => \RoutesPro\Support\GeoPT::districts(),
            'countiesByDistrict' => \RoutesPro\Support\GeoPT::counties_by_district(),
            'citiesByDistrict' => \RoutesPro\Support\GeoPT::cities_by_district(),
        ])); ?>'>
  <div class="rp-card" style="margin-bottom:16px">
    <div class="rp-section-title">
      <div>
        <div class="rp-chip">Rotas Front</div>
        <h3 style="margin:0 0 6px;color:#0f172a">Descobrir PDVs e construir rota</h3>
        <div class="rp-meta">Filtra por campanha, geografia, categoria e subcategoria, mistura base interna com Google, adiciona à fila e associa a rota à campanha.</div>
      </div>
    </div>
      <div class="rp-form" style="margin-top:14px">
        <select id="rpf-client" class="rp-full"><option value="">Cliente</option><?php foreach($clients as $c): ?><option value="<?php echo intval($c['id']); ?>"><?php echo esc_html($c['name']); ?></option><?php endforeach; ?></select>
        <select id="rpf-project"><option value="">Campanha</option></select>
        <input type="date" id="rpf-date" value="<?php echo esc_attr(date('Y-m-d')); ?>">
        <select id="rpf-route-status"><option value="planned">planned</option><option value="draft">draft</option><option value="in_progress">in_progress</option></select>
      </div>
    <div class="rp-toolbar" style="margin-top:12px">
      <select id="rpf-district"><option value="">Distrito</option></select>
      <select id="rpf-county"><option value="">Concelho</option></select>
      <select id="rpf-city"><option value="">Cidade</option></select>
      <select id="rpf-category"><option value="">Categoria</option></select>
      <select id="rpf-subcategory"><option value="">Subcategoria</option></select>
      <input type="search" id="rpf-q" placeholder="Nome, morada ou contacto" autocomplete="off">
      <button type="button" class="rp-btn" id="rpf-search">PDVs existentes</button>
      <button type="button" class="rp-btn alt" id="rpf-google">Google + Internos</button>
    </div>
    <div class="rp-actions">
      <button type="button" class="rp-btn ghost" id="rpf-openmaps">Abrir no Google Maps</button>
      <button type="button" class="rp-btn ghost" id="rpf-recalc">Recalcular</button>
      <button type="button" class="rp-btn ghost" id="rpf-clear">Limpar</button>
<button type="button" class="rp-btn" id="rpf-save-route">Guardar rota</button>
    </div>
  </div>
  <div class="rp-grid">
    <div class="rp-card">
      <div id="rpf-map" class="rp-map"></div>
      <div class="rp-list" id="rpf-results"></div>
    </div>
    <div class="rp-card">
      <h4 style="margin:0;color:#0f172a">PDVs adicionados à rota</h4>
      <div class="rp-summary">
        <div><span>Paragens</span><strong id="rpf-total">0</strong></div>
        <div><span>Kms</span><strong id="rpf-km">0</strong></div>
        <div><span>Viagem</span><strong id="rpf-travel">0m</strong></div>
        <div><span>Total</span><strong id="rpf-total-time">0m</strong></div>
      </div>
      <div class="rp-queue" id="rpf-queue"></div>
      <p class="rp-note" id="rpf-status-msg">Adiciona PDVs da lista para construir a rota. Podes misturar resultados internos e Google sem duplicar paragens.</p>
    </div>
  </div>
</div>
<script>
(async function(){
  const root=document.currentScript.previousElementSibling; if(!root || !root.classList.contains('rp-front-routes')) return;
  const nonce=root.dataset.nonce, gmKey=root.dataset.gmkey||'', canManage=root.dataset.canManage==='1';
  const api='<?php echo esc_url(rest_url('routespro/v1/')); ?>';
  const projects=JSON.parse(root.dataset.projects||'[]');
  const initialClientId = String(root.dataset.clientId || '');
  const initialProjectId = String(root.dataset.projectId || '');
  const scope=JSON.parse(root.dataset.scope||'{}');
  const geoPT=JSON.parse(root.dataset.geopt||'{}');
  const currentUserId = <?php echo (int) get_current_user_id(); ?>;
  const isAppContext = !!root.closest('.rp-app');
  let map, markers=[], routeLine=null, googleReady=false, googleMap=null, placesSvc=null, geocoder=null, directionsService=null, infoWindow=null, routeRenderer=null, mapObserver=null;
  let queue=[]; let resultItems=[]; let categories=[];
  const els={ q:root.querySelector('#rpf-q'), results:root.querySelector('#rpf-results'), queue:root.querySelector('#rpf-queue'), total:root.querySelector('#rpf-total'), km:root.querySelector('#rpf-km'), travel:root.querySelector('#rpf-travel'), totalTime:root.querySelector('#rpf-total-time'), status:root.querySelector('#rpf-status-msg'), client:root.querySelector('#rpf-client'), project:root.querySelector('#rpf-project'), date:root.querySelector('#rpf-date'), routeStatus:root.querySelector('#rpf-route-status'), district:root.querySelector('#rpf-district'), county:root.querySelector('#rpf-county'), city:root.querySelector('#rpf-city'), category:root.querySelector('#rpf-category'), subcategory:root.querySelector('#rpf-subcategory') };
  function esc(s){ return (s==null?'':String(s)).replace(/[&<>]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }
  function setStatus(msg){ if(els.status) els.status.textContent=msg||''; }
  function setLocked(el, locked){ if(!el) return; el.disabled=!!locked; el.classList.toggle('ff-locked', !!locked); }
  function lockIfSingle(el){ if(!el) return false; const opts=Array.from(el.options||[]).filter(o=>String(o.value||'')!==''); const one=opts.length===1; if(one) el.value=String(opts[0].value||''); setLocked(el, one); return one; }
  function enforcePortalScopeLocks(){ lockIfSingle(els.client); syncProjects(); lockIfSingle(els.project); }
  function lockCommercialPanel(){ const panel=root.querySelector('#rpcp-commercial-panel .rp-front-commercial'); if(!panel) return; const c=panel.querySelector('#rpc-client'); const p=panel.querySelector('#rpc-project'); const o=panel.querySelector('#rpc-owner'); const r=panel.querySelector('#rpc-role'); if(r){ const wrap=r.closest('label,div')||r; wrap.style.display='none'; }
    const apply=()=>{ if(c){ lockIfSingle(c); }
      if(p){ lockIfSingle(p); }
      if(o){ lockIfSingle(o); }
    };
    setTimeout(apply,50); setTimeout(apply,400); setTimeout(apply,1200);
  }
  async function j(url, opts){ const r=await fetch(url,Object.assign({credentials:'same-origin',headers:{'X-WP-Nonce':nonce}},opts||{})); const t=await r.text(); let d={}; try{d=t?JSON.parse(t):{}}catch(_){d={message:t}} if(!r.ok) throw new Error(d.message||r.statusText); return d; }
  function allowedClientIds(){ return Array.isArray(scope?.client_ids) ? scope.client_ids.map(v=>String(parseInt(v,10))).filter(Boolean) : []; }
  function allowedProjectIds(){ return Array.isArray(scope?.project_ids) ? scope.project_ids.map(v=>String(parseInt(v,10))).filter(Boolean) : []; }
  function setLockedField(el, locked){ if(!el) return; el.disabled = !!locked; el.classList.toggle('ff-locked', !!locked); }
  function maybeLockSingleSelect(el){ if(!el) return false; const choices = Array.from(el.options||[]).filter(o=>String(o.value||'')!==''); const shouldLock = choices.length === 1; if(shouldLock){ el.value = String(choices[0].value||''); } setLockedField(el, shouldLock); return shouldLock; }
  function syncProjects(selected){ const cid=parseInt(els.client?.value||'0',10); const filtered=cid ? projects.filter(x=>parseInt(x.client_id||0,10)===cid) : projects; const wanted=String(selected||els.project?.value||''); els.project.innerHTML='<option value="">Campanha</option>'+filtered.map(x=>`<option value="${x.id}">${esc(x.name)}</option>`).join(''); if(wanted && Array.from(els.project.options).some(o=>String(o.value)===wanted)) els.project.value=wanted; maybeLockSingleSelect(els.project); }
  function lockRestrictedScope(){
    const clientIds=allowedClientIds();
    const projectIds=allowedProjectIds();
    if(!scope?.is_manager){
      if(els.client && !els.client.value && clientIds.length===1) els.client.value=clientIds[0];
      syncProjects(projectIds.length===1 ? projectIds[0] : '');
      if(els.project && !els.project.value && projectIds.length===1 && Array.from(els.project.options).some(o=>String(o.value)===projectIds[0])) els.project.value=projectIds[0];
      if(els.client) setLockedField(els.client, clientIds.length <= 1 && clientIds.length > 0);
      if(els.project) setLockedField(els.project, projectIds.length <= 1 && projectIds.length > 0);
      return;
    }
    maybeLockSingleSelect(els.client);
    syncProjects('');
    maybeLockSingleSelect(els.project);
  }
  function discoveryScopeReady(){ return scope?.is_manager || (!!els.client?.value && !!els.project?.value); }
  function effectiveDiscoveryOwnerId(){ return (isAppContext && !scope?.is_manager) ? String(currentUserId || '') : ''; }
  function updateDiscoverySearchPlaceholder(){ if(!els.q) return; const projectLabel = els.project && els.project.value ? (els.project.options[els.project.selectedIndex]?.text || 'campanha ativa') : 'campanha selecionada'; els.q.placeholder = 'Nome, morada ou contacto' + (els.project && els.project.value ? ' em ' + projectLabel : ''); }
  function bindDiscoveryAutocomplete(){ if(!(window.google && google.maps && google.maps.places && google.maps.places.Autocomplete) || !els.q) return false; if(els.q.dataset.rpAutocomplete) return true; updateDiscoverySearchPlaceholder(); const acQ = new google.maps.places.Autocomplete(els.q,{componentRestrictions:{country:'pt'}, fields:['formatted_address','geometry','name','place_id','address_components']}); els.q.dataset.rpAutocomplete='1'; acQ.addListener('place_changed',()=>{ const p=acQ.getPlace(); if(!p) return; const item={name:p.name||'',address:p.formatted_address||'',lat:p.geometry?.location?.lat?.(),lng:p.geometry?.location?.lng?.(),place_id:p.place_id||'',contact_person:p.name||'',source:'google',client_id:parseInt(els.client?.value||'0',10)||null,project_id:parseInt(els.project?.value||'0',10)||null}; renderResults([item]); renderDiscoveryMarkers([item]); setStatus('Sugestão Google carregada no contexto atual.'); }); if(!els.q.dataset.rpAutocompleteFocus){ els.q.dataset.rpAutocompleteFocus='1'; els.q.addEventListener('focus',()=>{ loadGoogle().catch(()=>{}); bindDiscoveryAutocomplete(); }); els.q.addEventListener('click',()=>{ loadGoogle().catch(()=>{}); }); } return true; }
  function setupGoogleHelpers(){ if(!(window.google && google.maps)) return false; googleReady=true; if(!googleMap){ const dummy=document.createElement('div'); googleMap=new google.maps.Map(dummy,{center:{lat:38.7223,lng:-9.1393},zoom:11}); } if(!placesSvc && google.maps.places && google.maps.places.PlacesService){ placesSvc=new google.maps.places.PlacesService(googleMap); } if(!geocoder){ geocoder=new google.maps.Geocoder(); } if(!directionsService){ directionsService=new google.maps.DirectionsService(); } bindDiscoveryAutocomplete(); return !!placesSvc; }
  async function loadGoogle(){ const hasPlaces = !!(window.google && google.maps && google.maps.places && google.maps.places.Autocomplete && google.maps.places.PlacesService); if(hasPlaces){ setupGoogleHelpers(); return true; } if(window.google && google.maps && google.maps.importLibrary){ try { await google.maps.importLibrary('maps'); await google.maps.importLibrary('places'); } catch(_) {} if(window.google && google.maps && google.maps.places){ return setupGoogleHelpers(); } } if(!gmKey) return false; if(!window.__routesProGooglePromise){ window.__routesProGooglePromise = new Promise((res,rej)=>{ const callbackName='__routesProGoogleReady_'+Math.random().toString(36).slice(2); window[callbackName]=()=>{ try{ delete window[callbackName]; }catch(_){ window[callbackName]=undefined; } res(); }; const existing=document.querySelector('script[data-routespro-google="1"]'); if(existing){ const wait=()=>{ if(window.google && google.maps && google.maps.places){ return res(); } setTimeout(wait,180); }; return wait(); } const s=document.createElement('script'); s.src='https://maps.googleapis.com/maps/api/js?key='+encodeURIComponent(gmKey)+'&libraries=places&loading=async&callback='+callbackName; s.async=true; s.defer=true; s.dataset.routesproGoogle='1'; s.onerror=(err)=>{ try{ delete window[callbackName]; }catch(_){ window[callbackName]=undefined; } rej(err); }; document.head.appendChild(s); }); } try{ await window.__routesProGooglePromise; }catch(_){ return false; } return setupGoogleHelpers(); }
  function fixMapLayout(){ if(!map || !(window.google && google.maps)) return; setTimeout(()=>{ try{ google.maps.event.trigger(map,'resize'); }catch(_){ } }, 80); setTimeout(()=>{ try{ google.maps.event.trigger(map,'resize'); }catch(_){ } }, 320); setTimeout(()=>{ try{ google.maps.event.trigger(map,'resize'); }catch(_){ } }, 900); }
  function initMap(){ const mapEl=root.querySelector('#rpf-map'); if(!mapEl || !(window.google && google.maps)) return; mapEl.style.height = '430px'; mapEl.style.minHeight = '430px'; const visible = mapEl.offsetParent !== null && mapEl.getBoundingClientRect().width > 120; if(!visible){ setTimeout(initMap, 260); return; } if(!map){ map=new google.maps.Map(mapEl,{center:{lat:38.7223,lng:-9.1393},zoom:7,mapTypeId:google.maps.MapTypeId.ROADMAP,gestureHandling:'greedy',streetViewControl:false,fullscreenControl:true,mapTypeControl:false}); infoWindow = new google.maps.InfoWindow(); } fixMapLayout(); }
  function clearMarkers(){ markers.forEach(m=>m.setMap(null)); markers=[]; }
  function fitMarkerBounds(points){ if(!map) return; if(!points.length){ map.setCenter({lat:38.7223,lng:-9.1393}); map.setZoom(7); return; } if(points.length===1){ map.setCenter(points[0]); map.setZoom(14); return; } const bounds=new google.maps.LatLngBounds(); points.forEach(pt=>bounds.extend(pt)); map.fitBounds(bounds,30); }
  function dedupe(items){ const seen=new Map(); (items||[]).forEach((item)=>{ const key=(item.place_id||'') || (((item.name||'').trim().toLowerCase())+'|'+((item.address||'').trim().toLowerCase())); if(!key) return; const prev=seen.get(key); const score=(x)=>['phone','email','contact_person','website','lat','lng'].reduce((n,k)=>n+(x&&x[k]?1:0),0); if(!prev || score(item)>=score(prev)){ seen.set(key,item); } }); return Array.from(seen.values()); }
  function populateDistricts(){ const list=Array.isArray(geoPT.districts)?geoPT.districts:[]; els.district.innerHTML='<option value="">Distrito</option>'+list.map(x=>`<option value="${x}">${x}</option>`).join(''); }
  function populateDependent(){ const d=els.district.value||''; const counties=(geoPT.countiesByDistrict&&geoPT.countiesByDistrict[d])||[]; const cities=(geoPT.citiesByDistrict&&geoPT.citiesByDistrict[d])||[]; els.county.innerHTML='<option value="">Concelho</option>'+counties.map(x=>`<option value="${x}">${x}</option>`).join(''); els.city.innerHTML='<option value="">Cidade</option>'+cities.map(x=>`<option value="${x}">${x}</option>`).join(''); }
  async function loadCategories(){ const data=await j(api+'categories'); categories=Array.isArray(data.items)?data.items:[]; const seen={}; const roots=categories.filter(x=>!x.parent_id).filter(x=>{ const k=(x.slug||x.name||'').toString().trim().toLowerCase(); if(seen[k]) return false; seen[k]=1; return true; }); els.category.innerHTML='<option value="">Categoria</option>'+roots.map(x=>`<option value="${x.id}">${esc(x.name)}</option>`).join(''); fillSubcategories(); }
  function fillSubcategories(){ const pid=parseInt(els.category.value||'0',10); const seen={}; const subs=categories.filter(x=>parseInt(x.parent_id||0,10)===pid).filter(x=>{ const k=(x.name||'').trim().toLowerCase(); if(seen[k]) return false; seen[k]=1; return true; }); els.subcategory.innerHTML='<option value="">Subcategoria</option>'+subs.map(x=>`<option value="${x.id}">${esc(x.name)}</option>`).join(''); }
  function renderResults(items){ resultItems=dedupe(items); els.results.innerHTML=(resultItems.map((item,idx)=>`<div class="rp-item"><div class="rp-chip">${item.source==='google'?'Google':'Interno'}</div><h4>${esc(item.name||'PDV')}</h4><div class="rp-meta">${esc(item.address||'')}</div><div class="rp-meta">${esc(item.phone||'')} ${item.city?(' · '+esc(item.city)):''}</div><div class="rp-actions"><button type="button" class="rp-btn ghost" data-idx="${idx}">Adicionar à rota</button></div></div>`).join('')) || '<div class="rp-item">Sem resultados.</div>';
    els.results.querySelectorAll('button[data-idx]').forEach(btn=>btn.addEventListener('click',()=>{ const item=resultItems[parseInt(btn.dataset.idx,10)]; if(item) addToQueue(item); })); renderDiscoveryMarkers(resultItems); }
  function renderDiscoveryMarkers(items){ if(!map || !(window.google && google.maps)) return; clearMarkers(); const bounds=[]; (items||[]).forEach(item=>{ const lat=parseFloat(item.lat), lng=parseFloat(item.lng); if(!Number.isFinite(lat)||!Number.isFinite(lng)) return; const isGoogle=item.source==='google'; const marker=new google.maps.Marker({map, position:{lat,lng}, title:item.name||item.address||'PDV', icon:{path:google.maps.SymbolPath.CIRCLE, scale:isGoogle?8:7, fillColor:isGoogle?'#f97316':'#2563eb', fillOpacity:.95, strokeColor:isGoogle?'#c2410c':'#1d4ed8', strokeWeight:2}}); marker.addListener('click',()=>{ infoWindow.setContent('<div style="max-width:260px"><strong>'+esc(item.name||'PDV')+'</strong><br>'+esc(item.address||'')+'</div>'); infoWindow.open({anchor:marker,map}); addToQueue(item); }); markers.push(marker); bounds.push({lat,lng}); }); fitMarkerBounds(bounds); fixMapLayout(); }
  function buildInternalParams(){ const p=new URLSearchParams(); if(els.client?.value) p.set('client_id',els.client.value); if(els.project?.value) p.set('project_id',els.project.value); const ownerId = effectiveDiscoveryOwnerId(); if(ownerId) p.set('owner_user_id', ownerId); if(els.district.value) p.set('district',els.district.value); if(els.county.value) p.set('county',els.county.value); if(els.city.value) p.set('city',els.city.value); if(els.category.value) p.set('category_id',els.category.value); if(els.subcategory.value) p.set('subcategory_id',els.subcategory.value); if(els.q.value.trim()) p.set('q',els.q.value.trim()); p.set('per_page','200'); return p; }
  async function fetchInternalPages(params){ const merged=[]; let page=1, total=0; while(true){ const req=new URLSearchParams(params.toString()); req.set('page', String(page)); const data=await j(api+'commercial-search?'+req.toString()); const items=Array.isArray(data.items)?data.items:[]; total=parseInt(data.total||items.length||0,10)||0; merged.push(...items.map(x=>Object.assign({source:'internal'},x))); if(!items.length || merged.length>=total || page>=50) break; page++; } return dedupe(merged); }
  async function searchInternal(){ return fetchInternalPages(buildInternalParams()); }
  function geocodeArea(){ return new Promise((resolve)=>{ if(!googleReady || !geocoder) return resolve(null); const pieces=[els.city.value,els.county.value,els.district.value,'Portugal'].filter(Boolean); if(!pieces.length && !els.q.value.trim()) return resolve(null); geocoder.geocode({address:(pieces.length?pieces.join(', '):els.q.value.trim())+', Portugal'}, (res,status)=>{ if(status==='OK' && res && res[0]){ const g=res[0].geometry.location; resolve({lat:g.lat(),lng:g.lng()}); } else resolve(null); }); }); }
  function textSearch(req){ return new Promise((resolve)=>{ if(!placesSvc) return resolve([]); placesSvc.textSearch(req, (results,status)=>{ if(status!==google.maps.places.PlacesServiceStatus.OK || !Array.isArray(results)) return resolve([]); resolve(results); }); }); }
  function nearbySearch(req){ return new Promise((resolve)=>{ if(!placesSvc) return resolve([]); placesSvc.nearbySearch(req, (results,status)=>{ if(status!==google.maps.places.PlacesServiceStatus.OK || !Array.isArray(results)) return resolve([]); resolve(results); }); }); }
  function placeToItem(r){ return {name:r.name||'',address:r.formatted_address||r.vicinity||'',lat:r.geometry?.location?.lat?.(),lng:r.geometry?.location?.lng?.(),place_id:r.place_id||'',contact_person:r.contact_person||r.name||'',phone:r.formatted_phone_number||r.phone||'',email:r.email||'',source:'google'}; }
  async function googleSearch(){ if(!googleReady || !placesSvc){ return []; } const center=await geocodeArea(); const terms=[els.subcategory.options[els.subcategory.selectedIndex]?.text||'', els.category.options[els.category.selectedIndex]?.text||'', els.q.value.trim()].filter(Boolean); const query=(terms.join(' ').trim() || 'estabelecimento comercial') + ', ' + [els.city.value, els.county.value, els.district.value, 'Portugal'].filter(Boolean).join(', ');
    const textResults=await textSearch({query, language:'pt-PT'});
    let nearbyResults=[];
    if(center){ nearbyResults=await nearbySearch({location:new google.maps.LatLng(center.lat,center.lng), radius: els.city.value?12000:(els.county.value?25000:50000), keyword:terms.join(' ').trim()||undefined, language:'pt-PT'}); }
    return dedupe([...(textResults||[]), ...(nearbyResults||[])].map(placeToItem));
  }
  function queueKey(item){ return item.place_id || (((item.name||'').trim().toLowerCase())+'|'+((item.address||'').trim().toLowerCase())); }
  function addToQueue(item){ const key=queueKey(item); if(queue.some(x=>queueKey(x)===key)){ setStatus('Esse PDV já está na rota.'); return; } queue.push(item); renderQueue(); recalcRoute(); }
  function renderQueue(){ els.total.textContent=String(queue.length); els.queue.innerHTML=queue.map((item,idx)=>`<div class="rp-qitem"><div class="rp-no">${idx+1}</div><div><h4>${esc(item.name||'PDV')}</h4><div class="rp-meta">${esc(item.address||'')}</div></div><div><button type="button" class="rp-btn ghost" data-remove="${idx}">Remover</button></div></div>`).join('') || '<div class="rp-item">Sem PDVs adicionados.</div>'; els.queue.querySelectorAll('button[data-remove]').forEach(btn=>btn.addEventListener('click',()=>{ queue.splice(parseInt(btn.dataset.remove,10),1); renderQueue(); recalcRoute(); })); }
  function drawQueueLine(){ if(!map || !(window.google && google.maps)) return; if(routeRenderer){ routeRenderer.setMap(null); routeRenderer=null; } const pts=queue.map(x=>({lat:parseFloat(x.lat),lng:parseFloat(x.lng)})).filter(x=>Number.isFinite(x.lat)&&Number.isFinite(x.lng)); if(pts.length>=2){ const bounds=new google.maps.LatLngBounds(); pts.forEach(pt=>bounds.extend(pt)); map.fitBounds(bounds,30); } }
  async function recalcRoute(){ drawQueueLine(); if(queue.length<2 || !googleReady || !directionsService){ els.km.textContent='0'; els.travel.textContent='0m'; els.totalTime.textContent='0m'; return; } const points=queue.filter(x=>Number.isFinite(parseFloat(x.lat)) && Number.isFinite(parseFloat(x.lng))); if(points.length<2){ return; } const origin={lat:parseFloat(points[0].lat),lng:parseFloat(points[0].lng)}; const destination={lat:parseFloat(points[points.length-1].lat),lng:parseFloat(points[points.length-1].lng)}; const waypoints=points.slice(1,-1).slice(0,23).map(x=>({location:{lat:parseFloat(x.lat),lng:parseFloat(x.lng)}, stopover:true})); directionsService.route({origin,destination,waypoints,travelMode:'DRIVING',optimizeWaypoints:false}, (res,status)=>{ if(status!=='OK' || !res.routes?.[0]?.legs){ setStatus('Google não conseguiu calcular a rota completa.'); return; } if(routeRenderer){ routeRenderer.setMap(null); } routeRenderer=new google.maps.DirectionsRenderer({map,suppressMarkers:false,preserveViewport:false}); routeRenderer.setDirections(res); let meters=0, secs=0; res.routes[0].legs.forEach(leg=>{ meters += leg.distance?.value||0; secs += leg.duration?.value||0; }); els.km.textContent=(meters/1000).toFixed(1); const mins=Math.round(secs/60); els.travel.textContent=mins+'m'; els.totalTime.textContent=mins+'m'; setStatus('Rota recalculada com sucesso.'); }); }
  function openMaps(){ if(queue.length<2){ setStatus('Adiciona pelo menos dois PDVs.'); return; } const toPoint=s=> Number.isFinite(parseFloat(s.lat))&&Number.isFinite(parseFloat(s.lng)) ? `${s.lat},${s.lng}` : (s.address||s.name||''); const origin=toPoint(queue[0]), destination=toPoint(queue[queue.length-1]); const mids=queue.slice(1,-1).map(toPoint).slice(0,23); let url='https://www.google.com/maps/dir/?api=1&travelmode=driving&origin='+encodeURIComponent(origin)+'&destination='+encodeURIComponent(destination); if(mids.length) url+='&waypoints='+encodeURIComponent(mids.join('|')); window.open(url,'_blank','noopener'); }
  async function saveRoute(){ if(!queue.length){ return setStatus('Adiciona pelo menos um PDV.'); } if(!els.client.value){ return setStatus('Seleciona um cliente.'); } if(!els.project.value){ return setStatus('Seleciona uma campanha.'); } const route = await j(api+'routes',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify({client_id:parseInt(els.client.value,10),project_id:parseInt((els.project?.value)||'0',10)||null,date:els.date.value,status:els.routeStatus ? els.routeStatus.value : 'planned'})}); const routeId=parseInt(route.id||route.route_id||0,10); if(!routeId) return setStatus('Rota criada sem ID.'); let seq=0; for(const item of queue){ let locationId=parseInt(item.id||0,10)||0; if(!locationId){ const loc=await j(api+'locations',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify({name:item.name,address:item.address,phone:item.phone||'',email:item.email||'',contact_person:item.contact_person||'',place_id:item.place_id||'',lat:item.lat,lng:item.lng,source:item.source||'manual',client_id:parseInt(els.client.value,10),project_id:parseInt((els.project?.value)||'0',10)||null,location_type:'pdv',replace_existing:1})}); locationId=parseInt(loc.id,10); }
      if(locationId){ await j(api+'stops',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify({route_id:routeId,location_id:locationId,seq:seq++})}); }
    }
    setStatus('Rota gravada com sucesso.');
  }
  async function fitToZone(){ const center = await geocodeArea(); if(center && map){ map.setCenter(center); map.setZoom(els.city.value?12:(els.county.value?10:8)); fixMapLayout(); } }
  async function runCombined(withGoogle){ try{ if(!discoveryScopeReady()){ setStatus('Seleciona cliente e campanha válidos para descobrir PDVs.'); renderResults([]); return; } await loadGoogle().catch(()=>{}); initMap(); bindDiscoveryAutocomplete(); setStatus(withGoogle?'A combinar internos e Google...':'A pesquisar PDVs existentes...'); const internal=await searchInternal(); let items=internal; if(withGoogle){ const googleItems=await googleSearch(); items=dedupe([...internal, ...googleItems]); } renderResults(items); if(!items.length){ await fitToZone(); } setStatus('Pesquisa concluída.'); }catch(err){ setStatus(err.message||'Falha na pesquisa.'); } }
  root.querySelector('#rpf-search').addEventListener('click', ()=>runCombined(false));
  root.querySelector('#rpf-google').addEventListener('click', ()=>runCombined(true));
  root.querySelector('#rpf-openmaps').addEventListener('click', openMaps); root.querySelector('#rpf-recalc').addEventListener('click', recalcRoute); root.querySelector('#rpf-clear').addEventListener('click', ()=>{ queue=[]; renderQueue(); clearMarkers(); if(routeRenderer){ routeRenderer.setMap(null); routeRenderer=null; } if(map){ map.setCenter({lat:38.7223,lng:-9.1393}); map.setZoom(7); } els.km.textContent='0'; els.travel.textContent='0m'; els.totalTime.textContent='0m'; setStatus('Fila limpa.'); });
  els.district.addEventListener('change', populateDependent); els.category.addEventListener('change', fillSubcategories); els.q.addEventListener('keydown', (e)=>{ if(e.key==='Enter'){ e.preventDefault(); runCombined(true); } });
  root.querySelector('#rpf-save-route').addEventListener('click', saveRoute);
  root.querySelector('#rpf-client').addEventListener('change', ()=>{ syncProjects(''); lockRestrictedScope(); updateDiscoverySearchPlaceholder(); if(discoveryScopeReady()){ runCombined(false); } });
  root.querySelector('#rpf-project').addEventListener('change', ()=>{ updateDiscoverySearchPlaceholder(); if(discoveryScopeReady()){ runCombined(false); } else { setStatus('Seleciona campanha válida para descobrir PDVs.'); } });
  root.addEventListener('routespro:panel-open', ()=>{ lockRestrictedScope(); loadGoogle().then(()=>{ bindDiscoveryAutocomplete(); }).catch(()=>{}); initMap(); bindDiscoveryAutocomplete(); fixMapLayout(); setTimeout(()=>{ loadGoogle().then(()=>{ bindDiscoveryAutocomplete(); }).catch(()=>{}); if(discoveryScopeReady()){ runCombined(false); } },260); });
  window.addEventListener('resize', ()=>{ initMap(); fixMapLayout(); });
  if('IntersectionObserver' in window){ mapObserver = new IntersectionObserver((entries)=>{ entries.forEach(entry=>{ if(entry.isIntersecting){ loadGoogle().catch(()=>{}); initMap(); bindDiscoveryAutocomplete(); fixMapLayout(); } }); }, {threshold:0.2}); const mapEl=root.querySelector('#rpf-map'); if(mapEl) mapObserver.observe(mapEl); }
  lockRestrictedScope();
  updateDiscoverySearchPlaceholder();
  await loadGoogle().catch(()=>{ setStatus('Google Maps indisponível. A pesquisa interna continua ativa.'); }); initMap(); bindDiscoveryAutocomplete(); setTimeout(()=>{ initMap(); bindDiscoveryAutocomplete(); loadGoogle().then(()=>bindDiscoveryAutocomplete()).catch(()=>{}); },300); setTimeout(()=>{ initMap(); bindDiscoveryAutocomplete(); loadGoogle().then(()=>bindDiscoveryAutocomplete()).catch(()=>{}); },900); populateDistricts(); await loadCategories().catch(()=>{}); renderQueue(); if(discoveryScopeReady()){ setTimeout(()=>{ runCombined(false); },120); }
})();
</script>
<?php
        return ob_get_clean();
    }


    public static function dashboard($atts = []) {
        if (!current_user_can('routespro_manage')) return '<p>Sem permissões.</p>';
    
        $nonce = wp_create_nonce('wp_rest');
        $ap  = \RoutesPro\Admin\Appearance::get();
        $cbg = !empty($ap['bg_transparent']) ? 'transparent' : ($ap['bg_color'] ?? '#f7fafc');
        $ff  = $ap['font_family'] ?? 'inherit';
        $fz  = intval($ap['font_size_px'] ?? 16);
    
        ob_start(); ?>
    <style>
    .routespro-dashboard .card{background:<?php echo esc_attr($cbg); ?>;font-family:<?php echo esc_attr($ff); ?>;font-size:<?php echo esc_attr($fz); ?>px;border-radius:10px;border:1px solid #e6e6e6;padding:12px}
    .routespro-dashboard .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px}
    .routespro-dashboard .row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px}
    .routespro-dashboard .rp-table-scroll{display:block;width:100%;max-width:100%;overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch}
    .routespro-dashboard table{width:max-content;min-width:980px;border-collapse:collapse}
    .routespro-dashboard th, .routespro-dashboard td{border:1px solid #e5e7eb;padding:6px;text-align:left;font-size:14px;white-space:nowrap}
    .routespro-dashboard thead th{background:#f8fafc}
    </style>
    
    <div class="routespro-dashboard" data-nonce="<?php echo esc_attr($nonce); ?>">
      <div class="row">
        <label>Projeto <select id="db-project"><option value="">(todos)</option></select></label>
        <label>Função
          <select id="db-role">
            <option value="">(todas)</option>
            <option value="implementacao">Implementação</option>
            <option value="merchandising">Merchandising</option>
            <option value="comercial">Comercial HoReCa</option>
          </select>
        </label>
        <label>Funcionário <select id="db-user"><option value="">(todos)</option></select></label>
        <label>De <input type="date" id="db-from"></label>
        <label>Até <input type="date" id="db-to"></label>
        <button id="db-export" class="button">Exportar CSV</button>
      </div>
      <div id="rp-stats" class="grid" style="margin-bottom:10px"></div>
      <div class="rp-table-scroll">
        <table id="rp-table">
          <thead><tr>
            <th>Data</th><th>Projeto</th><th>Funcionário</th><th>Rota</th><th>Status</th><th># Paragens</th><th>Concluídas</th><th>% Done</th>
          </tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
    
 <script>
(function(){
  const box  = document.querySelector('.routespro-dashboard');
  if(!box) return;

  const nonce = box.dataset.nonce;

  const api  = '<?php echo esc_url( rest_url('routespro/v1/') ); ?>';
  const ajax = '<?php echo esc_url( admin_url('admin-ajax.php') ); ?>';

  const elStats = document.getElementById('rp-stats');
  const elTable = document.querySelector('#rp-table tbody');

  const selProject = document.getElementById('db-project');
  const selRole    = document.getElementById('db-role');
  const selUser    = document.getElementById('db-user');
  const from = document.getElementById('db-from');
  const to   = document.getElementById('db-to');

  const today = new Date().toISOString().slice(0,10);
  from.value = new Date(Date.now()-6*24*60*60*1000).toISOString().slice(0,10);
  to.value   = today;

  function ensureScroll(){
    const table = box.querySelector('#rp-table');
    if(table && !table.style.minWidth) table.style.minWidth = '980px';
  }

  function card(k,v){
    const d=document.createElement('div');
    d.className='card';
    d.innerHTML='<div><strong>'+k+'</strong><div style="font-size:22px">'+v+'</div></div>';
    return d;
  }

  async function safeFetch(url){
    try{
      const r = await fetch(url, {credentials:'same-origin', headers:{'X-WP-Nonce':nonce}});
      if(!r.ok) throw new Error('Request failed');
      return await r.json();
    }catch(e){
      console.error('RoutesPro error:', e);
      return null;
    }
  }

  async function loadProjects(){
    const data = await safeFetch(ajax+'?action=routespro_projects_for_user') || [];
    selProject.innerHTML = '<option value="">(todos)</option>' +
      data.map(x=>`<option value="${x.id}">${x.name}</option>`).join('');
  }

  async function loadUsers(){
    const data = await safeFetch(ajax+'?action=routespro_users') || [];
    selUser.innerHTML = '<option value="">(todos)</option>'+
      data.map(u=>{
        const title = (u.displayName || u.username || ('#'+u.ID));
        const mail  = (u.email ? ' ('+u.email+')' : '');
        return '<option value="'+u.ID+'">'+title+mail+'</option>';
      }).join('');
  }

  async function loadRoles(){
    const data = await safeFetch(ajax+'?action=routespro_roles') || [];
    selRole.innerHTML = '<option value="">(todas)</option>' +
      data.map(x => `<option value="${x.id}">${x.name || x.id}</option>`).join('');
  }

  async function load(){
    elStats.innerHTML = '<div>A carregar...</div>';

    const qs = [];
    qs.push('from='+encodeURIComponent(from.value));
    qs.push('to='+encodeURIComponent(to.value));
    if (selProject.value) qs.push('project_id='+encodeURIComponent(selProject.value));
    if (selRole.value)    qs.push('role='+encodeURIComponent(selRole.value));
    if (selUser.value)    qs.push('user_id='+encodeURIComponent(selUser.value));

    const s = await safeFetch(api+'stats?'+qs.join('&'));

    if(!s){
      elStats.innerHTML = '<div>Erro ao carregar dados</div>';
      return;
    }

    elStats.innerHTML = '';
    elStats.appendChild(card('Rotas', s.total_routes??'—'));
    elStats.appendChild(card('Concluídas', s.completed_routes??'—'));
    elStats.appendChild(card('% Rotas concl.', s.completion_rate? (s.completion_rate+'%') : '—'));
    elStats.appendChild(card('Paragens', s.total_stops??'—'));
    elStats.appendChild(card('% Paragens concl.', s.done_rate? (s.done_rate+'%') : '—'));
    elStats.appendChild(card('Média paragens/rota', s.avg_stops_per_route??'—'));

    const rows = Array.isArray(s.by_day) ? s.by_day : [];

    elTable.innerHTML = rows.map(x=>(
      '<tr>'+
        '<td>'+ (x.date||'') +'</td>'+
        '<td>'+ (x.project_name||'') +'</td>'+
        '<td>'+ (x.user_name||'') +'</td>'+
        '<td>#'+ (x.route_id||'') +'</td>'+
        '<td>'+ (x.route_status||'') +'</td>'+
        '<td>'+ (x.stops||0) +'</td>'+
        '<td>'+ (x.stops_done||0) +'</td>'+
        '<td>'+ (x.done_rate!=null ? (x.done_rate+'%') : '') +'</td>'+
      '</tr>'
    )).join('');

    ensureScroll();
  }

  document.getElementById('db-export').addEventListener('click', async function(){
    const s = await safeFetch(api+'stats');
    if(!s) return;

    const rows = Array.isArray(s.by_day) ? s.by_day : [];
    let csv = 'date,project,user,route_id,route_status,stops,stops_done,done_rate\n';

    for (const x of rows){
      csv += [
        x.date||'',
        JSON.stringify(x.project_name||''),
        JSON.stringify(x.user_name||''),
        x.route_id||'',
        x.route_status||'',
        x.stops||0,
        x.stops_done||0,
        (x.done_rate!=null ? x.done_rate : '')
      ].join(',') + '\n';
    }

    const blob = new Blob([csv], {type:'text/csv'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'routespro-dashboard.csv';
    a.click();
  });

  selProject.addEventListener('change', load);
  selRole.addEventListener('change', load);
  selUser.addEventListener('change', load);
  from.addEventListener('change', load);
  to.addEventListener('change', load);

  loadProjects().then(loadUsers).then(loadRoles).then(load);

})();
</script>
    <?php
        return ob_get_clean();
    }

    public static function route_change_form($atts = []) {
        if (!is_user_logged_in()) return '<p>Inicia sessão.</p>';
        $nonce    = wp_create_nonce('wp_rest');
        $route_id = intval($atts['route_id'] ?? 0);
        if(!$route_id) return '<p>Falta route_id.</p>';

        $ap  = \RoutesPro\Admin\Appearance::get();
        $c1  = !empty($ap['primary_transparent']) ? 'transparent' : ($ap['primary_color'] ?? '#2b6cb0');
        $c2  = !empty($ap['accent_transparent'])  ? 'transparent' : ($ap['accent_color'] ?? '#38b2ac');
        $cbg = !empty($ap['bg_transparent'])      ? 'transparent' : ($ap['bg_color'] ?? '#f7fafc');
        $ff  = $ap['font_family'] ?? 'inherit';
        $fz  = intval($ap['font_size_px'] ?? 16);

        ob_start(); ?>
<style>
#routespro-my-daily-route{ --rp-primary: <?php echo esc_html($c1); ?>; --rp-accent: <?php echo esc_html($c2); ?>; --rp-bg: <?php echo esc_html($cbg); ?>; font-family: <?php echo esc_html($ff); ?>; font-size: <?php echo esc_html($fz); ?>px; }
#routespro-my-daily-route fieldset{ background: var(--rp-bg); }
#routespro-my-daily-route .button.button-primary{ background: var(--rp-primary); border-color: var(--rp-primary); }
a, #routespro-my-daily-route button{ accent-color: var(--rp-accent); }
</style>

<div id="rp-change" data-route="<?php echo esc_attr($route_id); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
  <p>Carregar rota…</p>
</div>

<script>
(async function(){
  const root    = document.getElementById('rp-change');
  const routeId = root.dataset.route;
  const nonce   = root.dataset.nonce;

  const det = await fetch('<?php echo esc_url( rest_url('routespro/v1/routes/') ); ?>'+routeId+'?v='+Date.now(), {
    credentials:'same-origin', headers:{'X-WP-Nonce':nonce}
  }).then(async r=>{ const t=await r.text(); try{return JSON.parse(t)}catch(_){return {}} });

  const stops = det.stops || [];
  root.innerHTML = `
    <ol id="rp-sortable" style="list-style:decimal;padding-left:20px">
      ${stops.map(s=>`<li data-stop="${s.id}" draggable="true" style="padding:6px;border:1px solid #ddd;margin-bottom:6px;border-radius:6px;background:#fff">
        ${s.location_name} <small>(#${s.id})</small>
      </li>`).join('')}
    </ol>
    <button id="rp-save" class="button button-primary">Guardar ordem</button>
  `;

  const list = document.getElementById('rp-sortable');
  let dragEl=null;
  list.addEventListener('dragstart', (e)=>{ dragEl=e.target; e.dataTransfer.effectAllowed='move'; });
  list.addEventListener('dragover', (e)=>{
    e.preventDefault();
    const li=e.target.closest('li'); if(!li || li===dragEl) return;
    const rect=li.getBoundingClientRect(); const next=(e.clientY-rect.top)/(rect.bottom-rect.top)>0.5;
    list.insertBefore(dragEl, next?li.nextSibling:li);
  });

  document.getElementById('rp-save').addEventListener('click', async ()=>{
    const ids = Array.from(list.querySelectorAll('li')).map((li,i)=>({id:parseInt(li.dataset.stop), seq:i+1}));
    for (const item of ids) {
      await fetch('<?php echo esc_url( rest_url('routespro/v1/stops/') ); ?>'+item.id, {
        method:'PATCH', credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},
        body: JSON.stringify({seq:item.seq})
      });
    }
    alert('Ordem guardada.');
  });
})();
</script>
<?php
        return ob_get_clean();
    }

    public static function app($atts = []) {
        $guard = self::front_guard();
        if ($guard) return $guard;
        $theme = self::front_theme();
        $visible_primary = ($theme['primary'] === 'transparent') ? '#7c3aed' : $theme['primary'];
        $visible_accent  = ($theme['accent'] === 'transparent') ? '#0ea5e9' : $theme['accent'];
        $user = wp_get_current_user();
        $today = date('Y-m-d');
        $today_label = date_i18n('d/m/Y');
        $can_manage = current_user_can('routespro_manage');
        $can_scope = \RoutesPro\Support\Permissions::can_access_front();
        $scope = \RoutesPro\Support\Permissions::get_scope();
        $scope_client_ids = array_values(array_filter(array_map('absint', (array)($scope['client_ids'] ?? []))));
        $scope_project_ids = array_values(array_filter(array_map('absint', (array)($scope['project_ids'] ?? []))));
        $initial_client_id = absint($atts['client_id'] ?? ($_GET['client_id'] ?? 0));
        $initial_project_id = absint($atts['project_id'] ?? ($_GET['project_id'] ?? 0));
        if (!$initial_client_id && count($scope_client_ids) === 1) $initial_client_id = (int)$scope_client_ids[0];
        if (!$initial_project_id && count($scope_project_ids) === 1) $initial_project_id = (int)$scope_project_ids[0];
        $message_id = absint($_GET['routespro_message'] ?? 0);
        ob_start(); ?>
    <style>
    .rp-premium-shell{--rp-primary:<?php echo esc_attr($theme['primary']); ?>;--rp-accent:<?php echo esc_attr($theme['accent']); ?>;--rp-primary-solid:<?php echo esc_attr($visible_primary); ?>;--rp-accent-solid:<?php echo esc_attr($visible_accent); ?>;--rp-font:<?php echo esc_attr($theme['font']); ?>;--rp-size:<?php echo esc_attr($theme['size']); ?>px;font-family:var(--rp-font);font-size:var(--rp-size);color:#0f172a}
    .rp-premium-shell *{box-sizing:border-box}
    .rp-premium-shell .rp-shell{max-width:min(1520px,96vw);margin:0 auto;padding:18px 16px 104px}
    .rp-premium-shell .rp-hero{position:relative;overflow:hidden;background:radial-gradient(circle at top right,rgba(255,255,255,.18),transparent 28%),linear-gradient(135deg,#081120 0%,#162338 52%,<?php echo esc_attr($theme['primary']); ?> 120%);color:#fff;border-radius:30px;padding:28px;box-shadow:0 28px 70px rgba(15,23,42,.2)}
    .rp-premium-shell .rp-hero-grid{display:grid;grid-template-columns:1.15fr .85fr;gap:18px;align-items:end}
    .rp-premium-shell .rp-eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:.09em;opacity:.82;margin-bottom:8px}
    .rp-premium-shell .rp-hero h2{margin:0 0 10px;font-size:36px;line-height:1.02;font-weight:800;color:#fff}
    .rp-premium-shell .rp-hero p{margin:0;max-width:760px;opacity:.92;font-size:16px;line-height:1.6;color:#fff}
    .rp-premium-shell .rp-mini{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
    .rp-premium-shell .rp-mini span{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.12);color:#fff;border-radius:999px;padding:8px 12px;font-weight:700;border:1px solid rgba(255,255,255,.14)}
    .rp-premium-shell .rp-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:18px}
    .rp-premium-shell .rp-stat{background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.14);border-radius:20px;padding:16px;min-height:108px}
    .rp-premium-shell .rp-stat span,.rp-premium-shell .rp-stat small{color:rgba(255,255,255,.8)}
    .rp-premium-shell .rp-stat strong{display:block;font-size:26px;line-height:1.04;margin:6px 0;color:#fff}
    .rp-premium-shell .rp-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-top:16px}
    .rp-premium-shell .rp-card{background:#fff;border:1px solid #e2e8f0;border-radius:24px;box-shadow:0 16px 40px rgba(15,23,42,.08);padding:20px}
    .rp-premium-shell .rp-kicker{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:8px}
    .rp-premium-shell .rp-cta{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:14px;padding:12px 16px;background:var(--rp-primary);color:#fff !important;font-weight:700;text-decoration:none;cursor:pointer}
    .rp-premium-shell .rp-cta.alt{background:var(--rp-accent)}
    .rp-premium-shell .rp-cta.ghost{background:#fff;color:#0f172a !important;border:1px solid #cbd5e1}
    .rp-premium-shell .rp-pill{display:inline-flex;align-items:center;gap:8px;border-radius:999px;background:#eff6ff;color:#0f172a;padding:7px 12px;font-size:12px;font-weight:700;border:1px solid #dbeafe}
    .rp-premium-shell .rp-panels{margin-top:18px}
    .rp-premium-shell .rp-panel{display:none}
    .rp-premium-shell .rp-panel.active{display:block}
    .rp-premium-shell .rp-panel .rp-premium-card{background:#fff;border:1px solid #e2e8f0;border-radius:26px;box-shadow:0 16px 40px rgba(15,23,42,.08);padding:16px}
    .rp-premium-shell .rp-panel-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap;margin-bottom:14px}
    .rp-premium-shell .rp-panel-head h3{margin:0;font-size:24px;color:#0f172a}
    .rp-premium-shell .rp-panel-head p{margin:6px 0 0;color:#64748b;max-width:720px}
    .rp-premium-shell .rp-tabs{position:sticky;bottom:14px;z-index:25;margin-top:18px;display:block;padding-bottom:max(0px, env(safe-area-inset-bottom))}
    .rp-premium-shell .rp-tabbar{display:flex;gap:8px;background:rgba(15,23,42,.94);padding:10px;border-radius:22px;box-shadow:0 20px 48px rgba(15,23,42,.3);overflow:auto;-webkit-overflow-scrolling:touch}
    .rp-premium-shell .rp-tabbar button{flex:1 1 0;min-width:118px;border:0;border-radius:16px;padding:12px 10px;background:transparent;color:#cbd5e1;font-weight:700;cursor:pointer;white-space:nowrap}
    .rp-premium-shell .rp-tabbar button.active{background:#fff;color:#0f172a}
    .rp-premium-shell .rp-shell .rp-panel[data-panel="route"] #routespro-my-daily-route{max-width:none;width:100%}
    .rp-premium-shell .rp-shell .rp-panel[data-panel="route"] .rp-wrap{border-radius:22px;box-shadow:none;border-color:#e2e8f0;background:#fff}
    .rp-premium-shell .rp-shell .rp-panel[data-panel="commercial"] .rp-front-commercial .rp-card,
    .rp-premium-shell .rp-shell .rp-panel[data-panel="discovery"] .rp-front-routes .rp-card,
    .rp-premium-shell .rp-shell .rp-panel[data-panel="report"] .rp-report-quick .rp-card,
    .rp-premium-shell .rp-shell .rp-panel[data-panel="analytics"] .routespro-dashboard .card{box-shadow:none}
    .rp-premium-shell .rp-cta,.rp-premium-shell .rp-shell .rp-btn{background:var(--rp-primary-solid);color:#fff !important;border-color:var(--rp-primary-solid)}
    .rp-premium-shell .rp-cta.alt,.rp-premium-shell .rp-shell .rp-btn.alt,.rp-premium-shell .rp-shell .rp-btn.secondary{background:var(--rp-accent-solid);border-color:var(--rp-accent-solid);color:#fff !important}
    .rp-premium-shell .rp-cta.ghost,.rp-premium-shell .rp-shell .rp-btn.ghost{background:#fff;color:#0f172a !important;border:1px solid #cbd5e1}
    .rp-premium-shell .rp-shell .rp-panel[data-panel="commercial"] #rpc-role{display:none !important}
    @media (max-width:1100px){.rp-premium-shell .rp-hero-grid,.rp-premium-shell .rp-grid,.rp-premium-shell .rp-stats{grid-template-columns:1fr 1fr}}
    @media (max-width:760px){.rp-premium-shell .rp-shell{padding:14px 12px 108px}.rp-premium-shell .rp-hero h2{font-size:28px}.rp-premium-shell .rp-hero-grid,.rp-premium-shell .rp-grid,.rp-premium-shell .rp-stats{grid-template-columns:1fr}}
    </style>
    <div class="rp-premium-shell rp-app" id="routespro-app"
         data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>"
         data-today="<?php echo esc_attr($today); ?>"
         data-client-id="<?php echo esc_attr($initial_client_id); ?>"
         data-project-id="<?php echo esc_attr($initial_project_id); ?>"
         data-message-id="<?php echo esc_attr($message_id); ?>">
      <div class="rp-shell">
        <section class="rp-hero">
          <div class="rp-hero-grid">
            <div>
              <div class="rp-eyebrow">FieldFlow App</div>
              <h2>Frontoffice premium, alinhado com rota, base comercial e reporte.</h2>
              <p>O objetivo aqui é simples, fechar o plugin com uma experiência final de produto. A operação diária fica numa shell premium, coerente com o backoffice, rápida em mobile e forte em execução.</p>
              <div class="rp-mini">
                <span>Minha Rota com foco de execução</span>
                <span>Base Comercial com autocomplete</span>
                <span>Relatórios e analytics no mesmo fluxo</span>
              </div>
            </div>
            <div class="rp-card" style="background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.18);color:#fff">
              <div class="rp-kicker" style="color:rgba(255,255,255,.75)">Utilizador</div>
              <div style="font-size:22px;font-weight:800;margin-bottom:6px;color:#fff"><?php echo esc_html($user->display_name ?: $user->user_login); ?></div>
              <div style="opacity:.85;margin-bottom:16px;color:#fff"><?php echo esc_html($today_label); ?></div>
              <div class="rp-mini" style="margin-top:0">
                <a class="rp-cta" href="#rp-app-route">Começar rota</a>
                <a class="rp-cta alt" href="#rp-app-commercial">Abrir base</a>
              </div>
            </div>
          </div>
          <div class="rp-stats" id="rpa-kpis">
            <div class="rp-stat"><span>Rotas de hoje</span><strong id="rpa-routes"><?php echo esc_html($today); ?></strong><small id="rpa-routes-note">A carregar operação do dia.</small></div>
            <div class="rp-stat"><span>Paragens</span><strong id="rpa-stops">...</strong><small id="rpa-stops-note">Resumo da execução diária.</small></div>
            <div class="rp-stat"><span>Base comercial</span><strong id="rpa-commercial">...</strong><small id="rpa-commercial-note">PDVs disponíveis no teu âmbito.</small></div>
            <div class="rp-stat"><span>Modo</span><strong id="rpa-mode"><?php echo $can_manage ? 'Gestão e execução' : ($can_scope ? 'Âmbito restrito' : 'Execução'); ?></strong><small id="rpa-mode-note">Tabs prontas para frontoffice moderno.</small></div>
          </div>
        </section>
    
        <section class="rp-grid">
          <article class="rp-card">
            <div class="rp-kicker">Hoje</div>
            <h3 style="margin:0 0 8px;color:#0f172a">Minha Rota</h3>
            <p style="margin:0 0 14px;color:#475569">Agenda do dia, ordem das paragens, estado, navegação e ações rápidas.</p>
            <button class="rp-cta" data-target="route">Abrir rota</button>
          </article>
          <article class="rp-card">
            <div class="rp-kicker">Prospecção</div>
            <h3 style="margin:0 0 8px;color:#0f172a">Descobrir PDVs</h3>
            <p style="margin:0 0 14px;color:#475569">Descoberta, construção de rota e enriquecimento da base comercial.</p>
            <button class="rp-cta alt" data-target="discovery">Abrir descoberta</button>
          </article>
          <article class="rp-card">
            <div class="rp-kicker">Base Comercial</div>
            <h3 style="margin:0 0 8px;color:#0f172a">Pesquisar e editar</h3>
            <p style="margin:0 0 14px;color:#475569">Base frontal com mapa, filtros, autocomplete e edição imediata de PDVs.</p>
            <button class="rp-cta ghost" data-target="commercial">Abrir base</button>
          </article>
          <article class="rp-card">
            <div class="rp-kicker">Relatórios</div>
            <h3 style="margin:0 0 8px;color:#0f172a">Reporte rápido</h3>
            <p style="margin:0 0 14px;color:#475569">Check-in, notas, falhas e fecho da visita, tudo sem fricção.</p>
            <button class="rp-cta" data-target="report">Abrir reporte</button>
          </article>
          <article class="rp-card">
            <div class="rp-kicker">Mensagens</div>
            <h3 style="margin:0 0 8px;color:#0f172a">Inbox operacional</h3>
            <p style="margin:0 0 14px;color:#475569">Mensagens recebidas, resposta rápida e fecho do ciclo operacional sem sair da app.</p>
            <button class="rp-cta alt" data-target="messages">Abrir mensagens</button>
          </article>
        </section>
    
        <div class="rp-panels">
          <div class="rp-panel active" data-panel="route" id="rp-app-route">
            <div class="rp-premium-card">
              <div class="rp-panel-head"><div><h3>Minha Rota</h3><p>Layout premium para a operação diária, mantendo toda a lógica já funcional do shortcode base.</p></div><span class="rp-pill">Execução do dia</span></div>
              <?php echo do_shortcode('[fieldflow_route_today]'); ?>
            </div>
          </div>
          <div class="rp-panel" data-panel="discovery" id="rp-app-discovery">
            <div class="rp-premium-card">
              <div class="rp-panel-head"><div><h3>Descobrir</h3><p>Construção de rota, descoberta de novos PDVs e análise de cobertura, dentro da mesma shell visual.</p></div><span class="rp-pill">Prospecção</span></div>
              <?php echo do_shortcode('[fieldflow_discovery]'); ?>
            </div>
          </div>
          <div class="rp-panel" data-panel="report" id="rp-app-report">
            <div class="rp-premium-card">
              <div class="rp-panel-head"><div><h3>Reportar</h3><p>Fecho de visita em poucos passos, desenhado para uso real em telemóvel no terreno.</p></div><span class="rp-pill">Check-in e fecho</span></div>
              <?php echo do_shortcode('[fieldflow_report_visit]'); ?>
            </div>
          </div>
          <div class="rp-panel" data-panel="commercial" id="rp-app-commercial">
            <div class="rp-premium-card">
              <div class="rp-panel-head"><div><h3>Base Comercial</h3><p>Autocomplete ativo na morada e na pesquisa, mapa premium, edição rápida e gravação imediata na base.</p></div><span class="rp-pill">Autocomplete e mapa</span></div>
              <?php echo do_shortcode('[fieldflow_front_commercial]'); ?>
            </div>
          </div>
          <div class="rp-panel" data-panel="messages" id="rp-app-messages">
            <div class="rp-premium-card">
              <div class="rp-panel-head"><div><h3>Mensagens</h3><p>Inbox operacional para owners, com resposta, estados e ligação direta ao histórico no sistema.</p></div><span class="rp-pill">Workflow</span></div>
              <?php
                echo do_shortcode(sprintf(
                  '[fieldflow_team_inbox client_id="%d" project_id="%d"]',
                  $initial_client_id,
                  $initial_project_id
                ));
              ?>
            </div>
          </div>
          <?php if ($can_manage): ?>
          <div class="rp-panel" data-panel="analytics" id="rp-app-analytics">
            <div class="rp-premium-card">
              <div class="rp-panel-head"><div><h3>Analytics</h3><p>Leitura rápida de performance, execução e produtividade, sem sair do frontoffice premium.</p></div><span class="rp-pill">Gestão</span></div>
              <?php echo do_shortcode('[fieldflow_dashboard]'); ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
    
        <div class="rp-tabs">
          <div class="rp-tabbar">
            <button type="button" class="active" data-panel="route">Minha Rota</button>
            <button type="button" data-panel="discovery">Descobrir</button>
            <button type="button" data-panel="report">Reportar</button>
            <button type="button" data-panel="commercial">Base</button>
            <button type="button" data-panel="messages">Mensagens</button>
            <?php if ($can_manage): ?><button type="button" data-panel="analytics">Analytics</button><?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <script>
    (async function(){
      const root=document.getElementById('routespro-app');if(!root)return;
      const nonce=root.dataset.nonce||'';
      const api='<?php echo esc_url(rest_url('routespro/v1/')); ?>';
      const today=root.dataset.today||'<?php echo esc_js($today); ?>';
      const initialClientId=root.dataset.clientId||'';
      const initialProjectId=root.dataset.projectId||'';
      const initialMessageId=root.dataset.messageId||'';
      const $=sel=>root.querySelector(sel);
      const ensureTableScroll=(scope)=>{
        (scope||root).querySelectorAll('table').forEach(table=>{
          if(table.closest('.rp-table-wrap,.rp-table-scroll')) return;
          const wrap=document.createElement('div');
          wrap.className='rp-table-scroll';
          wrap.style.display='block';
          wrap.style.width='100%';
          wrap.style.maxWidth='100%';
          wrap.style.overflowX='auto';
          wrap.style.overflowY='hidden';
          wrap.style.webkitOverflowScrolling='touch';
          table.parentNode.insertBefore(wrap, table);
          wrap.appendChild(table);
          if(!table.style.minWidth) table.style.minWidth='980px';
        });
      };
      const panelScopeDetail=()=>({client_id:initialClientId||'',project_id:initialProjectId||'',message_id:initialMessageId||''});
const pingPanel=(target,key)=>{
  if(!target) return;

  const detail = Object.assign({panel:key}, panelScopeDetail());

  const ev = ()=>new CustomEvent('routespro:panel-open',{detail});

  // dispara no próprio painel
  target.dispatchEvent(ev());

  // dispara em filhos relevantes
  target.querySelectorAll('.rp-front-routes,.rp-front-commercial,.rp-client-portal,#routespro-my-daily-route,.routespro-dashboard,.rp-team-inbox').forEach(el=>{
    el.dispatchEvent(ev());
    el.dispatchEvent(new CustomEvent('routespro:scope-change',{detail}));
  });

  // fallback global
  document.dispatchEvent(new CustomEvent('routespro:panel-open',{detail}));
  document.dispatchEvent(new CustomEvent('routespro:scope-change',{detail}));

  ensureTableScroll(target);
};
      const openPanel=(key)=>{
        root.querySelectorAll('.rp-tabbar button').forEach(btn=>btn.classList.toggle('active',btn.dataset.panel===key));
        root.querySelectorAll('.rp-panel').forEach(panel=>panel.classList.toggle('active',panel.dataset.panel===key));
        const target=root.querySelector('.rp-panel[data-panel="'+key+'"]');
        if(target){
          pingPanel(target,key);
          setTimeout(()=>target.scrollIntoView({behavior:'smooth',block:'start'}),10);
        }
      };
      const setMetric=(base,strongText,smallText)=>{
        const strong=$('#'+base);
        const note=$('#'+base+'-note');
        if(strong&&strongText!=null) strong.textContent=String(strongText);
        if(note&&smallText!=null) note.textContent=String(smallText);
      };
      const j=async(url,opts={})=>{
        const res=await fetch(url,Object.assign({credentials:'same-origin',headers:{'X-WP-Nonce':nonce}},opts));
        const text=await res.text();
        let data={};
        try{data=text?JSON.parse(text):{}}catch(_){data={message:text}}
        if(!res.ok) throw new Error(data.message||'Falha no pedido.');
        return data;
      };
      const loadOverview=async()=>{
        try{
          const [routesData,statsData,commercialData]=await Promise.all([
            j(api+'routes?user_id=me&date='+encodeURIComponent(today)).catch(()=>({routes:[]})),
            j(api+'stats?from='+encodeURIComponent(today)+'&to='+encodeURIComponent(today)).catch(()=>({})),
            j(api+'commercial-search?page=1&per_page=1'+(initialClientId?'&client_id='+encodeURIComponent(initialClientId):'')+(initialProjectId?'&project_id='+encodeURIComponent(initialProjectId):'')).catch(()=>({stats:{}}))
          ]);
          const routes=Array.isArray(routesData.routes)?routesData.routes:[];
          let totalStops=0,doneStops=0;
          const details=await Promise.all(routes.slice(0,12).map(route=>j(api+'routes/'+route.id).catch(()=>null)));
          details.forEach(route=>{
            const stops=Array.isArray(route&&route.stops)?route.stops:[];
            totalStops+=stops.length;
            doneStops+=stops.filter(stop=>['done','completed'].includes(String(stop.status||'').toLowerCase())).length;
          });
          setMetric('rpa-routes',routes.length,routes.length?('Rotas atribuídas para '+today+'.'):('Sem rotas atribuídas para hoje.'));
          setMetric('rpa-stops',totalStops?`${doneStops}/${totalStops}`:'0/0',totalStops?('Paragens concluídas dentro da tua operação diária.'):('Sem paragens carregadas neste momento.'));
          const cs=(commercialData&&commercialData.stats)||{};
          setMetric('rpa-commercial',cs.total_visible||0,`Base visível, ${cs.validated_count||0} validados e ${cs.with_coords||0} com coordenadas.`);
          const completion=(statsData&&typeof statsData.done_rate!=='undefined')?Number(statsData.done_rate||0):null;
          setMetric('rpa-mode',$('#rpa-mode')?$('#rpa-mode').textContent:'<?php echo $can_manage ? 'Gestão e execução' : ($can_scope ? 'Âmbito restrito' : 'Execução'); ?>',completion===null?'Tabs prontas para frontoffice moderno.':('Execução do dia em '+completion+'% no âmbito atual.'));
        }catch(_){
          setMetric('rpa-routes','n.d.','Não foi possível carregar o resumo operacional.');
          setMetric('rpa-stops','n.d.','Tenta atualizar a página para recarregar os dados.');
          setMetric('rpa-commercial','n.d.','Resumo comercial indisponível de momento.');
        }
      };
      root.querySelectorAll('.rp-tabbar button,[data-target]').forEach(btn=>btn.addEventListener('click',()=>openPanel(btn.dataset.panel||btn.dataset.target||'route')));
      const initialPanel=(initialMessageId||window.location.hash==='#rp-app-messages')?'messages':'route';
      setTimeout(()=>openPanel(initialPanel),120);
      await loadOverview();
    })();
    </script>
    <?php
        return ob_get_clean();
    }


    public static function route_today($atts = []) {
        return self::my_daily_route($atts);
    }

    public static function discovery($atts = []) {
        return self::front_routes($atts);
    }

    public static function checkin($atts = []) {
        $atts['mode'] = 'checkin';
        return self::report_visit($atts);
    }


    public static function client_team_mail($atts = []) {
        $guard = self::front_guard();
        if ($guard) return $guard;
        $theme = self::front_theme();
        $nonce = wp_create_nonce('wp_rest');
        $scope = \RoutesPro\Support\Permissions::get_scope();
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $clients = \RoutesPro\Support\Permissions::filter_clients($wpdb->get_results("SELECT id,name FROM {$px}clients ORDER BY name ASC", ARRAY_A) ?: []);
        $projects = \RoutesPro\Support\Permissions::filter_projects($wpdb->get_results("SELECT id,client_id,name FROM {$px}projects ORDER BY name ASC", ARRAY_A) ?: []);
        $client_id = absint($atts['client_id'] ?? ($_GET['client_id'] ?? 0));
        $project_id = absint($atts['project_id'] ?? ($_GET['project_id'] ?? 0));
        [$client_id, $project_id] = \RoutesPro\Support\Permissions::sanitize_scope_selection($client_id, $project_id);
        ob_start(); ?>
<style>
.rp-team-mail{font-family:<?php echo esc_attr($theme['font']); ?>;font-size:<?php echo esc_attr($theme['size']); ?>px}
.rp-team-mail .rp-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.rp-team-mail .rp-card{background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:18px;box-shadow:0 12px 26px rgba(15,23,42,.06)}
.rp-team-mail label{display:block;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:6px}
.rp-team-mail input,.rp-team-mail select,.rp-team-mail textarea{width:100%;border:1px solid #cbd5e1;border-radius:14px;padding:12px;background:#fff}
.rp-team-mail textarea{min-height:180px;resize:vertical}
.rp-team-mail .rp-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:16px}
.rp-team-mail .rp-btn{border:0;border-radius:14px;padding:12px 16px;font-weight:800;cursor:pointer;background:<?php echo esc_attr($theme['primary']); ?>;color:#fff;transition:all .18s ease}
.rp-team-mail .rp-btn.alt{background:#fff;color:#0f172a;border:1px solid #cbd5e1}
.rp-team-mail .rp-btn:hover,.rp-team-mail .rp-btn:focus,.rp-team-mail .rp-btn:active{background:<?php echo esc_attr($theme['primary']); ?> !important;color:#fff !important;outline:none;box-shadow:0 0 0 3px rgba(15,23,42,.10)}
.rp-team-mail .rp-btn.alt:hover,.rp-team-mail .rp-btn.alt:focus,.rp-team-mail .rp-btn.alt:active{background:#fff !important;color:#0f172a !important;border-color:#cbd5e1 !important;outline:none;box-shadow:0 0 0 3px rgba(15,23,42,.08)}
.rp-team-mail .rp-note{padding:12px 14px;border:1px dashed #cbd5e1;border-radius:16px;background:#f8fafc;color:#475569;min-height:52px;display:flex;align-items:center}
.rp-team-mail .rp-note.is-success{background:#ecfdf5;border-color:#86efac;color:#166534}
.rp-team-mail .rp-note.is-error{background:#fef2f2;border-color:#fca5a5;color:#991b1b}
.rp-team-mail .rp-note.is-info{background:#eff6ff;border-color:#93c5fd;color:#1d4ed8}
.rp-team-mail .rp-mini{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.rp-team-mail .rp-mini button{border:1px solid <?php echo esc_attr($theme['primary']); ?>;background:<?php echo esc_attr($theme['primary']); ?>;color:#fff;border-radius:999px;padding:8px 12px;cursor:pointer;font-weight:700}.rp-team-mail .rp-mini button:nth-child(even){background:<?php echo esc_attr($theme['accent']); ?>;border-color:<?php echo esc_attr($theme['accent']); ?>}.rp-team-mail .rp-mini button:hover{opacity:.92}
.rp-team-mail .rp-history{display:flex;flex-direction:column;gap:10px;max-height:420px;overflow:auto}.rp-team-mail .rp-history-item{border:1px solid #e2e8f0;border-radius:16px;padding:14px;background:#fff}
.rp-team-mail .rp-pill{display:inline-flex;padding:5px 10px;border-radius:999px;background:#eff6ff;border:1px solid #bfdbfe;font-size:12px;font-weight:700}
.rp-team-mail select.ff-locked{background:#f8fafc!important;color:#475569!important;cursor:not-allowed!important;opacity:1!important}
@media (max-width: 860px){.rp-team-mail .rp-grid{grid-template-columns:1fr}}
</style>
<div class="rp-team-mail" id="routespro-client-team-mail" data-nonce="<?php echo esc_attr($nonce); ?>" data-client-id="<?php echo esc_attr($client_id); ?>" data-project-id="<?php echo esc_attr($project_id); ?>" data-projects='<?php echo esc_attr(wp_json_encode($projects)); ?>' data-current-user="<?php echo esc_attr(get_current_user_id()); ?>">
  <div class="rp-grid">
    <div class="rp-card">
      <h3 style="margin:0 0 6px">Contacto operacional com a equipa</h3>
      <p style="margin:0 0 16px;color:#64748b">Envia medidas corretivas, preventivas, informação geral ou pedidos operacionais diretamente ao owner certo da campanha, sem expor a equipa toda.</p>
      <div class="rp-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));gap:12px">
        <div>
          <label for="rpctm-client">Cliente</label>
          <select id="rpctm-client"><option value="">Selecionar</option><?php foreach($clients as $c): ?><option value="<?php echo intval($c['id']); ?>" <?php selected($client_id, intval($c['id'])); ?>><?php echo esc_html($c['name']); ?></option><?php endforeach; ?></select>
        </div>
        <div>
          <label for="rpctm-project">Campanha</label>
          <select id="rpctm-project"><option value="">Selecionar</option></select>
        </div>
        <div>
          <label for="rpctm-sender">Enviar como</label>
          <select id="rpctm-sender"><option value="">Selecionar utilizador cliente</option></select>
        </div>
        <div>
          <label for="rpctm-owner">Enviar para</label>
          <select id="rpctm-owner"><option value="">Selecionar operativo da campanha</option></select>
        </div>
        <div>
          <label for="rpctm-kind">Tipo de mensagem</label>
          <select id="rpctm-kind">
            <option value="corretiva">Medida corretiva</option>
            <option value="preventiva">Medida preventiva</option>
            <option value="informacao">Informação</option>
            <option value="geral">Geral</option>
          </select>
        </div>
        <div>
          <label for="rpctm-priority">Prioridade</label>
          <select id="rpctm-priority"><option value="normal">Normal</option><option value="alta">Alta</option><option value="critica">Crítica</option></select>
        </div>
        <div>
          <label>&nbsp;</label>
          <label style="display:flex;align-items:center;gap:8px;text-transform:none;letter-spacing:0;font-size:14px"><input type="checkbox" id="rpctm-copy" checked style="width:auto"> Receber cópia no meu email</label>
        </div>
        <div style="grid-column:1/-1">
          <label for="rpctm-subject">Assunto</label>
          <input type="text" id="rpctm-subject" placeholder="Ex.: Ajuste preventivo de rota na zona norte">
        </div>
        <div style="grid-column:1/-1">
          <label for="rpctm-message">Mensagem</label>
          <textarea id="rpctm-message" placeholder="Explica o contexto, a medida desejada e a urgência."></textarea>
        </div>
      </div>
      <div class="rp-mini">
        <button type="button" data-template="corretiva">Template corretivo</button>
        <button type="button" data-template="preventiva">Template preventivo</button>
        <button type="button" data-template="informacao">Template informativo</button>
        <button type="button" data-template="geral">Template geral</button>
      </div>
      <div class="rp-actions">
        <button type="button" class="rp-btn" id="rpctm-send">Enviar email</button>
        <button type="button" class="rp-btn alt" id="rpctm-clear">Limpar</button>
      </div>
      <div class="rp-note" id="rpctm-status" style="margin-top:14px">Seleciona primeiro a campanha para veres apenas os owners relevantes.</div>
    </div>
    <div class="rp-card">
      <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:12px">
        <div><h3 style="margin:0 0 4px">Histórico recente</h3><p style="margin:0;color:#64748b">Últimas mensagens enviadas pelo portal, para o cliente manter contexto sem sair da página.</p></div>
        <span class="rp-pill">Email + rastreio</span>
      </div>
      <div class="rp-history" id="rpctm-history"><div class="rp-note">O histórico fica disponível assim que houver emails registados.</div></div>
    </div>
  </div>
</div>
<script>
(function(){
  const root=document.getElementById('routespro-client-team-mail'); if(!root) return;
  const ajax='<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
  const projects=JSON.parse(root.dataset.projects||'[]');
  let isSending=false;
  const els={client:root.querySelector('#rpctm-client'),project:root.querySelector('#rpctm-project'),sender:root.querySelector('#rpctm-sender'),owner:root.querySelector('#rpctm-owner'),kind:root.querySelector('#rpctm-kind'),priority:root.querySelector('#rpctm-priority'),subject:root.querySelector('#rpctm-subject'),message:root.querySelector('#rpctm-message'),copy:root.querySelector('#rpctm-copy'),send:root.querySelector('#rpctm-send'),clear:root.querySelector('#rpctm-clear'),status:root.querySelector('#rpctm-status'),history:root.querySelector('#rpctm-history')};
  const templates={corretiva:{subject:'Medida corretiva para campanha',body:'Olá,\n\nPrecisamos de uma ação corretiva na campanha/rota indicada.\n\nContexto:\n- \n\nAção pretendida:\n- \n\nObrigado.'},preventiva:{subject:'Medida preventiva para campanha',body:'Olá,\n\nQuero antecipar um ajuste preventivo nesta campanha.\n\nRisco identificado:\n- \n\nSugestão:\n- \n\nObrigado.'},informacao:{subject:'Informação operacional de campanha',body:'Olá,\n\nPartilho a seguinte informação relevante para a campanha.\n\nDetalhe:\n- \n\nObrigado.'},geral:{subject:'Pedido geral de acompanhamento',body:'Olá,\n\nSegue mensagem geral sobre a campanha.\n\nDetalhe:\n- \n\nObrigado.'}};
  const esc=s=>(s==null?'':String(s)).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
  function setStatus(msg, type){
    if(!els.status) return;
    els.status.textContent = msg || '';
    els.status.classList.remove('is-success','is-error','is-info');
    if(type === 'success') els.status.classList.add('is-success');
    else if(type === 'error') els.status.classList.add('is-error');
    else if(type === 'info') els.status.classList.add('is-info');
  }
  const setLocked=(el,locked)=>{ if(!el) return; el.disabled=!!locked; el.classList.toggle('ff-locked',!!locked); };
  const lockIfSingle=(el)=>{ if(!el) return false; const opts=Array.from(el.options||[]).filter(o=>String(o.value||'')!==''); const one=opts.length===1; if(one) el.value=String(opts[0].value||''); setLocked(el,one); return one; };
  function syncProjects(preferred){ const cid=parseInt(els.client.value||'0',10); const filtered=cid?projects.filter(p=>parseInt(p.client_id||0,10)===cid):projects; const keep=preferred||els.project.value||root.dataset.projectId||''; els.project.innerHTML='<option value="">Selecionar</option>'+filtered.map(p=>`<option value="${p.id}">${esc(p.name)}</option>`).join(''); if(keep) els.project.value=String(keep); lockIfSingle(els.project); }
  async function loadOwners(){ const cid=els.client.value||''; const pid=els.project.value||''; els.owner.innerHTML='<option value="">A carregar...</option>'; if(els.sender) els.sender.innerHTML='<option value="">A carregar...</option>'; lockIfSingle(els.client); if(!pid){ els.owner.innerHTML='<option value="">Seleciona campanha</option>'; if(els.sender) els.sender.innerHTML='<option value="">Seleciona campanha</option>'; lockIfSingle(els.project); return; } const recipientUrl=new URL(ajax, window.location.origin); recipientUrl.searchParams.set('action','routespro_team_recipients'); if(cid) recipientUrl.searchParams.set('client_id',cid); if(pid) recipientUrl.searchParams.set('project_id',pid); const senderUrl=new URL(ajax, window.location.origin); senderUrl.searchParams.set('action','routespro_get_client_team_senders'); senderUrl.searchParams.set('_wpnonce', root.dataset.nonce||''); if(cid) senderUrl.searchParams.set('client_id',cid); if(pid) senderUrl.searchParams.set('project_id',pid); const [recipientRes,senderRes]=await Promise.all([fetch(recipientUrl.toString(),{credentials:'same-origin'}),fetch(senderUrl.toString(),{credentials:'same-origin'})]); const users=await recipientRes.json().catch(()=>[]); const senders=await senderRes.json().catch(()=>[]); const recipientOptions=Array.isArray(users)?users.map(u=>`<option value="${u.ID}">${esc(u.label||u.display_name||('#'+u.ID))}</option>`).join(''):''; const senderOptions=Array.isArray(senders)?senders.map(u=>`<option value="${u.ID}">${esc(u.label||u.display_name||('#'+u.ID))}</option>`).join(''):''; els.owner.innerHTML='<option value="">Selecionar operativo da campanha</option>'+recipientOptions; if(els.sender){ els.sender.innerHTML='<option value="">Selecionar utilizador cliente</option>'+senderOptions; } if(Array.isArray(users)&&users.length===1){ els.owner.value=String(users[0].ID||''); }
      const currentUserId=String(root.dataset.currentUser||'');
      if(els.sender && Array.isArray(senders)&&senders.length){
        const mine=senders.find(u=>String(u.ID||'')===currentUserId);
        if(mine){
          els.sender.value=currentUserId;
          setLocked(els.sender,true);
        }else if(senders.length===1){
          els.sender.value=String(senders[0].ID||'');
        }
      }
      if(els.sender && (!Array.isArray(senders) || !senders.length)){ els.sender.innerHTML='<option value="">Sem utilizador cliente disponível</option>'; }
      lockIfSingle(els.project);
      if(!(els.sender && String(els.sender.value||'')===currentUserId && currentUserId)){ lockIfSingle(els.sender); }
      lockIfSingle(els.owner);
      const hasRecipients=Array.isArray(users)&&users.length; const hasSenders=Array.isArray(senders)&&senders.length; setStatus(hasRecipients&&hasSenders?'Contactos filtrados pela campanha ativa.':(!hasRecipients?'Sem operativos disponíveis para esta campanha.':'Sem utilizador cliente disponível para esta campanha.'), hasRecipients&&hasSenders ? 'info' : 'error'); }
  function wireTemplates(){ root.querySelectorAll('[data-template]').forEach(btn=>btn.addEventListener('click',()=>{ const key=btn.dataset.template||'geral'; const tpl=templates[key]||templates.geral; els.kind.value=key; if(!els.subject.value) els.subject.value=tpl.subject; if(!els.message.value) els.message.value=tpl.body; })); }
  async function loadHistory(){ const base=root.closest('.rp-client-portal'); if(!base){ return; } const panel=base.querySelector('#rpcp-routes-table'); if(!panel) return; }
  async function sendMail(){ if(isSending){ return; } isSending=true; if(els.send){ els.send.disabled=true; els.send.style.opacity='.65'; } try{ const subjectSnapshot=els.subject.value||''; const messageSnapshot=els.message.value||''; const fd=new FormData(); fd.append('action','routespro_send_client_team_mail'); fd.append('_wpnonce', root.dataset.nonce||''); fd.append('client_id', els.client.value||''); fd.append('project_id', els.project.value||''); fd.append('sender_user_id', els.sender?.value||''); fd.append('recipient_user_id', els.owner.value||''); fd.append('message_kind', els.kind.value||'geral'); fd.append('priority', els.priority.value||'normal'); fd.append('subject', subjectSnapshot); fd.append('message', messageSnapshot); if(els.copy.checked) fd.append('send_copy','1'); setStatus('A enviar email...', 'info'); const res=await fetch(ajax,{method:'POST',credentials:'same-origin',body:fd}); const data=await res.json().catch(()=>({message:'Falha ao enviar.'})); if(!res.ok||!data.ok){ throw new Error(data.message||'Falha ao enviar.'); } renderLocalHistory(data.message||'Mensagem enviada com sucesso.', subjectSnapshot); setStatus(data.message||'Mensagem enviada.', 'success'); els.subject.value=''; els.message.value=''; } finally { isSending=false; if(els.send){ els.send.disabled=false; els.send.style.opacity=''; } } }
  function renderLocalHistory(note, subjectText){ const now=new Date(); const item=document.createElement('div'); item.className='rp-history-item'; item.innerHTML=`<div style="display:flex;justify-content:space-between;gap:10px;align-items:center"><strong>${esc(subjectText||els.subject.value||'Mensagem enviada')}</strong><span class="rp-pill">${esc(els.kind.options[els.kind.selectedIndex]?.text||'Geral')}</span></div><div style="margin-top:8px;color:#64748b">${esc(els.project.options[els.project.selectedIndex]?.text||'Sem campanha')} · De: ${esc(els.sender?.options[els.sender.selectedIndex]?.text||'Sem owner')} · Para: ${esc(els.owner.options[els.owner.selectedIndex]?.text||'Sem owner')} · ${now.toLocaleString()}</div><div style="margin-top:10px">${esc(note)}</div>`;
    if(els.history){ if(els.history.firstElementChild && els.history.firstElementChild.classList.contains('rp-note')) els.history.innerHTML=''; els.history.prepend(item); } }
  els.client?.addEventListener('change', async()=>{ syncProjects(''); await loadOwners(); });
  els.project?.addEventListener('change', loadOwners);
  els.clear?.addEventListener('click',()=>{ els.subject.value=''; els.message.value=''; setStatus('Formulário limpo.', 'info'); });
  els.send?.addEventListener('click', async()=>{ try{ await sendMail(); }catch(err){ setStatus(err.message||'Falha ao enviar email.', 'error'); } });
  wireTemplates();
  syncProjects(root.dataset.projectId||'');
  if(root.dataset.clientId && !els.client.value) els.client.value=root.dataset.clientId;
  if(root.dataset.projectId) els.project.value=root.dataset.projectId;
  lockIfSingle(els.client); lockIfSingle(els.project);
  loadOwners();
  document.addEventListener('routespro:scope-change', ev=>{ const d=ev.detail||{}; if(typeof d.client_id!=='undefined'){ els.client.value=String(d.client_id||''); } syncProjects(String(d.project_id||'')); if(typeof d.project_id!=='undefined'){ els.project.value=String(d.project_id||''); } loadOwners(); });
})();
</script>
<?php
        return ob_get_clean();
    }



    public static function team_inbox($atts = []) {
        $guard = self::front_guard();
        if ($guard) return $guard;
        $theme = self::front_theme();
        $scope = \RoutesPro\Support\Permissions::get_scope();
        $scope_client_ids = array_values(array_filter(array_map('absint', (array)($scope['client_ids'] ?? []))));
        $scope_project_ids = array_values(array_filter(array_map('absint', (array)($scope['project_ids'] ?? []))));
        $client_id = absint($atts['client_id'] ?? ($_GET['client_id'] ?? 0));
        $project_id = absint($atts['project_id'] ?? ($_GET['project_id'] ?? 0));
        if (!$client_id && count($scope_client_ids) === 1) $client_id = (int)$scope_client_ids[0];
        if (!$project_id && count($scope_project_ids) === 1) $project_id = (int)$scope_project_ids[0];
        $message_id = absint($_GET['routespro_message'] ?? 0);
        ob_start(); ?>
    <div class="rp-team-inbox" id="routespro-team-inbox"
         data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>"
         data-client-id="<?php echo esc_attr($client_id); ?>"
         data-project-id="<?php echo esc_attr($project_id); ?>"
         data-message-id="<?php echo esc_attr($message_id); ?>"
         data-scope='<?php echo esc_attr(wp_json_encode($scope)); ?>'>
<style>
.rp-team-inbox .rp-mail-toolbar{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}
.rp-team-inbox .rp-mail-toolbar select,.rp-team-inbox .rp-mail-toolbar button{width:100%;padding:11px 12px;border:1px solid #cbd5e1;border-radius:14px;background:#fff;color:#0f172a}
.rp-team-inbox .rp-mail-grid{display:grid;grid-template-columns:minmax(280px,360px) 1fr;gap:14px}
.rp-team-inbox .rp-mail-list{border:1px solid #e2e8f0;border-radius:20px;background:#fff;max-height:620px;overflow:auto}
.rp-team-inbox .rp-mail-item{display:block;width:100%;text-align:left;background:#fff;border:0;border-bottom:1px solid #eef2f7;padding:14px 16px;cursor:pointer}
.rp-team-inbox .rp-mail-item.active{background:#eff6ff}
.rp-team-inbox .rp-mail-item strong{display:block;color:#0f172a;margin-bottom:4px}
.rp-team-inbox .rp-mail-item small{display:block;color:#64748b}
.rp-team-inbox .rp-mail-detail{border:1px solid #e2e8f0;border-radius:20px;background:#fff;padding:18px;min-width:0}
.rp-team-inbox .rp-badges{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}
.rp-team-inbox .rp-badge{display:inline-flex;padding:6px 10px;border-radius:999px;background:#eff6ff;color:#0f172a;font-weight:700;font-size:12px;border:1px solid #dbeafe}
.rp-team-inbox .rp-body{padding:14px;border:1px solid #e5e7eb;border-radius:16px;background:#f8fafc;color:#0f172a;line-height:1.6;overflow-wrap:anywhere}
.rp-team-inbox .rp-body p:first-child{margin-top:0}
.rp-team-inbox .rp-body p:last-child{margin-bottom:0}
.rp-team-inbox textarea{width:100%;min-height:140px;padding:12px;border:1px solid #cbd5e1;border-radius:16px}
.rp-team-inbox .rp-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.rp-team-inbox .rp-cta,.rp-team-inbox .rp-cta:visited{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:14px;padding:12px 16px;font-weight:700;cursor:pointer;transition:all .18s ease;border:1px solid <?php echo esc_attr($theme['primary']); ?>;background:<?php echo esc_attr($theme['primary']); ?>;color:#fff !important;text-decoration:none !important;-webkit-appearance:none;appearance:none}
.rp-team-inbox .rp-cta.alt,.rp-team-inbox .rp-cta.alt:visited{background:<?php echo esc_attr($theme['accent']); ?>;border-color:<?php echo esc_attr($theme['accent']); ?>;color:#fff !important}
.rp-team-inbox .rp-cta.ghost,.rp-team-inbox .rp-cta.ghost:visited{background:#fff;border-color:#cbd5e1;color:#0f172a !important}
.rp-team-inbox .rp-cta:hover,.rp-team-inbox .rp-cta:focus,.rp-team-inbox .rp-cta:active{background:<?php echo esc_attr($theme['primary']); ?> !important;border-color:<?php echo esc_attr($theme['primary']); ?> !important;color:#fff !important;text-decoration:none !important;outline:none;box-shadow:0 0 0 3px rgba(15,23,42,.10)}
.rp-team-inbox .rp-cta.alt:hover,.rp-team-inbox .rp-cta.alt:focus,.rp-team-inbox .rp-cta.alt:active{background:<?php echo esc_attr($theme['accent']); ?> !important;border-color:<?php echo esc_attr($theme['accent']); ?> !important;color:#fff !important}
.rp-team-inbox .rp-cta.ghost:hover,.rp-team-inbox .rp-cta.ghost:focus,.rp-team-inbox .rp-cta.ghost:active{background:#f1f5f9 !important;border-color:#cbd5e1 !important;color:#0f172a !important}
.rp-team-inbox .rp-status{margin-top:12px;padding:12px 14px;border-radius:14px;border:1px solid #cbd5e1;background:#f8fafc;color:#0f172a;font-weight:700;min-height:48px;display:flex;align-items:center}
.rp-team-inbox .rp-status.is-success{background:#ecfdf5;border-color:#86efac;color:#166534}
.rp-team-inbox .rp-status.is-error{background:#fef2f2;border-color:#fca5a5;color:#991b1b}
.rp-team-inbox .rp-status.is-info{background:#eff6ff;border-color:#93c5fd;color:#1d4ed8}
.rp-team-inbox select.ff-locked{background:#f8fafc!important;color:#475569!important;cursor:not-allowed!important;opacity:1!important}
@media (max-width:900px){.rp-team-inbox .rp-mail-grid,.rp-team-inbox .rp-mail-toolbar{grid-template-columns:1fr}}
</style>
      <div class="rp-mail-toolbar">
        <select id="rpti-client"><option value="">Cliente</option></select>
        <select id="rpti-project"><option value="">Campanha</option></select>
        <select id="rpti-filter-status" autocomplete="off"><option value="">Todos os estados</option><option value="novo">Novo</option><option value="em_analise">Em análise</option><option value="respondido">Respondido</option><option value="concluido">Concluído</option></select>
        <button type="button" class="rp-cta" id="rpti-refresh">Atualizar</button>
      </div>
      <div class="rp-mail-grid">
        <div class="rp-mail-list" id="rpti-list"><div style="padding:16px;color:#64748b">A carregar mensagens...</div></div>
        <div class="rp-mail-detail" id="rpti-detail"><div style="color:#64748b">Seleciona uma mensagem para abrir o detalhe.</div></div>
      </div>
      <div class="rp-status" id="rpti-status-note"></div>
    </div>
<script>
(function(){
  const root=document.getElementById('routespro-team-inbox');
  if(!root || root.dataset.rpInit==='1') return;
  root.dataset.rpInit='1';

  const ajax='<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
  const nonce=root.dataset.nonce||'';
  const scope=JSON.parse(root.dataset.scope||'{}');
  const initialClientId=String(root.dataset.clientId||'');
  const initialProjectId=String(root.dataset.projectId||'');
  const initialMessageId=String(root.dataset.messageId||'');
  const isAppContext=!!root.closest('.rp-app');

  const els={
    client:root.querySelector('#rpti-client'),
    project:root.querySelector('#rpti-project'),
    status:root.querySelector('#rpti-filter-status'),
    refresh:root.querySelector('#rpti-refresh'),
    list:root.querySelector('#rpti-list'),
    detail:root.querySelector('#rpti-detail'),
    note:root.querySelector('#rpti-status-note')
  };

  const state={items:[],selected:null,statusTouched:false,lastNote:{msg:'',type:''}};
  const esc=s=>(s==null?'':String(s)).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
  const allowedClientIds=()=>Array.isArray(scope?.client_ids)?scope.client_ids.map(v=>String(parseInt(v,10))).filter(Boolean):[];
  const allowedProjectIds=()=>Array.isArray(scope?.project_ids)?scope.project_ids.map(v=>String(parseInt(v,10))).filter(Boolean):[];

  function setNote(msg,type,remember=true){
    if(!els.note) return;
    els.note.textContent = msg || '';
    els.note.classList.remove('is-success','is-error','is-info');
    if(type==='success') els.note.classList.add('is-success');
    else if(type==='error') els.note.classList.add('is-error');
    else if(type==='info') els.note.classList.add('is-info');
    if(remember) state.lastNote={msg:msg||'',type:type||''};
  }

  const setLockedField=(el,locked)=>{ if(!el) return; el.disabled=!!locked; el.classList.toggle('ff-locked', !!locked); };
  const maybeLockSingleSelect=(el)=>{ if(!el) return false; const choices=Array.from(el.options||[]).filter(o=>String(o.value||'')!==''); const shouldLock=choices.length===1; if(shouldLock) el.value=String(choices[0].value||''); setLockedField(el, shouldLock); return shouldLock; };

  async function req(action, params={}, method='GET'){
    try{
      if(method==='GET'){
        const q=new URLSearchParams(Object.assign({action,_wpnonce:nonce},params));
        const res=await fetch(ajax+'?'+q.toString(),{credentials:'same-origin'});
        const text=await res.text();
        let data={};
        try{ data=text?JSON.parse(text):{}; }catch(_){ data={message:text}; }
        if(!res.ok) throw new Error(data.message||'Erro no pedido');
        return data;
      }
      const fd=new FormData();
      fd.append('action',action);
      fd.append('_wpnonce',nonce);
      Object.keys(params).forEach(k=>fd.append(k, params[k] ?? ''));
      const res=await fetch(ajax,{method:'POST',credentials:'same-origin',body:fd});
      const text=await res.text();
      let data={};
      try{ data=text?JSON.parse(text):{}; }catch(_){ data={message:text}; }
      if(!res.ok) throw new Error(data.message||'Erro no pedido');
      return data;
    }catch(e){
      console.error('RoutesPro AJAX error:', e);
      return {error:true,message:e.message||'Erro de comunicação com o servidor.'};
    }
  }

  function senderLabel(item){
    return item.sender_name || item?.meta?.selected_sender_name || item?.meta?.sender_name || item.sender_email || 'Sistema';
  }

  function prettyDate(value){
    if(!value) return '';
    const d=new Date(value.replace(' ', 'T'));
    if(Number.isNaN(d.getTime())) return String(value);
    return d.toLocaleString('pt-PT');
  }

  function normalizeBody(body){
    const raw=String(body||'').trim();
    if(!raw) return '<p>Sem conteúdo disponível.</p>';
    if(raw.indexOf('<')===-1){
      return '<p>'+esc(raw).replace(/\n+/g,'</p><p>')+'</p>';
    }
    try{
      const parser=new DOMParser();
      const doc=parser.parseFromString(raw,'text/html');
      const selectors=[
        'div[style*="padding:14px;border:1px solid #e5e7eb"]',
        'div[style*="padding:14px;border:1px solid #e5e7eb;border-radius:14px;background:#f8fafc"]',
        'div[style*="padding:14px;border:1px solid #e5e7eb;border-radius:16px;background:#f8fafc"]',
        'div[style*="background:#f8fafc"]'
      ];
      let node=null;
      selectors.some(sel=>{ node=doc.querySelector(sel); return !!node; });
      if(!node){
        const bodyNode=doc.body || doc.documentElement;
        if(bodyNode){
          bodyNode.querySelectorAll('script,style,title,meta,link,img').forEach(el=>el.remove());
          bodyNode.querySelectorAll('h1,h2').forEach(el=>{ if((el.textContent||'').trim().toLowerCase()===(state.selected?.subject||'').trim().toLowerCase()) el.remove(); });
          node=bodyNode;
        }
      }
      const html=(node?.innerHTML||'').trim();
      return html || '<p>Sem conteúdo disponível.</p>';
    }catch(_){
      return raw;
    }
  }

  function renderList(){
    if(!state.items.length){
      els.list.innerHTML='<div style="padding:16px;color:#64748b">Sem mensagens no contexto atual.</div>';
      els.detail.innerHTML='<div style="color:#64748b">Sem detalhe disponível.</div>';
      return;
    }

    els.list.innerHTML=state.items.map(item=>`
      <button type="button" class="rp-mail-item ${String(state.selected?.id||'')===String(item.id)?'active':''}" data-id="${item.id}">
        <strong>${esc(item.subject||'Mensagem')}</strong>
        <small>${esc(senderLabel(item))} · ${esc(item.workflow_status||'novo')}</small>
        <small>${esc(item.client_name||'Cliente')} · ${esc(item.project_name||'Campanha')} · ${esc(prettyDate(item.created_at||''))}</small>
      </button>
    `).join('');

    els.list.querySelectorAll('[data-id]').forEach(btn=>{
      btn.addEventListener('click',()=>{
        state.selected=state.items.find(x=>String(x.id)===String(btn.dataset.id)) || null;
        renderDetail();
        renderList();
      });
    });

    if(!state.selected){
      state.selected=state.items.find(x=>String(x.id)===initialMessageId) || state.items[0] || null;
      renderDetail();
      renderList();
    }
  }

  function renderDetail(){
    const item=state.selected;
    if(!item){
      els.detail.innerHTML='<div style="color:#64748b">Seleciona uma mensagem para abrir o detalhe.</div>';
      return;
    }

    const bodyHtml=normalizeBody(item.body||'');
    const replyPrefill=esc(item?.meta?.last_reply_excerpt || '');

    els.detail.innerHTML=`
      <div class="rp-kicker">Mensagem #${esc(item.id||'')}</div>
      <h3 style="margin:0 0 8px;color:#0f172a">${esc(item.subject||'Mensagem')}</h3>
      <div class="rp-badges">
        <span class="rp-badge">${esc(item.workflow_status||'novo')}</span>
        <span class="rp-badge">${esc(item.message_kind||'geral')}</span>
        <span class="rp-badge">${esc(item.project_name||'Sem campanha')}</span>
      </div>
      <p style="margin:0 0 10px;color:#64748b">De ${esc(senderLabel(item))} para ${esc(item.recipient_user_name||item.recipient_name||item.recipient_email||'equipa')} · ${esc(prettyDate(item.created_at||''))}</p>
      <div class="rp-body">${bodyHtml}</div>
      <div class="rp-actions">
        <button type="button" class="rp-cta ghost" data-status="em_analise">Marcar em análise</button>
        <button type="button" class="rp-cta alt" data-status="concluido">Marcar concluído</button>
        ${item.app_url?`<a class="rp-cta ghost" href="${item.app_url}" target="_blank" rel="noopener">Abrir link</a>`:''}
      </div>
      <div style="margin-top:16px">
        <label style="display:block;margin-bottom:8px;font-weight:700">Responder</label>
        <textarea id="rpti-reply" placeholder="Escreve uma resposta operacional...">${replyPrefill}</textarea>
        <div class="rp-actions">
          <button type="button" class="rp-cta" id="rpti-send-reply">Enviar resposta</button>
        </div>
      </div>
    `;

    els.detail.querySelectorAll('[data-status]').forEach(btn=>btn.addEventListener('click',()=>changeStatus(btn.dataset.status)));
    const replyBtn=els.detail.querySelector('#rpti-send-reply');
    if(replyBtn) replyBtn.addEventListener('click',sendReply);
  }

  function enforceScopeLocks(){
    const allowedClients=allowedClientIds();
    const allowedProjects=allowedProjectIds();
    if(isAppContext && !scope?.is_manager){
      if(els.client){
        if(!els.client.value && initialClientId && allowedClients.includes(initialClientId)) els.client.value=initialClientId;
        if(!els.client.value && allowedClients.length===1) els.client.value=allowedClients[0];
        setLockedField(els.client, allowedClients.length <= 1 && allowedClients.length > 0);
      }
      if(els.project){
        if(!els.project.value && initialProjectId && allowedProjects.includes(initialProjectId) && Array.from(els.project.options).some(o=>String(o.value)===initialProjectId)) els.project.value=initialProjectId;
        if(!els.project.value && allowedProjects.length===1 && Array.from(els.project.options).some(o=>String(o.value)===allowedProjects[0])) els.project.value=allowedProjects[0];
        setLockedField(els.project, allowedProjects.length <= 1 && allowedProjects.length > 0);
      }
      return;
    }
    maybeLockSingleSelect(els.client);
    maybeLockSingleSelect(els.project);
  }

  async function loadClients(){
    const data=await req('routespro_front_clients');
    const rows=Array.isArray(data)?data:[];
    const current=String(els.client.value||initialClientId||'');
    els.client.innerHTML='<option value="">Cliente</option>' + rows.map(c=>`<option value="${c.id}">${esc(c.name)}</option>`).join('');
    if(current && Array.from(els.client.options).some(o=>String(o.value)===current)) els.client.value=current;
    enforceScopeLocks();
  }

  async function loadProjects(){
    const data=await req('routespro_front_projects',{client_id:els.client.value||''});
    const rows=Array.isArray(data)?data:[];
    const current=String(els.project.value||initialProjectId||'');
    els.project.innerHTML='<option value="">Campanha</option>' + rows.map(p=>`<option value="${p.id}">${esc(p.name)}</option>`).join('');
    if(current && Array.from(els.project.options).some(o=>String(o.value)===current)) els.project.value=current;
    enforceScopeLocks();
  }

  async function loadItems(preserveNote){
    if(!preserveNote) setNote('A carregar mensagens...', 'info');

    const selectedId=String(state.selected?.id || initialMessageId || '');
    const statusValue=(state.statusTouched ? (els.status.value||'') : '');
    const data=await req('routespro_get_team_messages',{
      client_id:els.client.value||'',
      project_id:els.project.value||'',
      status:statusValue,
      message_id:initialMessageId||''
    });

    if(data.error){
      setNote(data.message||'Erro ao carregar mensagens.', 'error');
      return;
    }

    state.items=Array.isArray(data.items)?data.items:[];
    state.selected=state.items.find(x=>String(x.id)===selectedId) || state.items.find(x=>String(x.id)===String(initialMessageId)) || state.items[0] || null;
    renderList();
    renderDetail();

    if(preserveNote && state.lastNote.msg){
      setNote(state.lastNote.msg, state.lastNote.type, false);
      return;
    }

    if(state.items.length){
      setNote('Mensagens carregadas.', 'success');
    }else{
      setNote(data.message || 'Sem mensagens no contexto atual.', 'info');
    }
  }

  async function changeStatus(status){
    if(!state.selected) return;
    setNote('A atualizar estado...', 'info');
    const data=await req('routespro_update_team_message',{
      log_id:state.selected.id,
      message_action:'status',
      status
    },'POST');
    if(data.error){
      setNote(data.message||'Erro ao atualizar estado.', 'error');
      return;
    }
    setNote(data.message || 'Estado atualizado.', 'success');
    await loadItems(true);
  }

  async function sendReply(){
    if(!state.selected) return;
    const textarea=els.detail.querySelector('#rpti-reply');
    const msg=(textarea?.value||'').trim();
    if(!msg){
      setNote('Escreve uma mensagem antes de enviar.', 'error');
      return;
    }
    setNote('A enviar resposta...', 'info');
    const data=await req('routespro_update_team_message',{
      log_id:state.selected.id,
      message_action:'reply',
      reply_message:msg
    },'POST');
    if(data.error || !data.ok){
      setNote(data.message||'Erro ao enviar resposta.', 'error');
      return;
    }
    if(textarea) textarea.value='';
    setNote(data.message||'Resposta enviada com sucesso.', 'success');
    await loadItems(true);
  }

  [els.client,els.project,els.status].forEach(el=>el&&el.addEventListener('change',async()=>{
    if(el===els.status) state.statusTouched=true;
    if(el===els.client) await loadProjects();
    await loadItems();
  }));

  if(els.refresh) els.refresh.addEventListener('click',()=>loadItems());

  document.addEventListener('routespro:scope-change',async ev=>{
    const d=ev.detail||{};
    if(typeof d.client_id!=='undefined') els.client.value=String(d.client_id||'');
    await loadProjects();
    if(typeof d.project_id!=='undefined' && Array.from(els.project.options).some(o=>String(o.value)===String(d.project_id||''))){
      els.project.value=String(d.project_id||'');
    }
    if(typeof d.message_id!=='undefined' && d.message_id){
      root.dataset.messageId=String(d.message_id);
    }
    enforceScopeLocks();
    await loadItems();
  });

  loadClients().then(loadProjects).then(async()=>{
    if(els.status){ els.status.value=''; try{ els.status.selectedIndex=0; }catch(_){ } }
    enforceScopeLocks();
    await loadItems();
  });
})();
</script>
    <?php
        return ob_get_clean();
    }

    public static function client_portal($atts = []) {
        $guard = self::front_guard();
        if ($guard) return $guard;
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $theme = self::front_theme();
        $client_id = absint($atts['client_id'] ?? ($_GET['client_id'] ?? 0));
        $project_id = absint($atts['project_id'] ?? ($_GET['project_id'] ?? 0));
        $nonce = wp_create_nonce('wp_rest');
        $export_nonce = wp_create_nonce('routespro_export_submissions');
        $can_manage = current_user_can('routespro_manage');
        $can_scope = \RoutesPro\Support\Permissions::can_access_front();
        $clients = \RoutesPro\Support\Permissions::filter_clients($wpdb->get_results("SELECT id,name FROM {$px}clients ORDER BY name ASC", ARRAY_A) ?: []);
        $projects = \RoutesPro\Support\Permissions::filter_projects($wpdb->get_results("SELECT id,name,client_id FROM {$px}projects ORDER BY name ASC", ARRAY_A) ?: []);
        ob_start(); ?>
<style>
.rp-client-premium{--rp-primary:<?php echo esc_attr($theme['primary']); ?>;--rp-accent:<?php echo esc_attr($theme['accent']); ?>;font-family:<?php echo esc_attr($theme['font']); ?>;font-size:<?php echo esc_attr($theme['size']); ?>px;color:#0f172a}
.rp-client-premium *{box-sizing:border-box}.rp-client-premium .rp-shell{max-width:min(1540px,96vw);margin:0 auto;padding:18px 16px 104px}
.rp-client-premium .rp-hero{position:relative;overflow:hidden;background:radial-gradient(circle at top right,rgba(255,255,255,.16),transparent 26%),linear-gradient(135deg,#081120 0%,#162338 52%,<?php echo esc_attr($theme['primary']); ?> 120%);color:#fff;border-radius:30px;padding:28px;box-shadow:0 28px 70px rgba(15,23,42,.2)}
.rp-client-premium .rp-hero-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:18px;align-items:end}
.rp-client-premium .rp-eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:.09em;opacity:.82;margin-bottom:8px}
.rp-client-premium .rp-hero h2{margin:0 0 10px;font-size:36px;line-height:1.02;font-weight:800;color:#fff}
.rp-client-premium .rp-hero p{margin:0;max-width:760px;opacity:.92;font-size:16px;line-height:1.6;color:#fff}
.rp-client-premium .rp-card{background:#fff;border:1px solid #e2e8f0;border-radius:24px;box-shadow:0 16px 40px rgba(15,23,42,.08);padding:20px}
.rp-client-premium .rp-kicker{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:8px}
.rp-client-premium .rp-cta{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:14px;padding:12px 16px;background:var(--rp-primary);color:#fff !important;font-weight:700;text-decoration:none;cursor:pointer}
.rp-client-premium .rp-cta.alt{background:var(--rp-accent)}
.rp-client-premium .rp-cta.ghost{background:#fff;color:#0f172a !important;border:1px solid #cbd5e1}
.rp-client-premium .rp-mini{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}.rp-client-premium .rp-mini span{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.12);color:#fff;border-radius:999px;padding:8px 12px;font-weight:700;border:1px solid rgba(255,255,255,.14)}
.rp-client-premium .rp-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:18px}
.rp-client-premium .rp-metric{background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.14);border-radius:20px;padding:16px;min-height:104px}.rp-client-premium .rp-metric span,.rp-client-premium .rp-metric small{color:rgba(255,255,255,.8)}.rp-client-premium .rp-metric strong{display:block;font-size:26px;line-height:1.05;margin:6px 0;color:#fff}
.rp-client-premium .rp-toolbar{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-top:16px}.rp-client-premium .rp-toolbar .full{grid-column:1/-1}
.rp-client-premium select,.rp-client-premium input,.rp-client-premium textarea{width:100%;border:1px solid #cbd5e1;border-radius:14px;padding:12px;background:#fff}
.rp-client-premium .rp-panels{margin-top:18px}.rp-client-premium .rp-panel{display:none}.rp-client-premium .rp-panel.active{display:block}
.rp-client-premium .rp-panel-card{background:#fff;border:1px solid #e2e8f0;border-radius:26px;box-shadow:0 16px 40px rgba(15,23,42,.08);padding:18px}
.rp-client-premium .rp-panel-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap;margin-bottom:14px}.rp-client-premium .rp-panel-head h3{margin:0;font-size:24px}.rp-client-premium .rp-panel-head p{margin:6px 0 0;color:#64748b;max-width:760px}
.rp-client-premium .rp-pill{display:inline-flex;align-items:center;gap:8px;border-radius:999px;background:#eff6ff;color:#0f172a;padding:7px 12px;font-size:12px;font-weight:700;border:1px solid #dbeafe}
.rp-client-premium .rp-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.rp-client-premium .rp-kpi{background:#f8fafc;border:1px solid #e2e8f0;border-radius:18px;padding:14px}.rp-client-premium .rp-kpi span{display:block;color:#64748b;font-size:12px;text-transform:uppercase;letter-spacing:.06em}.rp-client-premium .rp-kpi strong{display:block;font-size:28px;line-height:1.02;margin-top:6px}
.rp-client-premium .rp-two{display:grid;grid-template-columns:minmax(340px,.9fr) minmax(0,1.1fr);gap:16px;margin-top:16px;align-items:start}
.rp-client-premium .rp-subcard{background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:16px;min-width:0}
.rp-client-premium .rp-chart{min-width:0;overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch}
.rp-client-premium .rp-chart svg{display:block;height:260px;min-width:720px;width:max-content;max-width:none}.rp-client-premium .rp-chart-empty{padding:26px;border:1px dashed #cbd5e1;border-radius:16px;color:#64748b;background:#f8fafc}
.rp-client-premium .rp-table-wrap{display:block;width:100%;max-width:100%;overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch}.rp-client-premium .rp-table-wrap > table{width:max-content;min-width:1100px;border-collapse:collapse}.rp-client-premium table{border-collapse:collapse}.rp-client-premium .rp-report-table{min-width:1100px}.rp-client-premium #rpcp-overview .rp-table-wrap table{min-width:1100px}.rp-client-premium #rpcp-analytics-panel .rp-table-wrap table{min-width:1100px}.rp-client-premium th,.rp-client-premium td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left;font-size:14px;vertical-align:top;white-space:nowrap}.rp-client-premium th{color:#64748b;font-size:12px;text-transform:uppercase;letter-spacing:.06em}
.rp-client-premium .rp-route-layout{display:grid;grid-template-columns:minmax(360px,420px) minmax(0,1fr);gap:18px;align-items:start}.rp-client-premium .rp-route-list{max-height:560px;overflow:auto;padding-right:4px}.rp-client-premium #rpcp-route-detail{min-width:0}.rp-client-premium #rpcp-route-detail .rp-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.rp-client-premium #rpcp-route-detail .rp-row{display:grid !important;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;align-items:stretch}.rp-client-premium #rpcp-route-detail .rp-row > *{min-width:0;width:100%}.rp-client-premium #rpcp-route-detail .rp-row > select:first-child{grid-column:1/-1}.rp-client-premium #rpcp-route-detail .rp-row > button{white-space:normal;min-height:46px}
.rp-client-premium .rp-route-item{border:1px solid #e2e8f0;border-radius:18px;padding:14px;background:#fff;margin-bottom:10px;cursor:pointer;transition:.18s ease}.rp-client-premium .rp-route-item.active{border-color:var(--rp-primary);box-shadow:0 12px 24px rgba(15,23,42,.08);transform:translateY(-1px)}
.rp-client-premium .rp-route-item h4{margin:0 0 6px;font-size:16px}.rp-client-premium .rp-meta{color:#64748b;font-size:13px}.rp-client-premium .rp-stop-list{display:flex;flex-direction:column;gap:10px;margin-top:14px}
.rp-client-premium .rp-stop{display:grid;grid-template-columns:48px minmax(0,1fr);gap:12px;align-items:start;border:1px solid #e2e8f0;border-radius:18px;padding:14px;background:#fff}
.rp-client-premium .rp-stop-no{display:flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:14px;background:#eff6ff;font-weight:800;color:#0f172a}
.rp-client-premium .rp-stop-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-start;align-items:center;margin-top:12px}.rp-client-premium .rp-stop-actions .rp-small{min-width:44px}.rp-client-premium .rp-small{padding:8px 12px;border-radius:12px;border:1px solid #cbd5e1;background:#fff;color:#0f172a;cursor:pointer;font-weight:700}.rp-client-premium .rp-small.primary{background:var(--rp-primary);border-color:var(--rp-primary);color:#fff}.rp-client-premium .rp-small.alt{background:var(--rp-accent);border-color:var(--rp-accent);color:#fff}
.rp-client-premium .rp-badge{display:inline-flex;align-items:center;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:700;background:#f8fafc;border:1px solid #e2e8f0}.rp-client-premium .rp-badge.done{background:#e6ffed;border-color:#b7f5c5}.rp-client-premium .rp-badge.failed{background:#ffeaea;border-color:#ffc4c4}.rp-client-premium .rp-badge.in_progress{background:#fff7dd;border-color:#fde68a}
.rp-client-premium .rp-statusbox{margin-top:12px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:16px;padding:12px;color:#334155}
.rp-client-premium .rp-tabs{position:sticky;bottom:14px;z-index:25;margin-top:18px}.rp-client-premium .rp-tabbar{display:flex;gap:8px;background:rgba(15,23,42,.94);padding:10px;border-radius:22px;box-shadow:0 20px 48px rgba(15,23,42,.3);overflow:auto}.rp-client-premium .rp-tabbar button{flex:1 1 0;min-width:118px;border:0;border-radius:16px;padding:12px 10px;background:transparent;color:#cbd5e1;font-weight:700;cursor:pointer}.rp-client-premium .rp-tabbar button.active{background:#fff;color:#0f172a}
.rp-client-premium .rp-panel[data-panel="commercial"] .rp-front-commercial .rp-card,.rp-client-premium .rp-panel[data-panel="analytics"] .routespro-dashboard .card{box-shadow:none}
@media (max-width:1280px){.rp-client-premium .rp-route-layout{grid-template-columns:1fr}.rp-client-premium #rpcp-route-detail .rp-row{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:640px){.rp-client-premium #rpcp-route-detail .rp-row{grid-template-columns:1fr}}
@media (max-width:1180px){.rp-client-premium .rp-hero-grid,.rp-client-premium .rp-metrics,.rp-client-premium .rp-toolbar,.rp-client-premium .rp-kpi-grid,.rp-client-premium .rp-two,.rp-client-premium .rp-route-layout{grid-template-columns:1fr 1fr}}
@media (max-width:860px){.rp-client-premium .rp-shell{padding:14px 12px 108px}.rp-client-premium .rp-hero h2{font-size:28px}.rp-client-premium .rp-hero-grid,.rp-client-premium .rp-metrics,.rp-client-premium .rp-toolbar,.rp-client-premium .rp-kpi-grid,.rp-client-premium .rp-two,.rp-client-premium .rp-route-layout{grid-template-columns:1fr}.rp-client-premium .rp-stop{grid-template-columns:1fr}.rp-client-premium .rp-stop-no{width:40px;height:40px}.rp-client-premium .rp-stop-actions{justify-content:flex-start}}
</style>
<div class="rp-client-premium rp-client-portal" id="routespro-client-portal" data-nonce="<?php echo esc_attr($nonce); ?>" data-export-nonce="<?php echo esc_attr($export_nonce); ?>" data-projects='<?php echo esc_attr(wp_json_encode($projects)); ?>' data-client-id="<?php echo esc_attr($client_id); ?>" data-project-id="<?php echo esc_attr($project_id); ?>" data-can-manage="<?php echo ($can_manage || $can_scope) ? '1' : '0'; ?>">
  <div class="rp-shell">
    <section class="rp-hero">
      <div class="rp-hero-grid">
        <div>
          <div class="rp-eyebrow">FieldFlow Client Portal</div>
          <h2>Portal premium, com o mesmo ADN visual do app, mas com foco em gestão, edição e leitura executiva.</h2>
          <p>Este shortcode fica alinhado com o backoffice, especialmente Rotas e Base Comercial. Permite ver métricas, editar rotas, analisar relatórios e trabalhar a base sem sair do portal.</p>
          <div class="rp-mini"><span>Rotas editáveis</span><span>Relatórios organizados</span><span>Tabelas e gráficos premium</span></div>
        </div>
        <div class="rp-card" style="background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.18);color:#fff">
          <div class="rp-kicker" style="color:rgba(255,255,255,.75)">Escopo atual</div>
          <div style="font-size:20px;font-weight:800;margin-bottom:6px;color:#fff"><?php echo $can_manage ? 'Gestão total' : 'Consulta operacional'; ?></div>
          <div style="opacity:.85;margin-bottom:16px;color:#fff">Cliente, campanha, rotas, relatórios e base comercial.</div>
          <div class="rp-mini" style="margin-top:0"><a class="rp-cta" href="#rpcp-overview">Resumo</a><a class="rp-cta alt" href="#rpcp-routes-panel">Rotas</a></div>
        </div>
      </div>
      <div class="rp-metrics">
        <div class="rp-metric"><span>Escopo</span><strong id="rpcp-hero-scope">A carregar</strong><small id="rpcp-hero-scope-note">Cliente e campanha ativos no portal.</small></div>
        <div class="rp-metric"><span>Base comercial</span><strong id="rpcp-hero-commercial">...</strong><small id="rpcp-hero-commercial-note">PDVs visíveis no âmbito atual.</small></div>
        <div class="rp-metric"><span>Validação</span><strong id="rpcp-hero-validation">...</strong><small id="rpcp-hero-validation-note">Coordenadas e qualidade da base.</small></div>
        <div class="rp-metric"><span>Período</span><strong id="rpcp-hero-period">...</strong><small id="rpcp-hero-period-note">Janela temporal do resumo executivo.</small></div>
      </div>
      <div class="rp-toolbar">
        <?php if ($can_manage): ?>
          <select id="rpcp-client"><option value="">Cliente</option><?php foreach($clients as $c): ?><option value="<?php echo intval($c['id']); ?>" <?php selected($client_id, intval($c['id'])); ?>><?php echo esc_html($c['name']); ?></option><?php endforeach; ?></select>
          <select id="rpcp-project"><option value="">Campanha</option></select>
        <?php else: ?>
          <input type="hidden" id="rpcp-client" value="<?php echo esc_attr($client_id); ?>">
          <input type="hidden" id="rpcp-project" value="<?php echo esc_attr($project_id); ?>">
          <input type="text" value="Consulta do âmbito atual" readonly>
          <input type="text" value="Filtros herdados do shortcode" readonly>
        <?php endif; ?>
        <input type="date" id="rpcp-from" value="<?php echo esc_attr(date('Y-m-01')); ?>">
        <input type="date" id="rpcp-to" value="<?php echo esc_attr(date('Y-m-d')); ?>">
        <button type="button" class="rp-cta" id="rpcp-refresh">Atualizar portal</button>
      </div>
    </section>

    <div class="rp-panels">
      <div class="rp-panel active" data-panel="overview" id="rpcp-overview">
        <div class="rp-panel-card">
          <div class="rp-panel-head"><div><h3>Resumo executivo</h3><p>KPIs, tendência diária e quadro consolidado para leitura rápida de execução.</p></div><span class="rp-pill">Visão geral</span></div>
          <div class="rp-kpi-grid" id="rpcp-kpis"></div>
          <div class="rp-kpi-grid" id="rpcp-commercial-kpis" style="margin-top:12px"></div>
          <div class="rp-two">
            <div class="rp-subcard rp-chart"><h4 style="margin:0 0 10px">Execução por dia</h4><div id="rpcp-chart"></div></div>
            <div class="rp-subcard"><h4 style="margin:0 0 10px">Relatório consolidado</h4><div class="rp-table-wrap"><table><thead><tr><th>Data</th><th>Campanha</th><th>Rota</th><th>Status</th><th>Paragens</th><th>Concluídas</th><th>% Execução</th></tr></thead><tbody id="rpcp-routes-table"></tbody></table></div></div>
          </div>
        </div>
      </div>

      <div class="rp-panel" data-panel="routes" id="rpcp-routes-panel">
        <div class="rp-panel-card">
          <div class="rp-panel-head"><div><h3>Editor de rotas</h3><p>Seleciona uma rota para reordenar paragens, atualizar estados, abrir no Maps e rever detalhe operacional.</p></div><span class="rp-pill">Editar rota</span></div>
          <div class="rp-route-layout">
            <div class="rp-subcard">
              <h4 style="margin:0 0 10px">Rotas no período</h4>
              <div class="rp-route-list" id="rpcp-route-list"></div>
            </div>
            <div class="rp-subcard">
              <div id="rpcp-route-detail"><div class="rp-chart-empty">Seleciona uma rota para ver e editar o detalhe.</div></div>
              <div class="rp-statusbox" id="rpcp-status">Portal pronto. Escolhe filtros e carrega uma rota.</div>
            </div>
          </div>
        </div>
      </div>

      <div class="rp-panel" data-panel="reports" id="rpcp-reports-panel">
        <div class="rp-panel-card">
          <div class="rp-panel-head"><div><h3>Relatórios e evidências</h3><p>Tabela premium com contexto operacional e colunas dinâmicas das perguntas. Quando houver muita informação, o scroll horizontal entra em ação sem partir desktop, tablet ou mobile.</p></div><div class="routespro-exports-wrapper" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;position:relative;z-index:9999"><span class="rp-pill">Relatórios</span><a class="rp-small primary routespro-export-btn" id="rpcp-export-csv" href="#" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;justify-content:center;background:<?php echo esc_attr($theme['primary']); ?> !important;color:#ffffff !important;border:1px solid <?php echo esc_attr($theme['primary']); ?> !important;opacity:1 !important;pointer-events:auto !important;cursor:pointer !important;position:relative;z-index:9999;text-decoration:none">Exportar CSV</a><a class="rp-small routespro-export-btn" id="rpcp-export-xls" href="#" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;justify-content:center;background:#ffffff !important;color:#0f172a !important;border:1px solid #cbd5e1 !important;opacity:1 !important;pointer-events:auto !important;cursor:pointer !important;position:relative;z-index:9999;text-decoration:none">Exportar Excel</a><a class="rp-small alt routespro-export-btn" id="rpcp-export-pdf" href="#" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;justify-content:center;background:<?php echo esc_attr($theme['accent']); ?> !important;color:#ffffff !important;border:1px solid <?php echo esc_attr($theme['accent']); ?> !important;opacity:1 !important;pointer-events:auto !important;cursor:pointer !important;position:relative;z-index:9999;text-decoration:none">Exportar PDF</a></div></div>
          <div class="rp-table-wrap"><table class="rp-report-table"><thead id="rpcp-report-head"></thead><tbody id="rpcp-report-table"></tbody></table></div>
        </div>
      </div>

      <div class="rp-panel" data-panel="commercial" id="rpcp-commercial-panel">
        <div class="rp-panel-card">
          <div class="rp-panel-head"><div><h3>Base comercial</h3><p>Mesmo núcleo premium do routespro_app, com pesquisa, autocomplete e manutenção da base comercial.</p></div><span class="rp-pill">Base comercial</span></div>
          <?php echo do_shortcode('[fieldflow_front_commercial]'); ?>
        </div>
      </div>

      <div class="rp-panel" data-panel="team" id="rpcp-team-panel">
        <div class="rp-panel-card">
          <div class="rp-panel-head"><div><h3>Equipa e contacto operacional</h3><p>Canal premium para o cliente enviar medidas corretivas, preventivas ou informação geral ao owner certo da campanha.</p></div><span class="rp-pill">Equipa</span></div>
          <?php echo do_shortcode('[fieldflow_client_team_mail]'); ?>
        </div>
      </div>

      <div class="rp-panel" data-panel="analytics" id="rpcp-analytics-panel">
        <div class="rp-panel-card">
          <div class="rp-panel-head"><div><h3>Analytics detalhado</h3><p>Leitura adicional para gestão e comparação operacional no mesmo portal.</p></div><div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><span class="rp-pill">Analytics</span><a class="rp-small primary" id="rpcp-analytics-export-csv" href="#" target="_blank" rel="noopener">CSV</a><a class="rp-small" id="rpcp-analytics-export-xls" href="#" target="_blank" rel="noopener">Excel</a><a class="rp-small alt" id="rpcp-analytics-export-pdf" href="#" target="_blank" rel="noopener">PDF</a></div></div>
          <div class="rp-kpi-grid" id="rpcp-analytics-kpis"></div>
          <div class="rp-two">
            <div class="rp-subcard"><div class="rp-chart" id="rpcp-analytics-chart"></div></div>
            <div class="rp-subcard"><div class="rp-table-wrap"><table><thead><tr><th>Data</th><th>Campanha</th><th>Rota</th><th>Status</th><th>Paragens</th><th>Done</th><th>Execução</th></tr></thead><tbody id="rpcp-analytics-table"></tbody></table></div></div>
          </div>
        </div>
      </div>
    </div>

    <div class="rp-tabs">
      <div class="rp-tabbar">
        <button type="button" class="active" data-panel="overview">Resumo</button>
        <button type="button" data-panel="routes">Rotas</button>
        <button type="button" data-panel="reports">Relatórios</button>
        <button type="button" data-panel="commercial">Base</button>
        <button type="button" data-panel="team">Equipa</button>
        <button type="button" data-panel="analytics">Analytics</button>
      </div>
    </div>
  </div>
</div>
<script>
(async function(){
  const root=document.getElementById('routespro-client-portal'); if(!root) return;
  const nonce=root.dataset.nonce, exportNonce=root.dataset.exportNonce||'', api='<?php echo esc_url(rest_url('routespro/v1/')); ?>';
  const projects=JSON.parse(root.dataset.projects||'[]');
  const canManage=root.dataset.canManage==='1';
  const els={client:root.querySelector('#rpcp-client'),project:root.querySelector('#rpcp-project'),from:root.querySelector('#rpcp-from'),to:root.querySelector('#rpcp-to'),kpis:root.querySelector('#rpcp-kpis'),commercialKpis:root.querySelector('#rpcp-commercial-kpis'),chart:root.querySelector('#rpcp-chart'),routesTable:root.querySelector('#rpcp-routes-table'),routeList:root.querySelector('#rpcp-route-list'),routeDetail:root.querySelector('#rpcp-route-detail'),reportHead:root.querySelector('#rpcp-report-head'),reportTable:root.querySelector('#rpcp-report-table'),exportCsv:root.querySelector('#rpcp-export-csv'),exportXls:root.querySelector('#rpcp-export-xls'),exportPdf:root.querySelector('#rpcp-export-pdf'),analyticsKpis:root.querySelector('#rpcp-analytics-kpis'),analyticsChart:root.querySelector('#rpcp-analytics-chart'),analyticsTable:root.querySelector('#rpcp-analytics-table'),analyticsExportCsv:root.querySelector('#rpcp-analytics-export-csv'),analyticsExportXls:root.querySelector('#rpcp-analytics-export-xls'),analyticsExportPdf:root.querySelector('#rpcp-analytics-export-pdf'),status:root.querySelector('#rpcp-status')};
  const state={stats:null, commercial:null, routes:[], selectedRouteId:0, selectedRoute:null, reports:{rows:[],columns:[]}};
  const ensurePortalTableScroll=()=>{ root.querySelectorAll('.rp-table-wrap').forEach(wrap=>{ wrap.style.display='block'; wrap.style.width='100%'; wrap.style.maxWidth='100%'; wrap.style.overflowX='auto'; wrap.style.overflowY='hidden'; wrap.style.webkitOverflowScrolling='touch'; const table=wrap.querySelector('table'); if(table){ table.style.width='max-content'; table.style.maxWidth='none'; if(table.classList.contains('rp-report-table')){ table.style.minWidth='1100px'; }else if(table.closest('#rpcp-overview') || table.closest('#rpcp-analytics-panel')){ table.style.minWidth='1100px'; }else{ table.style.minWidth='980px'; } } }); root.querySelectorAll('.rp-chart svg').forEach(svg=>{ svg.style.width='max-content'; svg.style.maxWidth='none'; if(!svg.style.minWidth) svg.style.minWidth='720px'; }); };
  function esc(s){return(s==null?'':String(s)).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));}
  async function j(url, opts={}){const res=await fetch(url,Object.assign({credentials:'same-origin',headers:{'X-WP-Nonce':nonce}},opts));const text=await res.text();let data={};try{data=text?JSON.parse(text):{}}catch(_){data={message:text}}if(!res.ok) throw new Error(data.message||'Falha no pedido.');return data;}
  function setStatus(msg){ if(els.status) els.status.textContent=msg||''; }
  function setLocked(el, locked){ if(!el) return; el.disabled=!!locked; el.classList.toggle('ff-locked', !!locked); }
  function lockIfSingle(el){ if(!el) return false; const opts=Array.from(el.options||[]).filter(o=>String(o.value||'')!==''); const one=opts.length===1; if(one) el.value=String(opts[0].value||''); setLocked(el, one); return one; }
  function enforcePortalScopeLocks(){ lockIfSingle(els.client); syncProjects(); lockIfSingle(els.project); }
  function lockCommercialPanel(){ const panel=root.querySelector('#rpcp-commercial-panel .rp-front-commercial'); if(!panel) return; const c=panel.querySelector('#rpc-client'); const p=panel.querySelector('#rpc-project'); const o=panel.querySelector('#rpc-owner'); const r=panel.querySelector('#rpc-role'); if(r){ const wrap=r.closest('label,div')||r; wrap.style.display='none'; }
    const apply=()=>{ if(c){ lockIfSingle(c); }
      if(p){ lockIfSingle(p); }
      if(o){ lockIfSingle(o); }
    };
    setTimeout(apply,50); setTimeout(apply,400); setTimeout(apply,1200);
  }
  function syncProjects(){ if(!els.project) return; const cid=parseInt((els.client&&els.client.value)||'0',10); const filtered=cid?projects.filter(x=>parseInt(x.client_id||0,10)===cid):projects; const current=els.project.value||root.dataset.projectId||''; els.project.innerHTML='<option value="">Campanha</option>'+filtered.map(x=>`<option value="${x.id}">${esc(x.name)}</option>`).join(''); if(current) els.project.value=String(current); lockIfSingle(els.project); }
  function paramsForStats(){ const p=new URLSearchParams(); if(els.client&&els.client.value) p.set('client_id',els.client.value); if(els.project&&els.project.value) p.set('project_id',els.project.value); if(els.from&&els.from.value) p.set('from',els.from.value); if(els.to&&els.to.value) p.set('to',els.to.value); return p; }
  function paramsForCommercial(){ const p=new URLSearchParams(); if(els.client&&els.client.value) p.set('client_id',els.client.value); if(els.project&&els.project.value) p.set('project_id',els.project.value); p.set('page','1'); p.set('per_page','1'); return p; }
  function selectedText(el, emptyLabel){ return (el && el.selectedIndex > 0) ? esc(el.options[el.selectedIndex].text) : emptyLabel; }
  function setHeroMetric(base, strongText, smallText){ const strong=root.querySelector('#'+base); const note=root.querySelector('#'+base+'-note'); if(strong && strongText != null) strong.textContent=String(strongText); if(note && smallText != null) note.textContent=String(smallText); }
  function dateRange(from,to){ const out=[]; if(!from||!to) return out; const d=new Date(from+'T00:00:00'); const end=new Date(to+'T00:00:00'); while(d<=end){ out.push(d.toISOString().slice(0,10)); d.setDate(d.getDate()+1); } return out; }
  async function loadRoutesRange(){ const dates=dateRange(els.from.value,els.to.value).slice(0,62); const byId=new Map(); for(const dt of dates){ const p=new URLSearchParams(); p.set('date',dt); if(els.client&&els.client.value) p.set('client_id',els.client.value); if(els.project&&els.project.value) p.set('project_id',els.project.value); const data=await j(api+'routes?'+p.toString()).catch(()=>({routes:[]})); (Array.isArray(data.routes)?data.routes:[]).forEach(r=>byId.set(String(r.id),r)); }
    state.routes=Array.from(byId.values()).sort((a,b)=> String(b.date||'').localeCompare(String(a.date||'')) || (parseInt(b.id||0,10)-parseInt(a.id||0,10)));
    if(state.selectedRouteId && state.routes.some(r=>String(r.id)===String(state.selectedRouteId))){ await selectRoute(state.selectedRouteId,false); }
    renderRouteList(); renderReports(); }
  function badge(status){ const cls=(status||'pending').replace(/[^a-z_]/gi,'_'); return `<span class="rp-badge ${cls}">${esc(status||'pending')}</span>`; }
  function metric(label,val,small){ return `<div class="rp-kpi"><span>${label}</span><strong>${val}</strong><small style="display:block;margin-top:6px;color:#64748b">${small||''}</small></div>`; }
  function renderKpis(stats){ els.kpis.innerHTML=[metric('Rotas',stats?.total_routes||0,'Total no período'),metric('Concluídas',stats?.completed_routes||0,'Rotas fechadas'),metric('Paragens',stats?.total_stops||0,'Execução acumulada'),metric('% Execução',(stats?.done_rate||0)+'%','Conclusão de paragens'),metric('On time',stats?.on_time_rate==null?'n.d.':(stats.on_time_rate+'%'),'Dentro da janela horária'),metric('Média visita',stats?.avg_stop_minutes?stats.avg_stop_minutes+' min':'n.d.','Tempo médio por paragem'),metric('Campanha',selectedText(els.project,'Todas'),'Filtro ativo'),metric('Período',`${esc(els.from.value||'')} a ${esc(els.to.value||'')}`,'Janela temporal')].join(''); }
  function renderCommercialKpis(commercial){ const cs=(commercial&&commercial.stats)||{}; if(els.commercialKpis){ els.commercialKpis.innerHTML=[metric('PDVs visíveis',cs.total_visible||0,'Resultado atual da base comercial'),metric('Com coordenadas',cs.with_coords||0,'Prontos para mapa e rota'),metric('Origem Google',cs.google_count||0,'Registos vindos de integração Google'),metric('Validados',cs.validated_count||0,'Qualidade confirmada no âmbito ativo')].join(''); } setHeroMetric('rpcp-hero-scope',selectedText(els.client,'Todos os clientes')+' / '+selectedText(els.project,'Todas as campanhas'),'Escopo operacional sincronizado com o portal.'); setHeroMetric('rpcp-hero-commercial',cs.total_visible||0,`PDVs visíveis, ${cs.google_count||0} origem Google.`); setHeroMetric('rpcp-hero-validation',`${cs.validated_count||0} / ${cs.with_coords||0}`,'Validados e com coordenadas prontos para operação.'); setHeroMetric('rpcp-hero-period',`${esc(els.from?.value||'')} a ${esc(els.to?.value||'')}`,'Janela temporal ativa no resumo executivo.'); }
  function renderChart(rows){ rows=Array.isArray(rows)?rows:[]; if(!rows.length){ els.chart.innerHTML='<div class="rp-chart-empty">Sem dados no período selecionado.</div>'; return; } const values=rows.map(r=>parseFloat(r.done_rate||0)||0); const max=Math.max(100,...values); const barW=rows.length>12?34:50; const gap=14; const width=Math.max(380, rows.length*(barW+gap)+40); const height=240; const bars=rows.map((r,i)=>{ const x=20+i*(barW+gap); const val=parseFloat(r.done_rate||0)||0; const h=Math.max(8,Math.round((val/max)*160)); const y=190-h; const label=String(r.date||'').slice(5); return `<g><rect x="${x}" y="${y}" width="${barW}" height="${h}" rx="12" fill="<?php echo esc_attr($theme['primary']); ?>"></rect><text x="${x+barW/2}" y="206" text-anchor="middle" font-size="11" fill="#475569">${esc(label)}</text><text x="${x+barW/2}" y="${Math.max(18,y-8)}" text-anchor="middle" font-size="11" fill="#0f172a">${val}%</text></g>`; }).join(''); els.chart.innerHTML=`<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Execução por dia"><line x1="14" y1="190" x2="${width-12}" y2="190" stroke="#cbd5e1" stroke-width="1"></line>${bars}</svg>`; }
  function renderRoutesTable(rows){ rows=Array.isArray(rows)?rows:[]; els.routesTable.innerHTML=rows.map(x=>`<tr><td>${esc(x.date||'')}</td><td>${esc(x.project_name||'')}</td><td>#${esc(x.route_id||'')}</td><td>${badge(x.route_status||'')}</td><td>${esc(x.stops||0)}</td><td>${esc(x.stops_done||0)}</td><td>${esc(x.done_rate||0)}%</td></tr>`).join('')||'<tr><td colspan="7">Sem dados.</td></tr>'; }
  function renderAnalytics(stats){ if(!els.analyticsKpis || !els.analyticsChart || !els.analyticsTable) return; const s=stats||{}; els.analyticsKpis.innerHTML=[metric('Rotas',s?.total_routes||0,'Total no período'),metric('Concluídas',s?.completed_routes||0,'Rotas fechadas'),metric('Paragens',s?.total_stops||0,'Execução acumulada'),metric('% Execução',(s?.done_rate||0)+'%','Conclusão de paragens'),metric('On time',s?.on_time_rate==null?'n.d.':(s.on_time_rate+'%'),'Dentro da janela'),metric('Média visita',s?.avg_stop_minutes?s.avg_stop_minutes+' min':'n.d.','Tempo médio por paragem'),metric('Cliente',selectedText(els.client,'Todos'),'Escopo atual'),metric('Campanha',selectedText(els.project,'Todas'),'Escopo atual')].join('');
    const rows=Array.isArray(s.by_day)?s.by_day:[]; if(!rows.length){ els.analyticsChart.innerHTML='<div class="rp-chart-empty">Sem dados no período selecionado.</div>'; els.analyticsTable.innerHTML='<tr><td colspan="7">Sem dados.</td></tr>'; return; }
    const values=rows.map(r=>parseFloat(r.done_rate||0)||0); const max=Math.max(100,...values); const barW=rows.length>12?34:50; const gap=14; const width=Math.max(380, rows.length*(barW+gap)+40); const height=240; const bars=rows.map((r,i)=>{ const x=20+i*(barW+gap); const val=parseFloat(r.done_rate||0)||0; const h=Math.max(8,Math.round((val/max)*160)); const y=190-h; const label=String(r.date||'').slice(5); return `<g><rect x="${x}" y="${y}" width="${barW}" height="${h}" rx="12" fill="<?php echo esc_attr($theme['primary']); ?>"></rect><text x="${x+barW/2}" y="206" text-anchor="middle" font-size="11" fill="#475569">${esc(label)}</text><text x="${x+barW/2}" y="${Math.max(18,y-8)}" text-anchor="middle" font-size="11" fill="#0f172a">${val}%</text></g>`; }).join('');
    els.analyticsChart.innerHTML=`<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Execução por dia"><line x1="14" y1="190" x2="${width-12}" y2="190" stroke="#cbd5e1" stroke-width="1"></line>${bars}</svg>`;
    els.analyticsTable.innerHTML=rows.map(x=>`<tr><td>${esc(x.date||'')}</td><td>${esc(x.project_name||'')}</td><td>#${esc(x.route_id||'')}</td><td>${badge(x.route_status||'')}</td><td>${esc(x.stops||0)}</td><td>${esc(x.stops_done||0)}</td><td>${esc(x.done_rate||0)}%</td></tr>`).join('')||'<tr><td colspan="7">Sem dados.</td></tr>';
  }
  function renderRouteList(){ if(!els.routeList) return; if(!state.routes.length){ els.routeList.innerHTML='<div class="rp-chart-empty">Sem rotas encontradas com os filtros atuais.</div>'; return; } els.routeList.innerHTML=state.routes.map(r=>`<button type="button" class="rp-route-item ${String(r.id)===String(state.selectedRouteId)?'active':''}" data-route="${r.id}"><div class="rp-kicker">${esc(r.date||'')}</div><h4>Rota #${esc(r.id)}</h4><div class="rp-meta">${esc(r.status||'planned')} · client ${esc(r.client_id||'')} · project ${esc(r.project_id||'')}</div></button>`).join(''); els.routeList.querySelectorAll('[data-route]').forEach(btn=>btn.addEventListener('click',()=>selectRoute(btn.dataset.route,true))); }
  function exportUrl(format){ const p=new URLSearchParams(); p.set('action','routespro_export_submissions'); p.set('_wpnonce', exportNonce); if(els.client&&els.client.value) p.set('client_id',els.client.value); if(els.project&&els.project.value) p.set('project_id',els.project.value); if(els.from&&els.from.value) p.set('date_from',els.from.value); if(els.to&&els.to.value) p.set('date_to',els.to.value); if(state.selectedRouteId) p.set('route_id',state.selectedRouteId); p.set('format',format); return '<?php echo esc_url(admin_url('admin-post.php')); ?>?'+p.toString(); }
  async function loadReports(){ const p=new URLSearchParams(); if(els.client&&els.client.value) p.set('client_id',els.client.value); if(els.project&&els.project.value) p.set('project_id',els.project.value); if(els.from&&els.from.value) p.set('date_from',els.from.value); if(els.to&&els.to.value) p.set('date_to',els.to.value); if(state.selectedRouteId) p.set('route_id',state.selectedRouteId); p.set('limit','500'); state.reports=await j(api+'form-submissions?'+p.toString()).catch(()=>({rows:[],columns:[]})); renderReports(); refreshExportLinks(); }
  function renderReports(){ const cols=[{key:'route',label:'Rota'},{key:'stop',label:'Paragem'},{key:'client',label:'Cliente'},{key:'project',label:'Campanha / Projeto'},{key:'owner',label:'Owner'},{key:'submitter',label:'Submetido por'},{key:'date',label:'Data'},{key:'status',label:'Status'}].concat((state.reports.columns||[]).map(c=>({key:c.key,label:c.label}))); if(els.reportHead) els.reportHead.innerHTML='<tr>'+cols.map(c=>`<th>${esc(c.label||'')}</th>`).join('')+'</tr>'; const rows=Array.isArray(state.reports.rows)?state.reports.rows:[]; if(!rows.length){ els.reportTable.innerHTML=`<tr><td colspan="${cols.length}">Sem relatórios para os filtros atuais.</td></tr>`; return; } els.reportTable.innerHTML=rows.map(r=>{ const fixed=[`#${esc(r.route_id||r.joined_route_id||'')}`, `${esc(r.route_stop_id||r.stop_row_id||'')}${r.location_name?` · ${esc(r.location_name)}`:''}`, esc(r.client_name||''), esc(r.project_name||''), esc(r.owner_name||''), esc(r.user_name||''), esc(r.submitted_at||''), esc(r.status||'')]; const dynamic=(state.reports.columns||[]).map(c=>esc((r.answers&&r.answers[c.key])||'—')); return '<tr>'+fixed.concat(dynamic).map(v=>`<td>${v||'—'}</td>`).join('')+'</tr>'; }).join(''); }
  function renderRouteDetail(){ const route=state.selectedRoute; if(!route){ els.routeDetail.innerHTML='<div class="rp-chart-empty">Seleciona uma rota para ver e editar o detalhe.</div>'; return; } const stops=Array.isArray(route.stops)?route.stops:[]; const statusOptions=['planned','draft','in_progress','done','completed','cancelled']; const doneCount=stops.filter(s=>['done','completed'].includes(String(s.status||'').toLowerCase())).length; const failedCount=stops.filter(s=>String(s.status||'').toLowerCase()==='failed').length; const coordsCount=stops.filter(s=>Number.isFinite(parseFloat(s.lat))&&Number.isFinite(parseFloat(s.lng))).length; const pendingCount=Math.max(0, stops.length-doneCount-failedCount); const doneRate=stops.length?Math.round((doneCount/stops.length)*100):0; els.routeDetail.innerHTML=`<div class="rp-panel-head" style="margin-bottom:8px"><div><h3 style="font-size:22px">Rota #${esc(route.id)}</h3><p>Data ${esc(route.date||'')} · ${esc(route.status||'planned')} · ${stops.length} paragens</p></div><span class="rp-pill">Editor rápido</span></div><div class="rp-kpi-grid" style="margin-bottom:14px">${metric('Paragens',stops.length,'Total carregado')}${metric('Concluídas',doneCount,'Estado done ou completed')}${metric('Pendentes',pendingCount,'Ainda por executar')}${metric('% rota',doneRate+'%','Execução atual da rota')}${metric('Falhadas',failedCount,'Paragens com falha operacional')}${metric('Com coordenadas',coordsCount,'Pontos prontos para navegação')}${metric('Campanha',selectedText(els.project,'Todas'),'Filtro ativo no portal')}${metric('Status',esc(route.status||'planned'),'Estado atual da rota')}</div><div class="rp-row" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center"><select id="rpcp-route-status">${statusOptions.map(s=>`<option value="${s}" ${String(route.status||'')===s?'selected':''}>${s}</option>`).join('')}</select><button type="button" class="rp-small primary" id="rpcp-save-route-status">Atualizar estado</button><button type="button" class="rp-small alt" id="rpcp-open-route-maps">Abrir no Maps</button><button type="button" class="rp-small" id="rpcp-reload-route">Recarregar rota</button></div><div class="rp-stop-list">${stops.map((stop,idx)=>`<div class="rp-stop" data-stop="${stop.id}"><div class="rp-stop-no">${idx+1}</div><div><h4 style="margin:0 0 6px">${esc(stop.location_name||'PDV')}</h4><div class="rp-meta">${esc(stop.address||'')}</div><div style="margin-top:8px">${badge(stop.status||'pending')}</div><div class="rp-stop-actions"><button type="button" class="rp-small" data-move="up" data-stop="${stop.id}" aria-label="Subir paragem">▲</button><button type="button" class="rp-small" data-move="down" data-stop="${stop.id}" aria-label="Descer paragem">▼</button><button type="button" class="rp-small primary" data-stop-status="done" data-stop="${stop.id}">Marcar done</button><button type="button" class="rp-small" data-stop-status="pending" data-stop="${stop.id}">Pendente</button></div></div></div>`).join('') || '<div class="rp-chart-empty">Sem paragens nesta rota.</div>'}</div>`;
    const moveStop=(stopId,direction)=>{ const arr=route.stops||[]; const idx=arr.findIndex(s=>String(s.id)===String(stopId)); if(idx<0) return; const swap=direction==='up'?idx-1:idx+1; if(swap<0||swap>=arr.length) return; const temp=arr[idx]; arr[idx]=arr[swap]; arr[swap]=temp; arr.forEach((s,i)=>{ s.seq=i+1; }); renderRouteDetail(); renderReports(); };
    els.routeDetail.querySelector('#rpcp-save-route-status')?.addEventListener('click', async()=>{ try{ await j(api+'routes/'+route.id,{method:'PATCH',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify({status:els.routeDetail.querySelector('#rpcp-route-status').value})}); setStatus('Estado da rota atualizado.'); await selectRoute(route.id,false); await loadPortal(false); }catch(err){ setStatus(err.message||'Falha ao atualizar rota.'); } });
    els.routeDetail.querySelector('#rpcp-open-route-maps')?.addEventListener('click', ()=>{ const pts=(route.stops||[]).map(s=> Number.isFinite(parseFloat(s.lat))&&Number.isFinite(parseFloat(s.lng)) ? `${s.lat},${s.lng}` : (s.address||s.location_name||'')).filter(Boolean); if(pts.length<2){ return setStatus('São precisos pelo menos dois pontos válidos para abrir navegação.'); } const origin=pts[0], destination=pts[pts.length-1], mids=pts.slice(1,-1).slice(0,23); let url='https://www.google.com/maps/dir/?api=1&travelmode=driving&origin='+encodeURIComponent(origin)+'&destination='+encodeURIComponent(destination); if(mids.length) url+='&waypoints='+encodeURIComponent(mids.join('|')); window.open(url,'_blank','noopener'); });
    els.routeDetail.querySelector('#rpcp-reload-route')?.addEventListener('click', ()=>selectRoute(route.id,true));
    els.routeDetail.querySelectorAll('[data-move]').forEach(btn=>btn.addEventListener('click', ()=>moveStop(btn.dataset.stop,btn.dataset.move)));
    els.routeDetail.querySelectorAll('[data-stop-status]').forEach(btn=>btn.addEventListener('click', async()=>{ try{ await j(api+'stops/'+btn.dataset.stop,{method:'PATCH',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify({status:btn.dataset.stopStatus})}); setStatus('Estado da paragem atualizado.'); await selectRoute(route.id,false); await loadPortal(false); }catch(err){ setStatus(err.message||'Falha ao atualizar paragem.'); } }));
    const persistSequence=async()=>{ if(!canManage || !Array.isArray(route.stops)) return; for(let i=0;i<route.stops.length;i++){ const stop=route.stops[i]; await j(api+'stops/'+stop.id,{method:'PATCH',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify({seq:i+1})}); } setStatus('Ordem das paragens gravada.'); };
    els.routeDetail.querySelectorAll('[data-move]').forEach(btn=>btn.addEventListener('click', ()=>setTimeout(()=>persistSequence().catch(err=>setStatus(err.message||'Falha ao gravar ordem.')),40)));
  }
  async function selectRoute(routeId, announce){ if(!routeId){ state.selectedRouteId=0; state.selectedRoute=null; renderRouteList(); renderRouteDetail(); await loadReports(); return; } try{ state.selectedRouteId=parseInt(routeId,10)||0; state.selectedRoute=await j(api+'routes/'+routeId); renderRouteList(); renderRouteDetail(); await loadReports(); if(announce) setStatus('Rota carregada com sucesso.'); }catch(err){ setStatus(err.message||'Falha ao carregar detalhe da rota.'); } }
  async function loadPortal(withStatus=true){ try{ if(withStatus) setStatus('A atualizar portal...'); const [stats,commercial]=await Promise.all([j(api+'stats?'+paramsForStats().toString()).catch(()=>({by_day:[]})), j(api+'commercial-search?'+paramsForCommercial().toString()).catch(()=>({stats:{}}))]); state.stats=stats||{}; state.commercial=commercial||{}; renderKpis(state.stats); renderCommercialKpis(state.commercial); renderChart(state.stats.by_day||[]); renderRoutesTable(state.stats.by_day||[]); renderAnalytics(state.stats); ensurePortalTableScroll(); await loadRoutesRange(); if(!state.selectedRouteId) await loadReports(); refreshExportLinks(); enforcePortalScopeLocks(); lockCommercialPanel(); if(withStatus) setStatus('Portal atualizado.'); }catch(err){ setStatus(err.message||'Falha ao atualizar portal.'); } }
  root.querySelectorAll('.rp-tabbar button').forEach(btn=>btn.addEventListener('click',()=>{ const key=btn.dataset.panel||'overview'; root.querySelectorAll('.rp-tabbar button').forEach(b=>b.classList.toggle('active',b===btn)); root.querySelectorAll('.rp-panel').forEach(panel=>panel.classList.toggle('active',panel.dataset.panel===key)); const target=root.querySelector('.rp-panel[data-panel="'+key+'"]'); if(target){ ensurePortalTableScroll(); setTimeout(()=>target.scrollIntoView({behavior:'smooth',block:'start'}),10); target.querySelectorAll('.rp-front-commercial,.routespro-dashboard').forEach(el=>el.dispatchEvent(new CustomEvent('routespro:panel-open',{detail:{panel:key}}))); } }));
  root.querySelector('#rpcp-refresh')?.addEventListener('click',()=>loadPortal(true));
  if(els.client){ els.client.addEventListener('change',()=>{ syncProjects(); enforcePortalScopeLocks(); const detail={client_id:els.client.value||'',project_id:els.project?.value||''}; root.querySelectorAll('.rp-front-commercial').forEach(fc=>fc.dispatchEvent(new CustomEvent('routespro:scope-change',{detail}))); document.dispatchEvent(new CustomEvent('routespro:scope-change',{detail})); loadPortal(true); }); }
  if(els.project){ syncProjects(); els.project.addEventListener('change',()=>{ const detail={client_id:els.client?.value||'',project_id:els.project.value||''}; root.querySelectorAll('.rp-front-commercial').forEach(fc=>fc.dispatchEvent(new CustomEvent('routespro:scope-change',{detail}))); document.dispatchEvent(new CustomEvent('routespro:scope-change',{detail})); enforcePortalScopeLocks(); loadPortal(true); }); }
  if(els.from) els.from.addEventListener('change',()=>loadPortal(true));
  if(els.to) els.to.addEventListener('change',()=>loadPortal(true));
  function refreshExportLinks(){
    const disabled=!exportNonce;
    const map=[[els.exportCsv,'csv'],[els.exportXls,'xls'],[els.exportPdf,'pdf'],[els.analyticsExportCsv,'csv'],[els.analyticsExportXls,'xls'],[els.analyticsExportPdf,'pdf']];
    map.forEach(([btn,format])=>{
      if(!btn) return;
      btn.setAttribute('aria-disabled', disabled ? 'true' : 'false');
      btn.style.opacity=disabled?'0.55':'1';
      btn.style.pointerEvents=disabled?'none':'auto';
      btn.style.cursor=disabled?'not-allowed':'pointer';
      btn.style.position='relative';
      btn.style.zIndex='9999';
      btn.style.filter='none';
      btn.style.visibility='visible';
      btn.title=disabled?'Sessão expirada para exportação. Atualiza a página.':'Exportar relatório filtrado em '+format.toUpperCase();
      const href = disabled ? '#' : exportUrl(format);
      btn.setAttribute('href', href);
      btn.setAttribute('target', '_blank');
      btn.setAttribute('rel', 'noopener');
    });
  }
  [els.exportCsv, els.exportXls, els.exportPdf, els.analyticsExportCsv, els.analyticsExportXls, els.analyticsExportPdf].forEach(btn=>{
    if(!btn) return;
    btn.addEventListener('click', function(ev){
      refreshExportLinks();
      if(btn.getAttribute('href')==='#'){
        ev.preventDefault();
        setStatus('Sessão expirada para exportação. Atualiza a página.');
      }
    });
  });
  enforcePortalScopeLocks();
  lockCommercialPanel();
  await loadPortal(false);
  ensurePortalTableScroll();
})();
</script>
<?php
        return ob_get_clean();
    }


    public static function report_visit($atts = []) {
        $guard = self::front_guard();
        if ($guard) return $guard;
        $theme = self::front_theme();
        $nonce = wp_create_nonce('wp_rest');
        $scope = \RoutesPro\Support\Permissions::get_scope();
        $current_user_id = get_current_user_id();
        $route_id = absint($atts['route_id'] ?? ($_GET['route_id'] ?? 0));
        $stop_id  = absint($atts['stop_id'] ?? ($_GET['stop_id'] ?? 0));
        $mode     = sanitize_text_field($atts['mode'] ?? 'full');
        $binding = class_exists('\RoutesPro\Forms\BindingResolver') ? \RoutesPro\Forms\BindingResolver::resolve([
            'route_id' => $route_id,
            'stop_id' => $stop_id,
        ]) : null;
        $binding_mode = sanitize_key($binding['mode'] ?? '');
        $context = class_exists('\RoutesPro\Forms\BindingResolver') ? \RoutesPro\Forms\BindingResolver::get_context([
            'route_id' => $route_id,
            'stop_id' => $stop_id,
        ]) : ['route_id'=>$route_id,'stop_id'=>$stop_id];
        $route_id = (int) ($context['route_id'] ?? $route_id);
        $stop_id = (int) ($context['stop_id'] ?? $stop_id);
        if ($binding && $binding_mode === 'form_only' && !empty($binding['form_id']) && class_exists('\RoutesPro\Forms\Forms')) {
            return \RoutesPro\Forms\Forms::render_form_with_context((int) $binding['form_id'], $context, $binding, ['show_title' => true]);
        }
        global $wpdb;
        $users = [];
        $px = $wpdb->prefix . 'routespro_';
        $clients = \RoutesPro\Support\Permissions::filter_clients($wpdb->get_results("SELECT id,name FROM {$px}clients ORDER BY name ASC", ARRAY_A) ?: []);
        $projects = \RoutesPro\Support\Permissions::filter_projects($wpdb->get_results("SELECT id,client_id,name FROM {$px}projects ORDER BY name ASC", ARRAY_A) ?: []);
        ob_start(); ?>
<style>
.rp-report-quick{font-family:<?php echo esc_attr($theme['font']); ?>;font-size:<?php echo esc_attr($theme['size']); ?>px}
.rp-report-quick .rp-card{background:#fff;border:1px solid #e2e8f0;border-radius:24px;box-shadow:0 16px 40px rgba(15,23,42,.08);padding:18px}
.rp-report-quick .rp-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.rp-report-quick .rp-row .full{grid-column:1/-1}
.rp-report-quick input,.rp-report-quick select,.rp-report-quick textarea{width:100%;border:1px solid #cbd5e1;border-radius:14px;padding:12px}
.rp-report-quick textarea{min-height:110px}
.rp-report-quick .rp-btn{border:0;border-radius:14px;padding:12px 16px;background:<?php echo esc_attr($theme['primary']); ?>;color:#fff;font-weight:700;cursor:pointer}
.rp-report-quick .rp-btn.alt{background:<?php echo esc_attr($theme['accent']); ?>}
.rp-report-quick .rp-meta{color:#64748b;font-size:13px}
.rp-report-quick .rp-status{margin-top:12px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:16px;padding:12px}.rp-report-quick .rp-action-stack{display:flex;flex-direction:column;gap:12px;margin-top:14px}.rp-report-quick .rp-action-stack .rp-btn{width:100%;justify-content:center;display:inline-flex;align-items:center}.rp-report-quick .rp-submit-wrap{margin-top:18px;padding-top:14px;border-top:1px solid #e2e8f0}
@media (max-width:780px){.rp-report-quick .rp-row{grid-template-columns:1fr}}
</style>
<div class="rp-report-quick" id="routespro-report-quick" data-route-id="<?php echo esc_attr($route_id); ?>" data-stop-id="<?php echo esc_attr($stop_id); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" data-mode="<?php echo esc_attr($mode); ?>" data-scope='<?php echo esc_attr(wp_json_encode($scope)); ?>' data-current-user="<?php echo esc_attr((string) $current_user_id); ?>">
  <div class="rp-card">
    <h3 style="margin:0 0 8px">Reporte rápido de visita</h3>
    <div class="rp-meta">Pensado para mobile. Faz check-in, regista resultado e fecha a visita sem sair da rota.</div>
    <div class="rp-row" style="margin-top:14px">
      <select id="rpr-client" data-scope-client="1"><option value="">Cliente</option><?php foreach($clients as $c): ?><option value="<?php echo intval($c['id']); ?>"><?php echo esc_html($c['name']); ?></option><?php endforeach; ?></select>
      <select id="rpr-project" data-scope-project="1"><option value="">Campanha</option></select>
      <select id="rpr-owner"><option value="">Owner</option><?php foreach($users as $u): ?><option value="<?php echo intval($u->ID); ?>"><?php echo esc_html($u->display_name ?: $u->user_login); ?></option><?php endforeach; ?></select>
      <select id="rpr-route-select"><option value="">Rota disponível</option></select>
      <select id="rpr-stop-select" class="full"><option value="">Paragem disponível</option></select>
      <?php if ($route_id || $stop_id): ?>
      <div class="full rp-meta" id="rpr-context-meta">Contexto automático detetado<?php echo $route_id ? ' · route_id #' . esc_html((string) $route_id) : ''; ?><?php echo $stop_id ? ' · stop_id #' . esc_html((string) $stop_id) : ''; ?></div>
      <input id="rpr-route" type="hidden" value="<?php echo esc_attr($route_id ?: ''); ?>">
      <input id="rpr-stop" type="hidden" value="<?php echo esc_attr($stop_id ?: ''); ?>">
      <?php else: ?>
      <input id="rpr-route" type="number" placeholder="Route ID" value="<?php echo esc_attr($route_id ?: ''); ?>">
      <input id="rpr-stop" type="number" placeholder="Stop ID" value="<?php echo esc_attr($stop_id ?: ''); ?>">
      <?php endif; ?>
      <select id="rpr-status" class="full">
        <option value="done">Visitado</option>
        <option value="failed">Fechado / sem visita</option>
        <option value="in_progress">Em curso</option>
      </select>
      <select id="rpr-fail" class="full">
        <option value="">Motivo de falha, opcional</option>
        <option value="Fechado">Fechado</option>
        <option value="Sem interesse">Sem interesse</option>
        <option value="Sem contacto">Sem contacto</option>
        <option value="Reagendar">Reagendar</option>
      </select>
      <textarea id="rpr-note" class="full" placeholder="Notas rápidas da visita"></textarea>
      <div class="full rp-action-stack">
        <button type="button" class="rp-btn" id="rpr-checkin">Check-in agora</button>
      </div>
    </div>
    <div class="rp-status" id="rpr-status-box">Usa o reporte rápido abaixo. Quando o contexto da rota já existe, route_id e stop_id são preenchidos automaticamente.</div>
    <?php if ($binding && ($binding_mode === 'route_and_form') && !empty($binding['form_id']) && class_exists('\RoutesPro\Forms\Forms')): ?>
      <div style="margin-top:18px">
        <div class="rp-meta" style="margin-bottom:10px">Formulário dinâmico associado a este contexto.</div>
        <?php echo \RoutesPro\Forms\Forms::render_form_with_context((int) $binding['form_id'], $context, $binding, ['show_title' => true, 'hide_actions' => true]); ?>
      </div>
    <?php endif; ?>
    <div class="rp-submit-wrap">
      <button type="button" class="rp-btn alt" id="rpr-submit">Guardar reporte</button>
    </div>
  </div>
</div>
<script>
(function(){
  const root = document.getElementById('routespro-report-quick'); if(!root) return;
  const api = '<?php echo esc_url(rest_url('routespro/v1/')); ?>';
  const nonce = root.dataset.nonce;
  const projects = <?php echo wp_json_encode($projects); ?>;
  const ownerAjaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
  const scope = JSON.parse(root.dataset.scope || '{}');
  const currentUserId = String(root.dataset.currentUser || '');
  const isAppContext = !!root.closest('.rp-app');
  const els = {
    client: root.querySelector('#rpr-client'), project: root.querySelector('#rpr-project'), owner: root.querySelector('#rpr-owner'), routeSel: root.querySelector('#rpr-route-select'), stopSel: root.querySelector('#rpr-stop-select'),
    route: root.querySelector('#rpr-route'), stop: root.querySelector('#rpr-stop'), status: root.querySelector('#rpr-status'), fail: root.querySelector('#rpr-fail'), note: root.querySelector('#rpr-note'), box: root.querySelector('#rpr-status-box')
  };
  let routesCache = [];
  const esc = (s) => s == null ? '' : String(s);
  const setStatus = (msg) => { els.box.textContent = msg; };
  const nowIso = () => new Date().toISOString();
  const getGeo = () => new Promise(resolve => {
    if(!('geolocation' in navigator)) return resolve(null);
    navigator.geolocation.getCurrentPosition(pos => resolve({lat: pos.coords.latitude, lng: pos.coords.longitude}), () => resolve(null), {enableHighAccuracy:true, timeout:8000, maximumAge:60000});
  });

  async function j(url, opts={}){ const res = await fetch(url, Object.assign({credentials:'same-origin', headers:{'X-WP-Nonce':nonce}}, opts)); const text = await res.text(); let json={}; try{ json = text ? JSON.parse(text) : {}; }catch(_){ json={message:text}; } if(!res.ok) throw new Error(json.message || 'Falha no pedido.'); return json; }
  function syncProjects(){ const cid=parseInt((els.client?.value)||'0',10); const filtered=cid ? projects.filter(x=>parseInt(x.client_id||0,10)===cid) : projects; const current=String(els.project?.value||''); els.project.innerHTML='<option value="">Campanha</option>'+filtered.map(x=>`<option value="${x.id}">${esc(x.name)}</option>`).join(''); if(current && Array.from(els.project.options).some(o=>String(o.value)===current)) els.project.value=current; }
  function allowedClientIds(){ return Array.isArray(scope?.client_ids) ? scope.client_ids.map(v=>String(parseInt(v,10))).filter(Boolean) : []; }
  function allowedProjectIds(){ return Array.isArray(scope?.project_ids) ? scope.project_ids.map(v=>String(parseInt(v,10))).filter(Boolean) : []; }
  function lockReportScope(){
    if(!isAppContext || scope?.is_manager) return;
    const clientIds=allowedClientIds();
    const projectIds=allowedProjectIds();
    if(els.client){
      if(!els.client.value && clientIds.length===1) els.client.value=clientIds[0];
      els.client.disabled=true;
    }
    syncProjects();
    if(els.project){
      if(!els.project.value && projectIds.length===1 && Array.from(els.project.options).some(o=>String(o.value)===projectIds[0])) els.project.value=projectIds[0];
      els.project.disabled=true;
    }
  }
  async function syncOwners(){
    const current = els.owner?.value || '';
    const url = new URL(ownerAjaxUrl, window.location.origin);
    url.searchParams.set('action', 'routespro_users');
    if(els.client?.value) url.searchParams.set('client_id', els.client.value);
    if(els.project?.value) url.searchParams.set('project_id', els.project.value);
    try {
      const res = await fetch(url.toString(), { credentials:'same-origin' });
      const rows = await res.json();
      const users = Array.isArray(rows) ? rows : [];
      els.owner.innerHTML = '<option value="">Owner</option>' + users.map(u=>`<option value="${parseInt(u.ID||0,10)}">${esc(u.label || u.displayName || u.username || ('User '+(u.ID||'')))}</option>`).join('');
      if(current && Array.from(els.owner.options).some(o=>String(o.value)===String(current))) els.owner.value = current;
      if(isAppContext && !scope?.is_manager && currentUserId){
        if(!Array.from(els.owner.options).some(o=>String(o.value)===currentUserId)){
          const opt=document.createElement('option'); opt.value=currentUserId; opt.textContent='Meu utilizador'; els.owner.appendChild(opt);
        }
        els.owner.value = currentUserId;
        els.owner.disabled = true;
      }
    } catch(_){ }
  }
  async function loadRoutes(){ const p=new URLSearchParams(); p.set('date', new Date().toISOString().slice(0,10)); if(els.client?.value) p.set('client_id', els.client.value); if(els.project?.value) p.set('project_id', els.project.value); const data = await j(api+'routes?'+p.toString()); let rows = Array.isArray(data.routes) ? data.routes : []; const ownerId=parseInt((els.owner?.value)||'0',10); if(ownerId) rows = rows.filter(r=>parseInt(r.owner_user_id||0,10)===ownerId); routesCache = rows; els.routeSel.innerHTML='<option value="">Rota disponível</option>'+rows.map(r=>`<option value="${r.id}">#${r.id} · ${esc(r.date||'')} · ${esc(r.status||'')}</option>`).join(''); if(els.route.value){ els.routeSel.value = els.route.value; } if(rows.length===1 && !els.routeSel.value){ els.routeSel.value = String(rows[0].id); } if(els.routeSel.value){ await loadStops(); }
  }
  async function loadStops(){ const rid=parseInt((els.routeSel?.value)||els.route.value||'0',10); els.route.value = rid ? String(rid) : ''; if(!rid){ els.stopSel.innerHTML='<option value="">Paragem disponível</option>'; return; } const route = await j(api+'routes/'+rid); const stops = Array.isArray(route.stops) ? route.stops : []; els.stopSel.innerHTML='<option value="">Paragem disponível</option>'+stops.map(s=>`<option value="${s.id}">#${s.id} · ${esc(s.location_name||'PDV')}</option>`).join(''); if(els.stop.value){ els.stopSel.value = els.stop.value; } if(stops.length===1 && !els.stopSel.value){ els.stopSel.value = String(stops[0].id); } if(els.stopSel.value){ els.stop.value = els.stopSel.value; } syncResolvedContextToForm();
  }
  function syncResolvedContextToForm(){
    const form = root.querySelector('.routespro-dyn-form');
    if(!form) return null;
    const map = {
      routespro_client_id: els.client?.value || '',
      routespro_project_id: els.project?.value || '',
      routespro_route_id: els.route?.value || '',
      routespro_stop_id: els.stop?.value || '',
      routespro_location_id: ''
    };
    Object.keys(map).forEach(function(name){
      const input = form.querySelector('[name="'+name+'"]');
      if(input && map[name] !== '') input.value = map[name];
    });
    return form;
  }
  async function submitEmbeddedForm(){
    const form = syncResolvedContextToForm();
    if(!form) return null;
    const fd = new FormData(form);
    fd.set('routespro_ajax', '1');
    const res = await fetch(form.getAttribute('action'), { method:'POST', credentials:'same-origin', body: fd });
    const text = await res.text();
    let json = {};
    try { json = text ? JSON.parse(text) : {}; } catch(_){ json = { success:false, data:{message:text} }; }
    if(!res.ok || !json.success) throw new Error((json.data && (json.data.message || json.data.code)) || 'Falha ao guardar formulário.');
    return json.data || json;
  }
  async function sendEvent(eventType, payload){
    const stopId = parseInt(els.stop.value || '0', 10);
    if(!stopId){ setStatus('O stop_id deve ser resolvido automaticamente neste contexto.'); return null; }
    const res = await fetch(api+'events', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},
      body: JSON.stringify({ route_stop_id: stopId, event_type: eventType, payload })
    });
    const text = await res.text();
    let json = {};
    try { json = text ? JSON.parse(text) : {}; } catch(_){ json = { message: text }; }
    if(!res.ok) throw new Error(json.message || 'Falha ao guardar evento.');
    return json;
  }
  els.client?.addEventListener('change', ()=>{ syncProjects(); lockReportScope(); syncOwners().then(()=>loadRoutes()).catch(err=>setStatus(err.message||'Falha ao carregar rotas.')); });
  els.project?.addEventListener('change', ()=>{ lockReportScope(); syncOwners().then(()=>loadRoutes()).catch(err=>setStatus(err.message||'Falha ao carregar rotas.')); });
  els.owner?.addEventListener('change', ()=>{ loadRoutes().catch(err=>setStatus(err.message||'Falha ao carregar rotas.')); });
  els.routeSel?.addEventListener('change', ()=>{ loadStops().catch(err=>setStatus(err.message||'Falha ao carregar paragens.')); });
  els.stopSel?.addEventListener('change', ()=>{ els.stop.value = els.stopSel.value || ''; });
  lockReportScope();
  syncOwners().then(()=>loadRoutes()).catch(()=>{});
  root.querySelector('#rpr-checkin').addEventListener('click', async ()=>{
    try{
      setStatus('A fazer check-in...');
      const geo = await getGeo();
      await sendEvent('checkin', { arrived_at: nowIso(), real_lat: geo?.lat || null, real_lng: geo?.lng || null, note: els.note.value || '' });
      setStatus('Check-in registado com sucesso.');
    }catch(err){ setStatus(err.message || 'Falha no check-in.'); }
  });
  root.querySelector('#rpr-submit').addEventListener('click', async ()=>{
    try{
      const hasEmbeddedForm = !!root.querySelector('.routespro-dyn-form');
      setStatus(hasEmbeddedForm ? 'A guardar reporte e formulário...' : 'A guardar reporte...');
      const geo = await getGeo();
      const payload = { note: els.note.value || '', fail_reason: els.fail.value || '', departed_at: nowIso(), real_lat: geo?.lat || null, real_lng: geo?.lng || null };
      const status = els.status.value || 'done';
      const eventType = status === 'failed' ? 'failure' : (status === 'in_progress' ? 'checkin' : 'checkout');
      await sendEvent(eventType, payload);
      const stopRes = await fetch(api+'stops/'+encodeURIComponent(els.stop.value), { method:'PATCH', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce':nonce}, body: JSON.stringify({ status, note: els.note.value || '', fail_reason: els.fail.value || '', departed_at: nowIso(), real_lat: geo?.lat || null, real_lng: geo?.lng || null }) });
      const stopText = await stopRes.text();
      let stopJson = {};
      try { stopJson = stopText ? JSON.parse(stopText) : {}; } catch(_) { stopJson = { message: stopText }; }
      if(!stopRes.ok) throw new Error(stopJson.message || 'Falha ao atualizar a paragem.');
      if(hasEmbeddedForm){ await submitEmbeddedForm(); }
      setStatus(hasEmbeddedForm ? 'Reporte e formulário gravados com sucesso.' : 'Reporte gravado com sucesso.');
    }catch(err){ setStatus(err.message || 'Falha ao guardar reporte.'); }
  });
})();
</script>
<?php
        return ob_get_clean();
    }

}
