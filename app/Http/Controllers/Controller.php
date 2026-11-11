<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Exists;

abstract class Controller
{
    protected function workspace(): Workspace
    {
        return app(CurrentWorkspace::class)->get();
    }

    /**
     * Validation rule: the user must be a member of the current workspace.
     */
    protected function memberRule(): Exists
    {
        return (new Exists('workspace_user', 'user_id'))->where('workspace_id', $this->workspace()->id);
    }

    /**
     * Validation rule: a record of $table that belongs to the current workspace.
     */
    protected function ownedRule(string $table, bool $softDeletes = true): Exists
    {
        $rule = (new Exists($table, 'id'))->where('workspace_id', $this->workspace()->id);

        return $softDeletes ? $rule->whereNull('deleted_at') : $rule;
    }

    protected function perPage(Request $request, int $default = 25): int
    {
        return min(100, max(1, (int) $request->integer('per_page', $default)));
    }

    /**
     * Applies ?sort=&direction= limited to an allow-list of columns.
     *
     * @param  list<string>  $sortable
     */
    protected function applySort(BuilderContract $query, Request $request, array $sortable, string $default = 'created_at'): void
    {
        $sort = in_array($request->query('sort'), $sortable, true) ? $request->query('sort') : $default;
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort, $direction)->orderBy('id', $direction);
    }

    /**
     * @param  list<string>  $columns
     */
    protected function applySearch(BuilderContract $query, ?string $term, array $columns): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function ($builder) use ($term, $columns): void {
            foreach ($columns as $column) {
                $builder->orWhere($column, 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%');
            }
        });
    }
}
