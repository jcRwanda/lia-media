<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EquipmentItem;
use App\Services\QrLabelService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class EquipmentLabelController extends Controller
{
    public function pdf(Request $request, QrLabelService $qr)
    {
        abort_unless($request->user()?->canDo('equipment.delete'), 403);

        $data = $request->validate([
            'ids' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:100'],
            'trainee' => ['nullable', 'in:0,1,true,false'],
            'in_stock' => ['nullable', 'in:0,1,true,false'],
        ]);

        $query = EquipmentItem::query();
        if (! empty($data['ids'])) {
            $ids = array_filter(array_map('intval', explode(',', $data['ids'])));
            $query->whereIn('id', $ids);
        }
        if (! empty($data['category'])) {
            $query->where('category', $data['category']);
        }
        if (isset($data['trainee']) && $data['trainee'] !== '') {
            $query->where('allowed_for_trainees', in_array($data['trainee'], ['1', 'true'], true));
        }
        $inStock = ! isset($data['in_stock']) || in_array($data['in_stock'], ['1', 'true'], true);
        if ($inStock) {
            $query->where('total_qty', '>', 0);
        }

        $labels = $query->orderBy('category')->orderBy('name')->get()->map(fn (EquipmentItem $i) => [
            'name' => $i->name,
            'serial' => $i->serial_number,
            'tag' => $i->asset_tag,
            'category' => $i->category,
            'qr' => $qr->html($i->qrUrl()),
        ])->all();

        return $this->render($labels)->download('lia-equipment-labels-'.now()->format('Y-m-d').'.pdf');
    }

    public static function render(array $labels)
    {
        $logo = public_path('images/lia-logo-white.jpg');

        return Pdf::loadView('pdf.equipment-labels', [
            'pages' => array_chunk($labels, 12),
            'count' => count($labels),
            'logo' => is_file($logo) ? 'data:image/jpeg;base64,'.base64_encode(file_get_contents($logo)) : null,
            'date' => now()->format('d M Y'),
        ])->setPaper('a4')->setOption(['dpi' => 150, 'defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]);
    }
}
