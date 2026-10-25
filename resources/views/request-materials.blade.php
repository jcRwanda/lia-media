<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request Materials/Equipment - LIA</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-neutral-50 text-neutral-900 antialiased">
    <div class="max-w-3xl mx-auto p-4 sm:p-6">
        <h1 class="text-2xl font-semibold mb-2">Material / Equipment Request</h1>
        <p class="text-sm text-neutral-600 mb-6">Type: {{ $type }}</p>

        <form id="mr-form" class="space-y-4" enctype="multipart/form-data">
            <input type="hidden" name="requester_type" value="{{ $type }}">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <label class="block text-sm">Full names / Amazina yose*<input name="full_name" required class="mt-1 w-full rounded border p-2"></label>
                <label class="block text-sm">Phone / Telefoni*<input name="phone" required class="mt-1 w-full rounded border p-2"></label>
                <label class="block text-sm">Email / Imeli*<input name="email" type="email" required class="mt-1 w-full rounded border p-2"></label>
                <label class="block text-sm">National ID / Indangamuntu<input name="national_id" class="mt-1 w-full rounded border p-2"></label>
            </div>

            <fieldset class="border rounded p-4">
                <legend class="text-sm font-medium">Residential Address / Aderesi</legend>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-2">
                    <label class="block text-sm">Province / Intara<input name="province_res" class="mt-1 w-full rounded border p-2"></label>
                    <label class="block text-sm">District / Akarere<input name="district_res" class="mt-1 w-full rounded border p-2"></label>
                    <label class="block text-sm">Sector / Umurenge<input name="sector_res" class="mt-1 w-full rounded border p-2"></label>
                    <label class="block text-sm">Cell / Akagari<input name="cell_res" class="mt-1 w-full rounded border p-2"></label>
                    <label class="block text-sm sm:col-span-2">Residential address / Aderesi yuzuye<input name="residential_address" class="mt-1 w-full rounded border p-2"></label>
                </div>
            </fieldset>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <label class="block text-sm">Emergency contact name / Izina ry'uhagarariye*<input name="emergency_contact_name" required class="mt-1 w-full rounded border p-2"></label>
                <label class="block text-sm">Emergency contact phone / Telefoni*<input name="emergency_contact_phone" required class="mt-1 w-full rounded border p-2"></label>
            </div>

            <label class="block text-sm">Purpose/Project description / Impamvu y'umushinga*<textarea name="purpose_project_description" required class="mt-1 w-full rounded border p-2"></textarea></label>

            <fieldset class="border rounded p-4">
                <legend class="text-sm font-medium">Project Location / Aho umushinga uzabera</legend>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-2">
                    <label class="block text-sm">Province / Intara<input name="project_province" class="mt-1 w-full rounded border p-2"></label>
                    <label class="block text-sm">District / Akarere<input name="project_district" class="mt-1 w-full rounded border p-2"></label>
                    <label class="block text-sm">Sector / Umurenge<input name="project_sector" class="mt-1 w-full rounded border p-2"></label>
                    <label class="block text-sm">Cell / Akagari<input name="project_cell" class="mt-1 w-full rounded border p-2"></label>
                    <label class="block text-sm sm:col-span-2">Location / Aho<input name="project_location" class="mt-1 w-full rounded border p-2"></label>
                </div>
            </fieldset>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <label class="block text-sm">Needed from / Bitangwe kuva*<input name="needed_from" type="date" required class="mt-1 w-full rounded border p-2"></label>
                <label class="block text-sm">Return by / Bizagaruka*<input name="return_by" type="date" required class="mt-1 w-full rounded border p-2"></label>
            </div>

            @if(in_array($type, ['trainee', 'alumni']))
            <fieldset class="border rounded p-4">
                <legend class="text-sm font-medium">Trainee/Alumni Information</legend>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-2">
                    <label class="block text-sm">Program/Cohort / Porogaramu/Icyiciro<input name="program_cohort" class="mt-1 w-full rounded border p-2"></label>
                    <label class="block text-sm">Graduation/Completion Year / Umwaka<input name="graduation_completion_year" class="mt-1 w-full rounded border p-2"></label>
                    <label class="block text-sm">Student/Alumni ID / Nomero<input name="student_alumni_id" class="mt-1 w-full rounded border p-2"></label>
                    <label class="block text-sm">Project title / Umutwe w'umushinga<input name="project_title" class="mt-1 w-full rounded border p-2"></label>
                    <label class="block text-sm">Project type / Ubwoko bw'umushinga<input name="project_type" class="mt-1 w-full rounded border p-2"></label>
                    <label class="block text-sm">Portfolio/Social link<input name="portfolio_social_link" class="mt-1 w-full rounded border p-2"></label>
                    <label class="block text-sm sm:col-span-2">Expected outcome / Icyitezwe<textarea name="expected_outcome" class="mt-1 w-full rounded border p-2"></textarea></label>
                </div>
            </fieldset>
            @endif

            <fieldset class="border rounded p-4">
                <legend class="text-sm font-medium">Items Requested / Ibikoresho bisabwa*</legend>
                <div id="items" class="space-y-2"></div>
                <button type="button" id="add-item" class="mt-2 text-sm text-blue-600">+ Add item</button>
            </fieldset>

            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="terms_accepted" required class="mt-1">
                <span>
                    <div>{{ $bilingualTerms['kinyarwanda'] ?? '' }}</div>
                    <div class="mt-2">{{ $bilingualTerms['english'] ?? '' }}</div>
                </span>
            </label>

            <input type="text" name="{{ config('material-requests.honey_pot_name') }}" class="hidden" tabindex="-1" autocomplete="off">

            <button type="submit" class="px-4 py-2 bg-black text-white rounded">Submit Request / Kohereza</button>
            <p id="msg" class="text-sm"></p>
        </form>
    </div>

    <script>
    async function loadCatalog() {
        const res = await fetch('/api/v1/material-requests/catalog');
        const data = await res.json();
        window.catalog = data.items || [];
    }
    function addItemRow() {
        const items = document.getElementById('items');
        const row = document.createElement('div');
        row.className = 'grid grid-cols-1 sm:grid-cols-3 gap-2';
        row.innerHTML = `
            <select name="equipment_item_id[]" required class="rounded border p-2">
                ${(window.catalog||[]).map(i=>'<option value="'+i.id+'">'+i.name+' (qty:'+i.available_qty+')</option>').join('')}
            </select>
            <input name="qty[]" type="number" min="1" value="1" required class="rounded border p-2">
            <button type="button" class="text-sm text-red-600" onclick="this.parentElement.remove()">Remove</button>
        `;
        items.appendChild(row);
    }
    document.getElementById('add-item').addEventListener('click', addItemRow);
    document.getElementById('mr-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const fd = new FormData(form);
        const items = [];
        const ids = fd.getAll('equipment_item_id[]');
        const qtys = fd.getAll('qty[]');
        for (let i=0;i<ids.length;i++) items.push({equipment_item_id: parseInt(ids[i]), qty: parseInt(qtys[i])||1});
        const payload = Object.fromEntries(fd.entries());
        delete payload['equipment_item_id[]'];
        delete payload['qty[]'];
        payload.items = items;
        payload.terms_accepted = form.terms_accepted.checked ? '1' : '0';
        const res = await fetch('/api/v1/material-requests/submit', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        document.getElementById('msg').textContent = res.ok ? ('Submitted. Reference: '+(data.reference_code||'')) : (data.message||'Error');
    });
    loadCatalog().then(addItemRow);
    </script>
</body>
</html>
