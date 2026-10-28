<!DOCTYPE html>
<html><head><meta charset="utf-8">
<style>
@page { size: A4; margin: 0; }
body { margin: 0; font-family: 'DejaVu Sans', sans-serif; color: #111827; }
.page { position: relative; width: 210mm; height: 296mm; page-break-after: always; }
.page:last-child { page-break-after: auto; }
.head { position: absolute; top: 2.5mm; left: 30mm; width: 150mm; font-size: 7pt; color: #6b7280; }
table.grid { position: absolute; top: 8.5mm; left: 30mm; border-collapse: collapse; }
td.cell { width: 50mm; height: 70mm; padding: 0; border: 0.2mm dashed #9ca3af; vertical-align: top; }
.label { margin: 1.5mm; width: 45.8mm; height: 65.8mm; border: 0.5mm solid #b91c1c; border-radius: 3mm; overflow: hidden; text-align: center; }
.band { background: #b91c1c; color: #fff; height: 8mm; padding: 0 2mm; text-align: left; }
.band img { width: 6mm; height: 6mm; margin-top: 1mm; border-radius: 1mm; vertical-align: middle; }
.band span { font-size: 6.5pt; font-weight: bold; vertical-align: middle; padding-left: 1mm; }
.qrbox { position: relative; width: 34mm; height: 34mm; margin: 2mm auto 0; background: #fff; }
.qrlogo { position: absolute; left: 13.6mm; top: 13.6mm; width: 6.8mm; height: 6.8mm; }
.name { font-size: 7.4pt; font-weight: bold; margin: 1mm 2mm 0; height: 7.8mm; overflow: hidden; line-height: 1.3; }
.sn { font-size: 6pt; color: #4b5563; margin: 0.5mm 2mm 0; }
.pill { display: inline-block; margin-top: 1.5mm; background: #fdf2f2; color: #991b1b; border: 0.2mm solid #f5c2c2; border-radius: 2mm; padding: 0.6mm 2mm; font-size: 5.6pt; font-weight: bold; }
.empty { padding: 40mm; text-align: center; color: #6b7280; }
</style></head><body>
@if ($count === 0)
  <div class="empty">No materials match the selected filters.</div>
@endif
@foreach ($pages as $p => $page)
<div class="page">
  <div class="head">Love in Arts · LIA Media — equipment labels · {{ $date }} · sheet {{ $p + 1 }}/{{ count($pages) }} · cut along dashed lines</div>
  <table class="grid">
  @foreach (array_chunk($page, 3) as $row)
    <tr>
    @foreach ($row as $l)
      <td class="cell"><div class="label">
        <div class="band">@if ($logo)<img src="{{ $logo }}">@endif<span>Love in Arts · LIA Media</span></div>
        <div class="qrbox">{!! $l['qr'] !!}@if ($logo)<img class="qrlogo" src="{{ $logo }}">@endif</div>
        <div class="name">{{ \Illuminate\Support\Str::limit($l['name'], 40) }}</div>
        <div class="sn">SN: {{ $l['serial'] ?: '—' }}@if ($l['tag']) · {{ $l['tag'] }}@endif</div>
        <div class="pill">Scan to confirm pickup / return</div>
      </div></td>
    @endforeach
    </tr>
  @endforeach
  </table>
</div>
@endforeach
</body></html>
