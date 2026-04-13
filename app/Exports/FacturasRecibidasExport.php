<?php

namespace App\Exports;

use App\Models\Gastos;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\Exportable;

class FacturasRecibidasExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize
{
    use Exportable;

    protected string $desde;
    protected string $hasta;

    public function __construct(string $fechaDesde, string $fechaHasta)
    {
        $this->desde = $fechaDesde;
        $this->hasta = $fechaHasta;
    }

    public function query()
    {
        return Gastos::query()
            ->with(['categoria'])
            ->whereBetween('date', [$this->desde, $this->hasta])
            ->orderBy('date');
    }

    public function headings(): array
    {
        return [
            'Fecha',
            'Concepto',
            'Categoria',
            'Importe',
            'Estado',
            'Tiene Factura',
        ];
    }

    public function map($gasto): array
    {
        return [
            $gasto->date,
            $gasto->title ?? '',
            $gasto->categoria->nombre ?? ($gasto->categoria->name ?? ''),
            number_format(abs($gasto->quantity ?? 0), 2, ',', '.'),
            $this->mapEstado($gasto->estado_id),
            (!empty($gasto->factura_foto)) ? 'Si' : 'No',
        ];
    }

    protected function mapEstado(?int $estadoId): string
    {
        return match ($estadoId) {
            1 => 'Pend.Categorizar',
            2 => 'Pend.Pago',
            3 => 'Pagado',
            default => 'Desconocido',
        };
    }
}
