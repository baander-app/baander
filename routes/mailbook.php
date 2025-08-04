<?php

use App\Mail\MailbookMail;
use Xammie\Mailbook\Facades\Mailbook;

(new Xammie\Mailbook\Facades\Mailbook)->add(MailbookMail::class);
