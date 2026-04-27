<?php

namespace Modulos_ERP\CecosKrsft\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ceco extends Model
{
    protected $table = 'cecos';

    protected $fillable = [
        'codigo',
        'nombre',
        'razon_social',
        'descripcion',
        'estado',
        'parent_id',
        'tipo_cliente',
        'nivel',
        'tipo_subcuenta',
        'created_by_user_id',
        'codigo_auto_generado',
    ];

    protected $casts = [
        'estado' => 'boolean',
        'parent_id' => 'integer',
        'nivel' => 'integer',
        'created_by_user_id' => 'integer',
        'codigo_auto_generado' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación con el padre jerárquico
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Ceco::class, 'parent_id');
    }

    /**
     * Relación con el usuario que creó el CECO
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by_user_id');
    }

    /**
     * Relación con los proyectos asociados a este CECO
     */
    public function projects(): HasMany
    {
        return $this->hasMany(\Modulos_ERP\ProyectosKrsft\Models\Project::class, 'ceco_id');
    }

    /**
     * Relación con los proyectos activos asociados a este CECO
     */
    public function activeProjects(): HasMany
    {
        return $this->hasMany(\Modulos_ERP\ProyectosKrsft\Models\Project::class, 'ceco_id')->where('status', 'active');
    }

    /**
     * Verifica si el CECO tiene proyectos activos
     */
    public function hasActiveProjects(): bool
    {
        return $this->activeProjects()->exists();
    }

    /**
     * Scope para filtrar CECOs válidos para enrolamiento de proyectos
     * Un CECO es válido si:
     * - nivel = 1 (centro de costo cliente, no grupo raíz)
     * - no tiene parent_id (no es hijo de otro CECO)
     * - no tiene tipo_subcuenta (no es MO/Gastos Directos/Gastos Indirectos)
     * - razon_social coincide (case-insensitive, trimmed)
     * - tiene al menos un proyecto activo
     */
    public function scopeValidForEnrollment($query, string $razonSocial)
    {
        if (empty(trim($razonSocial))) {
            return $query->whereRaw('1 = 0');
        }

        // Versión exacta (con acentos, tal cual)
        $exactQuery = trim(mb_strtolower($razonSocial));
        
        // Versión normalizada (sin acentos, espacios simples)
        $normalized = strtr($exactQuery, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = trim($normalized);
        
        if (empty($normalized)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('nivel', 1)
            ->whereNull('parent_id')
            ->whereNull('tipo_subcuenta')
            ->where(function($q) use ($exactQuery, $normalized) {
                // 1. Coincidencia exacta original
                $q->whereRaw('LOWER(TRIM(razon_social)) = ?', [$exactQuery])
                  ->orWhereRaw('LOWER(TRIM(nombre)) = ?', [$exactQuery]);
                
                // 2. Coincidencia sin acentos (REPLACE para cada vocal)
                $q->orWhereRaw('LOWER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(razon_social, "ó","o"), "á","a"), "é","e"), "í","i"), "ú","u"))) = ?', [$normalized])
                  ->orWhereRaw('LOWER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(nombre, "ó","o"), "á","a"), "é","e"), "í","i"), "ú","u"))) = ?', [$normalized]);
                  
                // 3. LIKE por palabras sueltas para capturar espacios diferentes
                $words = explode(' ', $normalized);
                foreach (array_filter($words) as $word) {
                    if (strlen($word) >= 3) {
                        $q->orWhereRaw('LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(razon_social, "ó","o"), "á","a"), "é","e"), "í","i"), "ú","u")) LIKE ?', ['%' . $word . '%'])
                          ->orWhereRaw('LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(nombre, "ó","o"), "á","a"), "é","e"), "í","i"), "ú","u")) LIKE ?', ['%' . $word . '%']);
                    }
                }

                // 4. Coincidencia sin acentos Y sin espacios (para PUNTOELASTIC vs PUNTO ELASTIC)
                $queryNoSpaces = str_replace(' ', '', $normalized);
                if (strlen($queryNoSpaces) >= 3) {
                    $q->orWhereRaw('LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(razon_social, " ",""), "ó","o"), "á","a"), "é","e"), "í","i"), "ú","u")) LIKE ?', ['%' . $queryNoSpaces . '%'])
                      ->orWhereRaw('LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(nombre, " ",""), "ó","o"), "á","a"), "é","e"), "í","i"), "ú","u")) LIKE ?', ['%' . $queryNoSpaces . '%']);
                }
            })
            ->whereHas('activeProjects');
    }

    /**
     * Relación con los hijos jerárquicos
     */
    public function children(): HasMany
    {
        return $this->hasMany(Ceco::class, 'parent_id')->orderBy('codigo', 'asc');
    }

    /**
     * Obtiene todos los hijos recursivamente
     */
    public function allDescendants()
    {
        return $this->children()->with('allDescendants');
    }

    /**
     * Obtiene el camino jerárquico hasta la raíz
     */
    public function getAncestorPath()
    {
        $path = [$this];
        $current = $this;
        
        while ($current->parent_id) {
            $current = $current->parent;
            $path[] = $current;
        }
        
        return array_reverse($path);
    }

    /**
     * Verifica si es un cliente de la categoría 0105 (Otros Clientes)
     */
    public function isOtrosClientes(): bool
    {
        return $this->tipo_cliente === '0105';
    }

    /**
     * Verifica si es un cliente de la categoría 0106 (Red Interna)
     */
    public function isRedInterna(): bool
    {
        return $this->tipo_cliente === '0106';
    }

    /**
     * Verifica si es una subcuenta generada
     */
    public function isSubcuenta(): bool
    {
        return !is_null($this->tipo_subcuenta);
    }

    /**
     * Obtiene el nombre del tipo de subcuenta
     */
    public function getSubcuentaNombre(): ?string
    {
        return match($this->tipo_subcuenta) {
            '01' => 'MO (Mano de Obra)',
            '02' => 'Gastos Directos',
            '03' => 'Gastos Indirectos',
            default => null,
        };
    }

    /**
     * Verifica si el CECO está siendo usado por algún proyecto
     */
    public function isUsedByProject(): bool
    {
        return $this->projects()->exists();
    }
}

