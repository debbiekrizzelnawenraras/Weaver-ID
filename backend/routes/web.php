<?php

use Illuminate\Support\Facades\Route;

// The dashboards are static pages in /public. Send visitors to the login page.
Route::redirect('/', '/login.html');
