<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DuplicateRequestResource extends JsonResource
{
    protected $status, $message, $statusCode;

    public function __construct($status, $message, $resource, $statusCode){
        $this->status = $status;
        $this->message = $message;
        $this->resource = $resource;
        $this->statusCode = $statusCode;
    }


    public function toArray(Request $request): array
    {
       return [
        "status" => $this->status,
        "message" => $this->message,
        "resource" => $this->resource,
        'status_code' => $this->statusCode,
       ];
    }

    public function withResponse($request, $response)
    {
        $response->setStatusCode($this->statusCode); 
    }
}
