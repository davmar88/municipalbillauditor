<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MetroResource;
use App\Support\Metros;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MetroController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return MetroResource::collection(Metros::all());
    }
}
