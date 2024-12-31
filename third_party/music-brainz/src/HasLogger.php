<?php

namespace MusicBrainz;

trait HasLogger
{
    public function getLogger()
    {
        return LoggerManager::getInstance()->getLogger();
    }
}