<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Request status - {{ config('app.name') }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { colors: { lia: { 50: '#fdf2f2', 100: '#fbe3e3', 200: '#f5c2c2', 500: '#d23a3a', 600: '#b91c1c', 700: '#991b1b' } }, fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } } };</script>
    <style>
        .card { background: #fff; border: 1px solid #f1e4e4; border-radius: 1.25rem; padding: 1.25rem; box-shadow: 0 8px 24px -16px rgba(153,27,27,.25); }
        #edit input, #edit textarea { border: 1px solid #e5e7eb; border-radius: .75rem; padding: .6rem .75rem; margin-top: .25rem; }
        #edit input:focus, #edit textarea:focus { outline: none; border-color: #d23a3a; box-shadow: 0 0 0 3px #fbe3e3; }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-b from-lia-50 via-white to-white font-sans text-gray-900 antialiased">
<header class="bg-lia-600 text-white">
    <div class="mx-auto flex max-w-2xl items-center gap-3 px-4 py-4">
        <img src="{{ asset('images/lia-logo-white.jpg') }}" alt="Love in Arts" class="h-11 w-11 rounded-xl bg-white object-contain p-0.5 shadow">
        <div><p class="text-xs font-medium uppercase tracking-wider text-lia-100">Love in Arts · LIA Media</p><p class="text-sm font-semibold">Equipment desk</p></div>
    </div>
</header>
<main class="max-w-2xl mx-auto p-4 pb-12 space-y-4">
    <h1 class="text-2xl font-bold tracking-tight">Request status <span class="block text-sm font-medium text-gray-500">Aho ubusabe bugeze</span></h1>
    <div id="progress" class="card hidden"></div>
    <div id="box" class="card">Loading…</div>
    <div id="edit" class="card space-y-3 hidden"></div>
    <div id="history" class="card hidden"></div>
</main>
<script>
(() => {
    const uuid = @json(request()->route('uuid'));
    const api = '/api/v1/material-requests/status/' + uuid;
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const labels = {
        submitted: ['Waiting for review', 'bg-yellow-100 text-yellow-800'],
        changes_requested: ['Changes requested', 'bg-orange-100 text-orange-800'],
        approved: ['Approved', 'bg-green-100 text-green-800'],
        rejected: ['Not approved', 'bg-red-100 text-red-800'],
        issued: ['Equipment issued', 'bg-blue-100 text-blue-800'],
        returned: ['Returned', 'bg-gray-100 text-gray-800'],
        closed: ['Closed', 'bg-gray-100 text-gray-800'],
    };
    const flow = [['submitted', 'Submitted'], ['approved', 'Approved'], ['issued', 'Picked up'], ['returned', 'Returned']];
    function renderProgress(status) {
        const order = { submitted: 0, changes_requested: 0, approved: 1, issued: 2, returned: 3, closed: 3 };
        const at = order[status] ?? 0;
        const bad = status === 'rejected';
        $('progress').classList.remove('hidden');
        $('progress').innerHTML = '<ol class="flex items-center">' + flow.map(([, name], i) => {
            const done = !bad && i <= at;
            return `<li class="flex flex-1 items-center ${i < flow.length - 1 ? '' : 'flex-none'}"><div class="flex flex-col items-center gap-1"><span class="grid h-8 w-8 place-items-center rounded-full text-xs font-bold ${done ? 'bg-lia-600 text-white' : 'bg-gray-100 text-gray-400'}">${done && i < at ? '&#10003;' : i + 1}</span><span class="text-[11px] font-medium ${done ? 'text-lia-700' : 'text-gray-400'}">${name}</span></div>${i < flow.length - 1 ? `<span class="mx-1 mb-5 h-0.5 flex-1 ${!bad && i < at ? 'bg-lia-500' : 'bg-gray-200'}"></span>` : ''}</li>`;
        }).join('') + '</ol>';
    }
    function render(r) {
        renderProgress(r.status);
        const [label, cls] = labels[r.status] || [r.status, 'bg-gray-100'];
        const d = r.decision || {};
        let html = `<div class="flex items-center justify-between"><div><div class="text-xs font-medium uppercase tracking-wide text-gray-500">Reference</div><div class="font-mono text-lg font-semibold">${esc(r.reference_code)}</div></div><span class="px-3 py-1 rounded-full text-sm font-semibold ${cls}">${esc(label)}</span></div>
            <div class="text-sm text-gray-600 mt-2">${esc(r.full_name)} · ${esc(r.needed_from)} → ${esc(r.return_by)}</div>`;
        if (r.review_note) {
            html += `<div class="mt-3 p-3 rounded-xl border-l-4 border-lia-500 bg-lia-50 text-sm"><b>${r.status === 'rejected' ? 'Reason' : 'Note from LIA'}:</b> ${esc(r.review_note)}</div>`;
        }
        if (d.storekeeper) {
            html += `<div class="mt-3 text-sm">Pick-up contact: <b>${esc(d.storekeeper.name)}</b> (${esc(d.storekeeper.phone)})</div>`;
            if (d.storekeeper.whatsapp_url) {
                html += `<a href="${esc(d.storekeeper.whatsapp_url)}" target="_blank" rel="noopener" class="inline-flex mt-2 px-4 py-2.5 rounded-xl bg-green-600 font-semibold text-white text-sm">Message the storekeeper on WhatsApp</a>`;
            }
        }
        html += '<table class="w-full text-sm mt-4"><thead><tr class="text-left text-xs uppercase tracking-wide text-gray-500"><th>Item</th><th>Requested</th><th>Approved</th></tr></thead><tbody>'
            + r.items.map(i => `<tr class="border-t border-gray-100"><td class="py-2 font-medium">${esc(i.name)}</td><td>${esc(i.qty_requested)}</td><td>${esc(i.qty_approved ?? '-')}</td></tr>`).join('')
            + '</tbody></table>';
        $('box').innerHTML = html;

        $('history').classList.toggle('hidden', !r.history?.length);
        $('history').innerHTML = '<div class="font-semibold mb-2">History / Amateka</div>' + (r.history || []).map(h =>
            `<div class="text-sm border-t border-gray-100 py-2"><span class="text-gray-500">${esc(new Date(h.created_at).toLocaleString())}</span> · ${esc((labels[h.to_status] || [h.to_status])[0])} <span class="text-gray-500">by ${esc(h.actor)}</span>${h.note ? `<div class="text-gray-700">${esc(h.note)}</div>` : ''}</div>`).join('');

        const edit = $('edit');
        if (!d.can_resubmit) { edit.classList.add('hidden'); return; }
        edit.classList.remove('hidden');
        edit.innerHTML = `<div class="font-semibold text-lia-700">Edit and resubmit / Hindura wongere wohereze</div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="text-sm">Pick-up date<input type="date" id="e-from" value="${esc(r.needed_from)}" class="w-full border rounded p-2"></label>
                <label class="text-sm">Return by<input type="date" id="e-to" value="${esc(r.return_by)}" class="w-full border rounded p-2"></label>
            </div>
            <label class="text-sm block">Purpose / project<textarea id="e-purpose" class="w-full border rounded p-2" rows="3">${esc(r.purpose_project_description)}</textarea></label>
            <div class="text-sm font-medium">Items (set 0 to remove)</div>
            ${r.items.map(i => `<div class="flex items-center gap-2 text-sm"><span class="flex-1">${esc(i.name)}</span><input type="number" min="0" data-item="${i.equipment_item_id}" value="${esc(i.qty_requested)}" class="w-20 border rounded p-1"></div>`).join('')}
            <label class="text-sm block">Message to the team (optional)<textarea id="e-note" class="w-full border rounded p-2" rows="2"></textarea></label>
            <div id="e-err" class="text-sm text-red-600"></div>
            <button id="e-submit" class="w-full sm:w-auto px-5 py-3 rounded-xl bg-lia-600 font-semibold text-white text-sm hover:bg-lia-700 disabled:opacity-60">Resubmit request</button>`;
        $('e-submit').onclick = async () => {
            const items = [...edit.querySelectorAll('[data-item]')].map(el => ({ equipment_item_id: +el.dataset.item, qty: +el.value })).filter(i => i.qty > 0);
            $('e-err').textContent = '';
            $('e-submit').disabled = true;
            try {
                const res = await fetch(api + '/resubmit', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({ needed_from: $('e-from').value, return_by: $('e-to').value, purpose_project_description: $('e-purpose').value || null, items, note: $('e-note').value || null }) });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    const first = data.errors ? Object.values(data.errors)[0] : null;
                    $('e-err').textContent = (first && first[0]) || data.message || 'Could not resubmit.';
                    return;
                }
                render(data.request);
            } finally { const b = $('e-submit'); if (b) b.disabled = false; }
        };
    }
    fetch(api, { headers: { Accept: 'application/json' } }).then(r => r.ok ? r.json() : Promise.reject()).then(d => render(d.request))
        .catch(() => { $('box').textContent = 'Request not found.'; });
})();
</script>
</body>
</html>
