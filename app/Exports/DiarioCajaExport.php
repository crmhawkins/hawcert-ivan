<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\Exportable;

class DiarioCajaExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize
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
        $ingresos = DB::table('ingresos')
            ->select(
                'date',
                'title',
                DB::raw('quantity as ingreso'),
                DB::raw('0 as gasto')
            )
            ->whereBetween('date', [$this->desde, $this->hasta])
            ->where('quantity', '>', 0);

        $gastos = DB::table('gastos')
            ->select(
                'date',
                'title',
                DB::raw('0 as ingreso'),
                DB::raw('ABS(quantity) as gasto')
            )
            ->whereBetween('date', [$this->desde, $this->hasta]);

        return $ingresos->unionAll($gastos)->orderBy('date');
    }

    public function headings(): array
    {
        return [
            'Fecha',
            'Concepto',
            'Ingreso (EUR)',
            'Gasto (EUR)',
        ];
    }

    public function map($row): array
    {
        return [
            $row->date,
            $row->title,
            $row->ingreso > 0 ? number_format($row->ingreso, 2, ',', '.') : '',
            $row->gasto > 0 ? number_format($row->gasto, 2, ',', '.') : '',
        ];
    }
}
