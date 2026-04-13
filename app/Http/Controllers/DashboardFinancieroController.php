<?php

namespace App\Http\Controllers;

use App\Models\Invoices;
use App\Models\Ingresos;
use App\Models\Reserva;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardFinancieroController extends Controller
{
    public function index(Request $request)
    {
        $fechaDesde = $request->get('fecha_desde', now()->startOfMonth()->format('Y-m-d'));
        $fechaHasta = $request->get('fecha_hasta', now()->format('Y-m-d'));
        $estado = $request->get('estado', 'todos');
        $orderBy = $request->get('order_by', 'fecha');
        $direction = $request->get('direction', 'desc');

        // Validar campos de orden permitidos
        if (!in_array($orderBy, ['fecha', 'total', 'reference', 'created_at'])) {
            $orderBy = 'fecha';
        }
        if (!in_array($direction, ['asc', 'desc'])) {
            $direction = 'desc';
        }

        // Totales
        $queryBase = Invoices::whereBetween('fecha', [$fechaDesde, $fechaHasta]);

        $totalFacturado = (clone $queryBase)->sum('total');
        $totalCobrado = (clone $queryBase)->whereIn('invoice_status_id', [3, 4, 6])->sum('total');
        $totalPendiente = (clone $queryBase)->whereIn('invoice_status_id', [1, 2])->sum('total');
        $totalCancelado = (clone $queryBase)->whereIn('invoice_status_id', [5, 7])->sum('total');
        $numFacturas = (clone $queryBase)->count();

        // Facturas con filtro de estado
        $queryFacturas = Invoices::with(['cliente', 'reserva', 'estado'])
            ->whereBetween('fecha', [$fechaDesde, $fechaHasta]);

        if ($estado === 'pendiente') {
            $queryFacturas->whereIn('invoice_status_id', [1, 2]);
        } elseif ($estado === 'cobrada') {
            $queryFacturas->whereIn('invoice_status_id', [3, 4, 6]);
        } elseif ($estado === 'cancelada') {
            $queryFacturas->whereIn('invoice_status_id', [5, 7]);
        }

        $facturas = $queryFacturas->orderBy($orderBy, $direction)->paginate(25);

        // Ingresos por mes (ultimos 6 meses)
        $ingresosPorMes = DB::table('ingresos')
            ->selectRaw('DATE_FORMAT(date, "%Y-%m") as mes, SUM(quantity) as total')
            ->where('date', '>=', now()->subMonths(6)->startOfMonth())
            ->groupBy('mes')
            ->orderBy('mes')
            ->get();

        // Ingresos por canal (ultimo mes)
        $ingresosPorCanal = DB::table('ingresos')
            ->selectRaw('
                CASE
                    WHEN title LIKE "%booking%" THEN "Booking"
                    WHEN title LIKE "%airbnb%" THEN "Airbnb"
                    WHEN title LIKE "%stripe%" THEN "Web (Stripe)"
                    WHEN title LIKE "%agoda%" THEN "Agoda"
                    ELSE "Otros"
                END as canal,
                SUM(quantity) as total,
                COUNT(*) as num
            ')
            ->where('date', '>=', now()->startOfMonth())
            ->groupBy('canal')
            ->orderBy('total', 'desc')
            ->get();

        // Facturas pendientes desde 2026 - PAGINADAS 15/pagina
        $facturasAntiguas = Invoices::with(['cliente', 'reserva'])
            ->whereIn('invoice_status_id', [1, 2])
            ->where('fecha', '>=', '2026-01-01')
            ->orderBy('fecha', 'desc')
            ->paginate(15, ['*'], 'pend_page');

        return view('admin.tesoreria.dashboard-financiero', compact(
            'totalFacturado', 'totalCobrado', 'totalPendiente', 'totalCancelado',
            'numFacturas', 'facturas', 'ingresosPorMes', 'ingresosPorCanal',
            'facturasAntiguas', 'fechaDesde', 'fechaHasta', 'estado',
            'orderBy', 'direction'
        ));
    }

    /**
     * Cambiar estado de una factura (AJAX).
     */
    public function cambiarEstado(Request $request, $id)
    {
        $request->validate([
            'estado' => 'required|in:pendiente,cobrada,cancelada',
        ]);

        $factura = Invoices::findOrFail($id);
        $estadoMap = [
            'pendiente' => 1,
            'cobrada' => 3,
            'cancelada' => 5,
        ];

        $nuevoEstado = $estadoMap[$request->estado];
        $factura->update([
            'invoice_status_id' => $nuevoEstado,
            'fecha_cobro' => $request->estado === 'cobrada' ? now() : $factura->fecha_cobro,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Factura #{$factura->reference} marcada como {$request->estado}",
        ]);
    }

    /**
     * Asignar referencias consecutivas a facturas de 2026 que no tengan.
     */
    public function asignarReferencias()
    {
        // Obtener ultimo numero de referencia de 2026
        $ultimaRef = DB::table('invoices')
            ->whereNotNull('reference')
            ->where('reference', 'like', 'R2026%')
            ->orderByDesc('reference')
            ->value('reference');

        // Extraer el numero secuencial
        $ultimoNumero = 0;
        if ($ultimaRef && preg_match('/R\d{4}\/\d{2}\/(\d+)/', $ultimaRef, $matches)) {
            $ultimoNumero = (int) $matches[1];
        }

        // Obtener facturas sin referencia de 2026, ordenadas por fecha e id
        $facturasSinRef = DB::table('invoices')
            ->where(function($q) {
                $q->whereNull('reference')->orWhere('reference', '');
            })
            ->whereYear('fecha', 2026)
            ->orderBy('fecha', 'asc')
            ->orderBy('id', 'asc')
            ->get(['id', 'fecha']);

        $asignadas = 0;
        foreach ($facturasSinRef as $f) {
            $ultimoNumero++;
            $mes = Carbon::parse($f->fecha)->format('m');
            $referencia = sprintf('R2026/%s/%06d', $mes, $ultimoNumero);

            DB::table('invoices')->where('id', $f->id)->update([
                'reference' => $referencia,
                'updated_at' => now(),
            ]);
            $asignadas++;
        }

        return response()->json([
            'success' => true,
            'message' => "Se han asignado {$asignadas} referencias consecutivas.",
            'desde' => $ultimaRef,
            'primera_nueva' => $facturasSinRef->first() ? sprintf('R2026/%s/%06d', Carbon::parse($facturasSinRef->first()->fecha)->format('m'), ($ultimoNumero - $asignadas + 1)) : null,
            'ultima_nueva' => $facturasSinRef->last() ? sprintf('R2026/%s/%06d', Carbon::parse($facturasSinRef->last()->fecha)->format('m'), $ultimoNumero) : null,
        ]);
    }
}
