<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchUsersRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class UserSearchController extends Controller
{
    public function __invoke(SearchUsersRequest $request): AnonymousResourceCollection
    {
        $actor = $request->user('api');

        Gate::forUser($actor)->authorize('viewAny', User::class);

        $perPage = $request->integer('per_page', 25);

        $users = User::search($request->validated('query'))
            ->where('is_active', true)
            ->paginate($perPage, 'page', $request->integer('page', 1));

        $users->appends(['per_page' => $perPage]);

        return UserResource::collection($users);
    }
}
