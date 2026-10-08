<?php

use Illuminate\Support\Facades\Route;

// The UI is the static page set in public/ (admin/, sales/, hr/ …). Laravel only serves the API
// plus these two entry points.
Route::redirect('/', '/auth/Login.html');

// Public client upload link (handover §7): https://app.morenaproperties.co.za/u/{token}
// The page uses relative asset paths, so hand off to its real location with the token in the query.
Route::get('/u/{token}', fn (string $token) => redirect('/client-upload/Upload.html?t='.$token))
    ->where('token', '[a-z0-9]{6,64}');
