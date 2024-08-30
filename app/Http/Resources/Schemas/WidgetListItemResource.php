<?php

namespace App\Http\Resources\Schemas;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WidgetListItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * Id of the widget.
             * Use this to query the schema.
             *
             * @var string
             * @example https://baander.test/api/schemas/widgets/MainNavBar
             */
            'id'   => $this['id'],
            /**
             * Name of the schema.
             *
             * @var string
             * @example MainNavBar
             */
            'name' => $this['name'],
        ];
    }
}
