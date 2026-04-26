<?php

namespace Modulos_ERP\CecosKrsft\Controllers;

use App\Http\Controllers\Controller;
use App\Services\LogKrsftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Modulos_ERP\CecosKrsft\Models\Ceco;
use Modulos_ERP\CecosKrsft\Services\CecoHierarchyService;

class CecosController extends Controller
{
    protected string $cecosTable = 'cecos';

    public function __construct(private readonly CecoHierarchyService $cecoHierarchyService)
    {
    }

    public function index()
    {
        return Inertia::render('cecoskrsft/Index');
    }

    public function list(Request $request)
    {
        $query = Ceco::query()
            ->selectRaw('cecos.*, (SELECT id FROM projects WHERE projects.ceco_id = cecos.id LIMIT 1) as project_id');

        if ($request->has('search') && $request->search) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('cecos.codigo', 'like', $search)
                    ->orWhere('cecos.nombre', 'like', $search)
                    ->orWhere('cecos.razon_social', 'like', $search);
            });
        }

        if ($request->has('sort_by')) {
            $direction = $request->get('sort_direction', 'asc');
            $query->orderBy('cecos.' . $request->sort_by, $direction);
        } else {
            $query->orderBy('cecos.codigo', 'asc');
        }

        $cecos = $query->get();

        return response()->json([
            'success' => true,
            'data' => $cecos,
            'total' => $cecos->count(),
        ]);
    }

    /**
     * Obtiene el árbol jerárquico para visualización
     */
    public function getTree()
    {
        return response()->json([
            'success' => true,
            'data' => $this->cecoHierarchyService->getTree(),
        ]);
    }

    /**
     * Crea un cliente con sus 3 subcuentas obligatorias en una transacción
     */
    public function storeWithSubcuentas(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'razon_social' => 'nullable|string|max:255',
            'descripcion' => 'nullable|string',
            'tipo_cliente' => 'required|string|max:50',
            'estado' => 'boolean',
        ]);

        try {
            $result = $this->cecoHierarchyService->createWithSubcuentas(
                $validated,
                $request->user()->id ?? null,
            );

            app(\App\Services\LogKrsftService::class)->log(
                module: 'cecoskrsft',
                action: 'create_ceco_hierarchy',
                message: "CECO y subcuentas creados: {$validated['nombre']}",
                level: 'info',
                userId: auth()->id(),
                userName: auth()->user()?->name,
                extra: ['nombre' => $validated['nombre'], 'tipo' => $validated['tipo_cliente']]
            );

            return response()->json([
                'success' => true,
                'message' => 'Cliente y subcuentas creados exitosamente',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            app(\App\Services\LogKrsftService::class)->logError(
                module: 'cecoskrsft',
                action: 'create_ceco_hierarchy_error',
                message: "Error al crear jerarquía CECO {$validated['nombre']}: " . $e->getMessage()
            );

            return response()->json([
                'success' => false,
                'message' => 'Error al crear el cliente: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Crear CECO simple (sin subcuentas)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'codigo' => 'required|string|max:50|unique:' . $this->cecosTable,
            'nombre' => 'required|string|max:255',
            'razon_social' => 'nullable|string|max:255',
            'descripcion' => 'nullable|string',
            'estado' => 'boolean',
        ]);

        $id = DB::table($this->cecosTable)->insertGetId([
            'codigo' => $validated['codigo'],
            'codigo_auto_generado' => false,
            'nombre' => $validated['nombre'],
            'razon_social' => $validated['razon_social'] ?? null,
            'descripcion' => $validated['descripcion'] ?? null,
            'estado' => $validated['estado'] ?? true,
            'created_by_user_id' => $request->user()->id ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(\App\Services\LogKrsftService::class)->log(
            module: 'cecoskrsft',
            action: 'create_ceco',
            message: "CECO creado: {$validated['codigo']} - {$validated['nombre']}",
            level: 'info',
            userId: auth()->id(),
            userName: auth()->user()?->name,
            extra: ['ceco_id' => $id, 'codigo' => $validated['codigo']]
        );

        return response()->json([
            'success' => true,
            'message' => 'Centro de costo creado exitosamente',
            'data' => DB::table($this->cecosTable)->find($id),
        ]);
    }

    /**
     * Actualizar CECO
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'codigo' => 'required|string|max:50|unique:' . $this->cecosTable . ',codigo,' . $id,
            'nombre' => 'required|string|max:255',
            'razon_social' => 'nullable|string|max:255',
            'descripcion' => 'nullable|string',
            'estado' => 'boolean',
        ]);

        $beforeData = DB::table($this->cecosTable)->find($id);

        DB::table($this->cecosTable)->where('id', $id)->update([
            'codigo' => $validated['codigo'],
            'nombre' => $validated['nombre'],
            'razon_social' => $validated['razon_social'] ?? null,
            'descripcion' => $validated['descripcion'] ?? null,
            'estado' => $validated['estado'] ?? true,
            'updated_at' => now(),
        ]);

        $afterData = DB::table($this->cecosTable)->find($id);

        LogKrsftService::log(
            module: 'cecoskrsft',
            action: 'update_ceco',
            message: "CECO actualizado: {$validated['codigo']} - {$validated['nombre']}",
            level: 'info',
            userId: auth()->id(),
            userName: auth()->user()?->name,
            extra: [
                'ceco_id' => $id,
                'codigo' => $validated['codigo'],
                'before' => ['codigo' => $beforeData->codigo, 'nombre' => $beforeData->nombre, 'estado' => $beforeData->estado],
                'after' => ['codigo' => $afterData->codigo, 'nombre' => $afterData->nombre, 'estado' => $afterData->estado]
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Centro de costo actualizado exitosamente',
            'data' => $afterData,
        ]);
    }

    /**
     * Eliminar CECO (con validaciones)
     */
    public function destroy($id)
    {
        $ceco = Ceco::find($id);

        if (!$ceco) {
            return response()->json([
                'success' => false,
                'message' => 'Centro de costo no encontrado',
            ], 404);
        }

        // No permitir eliminar subcuentas individuales
        if ($ceco->isSubcuenta()) {
            return response()->json([
                'success' => false,
                'message' => 'No se pueden eliminar subcuentas generadas del sistema',
            ], 422);
        }

        DB::transaction(function () use ($ceco) {
            // Si es cabeza, eliminar primero subcuentas y luego la cabeza
            if ($ceco->children()->exists()) {
                $ceco->children()->delete();
            }
            $ceco->delete();
        });

        app(\App\Services\LogKrsftService::class)->log(
            module: 'cecoskrsft',
            action: 'delete_ceco',
            message: "CECO eliminado: {$ceco->codigo} - {$ceco->nombre}",
            level: 'warning',
            userId: auth()->id(),
            userName: auth()->user()?->name,
            extra: ['ceco_id' => $id, 'codigo' => $ceco->codigo]
        );

        return response()->json([
            'success' => true,
            'message' => 'Cabeza de CECO y subcuentas eliminadas exitosamente',
        ]);
    }

    /**
     * Mostrar un CECO específico
     */
    public function show($id)
    {
        $ceco = Ceco::find($id);

        if (!$ceco) {
            return response()->json([
                'success' => false,
                'message' => 'Centro de costo no encontrado'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $ceco
        ]);
    }
}
