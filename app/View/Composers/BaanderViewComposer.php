<?php

namespace App\View\Composers;

use Illuminate\View\View;

class BaanderViewComposer
{
    public function compose(View $view)
    {
        $view->with('baanderLinks', [
            'github'  => 'https://github.com/baander-app/baander',
            'website' => 'https://baander.app',
        ]);
    }
}