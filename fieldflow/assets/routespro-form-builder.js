(function ($) {
  'use strict';

  var WIDTH_PRESETS = [25, 33, 50, 66, 75, 100];

  var FB = {
    state: {
      meta: { title: '', subtitle: '' },
      questions: [],
      layout: {
        mode: 'single',
        show_progress: false,
        steps: [],
        field_layout: {}
      }
    },
    els: {
      schema: null,
      list: null
    },
    flags: {
      sortableInit: false,
      stepsSortableInit: false,
      layoutBound: false
    }
  };

  function safeJsonParse(str, fallback) {
    try {
      var o = JSON.parse(str);
      return o && typeof o === 'object' ? o : fallback;
    } catch (e) {
      return fallback;
    }
  }

  function escapeHtml(s) {
    s = (s === null || s === undefined) ? '' : String(s);
    return s
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function slugifyKey(str) {
    if (!str) return '';
    return String(str)
      .toLowerCase()
      .trim()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9]+/g, '_')
      .replace(/^_+|_+$/g, '')
      .replace(/_{2,}/g, '_');
  }

  function makeId(prefix) {
    return (prefix || 'id') + '_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
  }

  function existingKeysMap(exceptId) {
    var map = {};
    FB.state.questions.forEach(function (q) {
      if (!q) return;
      if (exceptId && q.id === exceptId) return;
      if (q.key) map[q.key] = true;
    });
    return map;
  }

  function uniqKey(base, exceptId) {
    base = slugifyKey(base || 'pergunta');
    if (!base) base = 'pergunta';

    var key = base;
    var i = 2;
    var existing = existingKeysMap(exceptId);

    while (existing[key]) {
      key = base + '_' + i;
      i++;
    }
    return key;
  }

  function normaliseQuestion(q) {
    q = q || {};
    var type = q.type || 'text';
    var allowed = [
      'text', 'textarea', 'number', 'currency', 'percent',
      'date', 'time', 'checkbox', 'select', 'radio',
      'image_upload', 'file_upload'
    ];
    if (allowed.indexOf(type) === -1) type = 'text';

    return {
      id: q.id || q._id || makeId('q'),
      label: q.label || '',
      key: q.key || '',
      type: type,
      required: !!q.required,
      help_text: q.help_text || '',
      options: Array.isArray(q.options) ? q.options : [],
      min: (q.min !== undefined && q.min !== null && q.min !== '') ? q.min : '',
      max: (q.max !== undefined && q.max !== null && q.max !== '') ? q.max : '',
      unit: q.unit || ''
    };
  }

  function normaliseLayout(layout) {
    layout = layout && typeof layout === 'object' ? layout : {};
    var mode = layout.mode === 'steps' ? 'steps' : 'single';
    var show_progress = !!layout.show_progress;

    var steps = Array.isArray(layout.steps) ? layout.steps : [];
    steps = steps.map(function (st) {
      st = st && typeof st === 'object' ? st : {};
      return {
        id: st.id || makeId('step'),
        title: st.title || '',
        description: st.description || '',
        fields: Array.isArray(st.fields) ? st.fields.map(slugifyKey).filter(Boolean) : []
      };
    });

    var field_layout = layout.field_layout && typeof layout.field_layout === 'object' ? layout.field_layout : {};
    var outFieldLayout = {};
    Object.keys(field_layout).forEach(function (k) {
      var key = slugifyKey(k);
      if (!key) return;
      var conf = field_layout[k] || {};
      var w = parseInt(conf.width || 100, 10);
      if (WIDTH_PRESETS.indexOf(w) === -1) w = 100;
      outFieldLayout[key] = { width: w };
    });

    return {
      mode: mode,
      show_progress: show_progress,
      steps: steps,
      field_layout: outFieldLayout
    };
  }

  function hydrateStateFromHidden() {
    var raw = FB.els.schema.val() || '';
    var data = safeJsonParse(raw, { meta: { title: '', subtitle: '' }, questions: [], layout: {} });

    if (!data.meta) data.meta = { title: '', subtitle: '' };
    if (!Array.isArray(data.questions)) data.questions = [];
    if (!data.layout) data.layout = {};

    FB.state.meta = {
      title: data.meta.title || '',
      subtitle: data.meta.subtitle || ''
    };

    FB.state.questions = data.questions.map(function (q) {
      return normaliseQuestion(q);
    });

    FB.state.questions.forEach(function (q) {
      q.key = slugifyKey(q.key);
      if (!q.key) q.key = uniqKey(q.label || 'pergunta', q.id);

      var existing = existingKeysMap(q.id);
      if (existing[q.key]) q.key = uniqKey(q.key, q.id);

      if (!q.label) q.label = q.key;
    });

    FB.state.layout = normaliseLayout(data.layout);

    if (FB.state.layout.mode === 'steps' && (!FB.state.layout.steps || !FB.state.layout.steps.length)) {
      FB.state.layout.steps = [{
        id: makeId('step'),
        title: 'Passo 1',
        description: '',
        fields: FB.state.questions.map(function (q) { return q.key; })
      }];
    }
  }

  function syncHidden() {
    if (!FB.els.schema || !FB.els.schema.length) return;

    var keyMap = {};
    FB.state.questions.forEach(function (q) { keyMap[q.key] = true; });

    if (FB.state.layout && Array.isArray(FB.state.layout.steps)) {
      FB.state.layout.steps.forEach(function (st) {
        st.fields = (st.fields || [])
          .map(slugifyKey)
          .filter(Boolean)
          .filter(function (k) { return !!keyMap[k]; });

        var seen = {};
        st.fields = st.fields.filter(function (k) {
          if (seen[k]) return false;
          seen[k] = true;
          return true;
        });
      });

      var keyOrder = FB.state.questions.map(function (q) { return q.key; });
      function keyPos(k) {
        var i = keyOrder.indexOf(k);
        return i === -1 ? 999999 : i;
      }
      FB.state.layout.steps.forEach(function (st) {
        st.fields = Array.isArray(st.fields) ? st.fields : [];
        st.fields.sort(function (a, b) {
          return keyPos(a) - keyPos(b);
        });
      });
    }

    var fieldLayoutClean = {};
    Object.keys(FB.state.layout.field_layout || {}).forEach(function (k) {
      var key = slugifyKey(k);
      if (!key || !keyMap[key]) return;
      var w = parseInt((FB.state.layout.field_layout[key] || {}).width || 100, 10);
      if (WIDTH_PRESETS.indexOf(w) === -1) w = 100;
      fieldLayoutClean[key] = { width: w };
    });
    FB.state.layout.field_layout = fieldLayoutClean;

    var payload = {
      meta: {
        title: FB.state.meta.title || '',
        subtitle: FB.state.meta.subtitle || ''
      },
      layout: {
        mode: FB.state.layout.mode || 'single',
        show_progress: !!FB.state.layout.show_progress,
        steps: (FB.state.layout.steps || []).map(function (st) {
          return {
            id: st.id,
            title: st.title || '',
            description: st.description || '',
            fields: st.fields || []
          };
        }),
        field_layout: FB.state.layout.field_layout || {}
      },
      questions: FB.state.questions.map(function (q) {
        var item = {
          _id: q.id,
          key: slugifyKey(q.key || ''),
          label: q.label || q.key || '',
          type: q.type || 'text',
          required: !!q.required
        };

        if (q.help_text) item.help_text = q.help_text;

        if (q.type === 'select' || q.type === 'radio') {
          var opts = (q.options || []).map(function (o) { return String(o || '').trim(); }).filter(Boolean);
          if (opts.length) item.options = opts;
        }

        if (q.min !== '' && q.min !== null && q.min !== undefined) item.min = q.min;
        if (q.max !== '' && q.max !== null && q.max !== undefined) item.max = q.max;
        if (q.unit) item.unit = q.unit;

        return item;
      })
    };

    FB.els.schema.val(JSON.stringify(payload));
  }

  function itemTemplateHTML() {
    return $('#twt-fb-item-tpl').html() || '';
  }

  function updateVisibilityForType($item, type) {
    var isChoice = (type === 'select' || type === 'radio');
    var isNumber = (type === 'number' || type === 'currency' || type === 'percent');
    var isUpload = (type === 'image_upload' || type === 'file_upload');

    $item.find('.twt-fb-options').toggle(isChoice);

    $item.find('input[data-fb="min"]').closest('.twt-fb-row').toggle(isNumber);
    $item.find('input[data-fb="max"]').closest('.twt-fb-row').toggle(isNumber);
    $item.find('input[data-fb="unit"]').closest('.twt-fb-row').toggle(isNumber);

    $item.find('.twt-fb-upload-hint').remove();
    if (isUpload) {
      var msg = (type === 'image_upload')
        ? 'Upload de imagem, no front usa ficheiro/imagem.'
        : 'Upload de ficheiro, no front usa ficheiro.';
      $item.find('.twt-fb-body').append('<div class="twt-fb-small twt-fb-upload-hint">' + escapeHtml(msg) + '</div>');
    }
  }

  function ensurePreviewBox($item) {
    var $prev = $item.find('.twt-fb-preview');
    if ($prev.length) return $prev;

    var html = '<div class="twt-fb-preview-wrap">' +
      '<div class="twt-fb-small" style="margin:10px 0 6px 0;">Preview</div>' +
      '<div class="twt-fb-preview"></div>' +
      '</div>';

    $item.find('.twt-fb-body').prepend(html);
    return $item.find('.twt-fb-preview');
  }

  function renderPreview($item, q) {
    var $prev = ensurePreviewBox($item);
    var type = q.type;

    var html = '';

    if (type === 'textarea') {
      html = '<textarea rows="3" style="width:100%;" disabled placeholder="Texto longo"></textarea>';
    } else if (type === 'date') {
      html = '<input type="date" style="width:100%;" readonly>';
    } else if (type === 'time') {
      html = '<input type="time" style="width:100%;" readonly>';
    } else if (type === 'checkbox') {
      html = '<label style="display:inline-flex;align-items:center;gap:10px;"><input type="checkbox" disabled> <span>Sim</span></label>';
    } else if (type === 'select') {
      var opts = (q.options || []).filter(Boolean);
      html = '<select style="width:100%;" disabled>' +
        '<option>Seleccionar</option>' +
        opts.slice(0, 6).map(function (o) { return '<option>' + escapeHtml(o) + '</option>'; }).join('') +
        '</select>';
    } else if (type === 'radio') {
      var ropts = (q.options || []).filter(Boolean);
      html = '<div style="display:grid;gap:8px;">' +
        ropts.slice(0, 6).map(function (o) {
          return '<label style="display:inline-flex;align-items:center;gap:10px;"><input type="radio" disabled> <span>' + escapeHtml(o) + '</span></label>';
        }).join('') +
        '</div>';
    } else if (type === 'image_upload') {
      html = '<input type="file" accept="image/*" style="width:100%;" disabled>';
    } else if (type === 'file_upload') {
      html = '<input type="file" style="width:100%;" disabled>';
    } else if (type === 'number' || type === 'currency' || type === 'percent') {
      var suffix = '';
      if (type === 'currency') suffix = '€';
      if (type === 'percent') suffix = '%';
      var min = (q.min !== '' && q.min !== null && q.min !== undefined) ? ' min="' + escapeHtml(q.min) + '"' : '';
      var max = (q.max !== '' && q.max !== null && q.max !== undefined) ? ' max="' + escapeHtml(q.max) + '"' : '';
      html = '<div style="display:flex;gap:10px;align-items:center;">' +
        '<input type="number" step="0.01" style="width:100%;" disabled' + min + max + '>' +
        (suffix ? '<span style="white-space:nowrap;opacity:.75;">' + suffix + '</span>' : '') +
        '</div>';
    } else {
      html = '<input type="text" style="width:100%;" disabled placeholder="Texto">';
    }

    $prev.html(html);
  }

  function renderItem(q) {
    var tpl = itemTemplateHTML();
    var optionsText = (q.options || []).join('\n');

    function rep(key, val) {
      var rx = new RegExp('{{' + key + '}}', 'g');
      tpl = tpl.replace(rx, val);
    }

    rep('id', q.id);
    rep('label', escapeHtml(q.label || 'Pergunta'));
    rep('key', escapeHtml(q.key || ''));
    rep('type', escapeHtml(q.type || 'text'));
    rep('help_text', escapeHtml(q.help_text || ''));
    rep('options_text', escapeHtml(optionsText));
    rep('min', escapeHtml(q.min === '' ? '' : String(q.min)));
    rep('max', escapeHtml(q.max === '' ? '' : String(q.max)));
    rep('unit', escapeHtml(q.unit || ''));
    rep('required', q.required ? 'checked' : '');

    var $el = $(tpl);
    $el.find('select[data-fb="type"]').val(q.type);

    var currentWidth = ((FB.state.layout.field_layout[q.key] || {}).width) || 100;
    if (WIDTH_PRESETS.indexOf(currentWidth) === -1) currentWidth = 100;

    var widthOptions = WIDTH_PRESETS.map(function (w) {
      return '<option value="' + w + '"' + (w === currentWidth ? ' selected' : '') + '>' + w + '%</option>';
    }).join('');

    var widthRow = '' +
      '<div class="twt-fb-row twt-fb-width-row">' +
      '<label>Largura (front)</label>' +
      '<select data-fb-layout-width="' + escapeHtml(q.key) + '">' +
      widthOptions +
      '</select>' +
      '<div class="twt-fb-small">Define a largura do campo no front (preset).</div>' +
      '</div>';

    $el.find('.twt-fb-body').append(widthRow);

    updateVisibilityForType($el, q.type);
    renderPreview($el, q);

    return $el;
  }

  function ensureSortableQuestions() {
    if (FB.flags.sortableInit) return;
    FB.flags.sortableInit = true;

    FB.els.list.sortable({
      handle: '.twt-fb-drag',
      placeholder: 'twt-fb-placeholder',
      update: function () {
        var order = [];
        FB.els.list.find('.twt-fb-item').each(function () {
          order.push($(this).attr('data-id'));
        });
        FB.state.questions.sort(function (a, b) {
          return order.indexOf(a.id) - order.indexOf(b.id);
        });
        syncHidden();
        renderStepsUI();
      }
    });
  }

  function ensureSortableSteps() {
    if (FB.flags.stepsSortableInit) return;

    var $layout = $('.twt-tcrm-form-builder .twt-fb-layout');
    if (!$layout.length) return;

    var $stepsWrap = $layout.find('.twt-fb-steps');
    if (!$stepsWrap.length) return;

    FB.flags.stepsSortableInit = true;

    $stepsWrap.sortable({
      handle: '.twt-fb-drag-step',
      placeholder: 'twt-fb-placeholder',
      update: function () {
        var order = [];
        $stepsWrap.find('.twt-fb-step').each(function () {
          order.push($(this).attr('data-step-id'));
        });

        FB.state.layout.steps.sort(function (a, b) {
          return order.indexOf(a.id) - order.indexOf(b.id);
        });

        syncHidden();
        renderStepsUI();
      }
    });
  }

  function findStep(stepId) {
    for (var i = 0; i < (FB.state.layout.steps || []).length; i++) {
      if (FB.state.layout.steps[i].id === stepId) return FB.state.layout.steps[i];
    }
    return null;
  }

  function renderStepsUI() {
    var $layout = $('.twt-tcrm-form-builder .twt-fb-layout');
    if (!$layout.length) return;

    var $steps = $layout.find('.twt-fb-steps');

    $layout.find('input[data-fb-layout-mode]').prop('checked', FB.state.layout.mode === 'steps');
    $layout.find('input[data-fb-layout-progress]').prop('checked', !!FB.state.layout.show_progress);

    $steps.empty();

    if (FB.state.layout.mode !== 'steps') {
      $steps.append('<div class="twt-fb-small">Modo single: as perguntas aparecem todas seguidas no front. Ativa "Wizard (Steps)" para organizar por passos.</div>');
      return;
    }

    if (!FB.state.layout.steps.length) {
      FB.state.layout.steps.push({
        id: makeId('step'),
        title: 'Passo 1',
        description: '',
        fields: []
      });
    }

    var allQuestions = FB.state.questions.slice();

    FB.state.layout.steps.forEach(function (st, idx) {
      var stepNo = idx + 1;

      var block = '' +
        '<div class="twt-fb-step" data-step-id="' + escapeHtml(st.id) + '">' +
        '<div style="display:flex;gap:10px;justify-content:space-between;align-items:center;flex-wrap:wrap;">' +
        '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">' +
        '<span class="twt-fb-drag-step" title="Arrastar">::</span>' +
        '<strong>Step ' + stepNo + '</strong>' +
        '</div>' +
        '<div>' +
        '<button type="button" class="button-link-delete" data-fb-del-step>Apagar</button>' +
        '</div>' +
        '</div>' +

        '<div class="twt-fb-grid" style="margin-top:10px;">' +
        '<div class="twt-fb-row">' +
        '<label>Título</label>' +
        '<input type="text" data-fb-step-title value="' + escapeHtml(st.title || '') + '">' +
        '</div>' +
        '<div class="twt-fb-row">' +
        '<label>Descrição</label>' +
        '<input type="text" data-fb-step-desc value="' + escapeHtml(st.description || '') + '" placeholder="Opcional">' +
        '</div>' +
        '</div>' +

        '<div class="twt-fb-small" style="margin:10px 0 6px 0;">Perguntas neste step</div>' +
        '<div class="twt-fb-step-fields">' +
        allQuestions.map(function (q) {
          var checked = (st.fields || []).indexOf(q.key) !== -1 ? ' checked' : '';
          return '' +
            '<label style="display:flex;align-items:center;gap:10px;">' +
            '<input type="checkbox" data-fb-step-field="' + escapeHtml(q.key) + '"' + checked + '>' +
            '<span><strong>' + escapeHtml(q.label) + '</strong> <span style="opacity:.6;">(' + escapeHtml(q.key) + ')</span></span>' +
            '</label>';
        }).join('') +
        '</div>' +
        '</div>';

      $steps.append(block);
    });

    ensureSortableSteps();
  }

  function addStep() {
    FB.state.layout.mode = 'steps';
    FB.state.layout.steps.push({
      id: makeId('step'),
      title: 'Passo ' + String(FB.state.layout.steps.length + 1),
      description: '',
      fields: []
    });
    syncHidden();
    renderStepsUI();
  }

  function deleteStep(stepId) {
    FB.state.layout.steps = FB.state.layout.steps.filter(function (s) { return s.id !== stepId; });
    if (!FB.state.layout.steps.length) {
      FB.state.layout.steps.push({
        id: makeId('step'),
        title: 'Passo 1',
        description: '',
        fields: []
      });
    }
    syncHidden();
    renderStepsUI();
  }

  function bindLayoutUI() {
    if (FB.flags.layoutBound) return;
    FB.flags.layoutBound = true;

    $(document).on('change.twtfbl', '.twt-tcrm-form-builder .twt-fb-layout input[data-fb-layout-mode]', function () {
      FB.state.layout.mode = $(this).is(':checked') ? 'steps' : 'single';

      if (FB.state.layout.mode === 'steps' && (!FB.state.layout.steps || !FB.state.layout.steps.length)) {
        FB.state.layout.steps = [{
          id: makeId('step'),
          title: 'Passo 1',
          description: '',
          fields: FB.state.questions.map(function (q) { return q.key; })
        }];
      }

      syncHidden();
      renderStepsUI();
    });

    $(document).on('change.twtfbl', '.twt-tcrm-form-builder .twt-fb-layout input[data-fb-layout-progress]', function () {
      FB.state.layout.show_progress = $(this).is(':checked');
      syncHidden();
    });

    $(document).on('click.twtfbl', '.twt-tcrm-form-builder .twt-fb-layout [data-fb-add-step]', function (e) {
      e.preventDefault();
      addStep();
    });

    $(document).on('click.twtfbl', '.twt-tcrm-form-builder .twt-fb-layout [data-fb-del-step]', function (e) {
      e.preventDefault();
      var $step = $(this).closest('.twt-fb-step');
      var stepId = $step.attr('data-step-id');
      if (!stepId) return;
      if (!confirm('Apagar este step?')) return;
      deleteStep(stepId);
    });

    $(document).on('input.twtfbl', '.twt-tcrm-form-builder .twt-fb-layout input[data-fb-step-title]', function () {
      var $step = $(this).closest('.twt-fb-step');
      var stepId = $step.attr('data-step-id');
      var st = findStep(stepId);
      if (!st) return;
      st.title = $(this).val();
      syncHidden();
    });

    $(document).on('input.twtfbl', '.twt-tcrm-form-builder .twt-fb-layout input[data-fb-step-desc]', function () {
      var $step = $(this).closest('.twt-fb-step');
      var stepId = $step.attr('data-step-id');
      var st = findStep(stepId);
      if (!st) return;
      st.description = $(this).val();
      syncHidden();
    });

    $(document).on('change.twtfbl', '.twt-tcrm-form-builder .twt-fb-layout input[data-fb-step-field]', function () {
      var $step = $(this).closest('.twt-fb-step');
      var stepId = $step.attr('data-step-id');
      var st = findStep(stepId);
      if (!st) return;

      var key = slugifyKey($(this).attr('data-fb-step-field'));
      if (!key) return;

      st.fields = Array.isArray(st.fields) ? st.fields : [];

      if ($(this).is(':checked')) {
        if (st.fields.indexOf(key) === -1) st.fields.push(key);
      } else {
        st.fields = st.fields.filter(function (k) { return k !== key; });
      }

      syncHidden();
    });
  }

  function bindItemUI($root) {
    $root.off('input.twtfb change.twtfb click.twtfb');

    $root.on('click.twtfb', '.twt-fb-del', function () {
      var $item = $(this).closest('.twt-fb-item');
      var id = $item.attr('data-id');
      if (!id) return;
      if (!confirm('Apagar esta pergunta?')) return;

      var q = findQuestion(id);
      FB.state.questions = FB.state.questions.filter(function (qq) { return qq.id !== id; });

      if (q && q.key) {
        (FB.state.layout.steps || []).forEach(function (st) {
          st.fields = (st.fields || []).filter(function (k) { return k !== q.key; });
        });
        delete FB.state.layout.field_layout[q.key];
      }

      renderAll();
    });

    $root.on('input.twtfb', 'input[data-fb="label"]', function () {
      var $item = $(this).closest('.twt-fb-item');
      var q = findQuestion($item.attr('data-id'));
      if (!q) return;

      q.label = $(this).val();

      if (!q.key) {
        q.key = uniqKey(q.label, q.id);
        $item.find('input[data-fb="key"]').val(q.key);
      }

      $item.find('.twt-fb-label').text(q.label || 'Pergunta');
      syncHidden();
      renderStepsUI();
    });

    $root.on('input.twtfb', 'input[data-fb="key"]', function () {
      var $item = $(this).closest('.twt-fb-item');
      var q = findQuestion($item.attr('data-id'));
      if (!q) return;

      var oldKey = q.key;
      var newKey = slugifyKey($(this).val());
      if (!newKey) newKey = uniqKey(q.label || 'pergunta', q.id);

      var existing = existingKeysMap(q.id);
      if (existing[newKey]) newKey = uniqKey(newKey, q.id);

      q.key = newKey;
      $(this).val(q.key);

      if (oldKey && oldKey !== newKey) {
        if (FB.state.layout.field_layout[oldKey]) {
          FB.state.layout.field_layout[newKey] = FB.state.layout.field_layout[oldKey];
          delete FB.state.layout.field_layout[oldKey];
        }
        (FB.state.layout.steps || []).forEach(function (st) {
          st.fields = (st.fields || []).map(function (k) { return k === oldKey ? newKey : k; });
        });
      }

      // FIX CRÍTICO: manter o select da largura alinhado com a key actual
      $item.find('select[data-fb-layout-width]').attr('data-fb-layout-width', q.key);

      syncHidden();
      renderStepsUI();
    });

    $root.on('change.twtfb', 'select[data-fb="type"]', function () {
      var $item = $(this).closest('.twt-fb-item');
      var q = findQuestion($item.attr('data-id'));
      if (!q) return;

      q.type = $(this).val();

      if ((q.type === 'select' || q.type === 'radio') && (!q.options || !q.options.length)) {
        q.options = ['Opção 1', 'Opção 2'];
        $item.find('textarea[data-fb="options"]').val(q.options.join('\n'));
      }

      if (!(q.type === 'select' || q.type === 'radio')) {
        q.options = [];
        $item.find('textarea[data-fb="options"]').val('');
      }

      if (!(q.type === 'number' || q.type === 'currency' || q.type === 'percent')) {
        q.min = '';
        q.max = '';
        q.unit = '';
        $item.find('input[data-fb="min"]').val('');
        $item.find('input[data-fb="max"]').val('');
        $item.find('input[data-fb="unit"]').val('');
      }

      $item.find('.twt-fb-type').text(q.type);
      updateVisibilityForType($item, q.type);
      renderPreview($item, q);
      syncHidden();
    });

    $root.on('change.twtfb', 'input[data-fb="required"]', function () {
      var $item = $(this).closest('.twt-fb-item');
      var q = findQuestion($item.attr('data-id'));
      if (!q) return;

      q.required = $(this).is(':checked');
      renderPreview($item, q);
      syncHidden();
    });

    $root.on('input.twtfb', 'input[data-fb="help_text"]', function () {
      var $item = $(this).closest('.twt-fb-item');
      var q = findQuestion($item.attr('data-id'));
      if (!q) return;

      q.help_text = $(this).val();
      syncHidden();
    });

    $root.on('input.twtfb', 'textarea[data-fb="options"]', function () {
      var $item = $(this).closest('.twt-fb-item');
      var q = findQuestion($item.attr('data-id'));
      if (!q) return;

      var lines = String($(this).val() || '').split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean);
      q.options = lines;
      renderPreview($item, q);
      syncHidden();
    });

    $root.on('input.twtfb', 'input[data-fb="min"], input[data-fb="max"], input[data-fb="unit"]', function () {
      var $item = $(this).closest('.twt-fb-item');
      var q = findQuestion($item.attr('data-id'));
      if (!q) return;

      q.min = $item.find('input[data-fb="min"]').val();
      q.max = $item.find('input[data-fb="max"]').val();
      q.unit = $item.find('input[data-fb="unit"]').val();
      renderPreview($item, q);
      syncHidden();
    });

    $root.on('change.twtfb', 'select[data-fb-layout-width]', function () {
      var key = slugifyKey($(this).attr('data-fb-layout-width'));
      if (!key) return;

      var w = parseInt($(this).val() || '100', 10);
      if (WIDTH_PRESETS.indexOf(w) === -1) w = 100;

      FB.state.layout.field_layout[key] = { width: w };
      syncHidden();
    });
  }

  function findQuestion(id) {
    for (var i = 0; i < FB.state.questions.length; i++) {
      if (FB.state.questions[i].id === id) return FB.state.questions[i];
    }
    return null;
  }

  function renderAll() {
    FB.els.list.empty();

    FB.state.questions.forEach(function (q) {
      FB.els.list.append(renderItem(q));
    });

    ensureSortableQuestions();
    bindItemUI(FB.els.list);

    renderStepsUI();

    syncHidden();
    syncRawTextarea();
  }

  function syncRawTextarea() {
    var pretty = safeJsonParse(FB.els.schema.val() || '', null);
    if (!pretty) return;
    $('textarea[name="schema_json_raw"]').val(JSON.stringify(pretty, null, 2));
  }

  function bindMetaUI() {
    $(document).on('input.twtfbmeta change.twtfbmeta blur.twtfbmeta', 'input[data-fb-meta="title"]', function () {
      FB.state.meta.title = $(this).val();
      syncHidden();
      syncRawTextarea();
    });
    $(document).on('input.twtfbmeta change.twtfbmeta blur.twtfbmeta', 'input[data-fb-meta="subtitle"]', function () {
      FB.state.meta.subtitle = $(this).val();
      syncHidden();
      syncRawTextarea();
    });
  }

  function bindSubmitSync() {
    $(document).on('submit', 'form', function () {
      var $form = $(this);
      if (!$form.find('input[name="action"][value="routespro_save_form"]').length) return;
      syncHidden();
      syncRawTextarea();
    });
  }

  function init() {
    FB.els.schema = $('#twt_form_schema_json');
    FB.els.list = $('#twt-fb-list');

    if (!FB.els.schema.length || !FB.els.list.length) return;

    hydrateStateFromHidden();

    bindLayoutUI();

    renderAll();

    $('#twt-fb-add').on('click', function () {
      FB.state.questions.unshift(normaliseQuestion({ label: 'Nova pergunta', type: 'text', required: false }));
      FB.state.questions[0].key = uniqKey(FB.state.questions[0].label, FB.state.questions[0].id);
      renderAll();
    });

    bindMetaUI();
    bindSubmitSync();
  }

  $(init);

})(jQuery);
