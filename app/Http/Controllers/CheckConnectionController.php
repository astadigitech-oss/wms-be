<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use Illuminate\Http\Request;

class CheckConnectionController extends Controller
{
    public function checkPingWithImage()
    {
        $image = url('storage/image-for-check-connection/423kb_image.png');
        return $image;
    }
}
