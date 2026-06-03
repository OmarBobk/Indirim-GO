<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Private channel for user notifications: private-App.Models.User.{id}
| Only the authenticated user can subscribe to their own channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('admin.fulfillments', function ($user) {
    return $user !== null && $user->can('view_fulfillments');
});

Broadcast::channel('admin.topups', function ($user) {
    return $user !== null && $user->hasRole('admin');
});

Broadcast::channel('admin.activities', function ($user) {
    return $user !== null && $user->hasRole('admin');
});

Broadcast::channel('admin.system-events', function ($user) {
    return $user !== null && $user->hasRole('admin');
});

Broadcast::channel('admin.bugs', function ($user) {
    return $user !== null && $user->can('manage_bugs');
});

Broadcast::channel('admin.automation', function ($user) {
    return $user !== null && $user->hasRole('admin');
});

Broadcast::channel('admin.automation', function ($user) {
    return $user !== null && $user->hasRole('admin');
});
