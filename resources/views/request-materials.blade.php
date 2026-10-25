@php
    $label = function (string $key) use ($labels): array {
        return $labels[$key] ?? ['kn' => $key, 'en' => $key];
    };
    $types = ['client' => ['kn' => 'Umukiriya', 'en' => 'Client'], 'trainee' => ['kn' => 'Umunyeshuri', 'en' => 'Trainee'], 'alumni' => ['kn' => 'Uwahize amasomo', 'en' => 'Alumni']];
    $formConfig = [
        'labels' => $labels,
        'requesterTypes' => $requesterTypes,
        'type' => $type,
        'divisionsUrl' => asset('data/rwanda-divisions.json'),
        'idMaxBytes' => (int) config('material-requests.id_document_max_bytes'),
        'signatureMaxBytes' => (int) config('material-requests.signature_max_bytes'),
    ];
@endphp
<!DOCTYPE html>
<html lang="rw">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request Materials / Equipment - LIA</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-neutral-50 text-neutral-900 antialiased">
<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold">Material / Equipment Request</h1>
        <p class="text-sm text-neutral-600">Gusaba ibikoresho &middot; {{ $types[$type]['en'] }} / {{ $types[$type]['kn'] }}</p>
    </div>

    <div id="notice" class="mb-4 hidden rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-800"></div>
    <div id="error" class="mb-4 hidden rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800"></div>
    <div id="success" class="mb-4 hidden rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800"></div>

    <form id="mr-form" class="space-y-5" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="requester_type" id="requester_type" value="{{ $type }}">
        <input type="hidden" name="student_alumni_id" id="student_alumni_id">
        <input type="hidden" name="reuse_id_token" id="reuse_id_token">

        <fieldset class="rounded-xl border border-neutral-200 bg-white p-4">
            <legend class="px-1 text-sm font-semibold">Personal details / Amakuru bwite</legend>
            <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <label class="block text-sm">{{ $label('full_name')['en'] }} / {{ $label('full_name')['kn'] }} *
                    <input name="full_name" id="full_name" required maxlength="255" class="mt-1 w-full rounded-lg border p-2">
                </label>
                <label class="block text-sm">{{ $label('phone')['en'] }} / {{ $label('phone')['kn'] }} *
                    <input name="phone" id="phone" required maxlength="50" inputmode="tel" class="mt-1 w-full rounded-lg border p-2">
                </label>
                <label class="block text-sm">{{ $label('email')['en'] }} / {{ $label('email')['kn'] }} *
                    <input name="email" id="email" type="email" required maxlength="255" class="mt-1 w-full rounded-lg border p-2">
                </label>
                <label class="block text-sm">{{ $label('project_name')['en'] }} / {{ $label('project_name')['kn'] }} *
                    <input name="project_name" required maxlength="255" class="mt-1 w-full rounded-lg border p-2">
                </label>
            </div>
            <div class="mt-3 rounded-lg border border-neutral-200 bg-neutral-50 p-3">
                <button type="button" id="start-prefill" class="text-sm font-medium text-sky-700 underline">Use my last request details / Koresha amakuru y'ubusabe bwa nyuma</button>
                <div id="prefill-otp" class="mt-3 hidden space-y-2">
                    <p id="prefill-sent" class="text-xs text-neutral-600"></p>
                    <div class="flex flex-wrap gap-2">
                        <input id="prefill-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" class="w-32 rounded-lg border p-2 text-sm">
                        <button type="button" id="verify-prefill" class="rounded-lg bg-neutral-900 px-3 py-2 text-sm text-white">Verify / Emeza</button>
                    </div>
                </div>
            </div>
        </fieldset>

        <fieldset class="rounded-xl border border-neutral-200 bg-white p-4">
            <legend class="px-1 text-sm font-semibold">{{ $label('residential_address')['en'] }} / {{ $label('residential_address')['kn'] }}</legend>
            <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <label class="block text-sm">{{ $label('province_res')['en'] }} / {{ $label('province_res')['kn'] }}
                    <select name="province_res" id="province_res" data-rwanda="province" class="mt-1 w-full rounded-lg border bg-white p-2"><option value="">Select / Hitamo</option></select>
                </label>
                <label class="block text-sm">{{ $label('district_res')['en'] }} / {{ $label('district_res')['kn'] }}
                    <select name="district_res" id="district_res" data-rwanda="district" class="mt-1 w-full rounded-lg border bg-white p-2"><option value="">Select / Hitamo</option></select>
                </label>
                <label class="block text-sm">{{ $label('sector_res')['en'] }} / {{ $label('sector_res')['kn'] }}
                    <select name="sector_res" id="sector_res" data-rwanda="sector" class="mt-1 w-full rounded-lg border bg-white p-2"><option value="">Select / Hitamo</option></select>
                </label>
                <label class="block text-sm">{{ $label('cell_res')['en'] }} / {{ $label('cell_res')['kn'] }}
                    <select name="cell_res" id="cell_res" data-rwanda="cell" class="mt-1 w-full rounded-lg border bg-white p-2"><option value="">Select / Hitamo</option></select>
                </label>
                <label class="block text-sm sm:col-span-2">{{ $label('village_res')['en'] }} / {{ $label('village_res')['kn'] }}
                    <select id="village_res_select" class="mt-1 w-full rounded-lg border bg-white p-2"><option value="">Select / Hitamo</option></select>
                    <input name="village_res" id="village_res" maxlength="255" placeholder="Type village if not listed / Andika umudugudu" class="mt-2 hidden w-full rounded-lg border p-2">
                </label>
            </div>
            <input type="hidden" name="residential_address" id="residential_address">
        </fieldset>

        <fieldset class="rounded-xl border border-neutral-200 bg-white p-4">
            <legend class="px-1 text-sm font-semibold">Emergency contact / Umuntu wo guhamagara mu gihe gikomeye</legend>
            <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <label class="block text-sm">{{ $label('emergency_contact_name')['en'] }} / {{ $label('emergency_contact_name')['kn'] }} *
                    <input name="emergency_contact_name" id="emergency_contact_name" required maxlength="255" class="mt-1 w-full rounded-lg border p-2">
                </label>
                <label class="block text-sm">{{ $label('emergency_contact_phone')['en'] }} / {{ $label('emergency_contact_phone')['kn'] }} *
                    <input name="emergency_contact_phone" id="emergency_contact_phone" required maxlength="50" inputmode="tel" class="mt-1 w-full rounded-lg border p-2">
                </label>
                <label class="block text-sm">{{ $label('relationship')['en'] }} / {{ $label('relationship')['kn'] }} *
                    <input name="relationship" id="relationship" required maxlength="255" class="mt-1 w-full rounded-lg border p-2">
                </label>
            </div>
        </fieldset>

        <label class="block rounded-xl border border-neutral-200 bg-white p-4 text-sm">
            <span class="font-semibold">{{ $label('project_summary')['en'] }} / {{ $label('project_summary')['kn'] }} *</span>
            <textarea name="project_summary" id="project_summary" required rows="4" class="mt-1 w-full rounded-lg border p-2"></textarea>
        </label>

        <fieldset class="rounded-xl border border-neutral-200 bg-white p-4">
            <legend class="px-1 text-sm font-semibold">{{ $label('items')['en'] }} / {{ $label('items')['kn'] }} *</legend>
            <input id="item-search" type="search" placeholder="Search equipment / Shakisha ibikoresho" class="mt-2 w-full rounded-lg border p-2 text-sm">
            <div id="items-list" class="mt-3 space-y-2 text-sm"><p class="text-neutral-500">Loading catalog...</p></div>
        </fieldset>

        <fieldset class="rounded-xl border border-neutral-200 bg-white p-4">
            <legend class="px-1 text-sm font-semibold">Dates / Amatariki</legend>
            <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <label class="block text-sm">{{ $label('needed_from')['en'] }} / {{ $label('needed_from')['kn'] }} *
                    <input name="needed_from" id="needed_from" type="date" required class="mt-1 w-full rounded-lg border p-2">
                </label>
                <label class="block text-sm">{{ $label('return_by')['en'] }} / {{ $label('return_by')['kn'] }} *
                    <input name="return_by" id="return_by" type="date" required class="mt-1 w-full rounded-lg border p-2">
                </label>
            </div>
        </fieldset>

        <fieldset class="rounded-xl border border-neutral-200 bg-white p-4">
            <legend class="px-1 text-sm font-semibold">{{ $label('project_location')['en'] }} / {{ $label('project_location')['kn'] }}</legend>
            <label class="mt-3 flex items-center gap-2 text-sm">
                <input type="checkbox" name="project_same_as_residential" id="project_same_as_residential" value="1" class="rounded border">
                <span>{{ $label('project_same_as_residential')['en'] }} / {{ $label('project_same_as_residential')['kn'] }}</span>
            </label>
            <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <label class="block text-sm">{{ $label('project_province')['en'] }} / {{ $label('project_province')['kn'] }}
                    <select name="project_province" id="project_province" data-rwanda="province" class="mt-1 w-full rounded-lg border bg-white p-2"><option value="">Select / Hitamo</option></select>
                </label>
                <label class="block text-sm">{{ $label('project_district')['en'] }} / {{ $label('project_district')['kn'] }}
                    <select name="project_district" id="project_district" data-rwanda="district" class="mt-1 w-full rounded-lg border bg-white p-2"><option value="">Select / Hitamo</option></select>
                </label>
                <label class="block text-sm">{{ $label('project_sector')['en'] }} / {{ $label('project_sector')['kn'] }}
                    <select name="project_sector" id="project_sector" data-rwanda="sector" class="mt-1 w-full rounded-lg border bg-white p-2"><option value="">Select / Hitamo</option></select>
                </label>
                <label class="block text-sm">{{ $label('project_cell')['en'] }} / {{ $label('project_cell')['kn'] }}
                    <select name="project_cell" id="project_cell" data-rwanda="cell" class="mt-1 w-full rounded-lg border bg-white p-2"><option value="">Select / Hitamo</option></select>
                </label>
            </div>
            <input type="hidden" name="project_location" id="project_location">
        </fieldset>

        <label class="block rounded-xl border border-neutral-200 bg-white p-4 text-sm" id="cohort-wrap">
            <span class="font-semibold">{{ $label('cohort_id')['en'] }} / {{ $label('cohort_id')['kn'] }} <span id="cohort-required">*</span></span>
            <select name="cohort_id" id="cohort_id" class="mt-1 w-full rounded-lg border bg-white p-2">
                <option value="">Select cohort / Hitamo icyiciro</option>
            </select>
        </label>

        <label class="flex items-start gap-2 rounded-xl border border-neutral-200 bg-white p-4 text-sm">
            <input type="checkbox" name="terms_accepted" id="terms_accepted" required class="mt-1 rounded border">
            <span>
                <span class="font-semibold">{{ $label('terms_accepted')['en'] }} / {{ $label('terms_accepted')['kn'] }} *</span>
                <span class="mt-1 block text-neutral-700">{{ $bilingualTerms['kinyarwanda'] ?? '' }}</span>
                <span class="mt-2 block text-neutral-700">{{ $bilingualTerms['english'] ?? '' }}</span>
            </span>
        </label>

        <label class="flex items-start gap-2 rounded-xl border border-neutral-200 bg-white p-4 text-sm">
            <input type="checkbox" name="consent_declaration" id="consent_declaration" required class="mt-1 rounded border">
            <span>
                <span class="font-semibold">{{ $label('consent_declaration')['en'] }} / {{ $label('consent_declaration')['kn'] }} *</span>
                <span class="mt-1 block text-neutral-700">{{ $consentDeclaration['kinyarwanda'] ?? '' }}</span>
                <span class="mt-2 block text-neutral-700">{{ $consentDeclaration['english'] ?? '' }}</span>
            </span>
        </label>

        <label class="block rounded-xl border border-neutral-200 bg-white p-4 text-sm">
            <span class="font-semibold">{{ $label('id_document')['en'] }} / {{ $label('id_document')['kn'] }} <span id="id-required" class="text-neutral-500"></span></span>
            <input name="id_document" id="id_document" type="file" accept="image/*,application/pdf" class="mt-1 w-full rounded-lg border p-2">
            <span class="mt-1 block text-xs text-neutral-500">Private upload / Ibikoresho by'ibanga. Staff only / Abakozi gusa.</span>
            <span id="id-reuse-note" class="mt-1 hidden text-xs text-green-700">Your last ID is attached after verification. / Indangamuntu yawe ya nyuma izakoreshwa.</span>
        </label>

        <fieldset class="rounded-xl border border-neutral-200 bg-white p-4">
            <legend class="px-1 text-sm font-semibold">{{ $label('signature')['en'] }} / {{ $label('signature')['kn'] }} *</legend>
            <p class="mt-1 text-xs text-neutral-500">Sign with your finger or mouse. / Sinye ukoresheje urutoki cyangwa imbeba.</p>
            <canvas id="signature-pad" class="mt-2 w-full touch-none rounded-lg border border-dashed border-neutral-400 bg-neutral-50" style="height: 170px"></canvas>
            <input type="hidden" name="signature" id="signature">
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-sm">
                <span>Date / Itariki: <strong id="signature-date"></strong></span>
                <button type="button" id="clear-signature" class="rounded-lg border px-3 py-1">Clear / Siba</button>
            </div>
        </fieldset>

        <input type="text" name="{{ config('material-requests.honey_pot_name') }}" class="hidden" tabindex="-1" autocomplete="off">

        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <button type="submit" id="submit-btn" class="rounded-lg bg-neutral-900 px-5 py-3 text-sm font-medium text-white disabled:opacity-60">Submit Request / Kohereza</button>
            <span id="submit-note" class="text-xs text-neutral-500"></span>
        </div>
    </form>
