<?php

namespace App\Core;

use App\Core\Traits\ApiResponse;
use App\Http\Controllers\Controller;

class BaseApiController extends Controller
{
    use ApiResponse;
}
