<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'content' => $this->content,
            'reasoning_metadata' => $this->reasoning_metadata,
            'processing_status' => $this->processing_status,
            'created_at' => $this->created_at,
        ];
    }
}
