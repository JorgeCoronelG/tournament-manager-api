<?php

namespace App\Core\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Contrato completo. Las capas que solo necesiten una parte pueden depender
 * de la interfaz específica (lectura, escritura, masivas o sincronización).
 *
 * @template TModel of Model
 *
 * @extends ReadableRepositoryInterface<TModel>
 * @extends WritableRepositoryInterface<TModel>
 */
interface BaseRepositoryInterface extends BulkRepositoryInterface, ReadableRepositoryInterface, RelationSyncRepositoryInterface, WritableRepositoryInterface {}
