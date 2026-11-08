<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\User;

class ApplicationPolicy
{
    /**
     * Create a new policy instance.
     */
    public function __construct()
    {
        //
    }

    public function view(User $user, Application $app): bool
    {
        return $user->id === $app->student->user_id
            || $user->id === $app->opportunity->organization->user_id
            || $user->role === 'admin';
    }

    public function manage(User $user, Application $app): bool
    {
        return $user->id === $app->opportunity->organization->user_id
            || $user->role === 'admin';
    }
}
