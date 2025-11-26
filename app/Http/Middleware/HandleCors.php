<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\HandleCors as Middleware;

class HandleCors extends Middleware
{
    protected function configureDefaults(Request $request): void
    {
        $this->allowedOrigins = ['http://localhost:3000'];
        $this->allowedMethods = ['*'];
        $this->allowedHeaders = ['*'];
        $this->supportsCredentials = true;
    }
}
