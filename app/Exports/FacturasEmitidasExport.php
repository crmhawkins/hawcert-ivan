<?php

namespace App\Exports;

use App\Models\Invoices;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\Exportable;

class FacturasEmitidasExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize
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
        return Invoices::query()
            ->with(['cliente', 'reserva'])
            ->whereBetween('fecha', [$this->desde, $this->hasta])
            ->orderBy('fecha');
    }

    public function headings(): array
    {
        return [
            'Referencia',
            'Fecha',
            'Cliente',
            'Concepto',
            'Base',
            'IVA',
            'Total',
            'Estado',
            'Fecha Cobro',
        ];
    }

    public function map($invoice): array
    {
        return [
            $invoice->reference ?? '',
            $invoice->fecha,
            $invoice->cliente->name ?? ($invoice->cliente->nombre ?? ''),
            $invoice->concepto ?? ($invoice->reserva->titulo ?? ''),
            number_format($invoice->base ?? 0, 2, ',', '.'),
            number_format($invoice->iva ?? 0, 2, ',', '.'),
            number_format($invoice->total ?? 0, 2, ',', '.'),
            $this->mapEstado($invoice->invoice_status_id),
            $invoice->fecha_cobro ?? '',
        ];
    }

    protected function mapEstado(?int $statusId): string
    {
        return match ($statusId) {
            1, 2 => 'Pendiente',
            3, 4, 6 => 'Cobrada',
            5, 7 => 'Cancelada',
            default => 'Desconocido',
        };
    }
}
