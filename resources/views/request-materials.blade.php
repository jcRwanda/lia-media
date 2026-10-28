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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: {
            colors: { lia: { 50: '#fdf2f2', 100: '#fbe3e3', 200: '#f5c2c2', 500: '#d23a3a', 600: '#b91c1c', 700: '#991b1b' } },
            fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
        } } };
    </script>
    <style>
        body { font-family: Inter, system-ui, sans-serif; }
        #mr-form { counter-reset: step; }
        #mr-form input:not([type=checkbox]):not([type=file]):not([type=hidden]), #mr-form select, #mr-form textarea {
            border: 1px solid #e5e7eb; border-radius: .75rem; padding: .65rem .8rem; background: #fff; font-size: .95rem; transition: border-color .15s, box-shadow .15s;
        }
        #mr-form input:focus, #mr-form select:focus, #mr-form textarea:focus { outline: none; border-color: #d23a3a; box-shadow: 0 0 0 3px #fbe3e3; }
        #mr-form input[type=checkbox] { accent-color: #b91c1c; width: 1.1rem; height: 1.1rem; }
        #mr-form input[type=file] { border: 1px dashed #f5c2c2; border-radius: .75rem; padding: .6rem; background: #fdf2f2; }
        #mr-form input[type=date] { min-height: 2.9rem; font-weight: 500; color: #111827; }
        #mr-form input[type=date]::-webkit-calendar-picker-indicator { filter: invert(19%) sepia(85%) saturate(3000%) hue-rotate(350deg); cursor: pointer; }
        #mr-form > fieldset, #mr-form > label { border-radius: 1.25rem !important; border-color: #f1e4e4 !important; box-shadow: 0 1px 2px rgba(17,24,39,.04), 0 8px 24px -16px rgba(153,27,27,.25); padding: 1.25rem !important; }
        #mr-form > fieldset > legend { counter-increment: step; display: flex; align-items: center; gap: .55rem; font-size: .95rem; font-weight: 700; color: #111827; padding: 0 .4rem; }
        #mr-form > fieldset > legend::before { content: counter(step); display: inline-grid; place-items: center; width: 1.7rem; height: 1.7rem; border-radius: 999px; background: #b91c1c; color: #fff; font-size: .8rem; font-weight: 700; }
        .lia-item { transition: border-color .15s, background .15s, box-shadow .15s; }
        .lia-item.is-selected { border-color: #d23a3a !important; background: #fdf2f2; box-shadow: 0 0 0 3px #fbe3e3; }
        .lia-steps a.is-active { background: #b91c1c; color: #fff; border-color: #b91c1c; }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-b from-lia-50 via-white to-white text-neutral-900 antialiased">
<header class="bg-lia-600 text-white">
    <div class="mx-auto flex max-w-3xl items-center gap-3 px-4 py-4 sm:px-6">
        <img src="{{ asset('images/lia-logo-white.jpg') }}" alt="Love in Arts" class="h-11 w-11 rounded-xl bg-white object-contain p-0.5 shadow">
        <div class="min-w-0">
            <p class="text-xs font-medium uppercase tracking-wider text-lia-100">Love in Arts · LIA Media</p>
            <p class="truncate text-sm font-semibold">Equipment desk / Ububiko bw'ibikoresho</p>
        </div>
    </div>
</header>
<div class="mx-auto max-w-3xl p-4 pb-16 sm:p-6">
    <div class="mb-5">
        <span class="inline-flex items-center rounded-full bg-lia-100 px-3 py-1 text-xs font-semibold text-lia-700">{{ $types[$type]['en'] }} / {{ $types[$type]['kn'] }}</span>
        <h1 class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">Material / Equipment Request</h1>
        <p class="mt-1 text-sm text-neutral-600">Gusaba ibikoresho &middot; Fill each section below, pick your equipment and sign. / Uzuza buri gice, hitamo ibikoresho maze usinye.</p>
    </div>
    <nav id="lia-steps" class="lia-steps sticky top-0 z-30 -mx-4 mb-5 flex gap-2 overflow-x-auto border-b border-lia-100 bg-white/90 px-4 py-2 text-xs font-medium backdrop-blur sm:mx-0 sm:rounded-2xl sm:border" aria-label="Sections"></nav>
    <div class="hidden">
    </div>

    <div id="notice" class="mb-4 hidden rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-800"></div>
    <div id="error" class="mb-4 hidden rounded-xl border border-lia-200 bg-lia-50 p-3 text-sm font-medium text-lia-700" role="alert"></div>
    <div id="success-screen" class="hidden rounded-3xl border border-lia-100 bg-white p-8 text-center shadow-xl shadow-lia-100/60">
        <div class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-green-100 text-3xl text-green-600">&#10003;</div>
        <h2 class="mt-4 text-2xl font-bold">Request submitted!</h2>
        <p class="mt-1 text-sm text-neutral-600">Ubusabe bwawe bwoherejwe. We'll review it and contact you soon. / Tuzabusuzuma tukumenyeshe vuba.</p>
        <div id="success" class="mx-auto mt-5 hidden max-w-md rounded-2xl bg-lia-50 p-4 text-sm font-medium text-lia-700 [&>a]:mt-3 [&>a]:inline-flex [&>a]:rounded-xl [&>a]:bg-lia-600 [&>a]:px-5 [&>a]:py-3 [&>a]:font-semibold [&>a]:text-white [&>a]:no-underline"></div>
        <button type="button" onclick="window.location.reload()" class="mt-5 text-sm font-semibold text-lia-700 underline underline-offset-4">Submit another request / Ohereza ubundi busabe</button>
    </div>

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
            <div class="mt-4 rounded-xl border border-lia-100 bg-lia-50/60 p-3">
                <button type="button" id="start-prefill" class="text-sm font-semibold text-lia-700 underline underline-offset-4">Use my last request details / Koresha amakuru y'ubusabe bwa nyuma</button>
                <div id="prefill-otp" class="mt-3 hidden space-y-2">
                    <p id="prefill-sent" class="text-xs text-neutral-600"></p>
                    <div class="flex flex-wrap gap-2">
                        <input id="prefill-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" class="w-32 rounded-lg border p-2 text-sm">
                        <button type="button" id="verify-prefill" class="rounded-xl bg-lia-600 px-4 py-2 text-sm font-semibold text-white hover:bg-lia-700">Verify / Emeza</button>
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
            <input id="item-search" type="search" placeholder="&#128269; Search equipment / Shakisha ibikoresho" class="mt-2 w-full rounded-lg border p-2 text-sm">
            <div id="selected-chips" class="mt-3 hidden flex-wrap gap-2"></div>
            <div id="accessory-reminders" class="mt-3 hidden space-y-2 text-sm"></div>
            <div id="items-list" class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2"><p class="text-neutral-500">Loading catalog...</p></div>
        </fieldset>

        <div id="lightbox" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/90 p-4" role="dialog" aria-modal="true">
            <button type="button" id="lb-close" class="absolute right-4 top-4 rounded-full bg-white/20 px-3 py-1 text-2xl text-white" aria-label="Close">&times;</button>
            <button type="button" id="lb-prev" class="absolute left-2 top-1/2 -translate-y-1/2 rounded-full bg-white/20 px-3 py-2 text-3xl text-white" aria-label="Previous">&#8249;</button>
            <img id="lb-img" alt="" class="max-h-[85vh] max-w-full select-none rounded object-contain">
            <button type="button" id="lb-next" class="absolute right-2 top-1/2 -translate-y-1/2 rounded-full bg-white/20 px-3 py-2 text-3xl text-white" aria-label="Next">&#8250;</button>
            <div id="lb-caption" class="absolute bottom-4 left-0 right-0 text-center text-sm text-white"></div>
        </div>

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
            <canvas id="signature-pad" class="mt-2 w-full touch-none rounded-xl border-2 border-dashed border-lia-200 bg-lia-50/50" style="height: 170px"></canvas>
            <input type="hidden" name="signature" id="signature">
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-sm">
                <span>Date / Itariki: <strong id="signature-date"></strong></span>
                <button type="button" id="clear-signature" class="rounded-xl border border-lia-200 px-3 py-1.5 font-medium text-lia-700 hover:bg-lia-50">Clear / Siba</button>
            </div>
        </fieldset>

        <input type="text" name="{{ config('material-requests.honey_pot_name') }}" class="hidden" tabindex="-1" autocomplete="off">

        <div class="sticky bottom-0 z-20 -mx-4 flex flex-col gap-2 border-t border-lia-100 bg-white/95 px-4 py-3 backdrop-blur sm:static sm:mx-0 sm:flex-row sm:items-center sm:border-0 sm:bg-transparent sm:p-0">
            <button type="submit" id="submit-btn" class="w-full rounded-xl bg-lia-600 px-6 py-3.5 text-base font-semibold text-white shadow-lg shadow-lia-200 transition hover:bg-lia-700 disabled:opacity-60 sm:w-auto">Submit Request / Kohereza</button>
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
        if (catalogType !== null && type() !== catalogType) loadCatalog();
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

    let catalogType = null;
    const selected = new Map(); // id -> qty

    async function loadCatalog() {
        catalogType = type();
        try {
            const res = await fetch('/api/v1/material-requests/catalog?type=' + encodeURIComponent(catalogType), { headers: { Accept: 'application/json' } });
            const data = await res.json();
            catalog = data.items || [];
        } catch (e) {
            catalog = [];
        }
        const ids = new Set(catalog.map((i) => i.id));
        Array.from(selected.keys()).forEach((id) => { if (!ids.has(id)) selected.delete(id); });
        renderCatalog($('item-search') ? $('item-search').value : '');
    }

    // ---- Lightbox ----
    const lb = { images: [], index: 0, name: '' };
    function lbShow() {
        $('lb-img').src = lb.images[lb.index];
        $('lb-img').alt = lb.name;
        $('lb-caption').textContent = lb.name + (lb.images.length > 1 ? '  ·  ' + (lb.index + 1) + ' / ' + lb.images.length : '');
        const multi = lb.images.length > 1;
        $('lb-prev').style.display = multi ? '' : 'none';
        $('lb-next').style.display = multi ? '' : 'none';
    }
    function openLightbox(images, name, start) {
        if (!images || !images.length) return;
        lb.images = images; lb.index = start || 0; lb.name = name;
        $('lightbox').classList.remove('hidden');
        $('lightbox').classList.add('flex');
        lbShow();
    }
    function closeLightbox() {
        $('lightbox').classList.add('hidden');
        $('lightbox').classList.remove('flex');
    }
    function lbStep(d) { lb.index = (lb.index + d + lb.images.length) % lb.images.length; lbShow(); }
    function setupLightbox() {
        $('lb-close').addEventListener('click', closeLightbox);
        $('lb-prev').addEventListener('click', (e) => { e.stopPropagation(); lbStep(-1); });
        $('lb-next').addEventListener('click', (e) => { e.stopPropagation(); lbStep(1); });
        $('lightbox').addEventListener('click', (e) => { if (e.target.id === 'lightbox') closeLightbox(); });
        document.addEventListener('keydown', (e) => {
            if ($('lightbox').classList.contains('hidden')) return;
            if (e.key === 'Escape') closeLightbox();
            if (e.key === 'ArrowLeft') lbStep(-1);
            if (e.key === 'ArrowRight') lbStep(1);
        });
        let x0 = null;
        $('lightbox').addEventListener('touchstart', (e) => { x0 = e.touches[0].clientX; }, { passive: true });
        $('lightbox').addEventListener('touchend', (e) => {
            if (x0 === null) return;
            const dx = e.changedTouches[0].clientX - x0;
            if (Math.abs(dx) > 40) lbStep(dx < 0 ? 1 : -1);
            x0 = null;
        });
    }

    function itemImages(item) {
        return (item.image_urls && item.image_urls.length) ? item.image_urls : (item.photo_url ? [item.photo_url] : []);
    }

    function renderReminders() {
        const box = $('accessory-reminders');
        box.innerHTML = '';
        const byId = new Map(catalog.map((i) => [i.id, i]));
        let any = false;
        selected.forEach((_qty, id) => {
            const item = byId.get(id);
            if (!item || !item.accessories || !item.accessories.length) return;
            const missing = item.accessories.filter((a) => byId.has(a.id) && !selected.has(a.id));
            if (!missing.length) return;
            any = true;
            const div = document.createElement('div');
            div.className = 'flex gap-3 rounded-xl border-l-4 border-amber-400 bg-amber-50 p-3 text-amber-900';
            const icon = document.createElement('span'); icon.className = 'text-lg leading-none'; icon.textContent = '\u{1F4A1}'; div.appendChild(icon);
            const body = document.createElement('div'); body.className = 'min-w-0';
            const p = document.createElement('p');
            p.innerHTML = '<strong>Don\'t forget / Ntiwibagirwe</strong> — ' ; p.appendChild(document.createTextNode(item.name + ' works with: ' + missing.map((a) => a.name).join(', ')));
            body.appendChild(p);
            const btns = document.createElement('div');
            btns.className = 'mt-2 flex flex-wrap gap-1.5';
            missing.forEach((a) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'rounded-full border border-amber-300 bg-white px-3 py-1 text-xs font-semibold hover:bg-amber-100';
                b.textContent = '+ Add ' + a.name;
                b.addEventListener('click', () => { selected.set(a.id, 1); renderCatalog($('item-search').value); });
                btns.appendChild(b);
            });
            body.appendChild(btns);
            div.appendChild(body);
            box.appendChild(div);
        });
        box.classList.toggle('hidden', !any);
    }

    function renderChips() {
        const box = $('selected-chips');
        if (!box) return;
        box.innerHTML = '';
        const byId = new Map(catalog.map((i) => [i.id, i]));
        selected.forEach((qty, id) => {
            const item = byId.get(id);
            if (!item) return;
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'inline-flex items-center gap-1.5 rounded-full bg-lia-600 py-1 pl-3 pr-2 text-xs font-semibold text-white';
            chip.title = 'Remove';
            chip.textContent = item.name + ' \u00d7' + qty;
            const x = document.createElement('span'); x.className = 'grid h-4 w-4 place-items-center rounded-full bg-white/25'; x.textContent = '\u2715'; chip.appendChild(x);
            chip.addEventListener('click', () => { selected.delete(id); renderCatalog($('item-search').value); });
            box.appendChild(chip);
        });
        box.classList.toggle('hidden', selected.size === 0);
        box.classList.toggle('flex', selected.size > 0);
    }

    function renderCatalog(search) {
        const box = $('items-list');
        box.innerHTML = '';
        const q = (search || '').toLowerCase();
        const rows = catalog.filter((item) => !q || selected.has(item.id) || item.name.toLowerCase().includes(q) || (item.category || '').toLowerCase().includes(q));
        renderReminders();
        renderChips();
        if (!rows.length) {
            const p = document.createElement('p');
            p.className = 'text-neutral-500 sm:col-span-2';
            p.textContent = 'No equipment available. / Nta bikoresho bihari.';
            box.appendChild(p);
            return;
        }
        rows.forEach((item) => {
            const row = document.createElement('div');
            row.className = 'lia-item flex items-center gap-3 rounded-2xl border border-neutral-200 bg-white p-3' + (selected.has(item.id) ? ' is-selected' : '');
            const check = document.createElement('input');
            check.type = 'checkbox';
            check.className = 'item-check rounded border';
            check.value = String(item.id);
            check.checked = selected.has(item.id);
            check.id = 'item-' + item.id;
            const imgs = itemImages(item);
            let thumb;
            if (imgs.length) {
                thumb = document.createElement('button');
                thumb.type = 'button';
                thumb.className = 'relative h-16 w-16 shrink-0 overflow-hidden rounded-xl border border-neutral-100 bg-neutral-50';
                thumb.title = 'View photos';
                const img = document.createElement('img');
                img.src = imgs[0]; img.alt = item.name; img.loading = 'lazy';
                img.className = 'h-full w-full object-cover';
                thumb.appendChild(img);
                if (imgs.length > 1) {
                    const n = document.createElement('span');
                    n.className = 'absolute bottom-1 right-1 rounded-full bg-black/60 px-1.5 text-[10px] font-semibold text-white';
                    n.textContent = imgs.length;
                    thumb.appendChild(n);
                }
                thumb.addEventListener('click', () => openLightbox(imgs, item.name, 0));
            } else {
                thumb = document.createElement('span');
                thumb.className = 'grid h-16 w-16 shrink-0 place-items-center rounded-xl bg-lia-50 text-2xl'; thumb.textContent = '\u{1F3A5}';
            }
            const name = document.createElement('label');
            name.className = 'min-w-0 flex-1 cursor-pointer';
            name.htmlFor = check.id;
            const title = document.createElement('span'); title.className = 'block font-semibold leading-tight text-neutral-900'; title.textContent = item.name; name.appendChild(title);
            const meta = document.createElement('span'); meta.className = 'mt-1 flex flex-wrap items-center gap-1 text-xs';
            if (item.category) { const c = document.createElement('span'); c.className = 'rounded-full bg-neutral-100 px-2 py-0.5 text-neutral-600'; c.textContent = item.category; meta.appendChild(c); }
            const av = document.createElement('span'); av.className = 'rounded-full bg-green-50 px-2 py-0.5 font-medium text-green-700'; av.textContent = 'available: ' + item.available_qty; meta.appendChild(av);
            name.appendChild(meta);
            if (item.accessories && item.accessories.length) {
                const acc = document.createElement('span');
                acc.className = 'mt-1 block truncate text-xs text-neutral-500';
                acc.textContent = 'Goes with: ' + item.accessories.map((a) => a.name).join(', ');
                name.appendChild(acc);
            }
            const qty = document.createElement('input');
            qty.type = 'number';
            qty.min = '1';
            qty.value = String(selected.get(item.id) || 1);
            qty.required = true;
            qty.className = 'item-qty w-16 rounded-lg border p-1 text-center'; qty.setAttribute('aria-label', 'Quantity');
            qty.disabled = !check.checked;
            check.addEventListener('change', () => {
                if (check.checked) selected.set(item.id, Math.max(1, Number(qty.value) || 1));
                else selected.delete(item.id);
                renderCatalog($('item-search').value);
            });
            qty.addEventListener('input', () => { if (selected.has(item.id)) selected.set(item.id, Math.max(1, Number(qty.value) || 1)); });
            row.appendChild(check);
            row.appendChild(thumb);
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
        return Array.from(selected.entries()).map(([id, qty]) => ({ equipment_item_id: id, qty: Math.max(1, Number(qty) || 1) }));
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
            form.classList.add('hidden'); $('lia-steps').classList.add('hidden'); $('success-screen').classList.remove('hidden'); window.scrollTo({ top: 0, behavior: 'smooth' });
            show('success', 'Request submitted. / Ubusabe bwoherejwe. Reference: ' + (data.reference_code || ''));
            if (data.status_url) { const box = document.createElement('a'); box.href = data.status_url; box.target = '_blank'; box.className = 'block mt-2 underline text-sm'; box.textContent = 'Track your request / Kurikirana ubusabe bwawe'; $('success').appendChild(box); }
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
        setupLightbox();
        $('item-search').addEventListener('input', (event) => renderCatalog(event.target.value));
        updateTypeUi();
        await Promise.all([loadDivisions(), loadCatalog(), loadCohorts()]);
        await loadSignedPrefill();
        updateTypeUi();
        if (type() !== catalogType) await loadCatalog();
    });
})();
</script>
<script>
(function () {
    const nav = document.getElementById('lia-steps');
    const sections = Array.from(document.querySelectorAll('#mr-form > fieldset'));
    sections.forEach((fs, i) => {
        fs.id = fs.id || 'step-' + (i + 1);
        fs.style.scrollMarginTop = '4rem';
        const a = document.createElement('a');
        a.href = '#' + fs.id;
        a.className = 'shrink-0 whitespace-nowrap rounded-full border border-lia-100 px-3 py-1.5 text-neutral-700';
        const text = (fs.querySelector('legend')?.textContent || '').split('/')[0].trim();
        a.textContent = (i + 1) + '. ' + text;
        nav.appendChild(a);
    });
    if (!('IntersectionObserver' in window)) return;
    const links = Array.from(nav.querySelectorAll('a'));
    const io = new IntersectionObserver((entries) => {
        entries.forEach((e) => {
            if (!e.isIntersecting) return;
            links.forEach((l) => l.classList.toggle('is-active', l.getAttribute('href') === '#' + e.target.id));
        });
    }, { rootMargin: '-30% 0px -60% 0px' });
    sections.forEach((s) => io.observe(s));
})();
</script>
</body>
</html>