</div>

<script>
(function () {
    'use strict';

    const CONFIG = @json($formConfig);

    const $ = (id) => document.getElementById(id);
    const form = $('mr-form');
    const typeInput = $('requester_type');
    const type = () => typeInput.value;
    const divisions = { north: null, south: null };
    let catalog = [];
    let signatureDrawn = false;
    let idReuseToken = null;
    let prefillChallenge = null;


    function show(id, message) {
        const el = $(id);
        el.textContent = message;
        el.classList.remove('hidden');
    }
    function hide(id) {
        $(id).classList.add('hidden');
    }
    function genericError(message) {
        show('error', message);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function setValue(id, value) {
        const el = $(id);
        if (!el || value === undefined || value === null) return;
        el.value = value;
        const hidden = document.querySelector('input[type="hidden"][name="' + el.name + '"][data-lock-hidden]');
        if (hidden) hidden.value = value;
    }

    function lockField(name) {
        const el = document.querySelector('[name="' + name + '"]');
        if (!el || el.dataset.locked) return;
        el.dataset.locked = '1';
        el.classList.add('bg-neutral-100');
        if (el.tagName === 'SELECT') {
            el.disabled = true;
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = name;
            hidden.value = el.value;
            hidden.dataset.lockHidden = '1';
            el.insertAdjacentElement('afterend', hidden);
        } else {
            el.readOnly = true;
        }
    }

    function applyPrefill(payload, shouldLock) {
        if (!payload || !payload.fields) return;
        Object.entries(payload.fields).forEach(([name, value]) => {
            if (name === 'requester_type') {
                typeInput.value = value;
            } else if (name === 'student_alumni_id') {
                setValue('student_alumni_id', value);
            } else {
                setValue(name, value);
            }
            if (shouldLock) lockField(name);
        });
        if (payload.id_reuse_token) {
            idReuseToken = payload.id_reuse_token;
            $('reuse_id_token').value = idReuseToken;
            $('id-reuse-note').classList.remove('hidden');
        }
        updateTypeUi();
    }

    function updateTypeUi() {
        const alumniType = type() === 'trainee' || type() === 'alumni';
        $('cohort-wrap').classList.toggle('hidden', !alumniType);
        $('cohort-required').classList.toggle('hidden', !alumniType);
        $('cohort_id').required = alumniType;
        const idRequired = type() === 'client' || type() === 'alumni';
        $('id-required').textContent = idRequired ? '*' : '(optional / si ngombwa)';
    }

    function fillSelect(select, values, placeholder) {
        select.innerHTML = '';
        const first = document.createElement('option');
        first.value = '';
        first.textContent = placeholder || 'Select / Hitamo';
        select.appendChild(first);
        (values || []).forEach((value) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = value;
            select.appendChild(option);
        });
    }

    function bindCascade(prefix) {
        const province = $(prefix + '_province');
        const district = $(prefix + '_district');
        const sector = $(prefix + '_sector');
        const cell = $(prefix + '_cell');
        const village = $(prefix + '_village');
        const villageSelect = $(prefix + '_village_select');
        if (!province) return;

        const data = divisions.north;
        fillSelect(province, data ? Object.keys(data) : [], 'Select / Hitamo');

        province.addEventListener('change', () => {
            const d = data && province.value ? Object.keys(data[province.value] || {}) : [];
            fillSelect(district, d);
            fillSelect(sector, []);
            fillSelect(cell, []);
            if (villageSelect) fillSelect(villageSelect, []);
            if (village) village.value = '';
        });
        district.addEventListener('change', () => {
            const s = data && province.value && district.value ? Object.keys(data[province.value][district.value] || {}) : [];
            fillSelect(sector, s);
            fillSelect(cell, []);
            if (villageSelect) fillSelect(villageSelect, []);
            if (village) village.value = '';
        });
        sector.addEventListener('change', () => {
            const c = data && province.value && district.value && sector.value ? Object.keys(data[province.value][district.value][sector.value] || {}) : [];
            fillSelect(cell, c);
            if (villageSelect) fillSelect(villageSelect, []);
            if (village) village.value = '';
        });
        cell.addEventListener('change', () => {
            const v = data && province.value && district.value && sector.value && cell.value ? (data[province.value][district.value][sector.value][cell.value] || []) : [];
            if (villageSelect) {
                fillSelect(villageSelect, v);
                const other = document.createElement('option');
                other.value = '__other__';
                other.textContent = 'Other / Ikindi';
                villageSelect.appendChild(other);
            }
            if (village) village.value = '';
        });
        if (villageSelect) {
            villageSelect.addEventListener('change', () => {
                if (village) {
                    village.classList.toggle('hidden', villageSelect.value !== '__other__');
                    village.value = villageSelect.value === '__other__' ? '' : villageSelect.value;
                }
            });
        }
        if (village) village.addEventListener('input', () => {
            if ($('project_same_as_residential').checked) copyResidentialToProject();
        });
    }

    function copyResidentialToProject() {
        ['province', 'district', 'sector', 'cell'].forEach((level) => {
            const source = $('res_' + level) || $(level + '_res');
            const target = $('project_' + level);
            if (source && target) {
                fillSelect(target, source.value ? Array.from(source.options).map((o) => o.value).filter(Boolean) : []);
                target.value = source.value;
                target.disabled = $('project_same_as_residential').checked;
            }
        });
    }

    async function loadDivisions() {
        try {
            const res = await fetch(CONFIG.divisionsUrl);
            if (!res.ok) throw new Error('divisions');
            divisions.north = await res.json();
            divisions.south = divisions.north;
            bindCascade('res');
            bindCascade('project');
        } catch (e) {
            console.warn('Rwanda divisions could not be loaded; free-text fallback remains available.');
            ['res', 'project'].forEach((prefix) => {
                ['province', 'district', 'sector', 'cell'].forEach((level) => {
                    const select = $(prefix + '_' + level);
                    if (select) select.classList.add('hidden');
                });
            });
            $('village_res_select').classList.add('hidden');
            const village = $('village_res');
            if (village) village.classList.remove('hidden');
        }
    }

    async function loadCatalog() {
        const box = $('items-list');
        try {
            const res = await fetch('/api/v1/material-requests/catalog', { headers: { Accept: 'application/json' } });
            const data = await res.json();
            catalog = data.items || [];
        } catch (e) {
            catalog = [];
        }
        renderCatalog('');
    }

    function renderCatalog(search) {
        const box = $('items-list');
        box.innerHTML = '';
        const q = search.toLowerCase();
        const rows = catalog.filter((item) => !q || item.name.toLowerCase().includes(q) || (item.category || '').toLowerCase().includes(q));
        if (!rows.length) {
            const p = document.createElement('p');
            p.className = 'text-neutral-500';
            p.textContent = 'No equipment available. / Nta bikoresho bihari.';
            box.appendChild(p);
            return;
        }
        rows.forEach((item) => {
            const row = document.createElement('label');
            row.className = 'flex items-center gap-3 rounded-lg border border-neutral-200 p-2';
            const check = document.createElement('input');
            check.type = 'checkbox';
            check.className = 'item-check rounded border';
            check.value = String(item.id);
            const name = document.createElement('span');
            name.className = 'flex-1';
            name.textContent = item.name + (item.category ? ' — ' + item.category : '') + ' (available: ' + item.available_qty + ')';
            const qty = document.createElement('input');
            qty.type = 'number';
            qty.min = '1';
            qty.value = '1';
            qty.required = true;
            qty.className = 'item-qty w-20 rounded-lg border p-1';
            qty.disabled = true;
            check.addEventListener('change', () => { qty.disabled = !check.checked; });
            row.appendChild(check);
            row.appendChild(name);
            row.appendChild(qty);
            box.appendChild(row);
        });
    }

    function setupSignature() {
        const canvas = $('signature-pad');
        const ctx = canvas.getContext('2d');
        let drawing = false;
        let last = null;

        function resize() {
            const ratio = Math.max(1, window.devicePixelRatio || 1);
            const rect = canvas.getBoundingClientRect();
            canvas.width = Math.floor(rect.width * ratio);
            canvas.height = Math.floor(rect.height * ratio);
            ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, rect.width, rect.height);
            ctx.strokeStyle = '#111827';
            ctx.lineWidth = 2;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            signatureDrawn = false;
            $('signature').value = '';
        }

        function point(event) {
            const rect = canvas.getBoundingClientRect();
            return { x: event.clientX - rect.left, y: event.clientY - rect.top };
        }
        canvas.addEventListener('pointerdown', (event) => {
            drawing = true;
            last = point(event);
            canvas.setPointerCapture(event.pointerId);
            event.preventDefault();
        });
        canvas.addEventListener('pointermove', (event) => {
            if (!drawing) return;
            const p = point(event);
            ctx.beginPath();
            ctx.moveTo(last.x, last.y);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            last = p;
            signatureDrawn = true;
            event.preventDefault();
        });
        ['pointerup', 'pointercancel', 'pointerleave'].forEach((name) => {
            canvas.addEventListener(name, () => { drawing = false; });
        });
        $('clear-signature').addEventListener('click', resize);
        window.addEventListener('resize', () => {
            if (!signatureDrawn) resize();
        });
        resize();
        return { canvas, resize, hasSignature: () => signatureDrawn };
    }

    let signaturePad;

    function setupDates() {
        const today = new Date().toISOString().slice(0, 10);
        $('needed_from').min = today;
        $('return_by').min = today;
        $('signature-date').textContent = new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'long', day: 'numeric' }).format(new Date());
        $('needed_from').addEventListener('change', () => {
            $('return_by').min = $('needed_from').value || today;
            if ($('return_by').value && $('return_by').value < $('needed_from').value) $('return_by').value = $('needed_from').value;
        });
    }

    async function loadCohorts() {
        try {
            const res = await fetch('/api/v1/material-requests/config', { headers: { Accept: 'application/json' } });
            const data = await res.json();
            const select = $('cohort_id');
            const current = select.value;
            select.innerHTML = '<option value="">Select cohort / Hitamo icyiciro</option>';
            (data.cohorts || []).forEach((cohort) => {
                const option = document.createElement('option');
                option.value = String(cohort.id);
                option.textContent = cohort.name + (cohort.code ? ' (' + cohort.code + ')' : '') + (cohort.year ? ' — ' + cohort.year : '');
                select.appendChild(option);
            });
            if (current) select.value = current;
        } catch (e) {
            console.warn('Cohorts could not be loaded.');
        }
    }

    async function loadSignedPrefill() {
        const params = new URLSearchParams(window.location.search);
        const token = params.get('prefill') || params.get('token');
        if (!token) return;
        try {
            const res = await fetch('/api/v1/material-requests/prefill?token=' + encodeURIComponent(token), { headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (!res.ok) {
                show('notice', data.message || 'This prefill link could not be used.');
                return;
            }
            await loadCohorts();
            applyPrefill(data, true);
            show('notice', 'Some fields were filled from your portal account and locked. / Amwe mu makuru yuzuzwa anakomejwe.');
        } catch (e) {
            show('notice', 'This prefill link could not be used.');
        }
    }

    function setupPrefillOtp() {
        $('start-prefill').addEventListener('click', async () => {
            hide('error');
            const email = $('email').value.trim();
            const phone = $('phone').value.trim();
            if (!email && !phone) {
                genericError('Enter your phone or email first. / Banza wandike telefoni cyangwa imeli.');
                return;
            }
            const res = await fetch('/api/v1/material-requests/prefill/start', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ email: email || null, phone: phone || null }),
            });
            const data = await res.json();
            if (!res.ok) {
                genericError((data.errors && Object.values(data.errors)[0] && Object.values(data.errors)[0][0]) || data.message || 'Could not send a code.');
                return;
            }
            prefillChallenge = data.challenge;
            $('prefill-otp').classList.remove('hidden');
            $('prefill-sent').textContent = data.sent_to
                ? 'A code was sent to ' + data.sent_to + '. / Kode yoherejwe kuri ' + data.sent_to + '.'
                : 'If we found a matching request, a code was sent to the email on file.';
        });

        $('verify-prefill').addEventListener('click', async () => {
            hide('error');
            if (!prefillChallenge) return;
            const res = await fetch('/api/v1/material-requests/prefill/verify', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ challenge: prefillChallenge, code: $('prefill-code').value.trim() }),
            });
            const data = await res.json();
            if (!res.ok) {
                genericError((data.errors && Object.values(data.errors)[0] && Object.values(data.errors)[0][0]) || data.message || 'Could not verify the code.');
                return;
            }
            applyPrefill({ fields: data.fields, id_reuse_token: data.id_reuse_token }, false);
            $('prefill-otp').classList.add('hidden');
            show('notice', 'Your previous address, emergency contact and ID are ready to reuse. / Amakuru yawe ya nyuma yiteguye gukoreshwa.');
        });
    }

    function collectItems() {
        const items = [];
        document.querySelectorAll('#items-list .item-check:checked').forEach((check) => {
            const qty = check.closest('label').querySelector('.item-qty');
            items.push({ equipment_item_id: Number(check.value), qty: Math.max(1, Number(qty.value) || 1) });
        });
        return items;
    }

    function fileToDataUri(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });
    }

    function validateForm(items) {
        if (!items.length) return 'Select at least one item. / Hitamo nibura igikoresho kimwe.';
        if ($('return_by').value < $('needed_from').value) return 'The return date must be on or after the pick-up date. / Itariki yo gusubiza igomba kuba nyuma cyangwa ku itariki yo gufata.';
        if (!signaturePad.hasSignature()) return "Please sign in the signature box. / Nyamuneka sinya mu kazu k'umukono.";
        if ($('terms_accepted').checked === false) return "Accept the terms and credit agreement. / Emeza amasezerano n'ikubahiriza rya Love In Arts.";
        if ($('consent_declaration').checked === false) return 'Accept the consent and declaration. / Emeza kwemera no kwitangaza.';
        if (type() === 'trainee' || type() === 'alumni') {
            if (!$('cohort_id').value) return 'Select your cohort. / Hitamo icyiciro cyawe.';
        }
        const idRequired = type() === 'client' || type() === 'alumni';
        const file = $('id_document').files[0];
        if (idRequired && !file && !idReuseToken) return 'Upload your ID (image or PDF). / Ohereza indangamuntu yawe.';
        if (file && file.size > CONFIG.idMaxBytes) return 'The ID file is too large. / Dosive ya indangamuntu ni nini cyane.';
        return null;
    }

    async function submitForm(event) {
        event.preventDefault();
        hide('error');
        hide('success');
        const items = collectItems();
        const problem = validateForm(items);
        if (problem) {
            genericError(problem);
            return;
        }

        const villageSelect = $('village_res_select');
        if (villageSelect && villageSelect.value && villageSelect.value !== '__other__') {
            $('village_res').value = villageSelect.value;
        }

        const formData = new FormData(form);
        formData.delete('items');
        items.forEach((item, index) => {
            formData.append('items[' + index + '][equipment_item_id]', String(item.equipment_item_id));
            formData.append('items[' + index + '][qty]', String(item.qty));
        });
        formData.delete('project_same_as_residential');
        if ($('project_same_as_residential').checked) formData.append('project_same_as_residential', '1');
        formData.set('signature', signaturePad.canvas.toDataURL('image/png'));
        formData.set('signature_date', new Date().toISOString().slice(0, 10));
        if (idReuseToken) formData.set('reuse_id_token', idReuseToken);
        if (!$('id_document').files[0]) formData.delete('id_document');
        formData.set('residential_address', [
            $('village_res').value, $('cell_res').value, $('sector_res').value, $('district_res').value, $('province_res').value,
        ].filter(Boolean).join(', '));
        formData.set('project_location', [
            $('project_cell').value, $('project_sector').value, $('project_district').value, $('project_province').value,
        ].filter(Boolean).join(', '));

        if (!formData.get('terms_accepted')) formData.set('terms_accepted', '');
        if (!formData.get('consent_declaration')) formData.set('consent_declaration', '');

        $('submit-btn').disabled = true;
        $('submit-note').textContent = 'Submitting... / Kohereza...';
        try {
            const res = await fetch('/api/v1/material-requests/submit', { method: 'POST', body: formData, headers: { Accept: 'application/json' } });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                const firstError = data.errors ? Object.values(data.errors)[0] : null;
                genericError((firstError && firstError[0]) || data.message || 'The request could not be submitted.');
                return;
            }
            form.reset();
            signaturePad.resize();
            updateTypeUi();
            show('success', 'Request submitted. / Ubusabe bwoherejwe. Reference: ' + (data.reference_code || ''));
        } catch (e) {
            genericError('The request could not be submitted. Please check your connection and try again.');
        } finally {
            $('submit-btn').disabled = false;
            $('submit-note').textContent = '';
        }
    }

    function setupProjectLocationToggle() {
        $('project_same_as_residential').addEventListener('change', (event) => {
            const checked = event.target.checked;
            ['province', 'district', 'sector', 'cell'].forEach((level) => {
                const target = $('project_' + level);
                if (target) target.disabled = checked;
            });
            if (checked) {
                copyResidentialToProject();
                $('project_location').value = [$('cell_res').value, $('sector_res').value, $('district_res').value, $('province_res').value].filter(Boolean).join(', ');
            }
        });
        ['province_res', 'district_res', 'sector_res', 'cell_res'].forEach((id) => {
            $(id).addEventListener('change', () => {
                if ($('project_same_as_residential').checked) copyResidentialToProject();
            });
        });
    }

    document.addEventListener('DOMContentLoaded', async () => {
        signaturePad = setupSignature();
        setupDates();
        setupProjectLocationToggle();
        setupPrefillOtp();
        form.addEventListener('submit', submitForm);
        $('item-search').addEventListener('input', (event) => renderCatalog(event.target.value));
        updateTypeUi();
        await Promise.all([loadDivisions(), loadCatalog(), loadCohorts()]);
        await loadSignedPrefill();
        updateTypeUi();
    });
})();
</script>
</body>
</html>
